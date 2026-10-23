# Security Policy

## Supported version

Security fixes target the latest release on the default branch.

## Reporting a vulnerability

Please use GitHub's private vulnerability reporting feature for this repository when it is available. Otherwise, open a minimal public issue asking the maintainers for a private reporting channel; do not include exploit details or private server data in a public issue.

Include the affected version, impact, and reproducible steps where safe. Do not upload database files containing real player data.

## Local data protection

On POSIX systems, BankSystem restricts the SQLite database and existing WAL/SHM files to owner-only permissions (0600). On Windows, protect the plugin data folder and backups using filesystem ACLs. The database is not encrypted at rest; access to the server account, storage, backups, and host remains security-sensitive.
