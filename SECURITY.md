# Security Policy

## Supported version
Security fixes target the latest release on the default branch.

## Reporting a vulnerability
Please use GitHub's private vulnerability reporting feature for this repository when it is available. Otherwise, open a minimal public issue asking the maintainers for a private reporting channel; do not include exploit details or private server data in a public issue.

Include the affected version, impact, and reproducible steps where safe. Do not upload database files containing real player data.

## Automated security checks
The CodeQL workflow analyzes GitHub Actions workflow files on pushes and pull requests targeting `main`, on a weekly schedule, and when manually dispatched. CodeQL does not support PHP, so the PHP plugin code continues to be checked by the CI unit/SQLite tests, PHP syntax lint, and Composer dependency audit. SARIF results from CodeQL are retained as a workflow artifact for 14 days.

This repository is public on GitHub.com, where code scanning is available for public repositories. The CodeQL workflow now uploads its GitHub Actions analysis results to Code Scanning and also retains the SARIF output as a workflow artifact for 14 days. The workflow currently analyzes GitHub Actions workflow files only; PHP code is covered by the CI test suite, syntax lint, and Composer dependency audit. See [GitHub's code scanning documentation](https://docs.github.com/en/code-security/concepts/code-scanning/code-scanning).

## Local data protection
On POSIX systems, BankSystem restricts the SQLite database and existing WAL/SHM files to owner-only permissions (0600). On Windows, protect the plugin data folder and backups using filesystem ACLs. The database is not encrypted at rest; access to the server account, storage, backups, and host remains security-sensitive.
