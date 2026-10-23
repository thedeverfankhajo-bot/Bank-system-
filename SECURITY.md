# Security Policy

## Supported version
Security fixes target the latest release on the default branch.

## Reporting a vulnerability
Please use GitHub's private vulnerability reporting feature for this repository when it is available. Otherwise, open a minimal public issue asking the maintainers for a private reporting channel; do not include exploit details or private server data in a public issue.

Include the affected version, impact, and reproducible steps where safe. Do not upload database files containing real player data.

## Automated CodeQL analysis
The CodeQL workflow analyzes PHP and GitHub Actions workflows on pushes and pull requests targeting `main`, on a weekly schedule, and when manually dispatched. SARIF results are retained as a workflow artifact for 14 days.

This repository is currently private. GitHub requires GitHub Code Security to be enabled for code-scanning alerts in private repositories. Until that entitlement is enabled, the workflow does not upload SARIF to GitHub Code Scanning. After enabling Code Security, set the repository Actions variable `CODEQL_UPLOAD_RESULTS` to the string `true` to publish results to the Security tab. See [GitHub's private repository enablement guidance](https://docs.github.com/en/code-security/reference/code-scanning/troubleshoot-analysis-errors/private-repository-enablement).

## Local data protection
On POSIX systems, BankSystem restricts the SQLite database and existing WAL/SHM files to owner-only permissions (0600). On Windows, protect the plugin data folder and backups using filesystem ACLs. The database is not encrypted at rest; access to the server account, storage, backups, and host remains security-sensitive.
