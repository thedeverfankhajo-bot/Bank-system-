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
- Operator-only balance administration for online players.
- SQLite persistence with prepared statements, atomic transactions, rollback on failure, and an audit log.
- Owner-only SQLite database and existing WAL/SHM file permissions on POSIX systems; Windows relies on filesystem ACLs.
- A public `BankService` API and `BankTransactionEvent` for other plugins.
- Configurable currency symbol and maximum transaction amount.
- GitHub Actions checks PHP 8.1–8.4, runs money and SQLite transaction tests, audits resolved Composer dependencies, and validates YAML.

## Requirements

- PocketMine-MP API 5.0.0 or newer in the 5.x API series.
- PHP 8.1+ with the `sqlite3` extension (provided by supported PocketMine-MP distributions).

## Installation

1. Download the source or build a plugin PHAR with DevTools.
2. Place the plugin folder in `plugins/` for development (DevTools required), or put the generated `BankSystem.phar` in `plugins/`.
3. Start the server once to generate `plugin_data/BankSystem/config.yml` and `bank.sqlite3`.
4. Configure the currency and limits, then restart the server.

Run `/bank` or `/bank panel` in-game to open the native Bedrock form panel. It uses PocketMine-MP's built-in form transport and does not require a separate FormAPI plugin.

The SQLite database is created automatically in the plugin data folder. Back it up while the server is stopped.

## Commands

| Command | Description |
|---|---|
| `/bank` or `/bank panel` | Open the interactive in-game bank panel |
| `/bank balance` | Show your wallet, bank, and total balance |
| `/bank deposit <amount>` | Move money from wallet to bank |
| `/bank withdraw <amount>` | Move money from bank to wallet |
| `/bank pay <player> <amount>` | Send wallet money to an online player |
| `/bank top` | Show the top 10 bank balances |
| `/bank history [page]` | Show your latest transactions |
| `/bank admin give <player> <wallet\|bank> <amount>` | Add funds to an online player |
| `/bank admin take <player> <wallet\|bank> <amount>` | Remove funds from an online player |
| `/bank admin set <player> <wallet\|bank> <amount>` | Set an online player's balance (zero is allowed) |

The `banksystem.admin` permission defaults to operators. Ordinary players receive `banksystem.use` by default. Amounts must be positive and may contain at most two decimal places; admin `set` may use `0`. The configured transaction cap also limits the absolute balance change made by admin `set`. Per-player transaction cooldown state is cleared on disconnect.

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

The API creates accounts on demand. Methods throw `BankException` for expected business-rule failures; callers should handle it. Database failures are not converted into successful transactions. Successful mutations dispatch `BankTransactionEvent` after the database commit. Event listeners should not assume an event can cancel a committed transaction.

## Development and checks

```sh
composer install
vendor/bin/phpunit
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

The repository workflow runs these checks on pushes and pull requests. The repository does not bundle PocketMine server binaries; a live-server smoke test must be run on a test server before production use.

## Security and consistency

- Database values use bound parameters; user input is never interpolated into SQL.
- Wallet/bank changes and their audit record commit together inside one SQLite transaction.
- Player accounts are keyed by PocketMine UUID rather than a mutable display name.
- Administrative operations require an explicit permission and currently target online players only.
- No passwords, payment credentials, network calls, or external services are involved.

## License

MIT. See `LICENSE`.
