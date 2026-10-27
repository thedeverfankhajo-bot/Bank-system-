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
use pocketmine\console\ConsoleCommandSender;
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
     * @deprecated Caller-supplied UUID/name/XUID values cannot prove a live player identity.
     * Use registerOnlinePlayer() with the actual PocketMine Player instance.
     */
    public function registerOnlineAccount(string $uuid, string $playerName, string $xuid) : BankAccount{
        throw new BankException('The legacy account registration API is disabled because caller-supplied identity strings are not verifiable. Use registerOnlinePlayer() with the actual Player instance.');
    }

    /**
     * Registers an authenticated live player and atomically links any XUID-backed offline account.
     */
    public function registerOnlinePlayer(Player $player) : BankAccount{
        $this->assertLivePlayer($player);
        $uuid = $player->getUniqueId()->toString();
        $playerName = $player->getName();
        $xuid = $player->getXuid();
        if(trim($uuid) === '' || trim($playerName) === ''){
            throw new BankException('A valid live player UUID and name are required.');
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
     * @deprecated Caller-supplied player identities are not verifiable. Use transferToNameAs().
     */
    public function transferToName(string $fromUuid, string $fromName, string $toName, int $amountMinor) : void{
        throw new BankException('The legacy transferToName() API is disabled because caller-supplied source identity is not verifiable. Use transferToNameAs() with the actual Player instance.');
    }

    /**
     * @deprecated Caller-supplied player identities are not verifiable. Use depositAs().
     */
    public function deposit(string $uuid, string $playerName, int $amountMinor) : void{
        throw new BankException('The legacy deposit() API is disabled because caller-supplied identity is not verifiable. Use depositAs() with the actual Player instance.');
    }

    /**
     * @deprecated Caller-supplied player identities are not verifiable. Use withdrawAs().
     */
    public function withdraw(string $uuid, string $playerName, int $amountMinor) : void{
        throw new BankException('The legacy withdraw() API is disabled because caller-supplied identity is not verifiable. Use withdrawAs() with the actual Player instance.');
    }

    /**
     * @deprecated Caller-supplied source identity is not verifiable. Use transferAs().
     */
    public function transfer(string $fromUuid, string $fromName, string $toUuid, string $toName, int $amountMinor) : void{
        throw new BankException('The legacy transfer() API is disabled because caller-supplied source identity is not verifiable. Use transferAs() with the actual Player instance.');
    }

    /**
     * Deposits from the authenticated player's wallet into their bank account.
     */
    public function depositAs(Player $actor, int $amountMinor) : void{
        $identity = $this->playerIdentity($actor);
        $this->guardTransactionAmount($amountMinor);
        $this->getAccount($identity['uuid'], $identity['name']);
        $record = $this->repository->move(
            $identity['uuid'], $identity['name'],
            $identity['uuid'], $identity['name'],
            $identity['uuid'], $identity['name'],
            'deposit', $amountMinor, 'wallet', 'bank',
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        );
        $this->publish($record);
    }

    /**
     * Withdraws from the authenticated player's bank account into their wallet.
     */
    public function withdrawAs(Player $actor, int $amountMinor) : void{
        $identity = $this->playerIdentity($actor);
        $this->guardTransactionAmount($amountMinor);
        $this->getAccount($identity['uuid'], $identity['name']);
        $record = $this->repository->move(
            $identity['uuid'], $identity['name'],
            $identity['uuid'], $identity['name'],
            $identity['uuid'], $identity['name'],
            'withdraw', $amountMinor, 'bank', 'wallet',
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        );
        $this->publish($record);
    }

    /**
     * Transfers from the authenticated player's wallet to an existing UUID-keyed account.
     * Destination identity is loaded from storage; caller-supplied display names are never trusted.
     */
    public function transferAs(Player $actor, string $toUuid, int $amountMinor) : void{
        $identity = $this->playerIdentity($actor);
        $this->guardTransactionAmount($amountMinor);
        $toUuid = trim($toUuid);
        if($toUuid === ''){
            throw new BankException('A valid destination account UUID is required.');
        }
        if($identity['uuid'] === $toUuid){
            throw new BankException('You cannot pay yourself.');
        }

        $this->getAccount($identity['uuid'], $identity['name']);
        $target = $this->repository->getAccount($toUuid);
        if($target === null){
            throw new BankException('The destination bank account could not be found or verified.');
        }

        $record = $this->repository->move(
            $identity['uuid'], $identity['name'],
            $identity['uuid'], $identity['name'],
            $target->uuid, $target->playerName,
            'pay', $amountMinor, 'wallet', 'wallet',
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        );
        $this->publish($record);
    }

    /**
     * Transfers to a verified online or saved offline account by name.
     * An offline account can be bootstrapped only from PocketMine's saved XUID.
     */
    public function transferToNameAs(Player $actor, string $toName, int $amountMinor) : void{
        $identity = $this->playerIdentity($actor);
        $this->guardTransactionAmount($amountMinor);

        $onlineTarget = $this->plugin->getServer()->getPlayerExact($toName);
        $target = $onlineTarget !== null
            ? $this->getAccount($onlineTarget->getUniqueId()->toString(), $onlineTarget->getName())
            : $this->findAccountByName($toName);
        if($target === null){
            throw new BankException('No saved or verifiable offline account exists for that player. Offline account creation requires PocketMine saved XUID data; otherwise the player must join the server first.');
        }
        if($target->uuid === $identity['uuid']){
            throw new BankException('You cannot pay yourself.');
        }

        $this->transferAs($actor, $target->uuid, $amountMinor);
    }


    /**
     * Legacy signature retained so integrations fail safely rather than silently spoofing
     * an actor by supplying arbitrary UUID/name strings.
     *
     * @deprecated Use giveAs() and pass the actual CommandSender.
     */
    /**
     * @deprecated Caller-supplied actor identifiers are not trustworthy for privileged operations.
     * Use giveAs() and pass the actual CommandSender.
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
     * @deprecated Caller-supplied actor identifiers are not trustworthy for privileged operations.
     * Use takeAs() and pass the actual CommandSender.
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
     * @deprecated Caller-supplied actor identifiers are not trustworthy for privileged operations.
     * Use setBalanceAs() and pass the actual CommandSender.
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
     * Requires the exact Player object that is currently connected to this server.
     */
    private function assertLivePlayer(Player $player) : void{
        $livePlayer = $this->plugin->getServer()->getPlayerExact($player->getName());
        if($livePlayer === null || $livePlayer !== $player ||
            $livePlayer->getUniqueId()->toString() !== $player->getUniqueId()->toString()){
            throw new BankException('The player must remain connected to make a banking transaction.');
        }
    }

    /**
     * Resolves a live, authorized player identity instead of trusting external UUID/name strings.
     *
     * @return array{uuid: string, name: string}
     */
    private function playerIdentity(Player $actor) : array{
        if(!$actor->hasPermission('banksystem.use')){
            throw new BankException('Permission banksystem.use is required for player banking transactions.');
        }
        $this->assertLivePlayer($actor);

        return [
            'uuid' => $actor->getUniqueId()->toString(),
            'name' => $actor->getName()
        ];
    }

    /**
     * @return array{uuid: string, name: string}
     */
    private function administratorIdentity(CommandSender $actor) : array{
        if(!$actor->hasPermission('banksystem.admin')){
            throw new BankException('Administrator permission banksystem.admin is required for balance changes.');
        }

        if($actor instanceof Player){
            $this->assertLivePlayer($actor);
            return [
                'uuid' => $actor->getUniqueId()->toString(),
                'name' => $actor->getName()
            ];
        }

        // PocketMine-MP API 5 exposes the server reference from ConsoleCommandSender;
        // Server::getConsoleSender() does not exist. Verify the sender class and that it
        // belongs to this server before attributing an operation to the console.
        if(!$actor instanceof ConsoleCommandSender || $actor->getServer() !== $this->plugin->getServer()){
            throw new BankException('Administrator identity must be a live player or this server console sender.');
        }

        return ['uuid' => 'console', 'name' => $actor->getName()];
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
