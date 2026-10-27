# BankSystem API

BankSystem exposes its service through the main plugin class. API calls use integer minor units rather than floats: with two decimal places, 1250 means 12.50.

## Obtaining the service

    $plugin = $this->getServer()->getPluginManager()->getPlugin("BankSystem");
    if(!$plugin instanceof \BankSystem\Main){
        return;
    }
    $bank = $plugin->getBankService();
    $account = $bank->getAccountForPlayer($player);

## Public operations

`getAccount()` no longer creates accounts or mutates the stored display name from a caller-supplied string; it only returns an existing UUID-keyed account. Use `getAccountForPlayer($player)` or `registerOnlinePlayer($player)` for live account registration. Offline account creation remains limited to `findAccountByName()` when PocketMine's saved XUID verifies the identity.

- getAccountForPlayer(Player $player): BankAccount — returns an authenticated live account, registering it safely if necessary
- getAccount(string $uuid, string $playerName): BankAccount — read-only lookup by UUID; the legacy name argument is not trusted for account creation or renaming
- findAccountByUuid(string $uuid): ?BankAccount — read-only lookup by stable UUID
- findAccountByName(string $playerName): ?BankAccount — resolves an existing account or creates a verifiable offline account from PocketMine's saved XUID; throws if ambiguous or conflicting
- validateTransactionAmount(int $amountMinor): void — checks positivity and the configured per-transaction cap before resolving an offline recipient
- registerOnlinePlayer(Player $player): BankAccount — registers an authenticated live player and reconciles a verified offline identity
- transferToNameAs(Player $actor, string $toName, int $amountMinor): void — sends to a known online/offline account using the actor's real identity
- depositAs(Player $actor, int $amountMinor): void
- withdrawAs(Player $actor, int $amountMinor): void
- transferAs(Player $actor, string $toUuid, int $amountMinor): void
- giveAs(CommandSender $actor, string $targetUuid, string $targetName, string $bucket, int $amountMinor): void
- takeAs(CommandSender $actor, string $targetUuid, string $targetName, string $bucket, int $amountMinor): void
- setBalanceAs(CommandSender $actor, string $targetUuid, string $targetName, string $bucket, int $amountMinor): void

The legacy `give()`, `take()`, and `setBalance()` signatures that accept caller-supplied actor UUID/name are retained but throw `BankException` without changing balances. The same fail-closed rule applies to the legacy `deposit()`, `withdraw()`, `transfer()`, `transferToName()`, and `registerOnlineAccount()` methods because caller-supplied UUID/name/XUID strings cannot prove a live player's identity or authorization. For player transactions, use `depositAs($player, $amountMinor)`, `withdrawAs($player, $amountMinor)`, `transferAs($player, $toUuid, $amountMinor)`, and `transferToNameAs($player, $toName, $amountMinor)` with the actual connected PocketMine `Player`. Each method checks `banksystem.use`, verifies the same player object is still online, and derives the source UUID/name from that object. For join registration, use `registerOnlinePlayer($player)`. For administration, pass the real `CommandSender` to `giveAs()`, `takeAs()`, or `setBalanceAs()`; the service re-checks `banksystem.admin`, verifies player/console sender provenance, and derives audit identity from the sender. Never forward untrusted identity strings as proof of actor identity.
- getTopAccounts(int $limit = 10): list of BankAccount
- getHistory(string $uuid, int $limit = 10, int $offset = 0): list of transaction records

The bucket value must be wallet or bank. Call `validateTransactionAmount($amountMinor)` before custom recipient lookups that may create a verified XUID-backed offline account; the money-changing methods enforce the cap themselves as well. API callers should catch BankException for expected rule failures, such as insufficient funds, invalid balances, self-payments, and changes above the configured transaction cap. The cap also applies to the absolute difference created by `setBalance`: it limits the change from the current balance, not the final balance value. This limit is checked inside the SQLite write transaction against the current value to prevent a stale pre-check from bypassing the cap. Database/runtime failures should be handled separately and must not be treated as successful payments.

## Offline account examples

An offline recipient can be resolved from an existing BankSystem account. If no account exists yet, BankSystem can create one only when PocketMine has saved a valid numeric `LastKnownXUID` for that player. The account initially uses an internal `offline-xuid:<xuid>` key; when that player next joins, the record and transaction-history UUID references are atomically promoted to the live UUID. A name alone is never used to invent a UUID or XUID. Missing XUID data, ambiguous names, mismatched XUIDs, a live session with an empty XUID attempting to reuse an XUID-linked account, or conflicts with a different existing account are rejected with `BankException` rather than guessing.

```php
try{
    $bank->transferToNameAs(
        $player,
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
- Offline lookup starts with an existing saved bank account. If it does not exist, the service consults PocketMine's own saved player data and may create the account only from a valid numeric `LastKnownXUID`; it never fabricates identity from a name. On the next live join, the temporary account key and historical transaction UUID references are migrated atomically to the live player UUID. If PocketMine has no saved XUID, or an existing row cannot be safely matched to it, the service rejects name-based offline operations. A player is registered through the live `Player` object on a real join.
- For an online name, BankSystem resolves the currently connected player's UUID first. For an offline name, ambiguous case-insensitive matches are rejected; use an explicit UUID through the API in that case.
- The in-game UI uses PocketMine-MP's native form interface. FormAPI is not a required dependency; the panel works without it and remains usable when FormAPI is installed because BankSystem doesn't replace FormAPI classes or transport.
- Keep API calls on PocketMine's main thread unless the plugin has independently designed a safe asynchronous boundary.
- Do not directly edit the SQLite database while the server is running.
- Do not assume a transaction failed just because an unrelated listener logs an error; BankSystem catches listener exceptions after commit and records them in the server log.
