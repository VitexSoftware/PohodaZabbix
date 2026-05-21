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

namespace VitexSoftware\PohodaZabbix\DataProvider;

use Ease\Shared;
use mServer\Bank;
use mServer\Invoice;
use VitexSoftware\DigestModules\Core\DataProviderInterface;

/**
 * Minimal Pohoda data provider for Zabbix metric collection.
 *
 * Fetches only invoices and bank statements — the two entity types
 * required by the Zabbix digest modules. Contacts and products are
 * not supported; those belong in the reporting (Pohoda-Digest) project.
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class PohodaDataProvider implements DataProviderInterface
{
    private array $connectionInfo;

    public function __construct(array $config = [])
    {
        if (empty($config)) {
            Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO']);
            $config = [
                'url'      => Shared::cfg('POHODA_URL'),
                'username' => Shared::cfg('POHODA_USERNAME'),
                'password' => Shared::cfg('POHODA_PASSWORD'),
                'ico'      => Shared::cfg('POHODA_ICO'),
            ];
        }

        $this->connectionInfo = $config;
    }

    /**
     * {@inheritDoc}
     */
    public function getData(string $entity, array $conditions = [], array $columns = []): array
    {
        [$filter, $postFilters] = $this->buildConditions($conditions);

        $raw = match ($entity) {
            DataProviderInterface::ENTITY_OUTCOMING_INVOICES => self::fetchInvoices($filter, 'issuedInvoice'),
            DataProviderInterface::ENTITY_INCOMING_INVOICES  => self::fetchInvoices($filter, 'receivedInvoice'),
            DataProviderInterface::ENTITY_BANK_STATEMENTS    => self::fetchBankStatements($filter),
            default => [],
        };

        foreach ($postFilters as $fn) {
            $raw = array_values(array_filter($raw, $fn));
        }

        $limit = isset($conditions[DataProviderInterface::FILTER_LIMIT])
            ? (int) $conditions[DataProviderInterface::FILTER_LIMIT]
            : 0;

        return $limit > 0 ? \array_slice($raw, 0, $limit) : $raw;
    }

    /**
     * {@inheritDoc}
     */
    public function testConnection(): bool
    {
        try {
            return (new Invoice())->isOnline();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getSystemName(): string
    {
        return 'pohoda';
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedEntities(): array
    {
        return [
            DataProviderInterface::ENTITY_OUTCOMING_INVOICES,
            DataProviderInterface::ENTITY_INCOMING_INVOICES,
            DataProviderInterface::ENTITY_BANK_STATEMENTS,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function supportsFeature(string $feature): bool
    {
        return \in_array($feature, [
            'date_filtering', 'payment_status', 'document_types',
            'multi_currency', 'overdue_tracking',
        ], true);
    }

    /**
     * {@inheritDoc}
     */
    public function getCompanyInfo(): array
    {
        return [
            'name'       => $this->connectionInfo['ico'] ?? 'Pohoda',
            'ico'        => $this->connectionInfo['ico'] ?? '',
            'system'     => 'Pohoda',
            'server_url' => $this->connectionInfo['url'] ?? '',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function formatDate(\DateTime $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * {@inheritDoc}
     */
    public function formatDatePeriod(string $column, \DatePeriod $period): string
    {
        return 'dateFrom=' . $this->formatDate($period->getStartDate())
             . '&dateTill=' . $this->formatDate($period->getEndDate());
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildConditions(array $conditions): array
    {
        $filter      = [];
        $postFilters = [];

        foreach ($conditions as $key => $value) {
            switch ($key) {
                case DataProviderInterface::FILTER_DATE_PERIOD:
                    if (\is_array($value) && ($value['period'] ?? null) instanceof \DatePeriod) {
                        $filter['dateFrom']  = $this->formatDate($value['period']->getStartDate());
                        $filter['dateTill']  = $this->formatDate($value['period']->getEndDate());
                    }

                    break;

                case DataProviderInterface::FILTER_CANCELLED:
                    $want = (bool) $value;
                    $postFilters[] = static fn (array $r): bool =>
                        ($r[DataProviderInterface::FIELD_CANCELLED] ?? false) === $want;

                    break;

                case DataProviderInterface::FILTER_OVERDUE:
                    if ($value) {
                        $today = date('Y-m-d');
                        $postFilters[] = static function (array $r) use ($today): bool {
                            $due = $r[DataProviderInterface::FIELD_DUE_DATE] ?? '';

                            return $due !== '' && $due < $today;
                        };
                    }

                    break;

                case DataProviderInterface::FILTER_PAYMENT_STATUS:
                    $want = (string) $value;
                    $postFilters[] = static function (array $r) use ($want): bool {
                        $status = $r[DataProviderInterface::FIELD_PAYMENT_STATUS] ?? '';

                        return match ($want) {
                            DataProviderInterface::PAYMENT_STATUS_UNPAID_OR_PARTIAL =>
                                $status === DataProviderInterface::PAYMENT_STATUS_UNPAID
                                || $status === DataProviderInterface::PAYMENT_STATUS_PARTIAL,
                            default => $status === $want,
                        };
                    };

                    break;

                case DataProviderInterface::FILTER_PAYMENT_DIRECTION:
                    $want = (string) $value;
                    $postFilters[] = static fn (array $r): bool =>
                        ($r[DataProviderInterface::FIELD_DIRECTION] ?? '') === $want;

                    break;

                case DataProviderInterface::FILTER_MATCHED:
                    $want = (bool) $value;
                    $postFilters[] = static fn (array $r): bool =>
                        ($r[DataProviderInterface::FIELD_MATCHED] ?? false) === $want;

                    break;

                case DataProviderInterface::FILTER_ACCOUNTED:
                    $want = (bool) $value;
                    $postFilters[] = static fn (array $r): bool =>
                        ($r[DataProviderInterface::FIELD_ACCOUNTED] ?? false) === $want;

                    break;

                case DataProviderInterface::FILTER_EXCLUDE_DOCUMENT_TYPE:
                    $exclude = (string) $value;
                    $postFilters[] = static fn (array $r): bool =>
                        ($r[DataProviderInterface::FIELD_DOCUMENT_TYPE] ?? '') !== $exclude;

                    break;

                case DataProviderInterface::FILTER_LIMIT:
                    break;
            }
        }

        return [$filter, $postFilters];
    }

    private static function fetchInvoices(array $filter, string $invoiceType): array
    {
        try {
            $raw = (new Invoice())->loadFromPohoda($filter);

            return \is_array($raw)
                ? array_map(static fn (array $inv) => self::normalizeInvoice($inv, $invoiceType), $raw)
                : [];
        } catch (\Exception $e) {
            error_log('Pohoda invoice fetch error: ' . $e->getMessage());

            return [];
        }
    }

    private static function fetchBankStatements(array $filter): array
    {
        try {
            $raw = (new Bank())->loadFromPohoda($filter);

            return \is_array($raw)
                ? array_map(self::normalizeBank(...), $raw)
                : [];
        } catch (\Exception $e) {
            error_log('Pohoda bank fetch error: ' . $e->getMessage());

            return [];
        }
    }

    private static function normalizeInvoice(array $inv, string $invoiceType): array
    {
        $home    = $inv['homeCurrency']    ?? [];
        $foreign = $inv['foreignCurrency'] ?? [];
        $address = $inv['partnerIdentity']['address'] ?? $inv['address'] ?? [];
        $total   = self::amount($home);
        $paid    = (float) ($inv['paidAmount'] ?? $inv['paid'] ?? 0);

        return [
            DataProviderInterface::FIELD_CODE                    => (string) ($inv['number'] ?? ''),
            DataProviderInterface::FIELD_COMPANY                 => (string) ($address['name'] ?? ''),
            DataProviderInterface::FIELD_CURRENCY                => self::currency($home ?: $foreign),
            DataProviderInterface::FIELD_DUE_DATE                => (string) ($inv['dateDue'] ?? $inv['dueDate'] ?? ''),
            DataProviderInterface::FIELD_TOTAL_AMOUNT            => $total,
            DataProviderInterface::FIELD_TOTAL_AMOUNT_FOREIGN    => self::amount($foreign),
            DataProviderInterface::FIELD_DEPOSIT_AMOUNT          => (float) ($inv['depositAmount']        ?? 0),
            DataProviderInterface::FIELD_DEPOSIT_AMOUNT_FOREIGN  => (float) ($inv['depositAmountForeign'] ?? 0),
            DataProviderInterface::FIELD_REMAINING_AMOUNT        => max(0.0, $total - $paid),
            DataProviderInterface::FIELD_REMAINING_AMOUNT_FOREIGN => 0.0,
            DataProviderInterface::FIELD_PAYMENT_STATUS          => self::paymentStatus($total, $paid),
            DataProviderInterface::FIELD_DOCUMENT_TYPE           => self::documentType(
                (string) ($inv['invoiceType'] ?? $inv['documentType'] ?? $invoiceType),
            ),
            DataProviderInterface::FIELD_CANCELLED               =>
                strtolower((string) ($inv['state'] ?? '')) === 'cancelled',
        ];
    }

    private static function normalizeBank(array $tx): array
    {
        $home   = $tx['homeCurrency'] ?? [];
        $amount = self::amount($home);

        return [
            DataProviderInterface::FIELD_CODE                 => (string) ($tx['number'] ?? ''),
            DataProviderInterface::FIELD_DATE                 => (string) ($tx['date'] ?? $tx['dateStatement'] ?? ''),
            DataProviderInterface::FIELD_COMPANY              => (string) (($tx['partnerIdentity']['address'] ?? $tx['address'] ?? [])['name'] ?? ''),
            DataProviderInterface::FIELD_DESCRIPTION          => (string) ($tx['text'] ?? $tx['note'] ?? ''),
            DataProviderInterface::FIELD_BANK_ACCOUNT         => (string) ($tx['account'] ?? ''),
            DataProviderInterface::FIELD_CURRENCY             => self::currency($home),
            DataProviderInterface::FIELD_TOTAL_AMOUNT         => abs($amount),
            DataProviderInterface::FIELD_TOTAL_AMOUNT_FOREIGN => abs(self::amount($tx['foreignCurrency'] ?? [])),
            DataProviderInterface::FIELD_DIRECTION            => $amount >= 0
                ? DataProviderInterface::DIRECTION_INCOMING
                : DataProviderInterface::DIRECTION_OUTGOING,
            DataProviderInterface::FIELD_CANCELLED            => false,
            DataProviderInterface::FIELD_ACCOUNTED            => (bool) ($tx['accountingDocument'] ?? false),
            DataProviderInterface::FIELD_MATCHED              => (bool) ($tx['paired'] ?? false),
        ];
    }

    private static function amount(array $data): float
    {
        return (float) ($data['priceNone'] ?? $data['price'] ?? $data['amount'] ?? 0);
    }

    private static function currency(array $data): string
    {
        $code = $data['currency']['ids'] ?? $data['currency'] ?? '';

        return (\is_string($code) && $code !== '') ? $code : 'CZK';
    }

    private static function paymentStatus(float $total, float $paid): string
    {
        $remaining = max(0.0, $total - $paid);

        if ($remaining <= 0.0) {
            return DataProviderInterface::PAYMENT_STATUS_PAID;
        }

        return $paid > 0.0
            ? DataProviderInterface::PAYMENT_STATUS_PARTIAL
            : DataProviderInterface::PAYMENT_STATUS_UNPAID;
    }

    private static function documentType(string $raw): string
    {
        return match (strtolower($raw)) {
            'advanceinvoice', 'proforma', 'deposit'      => DataProviderInterface::DOCUMENT_TYPE_PROFORMA,
            'creditnote', 'credit', 'dobropis'           => DataProviderInterface::DOCUMENT_TYPE_CREDIT_NOTE,
            'issuedinvoice', 'receivedinvoice', 'invoice' => DataProviderInterface::DOCUMENT_TYPE_INVOICE,
            default => $raw,
        };
    }
}
