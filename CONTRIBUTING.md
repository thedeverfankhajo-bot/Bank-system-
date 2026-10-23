# Contributing

1. Open an issue to report a bug or discuss a significant feature.
2. Keep the plugin compatible with the declared PocketMine-MP API 5 minimum.
3. Never interpolate player input into SQL. Use the repository prepared-statement helpers.
4. Keep every balance mutation and its transaction-history row in one database transaction.
5. Add or update tests for money parsing, balance rules, and persistence changes.
6. Run composer install, composer test, and composer lint before submitting a pull request.
7. Pin GitHub Actions to full upstream commit SHAs where practical, include the verified release version in a comment, and let Dependabot propose updates. Follow action-specific upstream guidance when it differs.
8. Do not commit server data, database files, credentials, generated PHARs, or the vendor directory.

Pull requests should describe the user-visible change, migration impact, tests run, and compatibility considerations.
