<?php

declare(strict_types=1);

namespace BankSystem\Tests\Database;

use BankSystem\BankException;
use BankSystem\Database\BankRepository;
use PHPUnit\Framework\TestCase;

final class BankRepositoryTest extends TestCase{
    private BankRepository $repository;

    protected function setUp() : void{
        parent::setUp();
        if(!extension_loaded('sqlite3')){
            self::markTestSkipped('The sqlite3 PHP extension is not available.');
        }
        $this->repository = new BankRepository(':memory:');
        $this->repository->ensureAccount('player-a', 'Alex', 10000, 0);
        $this->repository->ensureAccount('player-b', 'Blake', 0, 0);
    }

    protected function tearDown() : void{
        if(isset($this->repository)){
            $this->repository->close();
        }
        parent::tearDown();
    }

    public function testDepositMovesMoneyAndWritesOneLedgerEntry() : void{
        $record = $this->repository->move(
            'player-a', 'Alex',
            'player-a', 'Alex',
            'player-a', 'Alex',
            'deposit', 2500, 'wallet', 'bank'
        );

        $account = $this->repository->getAccount('player-a');
        self::assertNotNull($account);
        self::assertSame(7500, $account->walletBalance);
        self::assertSame(2500, $account->bankBalance);
        self::assertSame('deposit', $record['action']);
        self::assertCount(1, $this->repository->getHistory('player-a', 10, 0));
    }

    public function testFailedTransferLeavesBalancesAndHistoryUnchanged() : void{
        try{
            $this->repository->move(
                'player-a', 'Alex',
                'player-a', 'Alex',
                'player-b', 'Blake',
                'pay', 20000, 'wallet', 'wallet'
            );
            self::fail('Expected the transfer to fail for insufficient funds.');
        }catch(BankException){
        }

        $source = $this->repository->getAccount('player-a');
        $target = $this->repository->getAccount('player-b');
        self::assertNotNull($source);
        self::assertNotNull($target);
        self::assertSame(10000, $source->walletBalance);
        self::assertSame(0, $target->walletBalance);
        self::assertCount(0, $this->repository->getHistory('player-a', 10, 0));
    }

    public function testAdminAdjustmentIsRecorded() : void{
        $record = $this->repository->adjustBalance(
            'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'give', 500
        );

        $account = $this->repository->getAccount('player-b');
        self::assertNotNull($account);
        self::assertSame(500, $account->bankBalance);
        self::assertSame('admin_give_bank', $record['action']);
        self::assertCount(1, $this->repository->getHistory('player-b', 10, 0));
    }

    public function testRejoiningDoesNotResetExistingBalances() : void{
        $this->repository->move(
            'player-a', 'Alex',
            'player-a', 'Alex',
            'player-a', 'Alex',
            'deposit', 2500, 'wallet', 'bank'
        );

        $this->repository->ensureAccount('player-a', 'Alex_New', 999999, 999999);
        $account = $this->repository->getAccount('player-a');

        self::assertNotNull($account);
        self::assertSame('Alex_New', $account->playerName);
        self::assertSame(7500, $account->walletBalance);
        self::assertSame(2500, $account->bankBalance);
    }

    public function testSuccessfulTransferUpdatesBothAccountsAndBothHistories() : void{
        $record = $this->repository->move(
            'player-a', 'Alex',
            'player-a', 'Alex',
            'player-b', 'Blake',
            'pay', 2500, 'wallet', 'wallet'
        );

        $source = $this->repository->getAccount('player-a');
        $target = $this->repository->getAccount('player-b');
        self::assertNotNull($source);
        self::assertNotNull($target);
        self::assertSame(7500, $source->walletBalance);
        self::assertSame(2500, $target->walletBalance);
        self::assertSame(7500, $record['from_balance_after']);
        self::assertSame(2500, $record['to_balance_after']);
        self::assertCount(1, $this->repository->getHistory('player-a', 10, 0));
        self::assertCount(1, $this->repository->getHistory('player-b', 10, 0));
    }

    public function testSameBalanceTransferIsRejectedWithoutWritingHistory() : void{
        try{
            $this->repository->move(
                'player-a', 'Alex',
                'player-a', 'Alex',
                'player-a', 'Alex',
                'invalid-self-transfer', 100, 'wallet', 'wallet'
            );
            self::fail('Expected the same-balance transfer to be rejected.');
        }catch(BankException){
        }

        $account = $this->repository->getAccount('player-a');
        self::assertNotNull($account);
        self::assertSame(10000, $account->walletBalance);
        self::assertCount(0, $this->repository->getHistory('player-a', 10, 0));
    }

    public function testAdminSetToZeroRecordsBalanceDelta() : void{
        $this->repository->adjustBalance(
            'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'give', 500
        );
        $record = $this->repository->adjustBalance(
            'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'set', 0
        );

        $account = $this->repository->getAccount('player-b');
        self::assertNotNull($account);
        self::assertSame(0, $account->bankBalance);
        self::assertSame('admin_set_bank', $record['action']);
        self::assertSame(500, $record['amount_minor']);
        self::assertSame(500, $record['from_balance_before']);
        self::assertSame(0, $record['from_balance_after']);
        self::assertCount(2, $this->repository->getHistory('player-b', 10, 0));
    }

    public function testDestinationOverflowDoesNotChangeSource() : void{
        $this->repository->ensureAccount('player-c', 'Casey', 0, PHP_INT_MAX);

        try{
            $this->repository->move(
                'player-a', 'Alex',
                'player-a', 'Alex',
                'player-c', 'Casey',
                'pay', 1, 'wallet', 'bank'
            );
            self::fail('Expected an overflowing destination balance to be rejected.');
        }catch(BankException){
        }

        $source = $this->repository->getAccount('player-a');
        $target = $this->repository->getAccount('player-c');
        self::assertNotNull($source);
        self::assertNotNull($target);
        self::assertSame(10000, $source->walletBalance);
        self::assertSame(PHP_INT_MAX, $target->bankBalance);
        self::assertCount(0, $this->repository->getHistory('player-a', 10, 0));
    }
}
