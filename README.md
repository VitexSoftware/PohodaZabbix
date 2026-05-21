# Pohoda Zabbix

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Monitor your **Pohoda** accounting system using **Zabbix** — business metrics collected via digest modules through the Pohoda mServer API.

## Monitored Metrics

| Metric Key | Description |
|---|---|
| `pohoda.digest.debtors.count` | Number of companies with overdue invoices |
| `pohoda.digest.debtors.invoices_count` | Total overdue invoice count |
| `pohoda.digest.debtors.overdue_0_30` | Overdue invoices 0–30 days |
| `pohoda.digest.debtors.overdue_31_60` | Overdue invoices 31–60 days |
| `pohoda.digest.debtors.overdue_61_90` | Overdue invoices 61–90 days |
| `pohoda.digest.debtors.overdue_90plus` | Overdue invoices 90+ days |
| `pohoda.digest.outcoming_invoices.count` | Outgoing invoices in period |
| `pohoda.digest.outcoming_invoices.active_count` | Active outgoing invoices |
| `pohoda.digest.outcoming_invoices.cancelled_count` | Cancelled outgoing invoices |
| `pohoda.digest.incoming_invoices.count` | Incoming invoices in period |
| `pohoda.digest.incoming_invoices.active_count` | Active incoming invoices |
| `pohoda.digest.incoming_invoices.cancelled_count` | Cancelled incoming invoices |
| `pohoda.digest.incoming_payments.count` | Incoming bank payments in period |
| `pohoda.digest.outcoming_payments.count` | Outgoing bank payments in period |
| `pohoda.digest.waiting_income.count` | Unpaid outgoing invoices count |
| `pohoda.digest.waiting_income.total_amount` | Unpaid outgoing invoices total |
| `pohoda.digest.waiting_payments.count` | Unpaid incoming invoices count |
| `pohoda.digest.waiting_payments.total_amount` | Unpaid incoming invoices total |
| `pohoda.digest.unmatched_payments.count` | Unmatched bank payments count |
| `pohoda.digest.unmatched_payments.total_amount` | Unmatched bank payments total |
| `pohoda.server.connectivity` | mServer connectivity (1/0) |
| `pohoda.digest.last_run` | Last collection timestamp |

Results are cached per period for 5 minutes to reduce mServer load.

## Requirements

- PHP >= 8.1
- Zabbix Agent 2 (recommended) or Zabbix Agent
- Pohoda mServer access

## Installation

### From Debian Package

```bash
sudo apt install pohoda-zabbix
```

The package installs the Zabbix agent config and CLI commands automatically.

### From Source

```bash
composer install
cp example.env .env
# Edit .env with your Pohoda mServer credentials
```

## Configuration

Copy `example.env` to `.env` and set:

```env
POHODA_URL=http://localhost:5336
POHODA_USERNAME=@
POHODA_PASSWORD=
POHODA_ICO=12345678
EASE_LOGGER=syslog
```

## Usage

### pohoda-zabbix-digest

```bash
# All metrics as JSON (last 30 days, default)
pohoda-zabbix-digest

# Specify period
pohoda-zabbix-digest --period=year          # current calendar year
pohoda-zabbix-digest --period=2024          # full year 2024
pohoda-zabbix-digest --period=month         # last 30 days (default)

# Single metric value
pohoda-zabbix-digest debtors.count
pohoda-zabbix-digest --metric=debtors.count

# Debug mode (pretty-printed JSON)
pohoda-zabbix-digest --debug

# Custom env file
pohoda-zabbix-digest --env=/path/to/.env
```

### pohoda-zabbix-status

```bash
# Check mServer connectivity (returns 1 or 0, exit code 0/1)
pohoda-zabbix-status
```

## Zabbix Agent Setup

Install the UserParameter config to your Zabbix agent:

```bash
sudo cp zabbix/pohoda-digest.conf /etc/zabbix/zabbix_agent2.d/
sudo systemctl restart zabbix-agent2
```

## Project Structure

```
bin/
  pohoda-zabbix-digest    # Metrics collector CLI (Zabbix UserParameter)
  pohoda-zabbix-status    # Connectivity check CLI (Zabbix UserParameter)
src/PohodaZabbix/
  DigestFeeder.php            # Core metrics collector with period-aware caching
  DataProvider/
    PohodaDataProvider.php    # Pohoda mServer data provider
zabbix/
  pohoda-digest.conf      # Zabbix agent UserParameter config
debian/                   # Debian packaging
```

## License

MIT — see [LICENSE](LICENSE) for details.
