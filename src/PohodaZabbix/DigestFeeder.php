<?php

declare(strict_types=1);

/**
 * This file is part of the pohoda-zabbix package
 *
 * https://github.com/VitexSoftware/PohodaZabbix
 *
 * (c) Vítězslav Dvořák <info@vitexsoftware.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VitexSoftware\PohodaZabbix;

use VitexSoftware\DigestModules\Core\ModuleRunner;
use VitexSoftware\DigestModules\Core\ZabbixOutputInterface;
use VitexSoftware\DigestModules\Modules;
use VitexSoftware\PohodaZabbix\DataProvider\PohodaDataProvider;

/**
 * Business metrics feeder for Zabbix from Pohoda via digest modules.
 *
 * Runs selected digest modules via ModuleRunner, collects results,
 * and exports them as Zabbix-compatible key→value metrics with
 * file-based caching.
 *
 * All metric keys are prefixed with 'pohoda.digest.' by default.
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class DigestFeeder
{
    /** Cache TTL in seconds */
    private const CACHE_TTL = 300;

    /** Metric key prefix */
    private const METRIC_PREFIX = 'pohoda.digest.';

    /** @var array<string, string> Modules to run for Zabbix metrics */
    private const ZABBIX_MODULES = [
        'debtors' => Modules\Debtors::class,
        'outcoming_invoices' => Modules\OutcomingInvoices::class,
        'incoming_invoices' => Modules\IncomingInvoices::class,
        'incoming_payments' => Modules\IncomingPayments::class,
        'outcoming_payments' => Modules\OutcomingPayments::class,
        'waiting_income' => Modules\WaitingIncome::class,
        'waiting_payments' => Modules\WaitingPayments::class,
        'unmatched_payments' => Modules\UnmatchedPayments::class,
    ];

    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $systemCacheDir = '/var/cache/pohoda-zabbix';

        if ($cacheDir !== null) {
            $this->cacheDir = $cacheDir;
        } elseif (is_writable(\dirname($systemCacheDir)) && (is_dir($systemCacheDir) || @mkdir($systemCacheDir, 0o755, true))) {
            $this->cacheDir = $systemCacheDir;
        } else {
            $this->cacheDir = sys_get_temp_dir() . '/pohoda-zabbix-cache-' . posix_getuid();
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o755, true);
        }
    }

    /**
     * Get a single Zabbix metric value.
     *
     * @param string $metric Full or short metric key (e.g. 'debtors.count')
     */
    public function getMetric(string $metric, ?\DatePeriod $period = null): string
    {
        $allMetrics = $this->getAllMetrics($period);

        if (isset($allMetrics[$metric])) {
            return (string) $allMetrics[$metric];
        }

        $prefixed = self::METRIC_PREFIX . $metric;

        if (isset($allMetrics[$prefixed])) {
            return (string) $allMetrics[$prefixed];
        }

        return '0';
    }

    /**
     * Get all collected Zabbix metrics.
     *
     * @return array<string, int|float|string>
     */
    public function getAllMetrics(?\DatePeriod $period = null): array
    {
        $period ??= self::defaultPeriod();
        $cached = $this->getCachedMetrics($period);

        if ($cached !== null) {
            return $cached;
        }

        return $this->collectAndCacheMetrics($period);
    }

    /**
     * Test Pohoda mServer connectivity.
     *
     * @return bool True if server is reachable
     */
    public function testConnectivity(): bool
    {
        try {
            $provider = new PohodaDataProvider();

            return $provider->testConnection();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Parse a period string into a DatePeriod.
     *
     * Accepted values:
     *   month   — last 30 days up to today (default)
     *   year    — Jan 1 of current year up to today
     *   YYYY    — full calendar year (e.g. 2024)
     */
    public static function parsePeriod(string $spec): \DatePeriod
    {
        $spec = strtolower(trim($spec));

        if ($spec === 'month' || $spec === '') {
            return self::defaultPeriod();
        }

        if ($spec === 'year') {
            $start = new \DateTime('first day of January this year 00:00:00');
            $end = new \DateTime('today 23:59:59');

            return new \DatePeriod($start, new \DateInterval('P1D'), $end);
        }

        // Specific four-digit year, e.g. "2024"
        if (preg_match('/^\d{4}$/', $spec)) {
            $start = new \DateTime("{$spec}-01-01 00:00:00");
            $end = new \DateTime("{$spec}-12-31 23:59:59");

            return new \DatePeriod($start, new \DateInterval('P1D'), $end);
        }

        throw new \InvalidArgumentException("Unknown period spec '{$spec}'. Use: month, year, or YYYY.");
    }

    /**
     * Handle CLI invocation.
     */
    public static function handleCommandLine(): void
    {
        $options = getopt('m::e::d::p::c::', ['metric::', 'env::', 'debug::', 'period::', 'color::']);

        $envfile = $options['env'] ?? '../.env';
        \Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO'], $envfile);

        $debugMode = isset($options['debug']) || isset($options['d']);
        $requestedMetric = $options['metric'] ?? '';
        $periodSpec = $options['period'] ?? $options['p'] ?? 'month';

        // Find first positional argument as metric
        if (empty($requestedMetric)) {
            global $argv;

            foreach ($argv as $index => $arg) {
                if ($index === 0 || str_starts_with($arg, '-')) {
                    continue;
                }

                $prevArg = $argv[$index - 1] ?? '';

                if (\in_array($prevArg, ['-m', '--metric', '-e', '--env', '-p', '--period'], true)) {
                    continue;
                }

                $requestedMetric = $arg;

                break;
            }
        }

        try {
            $period = self::parsePeriod((string) $periodSpec);
        } catch (\InvalidArgumentException $e) {
            fwrite(\STDERR, $e->getMessage() . "\n");
            exit(1);
        }

        $feeder = new self();

        if (!empty($requestedMetric)) {
            echo $feeder->getMetric($requestedMetric, $period) . "\n";
        } else {
            $metrics = $feeder->getAllMetrics($period);
            $jsonFlags = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;

            if ($debugMode) {
                $jsonFlags |= \JSON_PRETTY_PRINT;
            }

            echo json_encode($metrics, $jsonFlags) . "\n";
        }

        exit(0);
    }

    /**
     * Handle connectivity check CLI.
     */
    public static function handleStatusCommandLine(): void
    {
        $options = getopt('e::d::', ['env::', 'debug::']);

        $envfile = $options['env'] ?? '../.env';
        \Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO'], $envfile);

        $feeder = new self();
        $connected = $feeder->testConnectivity();

        echo $connected ? '1' : '0';
        echo "\n";

        exit($connected ? 0 : 1);
    }

    private static function defaultPeriod(): \DatePeriod
    {
        return new \DatePeriod(
            new \DateTime('-1 month'),
            new \DateInterval('P1D'),
            new \DateTime(),
        );
    }

    private function cacheFile(\DatePeriod $period): string
    {
        $start = $period->getStartDate()->format('Y-m-d');
        $end = $period->getEndDate()->format('Y-m-d');

        return $this->cacheDir . '/digest_cache_' . $start . '_' . $end . '.json';
    }

    /**
     * @return array<string, int|float|string>|null
     */
    private function getCachedMetrics(\DatePeriod $period): ?array
    {
        $file = $this->cacheFile($period);

        if (!file_exists($file)) {
            return null;
        }

        if ((time() - filemtime($file)) > self::CACHE_TTL) {
            return null;
        }

        $content = file_get_contents($file);

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return \is_array($data) ? $data : null;
    }

    /**
     * @return array<string, int|float|string>
     */
    private function collectAndCacheMetrics(\DatePeriod $period): array
    {
        $dataProvider = new PohodaDataProvider();
        $moduleRunner = new ModuleRunner($dataProvider);

        foreach (self::ZABBIX_MODULES as $key => $class) {
            $moduleRunner->addModule($key, $class);
        }

        $digestData = $moduleRunner->run($period);
        $metrics = $this->extractZabbixMetrics($digestData);

        $json = json_encode($metrics, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->cacheFile($period), $json, \LOCK_EX);

        return $metrics;
    }

    /**
     * @param array<string, mixed> $digestData
     *
     * @return array<string, int|float|string>
     */
    private function extractZabbixMetrics(array $digestData): array
    {
        $metrics = [];
        $modules = $digestData['modules'] ?? [];

        foreach (self::ZABBIX_MODULES as $key => $class) {
            $moduleData = $modules[$key] ?? [];

            if (empty($moduleData) || !($moduleData['success'] ?? false)) {
                continue;
            }

            if (!class_exists($class)) {
                continue;
            }

            $module = new $class();

            if ($module instanceof ZabbixOutputInterface) {
                $items = $module->toZabbixItems($moduleData);

                foreach ($items as $itemKey => $value) {
                    $metrics[self::METRIC_PREFIX . $itemKey] = $value;
                }
            }
        }

        $metrics[self::METRIC_PREFIX . 'last_run'] = time();

        return $metrics;
    }
}
