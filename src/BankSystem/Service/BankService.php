<?php

declare(strict_types=1);

namespace BankSystem\Service;

use BankSystem\BankAccount;
use BankSystem\BankException;
use BankSystem\Database\BankRepository;
use BankSystem\Event\BankTransactionEvent;
use BankSystem\Main;
use BankSystem\Support\Money;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use Throwable;

final class BankService{
    public function __construct(
        private readonly Main $plugin,
        private readonly BankRepository $repository
    ){
    }

    public function getAccount(string $uuid, string $playerName) : BankAccount{
        if(trim($uuid) === '' || trim($playerName) === ''){
            throw new BankException('A valid player UUID and name are required.');
        }

        $this->repository->ensureAccount(
            $uuid,
            $playerName,
            $this->configuredAmount('balances.initial-wallet', '0.00'),
            $this->configuredAmount('balances.initial-bank', '0.00')
        );
        $account = $this->repository->getAccount($uuid);
        if($account === null){
            throw new BankException('Could not load the bank account.');
        }
        return $account;
    }

    /**
     * Registers a live player and links any previously created XUID-backed offline account.
     * This is called on join so the player returns to the exact same balance and history.
     */
    public function registerOnlineAccount(string $uuid, string $playerName, string $xuid) : BankAccount{
        if(trim($uuid) === '' || trim($playerName) === ''){
            throw new BankException('A valid player UUID and name are required.');
        }

        $initialWallet = $this->configuredAmount('balances.initial-wallet', '0.00');
        $initialBank = $this->configuredAmount('balances.initial-bank', '0.00');
        $this->repository->ensureAccountWithXuid($uuid, $playerName, $xuid, $initialWallet, $initialBank);
        $account = $this->repository->getAccount($uuid);
        if($account === null){
            throw new BankException('Could not load the registered bank account.');
        }
        return $account;
    }

    /**
     * Looks up an existing account by its stable UUID without creating it.
     *
     * This is the safe alternative when a last-known player name is ambiguous.
     */
    public function findAccountByUuid(string $uuid) : ?BankAccount{
        $uuid = trim($uuid);
        if($uuid === ''){
            throw new BankException('A valid player UUID is required.');
        }

        return $this->repository->getAccount($uuid);
    }

    /**
     * Finds an account by last-known name, or bootstraps it from PocketMine's saved XUID.
     *
     * A display name alone is never used to create an account or invent an identity.
     * If PocketMine has a saved Xbox XUID, that stable identifier is used for the
     * offline account and later reconciled with the live UUID on join.
     */
    public function findAccountByName(string $playerName) : ?BankAccount{
        $playerName = trim($playerName);
        // getOfflinePlayerData() uses the name to construct a file path. Apply
        // PocketMine's own username validation before touching player-data storage.
        if(!Player::isValidUserName($playerName)){
            throw new BankException('A valid PocketMine player name is required.');
        }

        $accounts = $this->repository->findAccountsByName($playerName);
        if(count($accounts) > 1){
            throw new BankException('More than one bank account matches that name. Use findAccountByUuid() to select a specific account safely.');
        }

        $xuid = $this->savedXuidForName($playerName);
        if($accounts !== []){
            $account = $accounts[0];
            if($xuid === null){
                throw new BankException('PocketMine has no saved XUID for that offline name, so the account owner cannot be verified safely. The player must join with an authenticated account, or a caller must use the account UUID directly.');
            }
            if($account->xuid === null){
                throw new BankException('PocketMine has an offline identity for that name, but this saved bank account has not been linked to it yet. The player must join once after this BankSystem update before offline banking can safely use the account.');
            }
            if($account->xuid !== $xuid){
                throw new BankException('That name belongs to a different saved offline identity. No money was moved.');
            }
            return $account;
        }

        if($xuid === null){
            return null;
        }

        return $this->repository->ensureOfflineAccount(
            $playerName,
            $xuid,
            $this->configuredAmount('balances.initial-wallet', '0.00'),
            $this->configuredAmount('balances.initial-bank', '0.00')
        );
    }

    /**
     * Reads only PocketMine's own player metadata. XUIDs are accepted only when
     * present in saved player data; no XUID or UUID is fabricated from a name.
     */
    private function savedXuidForName(string $playerName) : ?string{
        try{
            $data = $this->plugin->getServer()->getOfflinePlayerData($playerName);
            if($data === null || !method_exists($data, 'getString')){
                return null;
            }

            $xuid = $data->getString(Player::TAG_LAST_KNOWN_XUID, '');
            return $xuid !== '' && preg_match('/^[0-9]+$/D', $xuid) === 1 ? $xuid : null;
        }catch(Throwable $exception){
            $this->plugin->getLogger()->warning(
                'Could not inspect saved offline identity for ' . $playerName . ': ' . $exception->getMessage()
            );
            return null;
        }
    }

    /**
     * Transfers to a known account by player name, including a verified offline XUID account.
     * The recipient may be online or offline; no identity is created from the name alone.
     */
    public function transferToName(
        string $fromUuid,
        string $fromName,
        string $toName,
        int $amountMinor
    ) : void{
        // Invalid API calls must fail before a name lookup can create an offline account.
        $this->guardTransactionAmount($amountMinor);

        $onlineTarget = $this->plugin->getServer()->getPlayerExact($toName);
        $target = $onlineTarget !== null
            ? $this->getAccount($onlineTarget->getUniqueId()->toString(), $onlineTarget->getName())
            : $this->findAccountByName($toName);
        if($target === null){
            throw new BankException('No saved or verifiable offline account exists for that player. Offline account creation requires PocketMine saved XUID data; otherwise the player must join the server first.');
        }

        $this->transfer($fromUuid, $fromName, $target->uuid, $target->playerName, $amountMinor);
    }

    public function deposit(string $uuid, string $playerName, int $amountMinor) : void{
        $this->guardTransactionAmount($amountMinor);
        $this->getAccount($uuid, $playerName);
        $record = $this->repository->move(
            $uuid, $playerName,
            $uuid, $playerName,
            $uuid, $playerName,
            'deposit', $amountMinor, 'wallet', 'bank',
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        );
        $this->publish($record);
    }

    public function withdraw(string $uuid, string $playerName, int $amountMinor) : void{
        $this->guardTransactionAmount($amountMinor);
        $this->getAccount($uuid, $playerName);
        $record = $this->repository->move(
            $uuid, $playerName,
            $uuid, $playerName,
            $uuid, $playerName,
            'withdraw', $amountMinor, 'bank', 'wallet',
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        );
        $this->publish($record);
    }

    public function transfer(
        string $fromUuid,
        string $fromName,
        string $toUuid,
        string $toName,
        int $amountMinor
    ) : void{
        $this->guardTransactionAmount($amountMinor);
        if($fromUuid === $toUuid){
            throw new BankException('You cannot pay yourself.');
        }
        $this->getAccount($fromUuid, $fromName);
        $this->getAccount($toUuid, $toName);

        $record = $this->repository->move(
            $fromUuid, $fromName,
            $fromUuid, $fromName,
            $toUuid, $toName,
            'pay', $amountMinor, 'wallet', 'wallet',
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        );
        $this->publish($record);
    }

    /**
     * Legacy signature retained so integrations fail safely rather than silently spoofing
     * an actor by supplying arbitrary UUID/name strings.
     *
     * @deprecated Use giveAs() and pass the actual CommandSender.
     */
    public function give(
        string $targetUuid,
        string $targetName,
        string $bucket,
        int $amountMinor,
        string $actorUuid,
        string $actorName
    ) : void{
        throw new BankException('The legacy privileged API is disabled because caller-supplied actor identity is not verifiable. Use giveAs() with a CommandSender.');
    }

    /**
     * @deprecated Use takeAs() and pass the actual CommandSender.
     */
    /**
     * @deprecated Use takeAs() and pass the actual CommandSender.
     */
    public function take(
        string $targetUuid,
        string $targetName,
        string $bucket,
        int $amountMinor,
        string $actorUuid,
        string $actorName
    ) : void{
        throw new BankException('The legacy privileged API is disabled because caller-supplied actor identity is not verifiable. Use takeAs() with a CommandSender.');
    }

    /**
     * @deprecated Use setBalanceAs() and pass the actual CommandSender.
     */
    public function setBalance(
        string $targetUuid,
        string $targetName,
        string $bucket,
        int $amountMinor,
        string $actorUuid,
        string $actorName
    ) : void{
        throw new BankException('The legacy privileged API is disabled because caller-supplied actor identity is not verifiable. Use setBalanceAs() with a CommandSender.');
    }

    public function setBalance(
        string $targetUuid,
        string $targetName,
        string $bucket,
        int $amountMinor,
        string $actorUuid,
        string $actorName
    ) : void{
        if($amountMinor < 0){
            throw new BankException('A balance cannot be negative.');
        }
        $this->getAccount($targetUuid, $targetName);
        $this->publish($this->repository->adjustBalance(
            $actorUuid, $actorName, $targetUuid, $targetName, $bucket, 'set', $amountMinor,
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        ));
    }

    public function giveAs(
        CommandSender $actor,
        string $targetUuid,
        string $targetName,
        string $bucket,
        int $amountMinor
    ) : void{
        $identity = $this->administratorIdentity($actor);
        $this->guardTransactionAmount($amountMinor);
        $this->getAccount($targetUuid, $targetName);
        $this->publish($this->repository->adjustBalance(
            $identity['uuid'], $identity['name'], $targetUuid, $targetName, $bucket, 'give', $amountMinor,
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        ));
    }

    public function takeAs(
        CommandSender $actor,
        string $targetUuid,
        string $targetName,
        string $bucket,
        int $amountMinor
    ) : void{
        $identity = $this->administratorIdentity($actor);
        $this->guardTransactionAmount($amountMinor);
        $this->getAccount($targetUuid, $targetName);
        $this->publish($this->repository->adjustBalance(
            $identity['uuid'], $identity['name'], $targetUuid, $targetName, $bucket, 'take', $amountMinor,
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        ));
    }

    public function setBalanceAs(
        CommandSender $actor,
        string $targetUuid,
        string $targetName,
        string $bucket,
        int $amountMinor
    ) : void{
        $identity = $this->administratorIdentity($actor);
        if($amountMinor < 0){
            throw new BankException('A balance cannot be negative.');
        }
        $this->getAccount($targetUuid, $targetName);
        $this->publish($this->repository->adjustBalance(
            $identity['uuid'], $identity['name'], $targetUuid, $targetName, $bucket, 'set', $amountMinor,
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        ));
    }

    /**
     * @return array{uuid: string, name: string}
     */
    private function administratorIdentity(CommandSender $actor) : array{
        if(!$actor->hasPermission('banksystem.admin')){
            throw new BankException('Administrator permission banksystem.admin is required for balance changes.');
        }

        return [
            'uuid' => $actor instanceof Player ? $actor->getUniqueId()->toString() : 'console',
            'name' => $actor->getName()
        ];
    }

    /**
     * @return list<BankAccount>
     */
    public function getTopAccounts(int $limit = 10) : array{
        return $this->repository->getTopAccounts($limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHistory(string $uuid, int $limit = 10, int $offset = 0) : array{
        return $this->repository->getHistory($uuid, $limit, $offset);
    }

    /**
     * Validates an amount before a name lookup can create an XUID-backed offline account.
     */
    public function validateTransactionAmount(int $amountMinor) : void{
        $this->guardTransactionAmount($amountMinor);
    }

    private function guardTransactionAmount(int $amountMinor) : void{
        if($amountMinor <= 0){
            throw new BankException('The amount must be greater than zero.');
        }
        if($amountMinor > $this->configuredAmount('limits.max-transaction', '1000000.00')){
            throw new BankException('The amount exceeds the configured transaction limit.');
        }
    }

    private function configuredAmount(string $key, string $fallback) : int{
        $value = $this->plugin->getConfig()->getNested($key, $fallback);
        // Money config must remain exact: accept decimal strings or integers, but never
        // coerce YAML floats (already subject to binary rounding) or booleans to money.
        $parsed = is_string($value) || is_int($value)
            ? Money::parse((string) $value, true)
            : null;
        if($parsed === null){
            $this->plugin->getLogger()->warning('Invalid money value in config path ' . $key . '; using ' . $fallback . '.');
            return Money::parse($fallback, true) ?? 0;
        }
        return $parsed;
    }

    /**
     * Called only after SQLite has committed the transaction.
     *
     * @param array<string, mixed> $record
     */
    private function publish(array $record) : void{
        try{
            if(!BankTransactionEvent::hasHandlers()){
                return;
            }

            (new BankTransactionEvent($record))->call();
        }catch(Throwable $exception){
            // Any event-dispatch failure happens after commit and cannot turn the
            // completed money movement into a reported transaction failure.
            $this->plugin->getLogger()->error(
                'A BankTransactionEvent listener failed after transaction ' . (string) ($record['transaction_id'] ?? 'unknown') .
                ' had committed: ' . $exception->getMessage()
            );
        }
    }
}
