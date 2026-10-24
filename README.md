# BankSystem

A standalone banking plugin for Minecraft Bedrock servers running PocketMine-MP API 5.

## Documentation

- [فارسی — راهنمای فارسی](README.fa.md)
- [API integration guide](docs/API.md)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)

## Features

- Native in-game Bedrock form panel with a styled account dashboard, a one-tap balance refresh, a three-entry recent-activity preview, paginated transaction history, leaderboard, transfers, and permission-gated admin tools.
- Explicit confirmation forms for player transfers and privileged administrator balance changes.
- Separate wallet and bank balances, stored as integer minor units to avoid floating-point money errors.
- `/bank`, `/bank balance`, `/bank deposit`, `/bank withdraw`, `/bank pay`, `/bank top`, and `/bank history`.
- Operator-only balance administration for online and offline accounts, including eligible players known to PocketMine but not yet registered with BankSystem.
- SQLite persistence with prepared statements, atomic transactions, rollback on failure, and an audit log.
- Owner-only SQLite database and WAL/SHM permissions on POSIX systems, restrictive file creation, and symbolic-link rejection; Windows relies on filesystem ACLs.
- A public `BankService` API and `BankTransactionEvent` for other plugins.
- Configurable currency symbol and maximum transaction amount.
- GitHub Actions checks PHP 8.1–8.4, runs money and SQLite transaction tests, audits resolved Composer dependencies, and validates YAML.

## Requirements

- PocketMine-MP API 5.0.0 or newer in the 5.x API series.
- PHP 8.1+ with the `sqlite3` extension (provided by supported PocketMine-MP distributions).

## Installation

1. Build a self-contained plugin PHAR with `php -d phar.readonly=0 tools/build-phar.php`. The output is `build/BankSystem.phar`. Alternatively, use DevTools for a development build.
2. Put `build/BankSystem.phar` in the server's `plugins/` directory.
3. Start the server once to generate `plugin_data/BankSystem/config.yml` and `bank.sqlite3`.
4. Configure the currency and limits, then restart the server.

Run `/bank` or `/bank panel` in-game to open the native Bedrock form panel. It uses PocketMine-MP's built-in form transport, so FormAPI is not required. It also continues to work when FormAPI is installed, since BankSystem neither replaces nor patches its classes.

The SQLite database is created automatically in the plugin data folder. Back it up while the server is stopped.

## Commands

| Command | Description |
|---|---|
| `/bank` or `/bank panel` | Open the interactive in-game bank panel |
| `/bank balance` | Show your wallet, bank, and total balance |
| `/bank deposit <amount>` | Move money from wallet to bank |
| `/bank withdraw <amount>` | Move money from bank to wallet |
| `/bank pay <player> <amount>` | Send wallet money to an online or known offline account |
| `/bank top` | Show the top 10 bank balances |
| `/bank history [page]` | Show your latest transactions |
| `/bank admin give <player> <wallet\|bank> <amount>` | Add funds to an online or known offline account |
| `/bank admin take <player> <wallet\|bank> <amount>` | Remove funds from an online or known offline account |
| `/bank admin set <player> <wallet\|bank> <amount>` | Set an online or known offline account balance (zero is allowed) |

The `banksystem.admin` permission defaults to operators. Ordinary players receive `banksystem.use` by default. Amounts must be positive and may contain at most two decimal places; admin `set` may use `0`. The configured transaction cap also limits the absolute balance change made by admin `set`. Per-player transaction cooldown state is cleared on disconnect. For an offline recipient, BankSystem first uses an existing account; if none exists, it can create a zero/default-balance account from PocketMine's saved numeric `LastKnownXUID` and reconcile it with the live UUID next time they join. It never invents a UUID from a name. If saved XUID data is unavailable, or the stored account cannot be verified against that XUID, the operation is rejected safely.

## Configuration

See `resources/config.yml`. Money values in configuration are quoted decimal strings so that they are not rounded by YAML parsing. The default maximum transaction is `1000000.00`.

## API for other plugins

Obtain the plugin and service, then pass money as integer minor units (for example, `1250` means `12.50` when using two decimal places):

```php
use BankSystem\Main;

$plugin = $this->getServer()->getPluginManager()->getPlugin("BankSystem");
if($plugin instanceof Main){
    $service = $plugin->getBankService();
    $account = $service->getAccount($player->getUniqueId()->toString(), $player->getName());
    $service->deposit($player->getUniqueId()->toString(), $player->getName(), 1250);
}
```

The `getAccount($uuid, $name)` API creates an account if it does not exist; use `findAccountByUuid($uuid)` for a read-only lookup. Methods throw `BankException` for expected business-rule failures; callers should handle it. Database failures are not converted into successful transactions. Successful mutations dispatch `BankTransactionEvent` after the database commit. Event listeners should not assume an event can cancel a committed transaction.

## Development and checks

```sh
composer install
composer test
composer lint
php -d phar.readonly=0 tools/build-phar.php
```

The CI workflow runs unit tests and static checks on PHP 8.1–8.4. A separate runtime smoke test downloads the official PocketMine-MP 5.44.3 PHAR and the official production-recommended PHP 8.2 runtime, verifies their SHA-256 checksums, builds BankSystem.phar, and checks that the plugin initializes on a real PocketMine process. On success, it publishes `BankSystem.phar` and a `PocketMine-5.44.3-PHP82-Linux-x86_64` artifact containing the verified PocketMine server PHAR, PHP 8.2 runtime archive, and SHA-256 manifest. Both artifacts are retained for 14 days on the workflow run. This startup and offline-account smoke test is not a replacement for joining with a Bedrock client and exercising the UI and transactions on a staging server.

## Security and consistency

- Database values use bound parameters; user input is never interpolated into SQL.
- Wallet/bank changes and their audit record commit together inside one SQLite transaction.
- Player accounts are keyed by PocketMine UUID rather than a mutable display name.
- Administrative operations require the explicit `banksystem.admin` permission. They can target online players by live UUID and verified offline accounts by saved XUID. An offline account created from a saved XUID initially uses a temporary internal key; on the next join, that key and all related transaction-history UUID references are migrated atomically to the live UUID. Offline accounts are never created from a name alone, XUID/UUID conflicts are not auto-merged, and ambiguous historical name matches are rejected.
- No passwords, payment credentials, network calls, or external services are involved.

## License

MIT. See `LICENSE`.
