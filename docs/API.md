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
- findAccountByName(string $playerName): ?BankAccount — resolves an existing account or creates a verifiable offline account from PocketMine's saved XUID; throws if ambiguous or conflicting
- validateTransactionAmount(int $amountMinor): void — checks positivity and the configured per-transaction cap before resolving an offline recipient
- transferToName(string $fromUuid, string $fromName, string $toName, int $amountMinor): void — sends to a known account even when its owner is offline
- deposit(string $uuid, string $playerName, int $amountMinor): void
- withdraw(string $uuid, string $playerName, int $amountMinor): void
- transfer(string $fromUuid, string $fromName, string $toUuid, string $toName, int $amountMinor): void
- giveAs(CommandSender $actor, string $targetUuid, string $targetName, string $bucket, int $amountMinor): void
- takeAs(CommandSender $actor, string $targetUuid, string $targetName, string $bucket, int $amountMinor): void
- setBalanceAs(CommandSender $actor, string $targetUuid, string $targetName, string $bucket, int $amountMinor): void

The legacy `give()`, `take()`, and `setBalance()` signatures that accept caller-supplied actor UUID/name are retained for source discovery but now throw `BankException` without changing balances. Those strings cannot prove who authorized an administrator operation. Use the `*As()` methods and pass the real PocketMine `CommandSender`; the service re-checks `banksystem.admin` itself and records the actor identity from that sender. Never forward a player name or UUID received from an untrusted request as an actor identity.
- getTopAccounts(int $limit = 10): list of BankAccount
- getHistory(string $uuid, int $limit = 10, int $offset = 0): list of transaction records

The bucket value must be wallet or bank. Call `validateTransactionAmount($amountMinor)` before custom recipient lookups that may create a verified XUID-backed offline account; the money-changing methods enforce the cap themselves as well. API callers should catch BankException for expected rule failures, such as insufficient funds, invalid balances, self-payments, and changes above the configured transaction cap. The cap also applies to the absolute difference created by `setBalance`: it limits the change from the current balance, not the final balance value. This limit is checked inside the SQLite write transaction against the current value to prevent a stale pre-check from bypassing the cap. Database/runtime failures should be handled separately and must not be treated as successful payments.

## Offline account examples

An offline recipient can be resolved from an existing BankSystem account. If no account exists yet, BankSystem can create one only when PocketMine has saved a valid numeric `LastKnownXUID` for that player. The account initially uses an internal `offline-xuid:<xuid>` key; when that player next joins, the record and transaction-history UUID references are atomically promoted to the live UUID. A name alone is never used to invent a UUID or XUID. Missing XUID data, ambiguous names, mismatched XUIDs, or conflicts with a different existing account are rejected with `BankException` rather than guessing.

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

For an administrative operation against a known offline account, use `findAccountByUuid($uuid)` when the UUID is known, or safely resolve it with `findAccountByName()`, then pass the stored UUID and last-known name to `giveAs($sender, ...)`, `takeAs($sender, ...)`, or `setBalanceAs($sender, ...)`. These methods require the actual `CommandSender` to have `banksystem.admin`. The UUID lookup does not create an account. If you only know the last-known name, use `findAccountByName()` and handle missing or ambiguous results. Do not create a UUID from a username.

## Transaction event

A successful database mutation dispatches BankTransactionEvent after the SQLite transaction has committed. The event exposes transaction ID, actor, source and destination UUIDs, action, amount, bucket names, balance-before/after values, and creation time. It is informational and cannot cancel an already committed transaction.

## Integration guidance

- Use the player's UUID as the account key; player names may change.
- Offline lookup starts with an existing saved bank account. If it does not exist, the service consults PocketMine's own saved player data and may create the account only from a valid numeric `LastKnownXUID`; it never fabricates identity from a name. On the next live join, the temporary account key and historical transaction UUID references are migrated atomically to the live player UUID. If PocketMine has no saved XUID, or an existing row cannot be safely matched to it, the service rejects name-based offline operations. A player can always be registered through a real join.
- For an online name, BankSystem resolves the currently connected player's UUID first. For an offline name, ambiguous case-insensitive matches are rejected; use an explicit UUID through the API in that case.
- The in-game UI uses PocketMine-MP's native form interface. FormAPI is not a required dependency; the panel works without it and remains usable when FormAPI is installed because BankSystem doesn't replace FormAPI classes or transport.
- Keep API calls on PocketMine's main thread unless the plugin has independently designed a safe asynchronous boundary.
- Do not directly edit the SQLite database while the server is running.
- Do not assume a transaction failed just because an unrelated listener logs an error; BankSystem catches listener exceptions after commit and records them in the server log.
