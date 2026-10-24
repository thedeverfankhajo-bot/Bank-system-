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

    public function testFindAccountsByNameIsCaseInsensitive() : void{
        $matches = $this->repository->findAccountsByName('aLeX');

        self::assertCount(1, $matches);
        self::assertSame('player-a', $matches[0]->uuid);
        self::assertSame('Alex', $matches[0]->playerName);
    }

    public function testFindAccountsByNameExposesAmbiguousHistoricalNames() : void{
        $this->repository->ensureAccount('player-alex-2', 'alex', 0, 0);

        $matches = $this->repository->findAccountsByName('ALEX');

        self::assertCount(2, $matches);
        self::assertContains('player-a', array_map(
            static fn(\BankSystem\BankAccount $account) : string => $account->uuid,
            $matches
        ));
        self::assertContains('player-alex-2', array_map(
            static fn(\BankSystem\BankAccount $account) : string => $account->uuid,
            $matches
        ));
    }

    public function testKnownOfflineAccountCanBeUsedAsTransferRecipient() : void{
        // A disconnected player is represented by their persisted account and stable UUID.
        $offlineTarget = $this->repository->findAccountsByName('blAKE')[0];
        $record = $this->repository->move(
            'player-a', 'Alex',
            'player-a', 'Alex',
            $offlineTarget->uuid, $offlineTarget->playerName,
            'pay', 1250, 'wallet', 'wallet', PHP_INT_MAX
        );

        self::assertSame('player-b', $record['to_uuid']);
        self::assertSame('Blake', $record['to_name']);
        self::assertSame(1250, $this->repository->getAccount('player-b')?->walletBalance);
        self::assertCount(1, $this->repository->getHistory('player-b', 10, 0));
    }

    public function testDepositMovesMoneyAndWritesOneLedgerEntry() : void{
        $record = $this->repository->move(
            'player-a', 'Alex',
            'player-a', 'Alex',
            'player-a', 'Alex',
            'deposit', 2500, 'wallet', 'bank', PHP_INT_MAX
        );

        $account = $this->repository->getAccount('player-a');
        self::assertNotNull($account);
        self::assertSame(7500, $account->walletBalance);
        self::assertSame(2500, $account->bankBalance);
        self::assertSame('deposit', $record['action']);
        self::assertCount(1, $this->repository->getHistory('player-a', 10, 0));
    }

    public function testTransactionAboveConfiguredMaximumIsRejectedWithoutChangingBalances() : void{
        try{
            $this->repository->move(
                'player-a', 'Alex',
                'player-a', 'Alex',
                'player-b', 'Blake',
                'pay', 2500, 'wallet', 'wallet', 1000
            );
            self::fail('Expected a transaction above the configured cap to be rejected.');
        }catch(BankException $exception){
            self::assertStringContainsString('configured transaction limit', $exception->getMessage());
        }

        $source = $this->repository->getAccount('player-a');
        $target = $this->repository->getAccount('player-b');
        self::assertNotNull($source);
        self::assertNotNull($target);
        self::assertSame(10000, $source->walletBalance);
        self::assertSame(0, $target->walletBalance);
        self::assertCount(0, $this->repository->getHistory('player-a', 10, 0));
        self::assertCount(0, $this->repository->getHistory('player-b', 10, 0));
    }

    public function testFailedTransferLeavesBalancesAndHistoryUnchanged() : void{
        try{
            $this->repository->move(
                'player-a', 'Alex',
                'player-a', 'Alex',
                'player-b', 'Blake',
                'pay', 20000, 'wallet', 'wallet', PHP_INT_MAX
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

    public function testLedgerInsertFailureRollsBackBalanceChanges() : void{
        $property = new \ReflectionProperty(BankRepository::class, 'database');
        $database = $property->getValue($this->repository);
        self::assertInstanceOf(\SQLite3::class, $database);

        // Force the audit insert to fail after both balance updates have executed.
        // The repository must roll back the entire operation, not leave money partially moved.
        $database->exec("CREATE TRIGGER force_ledger_failure BEFORE INSERT ON bank_transactions BEGIN SELECT RAISE(ABORT, 'forced ledger failure'); END");

        try{
            $this->repository->move(
                'player-a', 'Alex',
                'player-a', 'Alex',
                'player-b', 'Blake',
                'pay', 2500, 'wallet', 'wallet', PHP_INT_MAX
            );
            self::fail('Expected the audit ledger insert to fail.');
        }catch(\Exception $exception){
            self::assertStringContainsString('forced ledger failure', $exception->getMessage());
        }

        $source = $this->repository->getAccount('player-a');
        $target = $this->repository->getAccount('player-b');
        self::assertNotNull($source);
        self::assertNotNull($target);
        self::assertSame(10000, $source->walletBalance);
        self::assertSame(0, $target->walletBalance);
        self::assertCount(0, $this->repository->getHistory('player-a', 10, 0));
        self::assertCount(0, $this->repository->getHistory('player-b', 10, 0));
    }

    public function testAdminAdjustmentIsRecorded() : void{
        $record = $this->repository->adjustBalance(
            'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'give', 500, 500
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
            'deposit', 2500, 'wallet', 'bank', PHP_INT_MAX
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
            'pay', 2500, 'wallet', 'wallet', PHP_INT_MAX
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
                'invalid-self-transfer', 100, 'wallet', 'wallet', PHP_INT_MAX
            );
            self::fail('Expected the same-balance transfer to be rejected.');
        }catch(BankException){
        }

        $account = $this->repository->getAccount('player-a');
        self::assertNotNull($account);
        self::assertSame(10000, $account->walletBalance);
        self::assertCount(0, $this->repository->getHistory('player-a', 10, 0));
    }

    public function testAdminSetCannotExceedConfiguredChangeLimit() : void{
        try{
            $this->repository->adjustBalance(
                'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'set', 100000, 1000
            );
            self::fail('Expected setting a balance beyond the configured change limit to be rejected.');
        }catch(BankException $exception){
            self::assertStringContainsString('configured transaction limit', $exception->getMessage());
        }

        $account = $this->repository->getAccount('player-b');
        self::assertNotNull($account);
        self::assertSame(0, $account->bankBalance);
        self::assertCount(0, $this->repository->getHistory('player-b', 10, 0));
    }

    public function testAdminSetLimitUsesAtomicDeltaFromCurrentBalance() : void{
        $this->repository->adjustBalance(
            'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'give', 1500, 1500
        );

        $record = $this->repository->adjustBalance(
            'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'set', 2000, 500
        );

        $account = $this->repository->getAccount('player-b');
        self::assertNotNull($account);
        self::assertSame(2000, $account->bankBalance);
        self::assertSame(500, $record['amount_minor']);
        self::assertSame(1500, $record['from_balance_before']);
        self::assertSame(2000, $record['from_balance_after']);
    }

    public function testAdminSetToZeroRecordsBalanceDelta() : void{
        $this->repository->adjustBalance(
            'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'give', 500, 500
        );
        $record = $this->repository->adjustBalance(
            'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'set', 0, 500
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
                'pay', 1, 'wallet', 'bank', PHP_INT_MAX
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
    public function testDatabaseCanBeReopenedWithoutLosingBalancesOrHistory() : void{
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'banksystem-test-' . bin2hex(random_bytes(8)) . '.sqlite3';
        $repository = new BankRepository($path);

        try{
            $repository->ensureAccount('persistent-player', 'Persisted', 10000, 500);
            $repository->move(
                'persistent-player', 'Persisted',
                'persistent-player', 'Persisted',
                'persistent-player', 'Persisted',
                'deposit', 2500, 'wallet', 'bank', PHP_INT_MAX
            );
            $repository->close();

            $repository = new BankRepository($path);
            $account = $repository->getAccount('persistent-player');
            self::assertNotNull($account);
            self::assertSame(7500, $account->walletBalance);
            self::assertSame(3000, $account->bankBalance);
            self::assertCount(1, $repository->getHistory('persistent-player', 10, 0));
        }finally{
            try{
                $repository->close();
            }catch(\Throwable){
            }
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function testNewDatabaseFilesAreOwnerOnlyImmediatelyAfterInitialization() : void{
        if(DIRECTORY_SEPARATOR === '\\'){
            self::markTestSkipped('POSIX file permission bits are not portable to Windows.');
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'banksystem-new-permissions-' . bin2hex(random_bytes(8)) . '.sqlite3';
        $repository = new BankRepository($path);

        try{
            self::assertFileExists($path);
            foreach([$path, $path . '-wal', $path . '-shm'] as $file){
                if(!is_file($file)){
                    continue;
                }
                $permissions = fileperms($file);
                self::assertNotFalse($permissions, 'Could not read permissions for ' . basename($file));
                if($permissions !== false){
                    self::assertSame(0600, $permissions & 0777, basename($file) . ' must start with owner-only permissions.');
                }
            }
        }finally{
            $repository->close();
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function testRefusesSymbolicLinkDatabaseWithoutChangingItsTarget() : void{
        if(DIRECTORY_SEPARATOR === '\\' || !function_exists('symlink')){
            self::markTestSkipped('Symbolic-link security checks require POSIX filesystem support.');
        }

        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'banksystem-link-target-' . bin2hex(random_bytes(8)) . '.sqlite3';
        $link = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'banksystem-link-' . bin2hex(random_bytes(8)) . '.sqlite3';
        file_put_contents($target, 'do-not-open-as-database');
        self::assertTrue(@chmod($target, 0644));

        if(!@symlink($target, $link)){
            @unlink($target);
            self::markTestSkipped('This environment does not permit symbolic-link creation.');
        }

        try{
            try{
                $repository = new BankRepository($link);
                $repository->close();
                self::fail('Expected a symbolic-link database path to be rejected.');
            }catch(\RuntimeException $exception){
                self::assertStringContainsString('symbolic links', $exception->getMessage());
            }

            self::assertSame('do-not-open-as-database', file_get_contents($target));
            self::assertSame(0644, fileperms($target) & 0777);
        }finally{
            @unlink($link);
            @unlink($target);
        }
    }

    public function testDatabaseAndExistingSidecarsAreOwnerOnlyOnPosix() : void{
        if(DIRECTORY_SEPARATOR === '\\'){
            self::markTestSkipped('POSIX file permission bits are not portable to Windows.');
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'banksystem-permissions-' . bin2hex(random_bytes(8)) . '.sqlite3';
        $repository = new BankRepository($path);

        try{
            $repository->ensureAccount('permission-player-a', 'PermissionA', 10000, 0);
            $repository->ensureAccount('permission-player-b', 'PermissionB', 0, 0);
            $repository->move(
                'permission-player-a', 'PermissionA',
                'permission-player-a', 'PermissionA',
                'permission-player-b', 'PermissionB',
                'pay', 100, 'wallet', 'wallet', PHP_INT_MAX
            );

            self::assertFileExists($path);
            foreach([$path, $path . '-wal', $path . '-shm'] as $file){
                if(!is_file($file)){
                    continue;
                }
                $permissions = fileperms($file);
                self::assertNotFalse($permissions, 'Could not read permissions for ' . basename($file));
                if($permissions !== false){
                    self::assertSame(0, $permissions & 0077, basename($file) . ' must not grant group/world access.');
                }
            }
        }finally{
            try{
                $repository->close();
            }catch(\Throwable){
            }
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function testLeaderboardSortsByBankBalanceAndBreaksTiesByName() : void{
        $this->repository->ensureAccount('player-c', 'Casey', 0, 0);
        $this->repository->adjustBalance(
            'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'give', 1500, 1500
        );
        $this->repository->adjustBalance(
            'console', 'CONSOLE', 'player-c', 'Casey', 'bank', 'give', 1500, 1500
        );

        $top = $this->repository->getTopAccounts(3);

        self::assertSame(['Blake', 'Casey', 'Alex'], array_map(
            static fn(\BankSystem\BankAccount $account) : string => $account->playerName,
            $top
        ));
    }

    public function testInvalidBucketAndAdjustmentModeAreRejected() : void{
        try{
            $this->repository->adjustBalance(
                'console', 'CONSOLE', 'player-b', 'Blake', 'cash', 'give', 500, PHP_INT_MAX
            );
            self::fail('Expected an unsupported balance bucket to be rejected.');
        }catch(BankException){
        }

        try{
            $this->repository->adjustBalance(
                'console', 'CONSOLE', 'player-b', 'Blake', 'bank', 'mint', 500, PHP_INT_MAX
            );
            self::fail('Expected an unsupported adjustment mode to be rejected.');
        }catch(BankException){
        }

        $account = $this->repository->getAccount('player-b');
        self::assertNotNull($account);
        self::assertSame(0, $account->bankBalance);
        self::assertCount(0, $this->repository->getHistory('player-b', 10, 0));
    }

}
