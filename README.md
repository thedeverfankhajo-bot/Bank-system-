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
- Optional SQLCipher encryption at rest for deployments using a SQLCipher-enabled PHP SQLite3 extension; encryption is opt-in and fails closed when requested but unavailable.
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

### Optional encryption at rest

The default database is **not encrypted**. Operators who need database-file encryption can opt in with a PHP `sqlite3` extension built against SQLCipher. Set `storage.encryption-at-rest: true` in `plugin_data/BankSystem/config.yml`, then provide `BANKSYSTEM_SQLCIPHER_KEY` to the PocketMine server process through the host's secret/environment configuration. Use a randomly generated key/passphrase of at least 32 bytes; do not put it in `config.yml`, source control, shell history, or issue reports. Startup fails rather than using plaintext if SQLCipher support or the key is missing.

Standard PocketMine PHP packages are not guaranteed to include SQLCipher. Check the exact runtime before enabling the setting. Do not turn it on against an existing plaintext `bank.sqlite3`: migrate a stopped-server backup offline with SQLCipher tooling and verify the encrypted copy first. Key loss prevents recovery, so secure the key and backups separately. Encryption is optional; POSIX file permissions and Windows NTFS ACLs still matter.

### Windows NTFS ACL hardening

On Windows, POSIX mode bits and `umask` do not enforce access control. The `tools/harden-windows-acl.ps1` script resets ACLs recursively for the plugin data directory and any backup directories, then grants access only to the actual PocketMine service account, SYSTEM, and local Administrators. Run PowerShell elevated and provide the exact service identity:

```powershell
# Review the target paths first
.\tools\harden-windows-acl.ps1 -DataDirectory 'C:\PocketMine\plugin_data\BankSystem' -ServerAccount 'NT AUTHORITY\LOCAL SERVICE' -BackupDirectory 'D:\PocketMineBackups\BankSystem' -WhatIf

# Apply and verify the ACLs
.\tools\harden-windows-acl.ps1 -DataDirectory 'C:\PocketMine\plugin_data\BankSystem' -ServerAccount 'NT AUTHORITY\LOCAL SERVICE' -BackupDirectory 'D:\PocketMineBackups\BankSystem'
icacls 'C:\PocketMine\plugin_data\BankSystem' /verify /T
```

All target directories must already exist. Replace `ServerAccount` with the identity that actually runs the server; a wrong identity can prevent the server from writing its database or backups. The script resets ACLs under each target, so choose paths carefully and include every backup location. Afterwards, verify real read/write and backup access under the service account.

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
    $service->depositAs($player, 1250);
}
```

The `getAccount($uuid, $name)` API retains its legacy create-on-first-access behaviour; only call it with a trusted UUID/name pair. Use `findAccountByUuid($uuid)` for a read-only lookup. Player mutations must use actor-bound methods (`depositAs`, `withdrawAs`, `transferAs`, `transferToNameAs`) with the actual connected `Player`; they enforce `banksystem.use` and do not trust caller-supplied source UUID/name strings. The legacy `deposit`, `withdraw`, `transfer`, `transferToName`, and `registerOnlineAccount` signatures are retained but fail closed with `BankException`. Expected business-rule failures should be handled; database failures are not converted into successful transactions. Successful mutations dispatch `BankTransactionEvent` after commit, and event listeners cannot cancel a committed transaction.

Administrative API operations must be authorized with the real PocketMine `CommandSender`: use `giveAs($sender, $targetUuid, $targetName, $bucket, $amountMinor)`, `takeAs(...)`, and `setBalanceAs(...)`. Each call independently checks `banksystem.admin` and derives audit actor identity from the sender. The older `give()`, `take()`, and `setBalance()` signatures that accept caller-supplied actor UUID/name are retained but now throw `BankException` without changing money; those strings cannot securely prove authorization. See [docs/API.md](docs/API.md) for the full contract.

## PHP security analysis

The separate `.github/workflows/php-security.yml` workflow runs Semgrep's PHP rules across `src/` and `tests/` and uploads SARIF results to GitHub Code Scanning. It complements unit tests, syntax lint, and `composer audit`; it is not a guarantee that all vulnerabilities will be found. The existing CodeQL workflow analyzes GitHub Actions workflow files, not PHP source.

## Development and checks

```sh
composer install
composer test
composer lint
php -d phar.readonly=0 tools/build-phar.php
```

The CI workflow runs unit tests and static checks on PHP 8.1–8.4. A separate runtime smoke test downloads the official PocketMine-MP 5.44.3 PHAR and the official production-recommended PHP 8.2 runtime, verifies their SHA-256 checksums, builds BankSystem.phar, and checks that the plugin initializes on a real PocketMine process. On success, the `BankSystem-API5-PHAR` artifact contains `BankSystem.phar` and `BankSystem.phar.sha256`. After extracting the artifact, verify the plugin binary with `sha256sum --check BankSystem.phar.sha256`. The workflow also publishes a `PocketMine-5.44.3-PHP82-Linux-x86_64` artifact containing the verified PocketMine server PHAR, PHP 8.2 runtime archive, and SHA-256 manifest. Both artifacts are retained for 14 days on the workflow run. This startup and offline-account smoke test is not a replacement for joining with a Bedrock client and exercising the UI and transactions on a staging server.

## Security and consistency

- Database values use bound parameters; user input is never interpolated into SQL.
- Wallet/bank changes and their audit record commit together inside one SQLite transaction.
- Player accounts are keyed by PocketMine UUID rather than a mutable display name.
- Administrative operations require the explicit `banksystem.admin` permission. They can target online players by live UUID and verified offline accounts by saved XUID. An offline account created from a saved XUID initially uses a temporary internal key; on the next join, that key and all related transaction-history UUID references are migrated atomically to the live UUID. Offline accounts are never created from a name alone, XUID/UUID conflicts are not auto-merged, and ambiguous historical name matches are rejected.
- No passwords, payment credentials, network calls, or external services are involved.

## License

MIT. See `LICENSE`.
