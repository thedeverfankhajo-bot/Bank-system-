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
}
