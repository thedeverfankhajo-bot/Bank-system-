# Security Policy

## Supported version

Security fixes target the latest release on the default branch.

## Reporting a vulnerability

Please use GitHub's private vulnerability reporting feature for this repository when it is available. Otherwise, open a minimal public issue asking the maintainers for a private reporting channel; do not include exploit details or private server data in a public issue.

Include the affected version, impact, and reproducible steps where safe. Do not upload database files containing real player data.

## Automated security checks

The CodeQL workflow analyzes GitHub Actions workflow files on pushes and pull requests targeting `main`, on a weekly schedule, and when manually dispatched. [GitHub's supported-language list](https://docs.github.com/en/code-security/concepts/code-scanning/codeql/codeql-code-scanning) does not include PHP, so this workflow does not analyze the plugin's PHP source. PHP is covered by the CI matrix on PHP 8.1–8.4, unit and SQLite transaction tests, PHP syntax lint, Composer dependency audit, and the PocketMine-MP runtime smoke test. Semgrep's PHP rules now run separately over `src/` and `tests/` and publish SARIF results to GitHub Code Scanning. These checks are useful but do not guarantee that every vulnerability is detected. CodeQL findings and SARIF artifacts apply to the supported GitHub Actions workflow files only.

## Local data protection

On POSIX systems, BankSystem uses a restrictive creation umask, rejects symbolic-link storage paths, and restricts SQLite database/WAL/SHM files to owner-only permissions (0600). These controls reduce access by other local users but are not encryption.

By default, the SQLite database is **not encrypted at rest**. Optional SQLCipher support is available for operators who provide a PHP `sqlite3` extension built with SQLCipher support. Set `storage.encryption-at-rest: true` and provide `BANKSYSTEM_SQLCIPHER_KEY` in the PocketMine server process environment. The key must be at least 32 bytes; keep it outside plugin configuration and source control. BankSystem verifies SQLCipher support and the ability to read the database at startup, and refuses to continue rather than silently falling back to plaintext.

Do not enable SQLCipher against an existing plaintext `bank.sqlite3` file. Back up the stopped server's data, migrate the database offline with SQLCipher tooling, validate the encrypted copy, and retain the original backup securely before switching the plugin to the encrypted file. A wrong or lost key makes the encrypted database inaccessible. Protect backups as well as the live database.

On Windows, POSIX mode bits and `umask` do not provide access control. Use `tools/harden-windows-acl.ps1` on `plugin_data/BankSystem` and every backup directory, supplying the exact PocketMine service account. The script resets ACLs recursively and allows only that account, SYSTEM, and local Administrators; review target paths with `-WhatIf` first, then verify with `icacls /verify /T` and test database/backup access as the service account. Do not run it against a drive root or a path whose data you do not intend to protect.

For all platforms, secure the host account and backups, stop the server before copying SQLite files, and never upload production databases to issues or logs.
