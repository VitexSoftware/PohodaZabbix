<?php

declare(strict_types=1);

/**
 * This file is part of the pohoda-zabbix package
 *
 * https://github.com/VitexSoftware/pohoda-zabbix
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
use VitexSoftware\PohodaDigest\DataProvider\PohodaDataProvider;

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
    private string $cacheFile;

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

        $this->cacheFile = $this->cacheDir . '/digest_cache.json';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o755, true);
        }
    }

    /**
     * Get a single Zabbix metric value.
     *
     * @param string $metric Full or short metric key (e.g. 'debtors.count')
     */
    public function getMetric(string $metric): string
    {
        $allMetrics = $this->getAllMetrics();

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
    public function getAllMetrics(): array
    {
        $cached = $this->getCachedMetrics();

        if ($cached !== null) {
            return $cached;
        }

        return $this->collectAndCacheMetrics();
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
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Handle CLI invocation.
     */
    public static function handleCommandLine(): void
    {
        $options = getopt('m::e::d::c::', ['metric::', 'env::', 'debug::', 'color::']);

        $envfile = $options['env'] ?? '../.env';
        \Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO'], $envfile);

        $debugMode = isset($options['debug']) || isset($options['d']);
        $requestedMetric = $options['metric'] ?? '';

        // Find first positional argument as metric
        if (empty($requestedMetric)) {
            global $argv;

            foreach ($argv as $index => $arg) {
                if ($index === 0 || str_starts_with($arg, '-')) {
                    continue;
                }

                $prevArg = $argv[$index - 1] ?? '';

                if (\in_array($prevArg, ['-m', '--metric', '-e', '--env'], true)) {
                    continue;
                }

                $requestedMetric = $arg;

                break;
            }
        }

        $feeder = new self();

        if (!empty($requestedMetric)) {
            echo $feeder->getMetric($requestedMetric) . "\n";
        } else {
            $metrics = $feeder->getAllMetrics();
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

    /**
     * @return array<string, int|float|string>|null
     */
    private function getCachedMetrics(): ?array
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        if ((time() - filemtime($this->cacheFile)) > self::CACHE_TTL) {
            return null;
        }

        $content = file_get_contents($this->cacheFile);

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return \is_array($data) ? $data : null;
    }

    /**
     * @return array<string, int|float|string>
     */
    private function collectAndCacheMetrics(): array
    {
        $dataProvider = new PohodaDataProvider();
        $moduleRunner = new ModuleRunner($dataProvider);

        foreach (self::ZABBIX_MODULES as $key => $class) {
            $moduleRunner->addModule($key, $class);
        }

        $start = new \DateTime('-1 month');
        $end = new \DateTime();
        $period = new \DatePeriod($start, new \DateInterval('P1D'), $end);

        $digestData = $moduleRunner->run($period);
        $metrics = $this->extractZabbixMetrics($digestData);

        $json = json_encode($metrics, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->cacheFile, $json, \LOCK_EX);

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
