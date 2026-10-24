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

    public function testEncryptionRequiresAnExternalKeyAndFailsBeforeOpeningStorage() : void{
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'banksystem-encryption-no-key-' . bin2hex(random_bytes(8)) . '.sqlite3';

        try{
            new BankRepository($path, null, true);
            self::fail('Expected encryption to fail closed when no key is configured.');
        }catch(\RuntimeException $exception){
            self::assertStringContainsString('BANKSYSTEM_SQLCIPHER_KEY', $exception->getMessage());
        }

        self::assertFileDoesNotExist($path, 'Missing-key validation must happen before SQLite opens or creates storage.');
    }

    public function testEncryptionDoesNotFallBackToPlaintextWhenSqlcipherIsUnavailable() : void{
        $probe = new \SQLite3(':memory:');
        try{
            $cipherVersion = $probe->querySingle('PRAGMA cipher_version');
        }finally{
            $probe->close();
        }

        if(is_string($cipherVersion) && trim($cipherVersion) !== ''){
            self::markTestSkipped('This runtime includes SQLCipher; the non-SQLCipher fallback test is not applicable.');
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'banksystem-encryption-no-cipher-' . bin2hex(random_bytes(8)) . '.sqlite3';
        try{
            try{
                $encrypted = new BankRepository($path, str_repeat('K', 32), true);
                $encrypted->close();
                self::fail('Expected encryption to fail when SQLCipher is unavailable.');
            }catch(\RuntimeException $exception){
                self::assertStringContainsString('SQLCipher', $exception->getMessage());
            }

            self::assertFileDoesNotExist(
                $path,
                'When SQLCipher is unavailable, encryption checks must fail before creating persistent storage.'
            );
            self::assertFileDoesNotExist($path . '-wal');
            self::assertFileDoesNotExist($path . '-shm');
        }finally{
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
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

    public function testFindAccountsByNameReturnsNoMatchForUnknownOfflinePlayer() : void{
        self::assertSame([], $this->repository->findAccountsByName('NotRegistered'));
    }

    public function testNameLookupIndexExistsForCaseInsensitiveSearch() : void{
        $property = new \ReflectionProperty(BankRepository::class, 'database');
        $database = $property->getValue($this->repository);
        self::assertInstanceOf(\SQLite3::class, $database);
        self::assertSame(
            1,
            $database->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'idx_bank_accounts_name'")
        );
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


    public function testOfflineAccountIsCreatedByXuidWithoutResettingExistingBalances() : void{
        $account = $this->repository->ensureOfflineAccount('OfflineBlake', '1234567890123', 100, 200);

        self::assertSame('offline-xuid:1234567890123', $account->uuid);
        self::assertSame('1234567890123', $account->xuid);
        self::assertSame(100, $account->walletBalance);
        self::assertSame(200, $account->bankBalance);

        $this->repository->adjustBalance(
            'console', 'CONSOLE', $account->uuid, $account->playerName, 'wallet', 'give', 500, 500
        );
        $again = $this->repository->ensureOfflineAccount('OfflineBlake', '1234567890123', 9000, 9000);

        self::assertSame($account->uuid, $again->uuid);
        self::assertSame(600, $again->walletBalance);
        self::assertSame(200, $again->bankBalance);
    }

    public function testOfflineAccountPromotionKeepsBalancesAndTransactionHistory() : void{
        $offline = $this->repository->ensureOfflineAccount('OfflineBlake', '9876543210123', 0, 0);
        $this->repository->move(
            'player-a', 'Alex',
            'player-a', 'Alex',
            $offline->uuid, $offline->playerName,
            'pay', 1250, 'wallet', 'wallet', PHP_INT_MAX
        );

        $this->repository->ensureAccountWithXuid(
            'live-blake-uuid', 'BlakeRenamed', '9876543210123', 999999, 999999
        );

        $account = $this->repository->getAccount('live-blake-uuid');
        self::assertNotNull($account);
        self::assertSame('BlakeRenamed', $account->playerName);
        self::assertSame('9876543210123', $account->xuid);
        self::assertSame(1250, $account->walletBalance);
        self::assertSame(0, $account->bankBalance);
        self::assertNull($this->repository->getAccount($offline->uuid));

        $history = $this->repository->getHistory('live-blake-uuid', 10, 0);
        self::assertCount(1, $history);
        self::assertSame('live-blake-uuid', $history[0]['to_uuid']);
        self::assertSame('pay', $history[0]['action']);
    }

    public function testXuidConflictDoesNotMergeTwoDifferentAccounts() : void{
        $offline = $this->repository->ensureOfflineAccount('OfflineBlake', '5555555555555', 1000, 0);
        $this->repository->ensureAccount('different-live-uuid', 'SomeoneElse', 2000, 3000);

        try{
            $this->repository->ensureAccountWithXuid(
                'different-live-uuid', 'SomeoneElse', '5555555555555', 0, 0
            );
            self::fail('Expected conflicting account identities to be rejected.');
        }catch(BankException $exception){
            self::assertStringContainsString('different saved bank accounts', $exception->getMessage());
        }

        self::assertSame(1000, $this->repository->getAccount($offline->uuid)?->walletBalance);
        self::assertSame(2000, $this->repository->getAccount('different-live-uuid')?->walletBalance);
        self::assertSame($offline->uuid, $this->repository->findAccountByXuid('5555555555555')?->uuid);
    }

    public function testVersionOneDatabaseMigratesToXuidSchemaWithoutLosingData() : void{
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'banksystem-v1-migration-' . bin2hex(random_bytes(8)) . '.sqlite3';
        $legacy = new \SQLite3($path);
        $legacy->exec(
            'CREATE TABLE bank_accounts (
                uuid TEXT PRIMARY KEY,
                player_name TEXT NOT NULL,
                wallet_balance INTEGER NOT NULL DEFAULT 0 CHECK(wallet_balance >= 0),
                bank_balance INTEGER NOT NULL DEFAULT 0 CHECK(bank_balance >= 0),
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );
        $legacy->exec("INSERT INTO bank_accounts (uuid, player_name, wallet_balance, bank_balance, created_at, updated_at) VALUES ('legacy-uuid', 'LegacyPlayer', 1234, 5678, 10, 20)");
        $legacy->exec('PRAGMA user_version = 1');
        $legacy->close();

        $migrated = new BankRepository($path);
        try{
            $account = $migrated->getAccount('legacy-uuid');
            self::assertNotNull($account);
            self::assertSame(1234, $account->walletBalance);
            self::assertSame(5678, $account->bankBalance);
            self::assertNull($account->xuid);

            $property = new \ReflectionProperty(BankRepository::class, 'database');
            $database = $property->getValue($migrated);
            self::assertInstanceOf(\SQLite3::class, $database);
            self::assertSame(2, (int) $database->querySingle('PRAGMA user_version'));
            self::assertSame(1, (int) $database->querySingle("SELECT COUNT(*) FROM pragma_table_info('bank_accounts') WHERE name = 'xuid'"));
        }finally{
            $migrated->close();
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

}
