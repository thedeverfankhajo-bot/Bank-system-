# BankSystem API

BankSystem exposes its service through the main plugin class. API calls use integer minor units rather than floats: with two decimal places, 1250 means 12.50.

## Obtaining the service

    $plugin = $this->getServer()->getPluginManager()->getPlugin("BankSystem");
    if(!$plugin instanceof \BankSystem\Main){
        return;
    }
    $bank = $plugin->getBankService();
    $account = $bank->getAccount($player->getUniqueId()->toString(), $player->getName());

## Public operations

- getAccount(string $uuid, string $playerName): BankAccount
- findAccountByUuid(string $uuid): ?BankAccount — reads an existing account by stable UUID without creating it
- findAccountByName(string $playerName): ?BankAccount — resolves an existing account by last-known name; throws if ambiguous
- transferToName(string $fromUuid, string $fromName, string $toName, int $amountMinor): void — sends to a known account even when its owner is offline
- deposit(string $uuid, string $playerName, int $amountMinor): void
- withdraw(string $uuid, string $playerName, int $amountMinor): void
- transfer(string $fromUuid, string $fromName, string $toUuid, string $toName, int $amountMinor): void
- give(string $targetUuid, string $targetName, string $bucket, int $amountMinor, string $actorUuid, string $actorName): void
- take(string $targetUuid, string $targetName, string $bucket, int $amountMinor, string $actorUuid, string $actorName): void
- setBalance(string $targetUuid, string $targetName, string $bucket, int $amountMinor, string $actorUuid, string $actorName): void
- getTopAccounts(int $limit = 10): list of BankAccount
- getHistory(string $uuid, int $limit = 10, int $offset = 0): list of transaction records

The bucket value must be wallet or bank. API callers should catch BankException for expected rule failures, such as insufficient funds, invalid balances, self-payments, and changes above the configured transaction cap. The cap also applies to the absolute difference created by `setBalance`: it limits the change from the current balance, not the final balance value. This limit is checked inside the SQLite write transaction against the current value to prevent a stale pre-check from bypassing the cap. Database/runtime failures should be handled separately and must not be treated as successful payments.

## Offline account examples

An offline recipient must already have a BankSystem account saved from a previous join. Resolve by the last-known name or use `transferToName()`; this never fabricates an identity from a name. Unknown recipients and names that match multiple historical accounts are rejected with `BankException`.

```php
try{
    $bank->transferToName(
        $player->getUniqueId()->toString(),
        $player->getName(),
        'KnownOfflinePlayer',
        1250 // 12.50 in minor units
    );
}catch(\BankSystem\BankException $exception){
    // Handle insufficient wallet funds, limits, missing or ambiguous account.
    $player->sendMessage('Transfer failed: ' . $exception->getMessage());
}
```

For an administrative operation against a known offline account, use `findAccountByUuid($uuid)` when the UUID is known and pass the returned account's stored UUID and last-known name to `give()`, `take()`, or `setBalance()`. This UUID lookup does not create an account. If you only know the last-known name, use `findAccountByName()` and handle missing or ambiguous results. Do not create a UUID from a username.

## Transaction event

A successful database mutation dispatches BankTransactionEvent after the SQLite transaction has committed. The event exposes transaction ID, actor, source and destination UUIDs, action, amount, bucket names, balance-before/after values, and creation time. It is informational and cannot cancel an already committed transaction.

## Integration guidance

- Use the player's UUID as the account key; player names may change.
- Offline lookups use the last name stored for an existing account. No UUID is fabricated from a name, and offline accounts are not auto-created. A player must have joined while BankSystem was enabled (or already have a BankSystem record) before offline transfers or admin adjustments can address that account by name.
- For an online name, BankSystem resolves the currently connected player's UUID first. For an offline name, ambiguous case-insensitive matches are rejected; use an explicit UUID through the API in that case.
- The in-game UI uses PocketMine-MP's native form interface. FormAPI is not a required dependency; the panel works without it and remains usable when FormAPI is installed because BankSystem doesn't replace FormAPI classes or transport.
- Keep API calls on PocketMine's main thread unless the plugin has independently designed a safe asynchronous boundary.
- Do not directly edit the SQLite database while the server is running.
- Do not assume a transaction failed just because an unrelated listener logs an error; BankSystem catches listener exceptions after commit and records them in the server log.
