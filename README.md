# Pohoda Zabbix

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Monitor your **Pohoda** accounting system using **Zabbix** — business metrics collected via digest modules through the Pohoda mServer API.

## Monitored Metrics

| Metric Key | Description |
|---|---|
| `pohoda.digest.debtors.count` | Number of debtors |
| `pohoda.digest.debtors.total_amount` | Total debtor amount |
| `pohoda.digest.debtors.max_overdue_days` | Maximum overdue days |
| `pohoda.digest.waiting_income.count` | Unpaid outgoing invoices count |
| `pohoda.digest.waiting_income.total_amount` | Unpaid outgoing invoices total |
| `pohoda.digest.waiting_payments.count` | Unpaid incoming invoices count |
| `pohoda.digest.waiting_payments.total_amount` | Unpaid incoming invoices total |
| `pohoda.digest.unmatched_payments.count` | Unmatched bank payments count |
| `pohoda.digest.unmatched_payments.total_amount` | Unmatched bank payments total |
| `pohoda.digest.incoming_invoices.count` | Incoming invoices in period |
| `pohoda.digest.outcoming_invoices.count` | Outgoing invoices in period |
| `pohoda.digest.incoming_payments.count` | Incoming payments in period |
| `pohoda.digest.outcoming_payments.count` | Outgoing payments in period |
| `pohoda.server.connectivity` | mServer connectivity (1/0) |
| `pohoda.digest.last_run` | Last collection timestamp |

Results are cached for 5 minutes to reduce mServer load.

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

```bash
# All metrics as JSON
pohoda-zabbix-digest

# Single metric
pohoda-zabbix-digest debtors.count
pohoda-zabbix-digest --metric=debtors.count

# Debug mode (pretty-printed JSON)
pohoda-zabbix-digest --debug

# Custom env file
pohoda-zabbix-digest --env=/path/to/.env

# Check mServer connectivity (returns 1 or 0)
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
  pohoda-zabbix-digest    # Main metrics collector CLI
  pohoda-zabbix-status    # Connectivity check CLI
src/PohodaZabbix/
  DigestFeeder.php        # Core metrics collector with caching
zabbix/
  pohoda-digest.conf      # Zabbix agent UserParameter config
debian/                   # Debian packaging
```

## License

MIT — see [LICENSE](LICENSE) for details.
