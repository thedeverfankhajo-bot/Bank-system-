<?php

declare(strict_types=1);

namespace BankSystem\Service;

use BankSystem\BankAccount;
use BankSystem\BankException;
use BankSystem\Database\BankRepository;
use BankSystem\Event\BankTransactionEvent;
use BankSystem\Main;
use BankSystem\Support\Money;
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

    public function give(
        string $targetUuid,
        string $targetName,
        string $bucket,
        int $amountMinor,
        string $actorUuid,
        string $actorName
    ) : void{
        $this->guardTransactionAmount($amountMinor);
        $this->getAccount($targetUuid, $targetName);
        $this->publish($this->repository->adjustBalance(
            $actorUuid, $actorName, $targetUuid, $targetName, $bucket, 'give', $amountMinor,
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        ));
    }

    public function take(
        string $targetUuid,
        string $targetName,
        string $bucket,
        int $amountMinor,
        string $actorUuid,
        string $actorName
    ) : void{
        $this->guardTransactionAmount($amountMinor);
        $this->getAccount($targetUuid, $targetName);
        $this->publish($this->repository->adjustBalance(
            $actorUuid, $actorName, $targetUuid, $targetName, $bucket, 'take', $amountMinor,
            $this->configuredAmount('limits.max-transaction', '1000000.00')
        ));
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
        $parsed = Money::parse(is_scalar($value) ? (string) $value : '', true);
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
        if(!BankTransactionEvent::hasHandlers()){
            return;
        }

        try{
            (new BankTransactionEvent($record))->call();
        }catch(Throwable $exception){
            // An external listener cannot turn an already committed payment into a failure.
            $this->plugin->getLogger()->error(
                'A BankTransactionEvent listener failed after transaction ' . (string) ($record['transaction_id'] ?? 'unknown') .
                ' had committed: ' . $exception->getMessage()
            );
        }
    }
}
