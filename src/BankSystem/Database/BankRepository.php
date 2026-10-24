<?php

declare(strict_types=1);

namespace BankSystem\Database;

use BankSystem\BankAccount;
use BankSystem\BankException;
use RuntimeException;
use Throwable;

final class BankRepository{
    private \SQLite3 $database;
    private string $path;

    public function __construct(string $path){
        // SQLite may create the main file and WAL/SHM sidecars while opening and migrating.
        // Use a restrictive process umask for that entire window on POSIX systems, then
        // restore the caller's umask even if initialization fails.
        $restrictCreation = DIRECTORY_SEPARATOR !== '\\' && $path !== ':memory:';
        $previousUmask = $restrictCreation ? umask(0077) : null;

        try{
            $directory = dirname($path);
            if(!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)){
                throw new RuntimeException('Could not create the bank database directory.');
            }

            $this->path = $path;
            $this->prepareStorageFiles();
            $this->database = new \SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            try{
                $this->database->enableExceptions(true);
                $this->database->busyTimeout(5000);
                $this->database->exec('PRAGMA journal_mode = WAL');
                $this->database->exec('PRAGMA synchronous = FULL');
                $this->database->exec('PRAGMA foreign_keys = ON');
                // Balance data and transaction history must not be world-readable on POSIX systems.
                $this->secureStoragePermissions();
                $this->migrate();
            }catch(Throwable $exception){
                $this->database->close();
                throw $exception;
            }
        }finally{
            if($previousUmask !== null){
                umask($previousUmask);
            }
        }
    }

    /**
     * Protects storage files before SQLite opens them. This prevents an existing
     * permissive file or a symbolic-link target from being followed during startup.
     *
     * New POSIX database files are created under the restrictive umask set by the
     * constructor. Existing WAL/SHM files are protected before SQLite can read them.
     */
    private function prepareStorageFiles() : void{
        if($this->path === ':memory:'){
            return;
        }

        $files = [$this->path, $this->path . '-wal', $this->path . '-shm'];
        foreach($files as $file){
            if(is_link($file)){
                throw new RuntimeException('Bank storage files must not be symbolic links: ' . basename($file));
            }
            if(file_exists($file) && !is_file($file)){
                throw new RuntimeException('Bank storage paths must be regular files: ' . basename($file));
            }
        }

        if(!file_exists($this->path) && (file_exists($this->path . '-wal') || file_exists($this->path . '-shm'))){
            throw new RuntimeException('A SQLite sidecar exists without its main bank database file.');
        }

        if(DIRECTORY_SEPARATOR === '\\'){
            // Windows file permissions are inherited from directory ACLs.
            return;
        }

        foreach($files as $file){
            if(is_file($file) && !@chmod($file, 0600)){
                throw new RuntimeException('Could not restrict permissions on bank storage file: ' . basename($file));
            }
        }

        if(file_exists($this->path)){
            return;
        }

        $handle = @fopen($this->path, 'x+b');
        if($handle === false){
            // Another process may have created the file between the checks above.
            if(is_link($this->path) || !is_file($this->path) || !@chmod($this->path, 0600)){
                throw new RuntimeException('Could not securely create the bank database file.');
            }
            return;
        }

        try{
            if(!@chmod($this->path, 0600)){
                throw new RuntimeException('Could not restrict permissions on the new bank database file.');
            }
        }finally{
            fclose($handle);
        }
    }

    public function close() : void{
        $this->database->close();
    }

    /**
     * Restricts SQLite data and sidecar files to the server account on POSIX systems.
     * Windows file access is controlled by filesystem ACLs instead.
     *
     * @throws RuntimeException if an existing file cannot be protected
     */
    private function secureStoragePermissions() : void{
        if(DIRECTORY_SEPARATOR === '\\'){
            return;
        }

        foreach([$this->path, $this->path . '-wal', $this->path . '-shm'] as $file){
            if(is_link($file)){
                throw new RuntimeException('Bank storage files must not be symbolic links: ' . basename($file));
            }
            if(is_file($file) && !@chmod($file, 0600)){
                throw new RuntimeException('Could not restrict permissions on bank storage file: ' . basename($file));
            }
        }
    }

    private function migrate() : void{
        // SQLite DDL and user_version changes are transactional. Keep the schema and its
        // version marker in one write transaction so interrupted migrations can be retried.
        $this->database->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;

        try{
            $version = (int) $this->database->querySingle('PRAGMA user_version');
            if($version > 1){
                throw new RuntimeException('The bank database was created by a newer BankSystem version.');
            }

            $this->database->exec(
                'CREATE TABLE IF NOT EXISTS bank_accounts (
                    uuid TEXT PRIMARY KEY,
                    player_name TEXT NOT NULL,
                    wallet_balance INTEGER NOT NULL DEFAULT 0 CHECK(wallet_balance >= 0),
                    bank_balance INTEGER NOT NULL DEFAULT 0 CHECK(bank_balance >= 0),
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )'
            );
            $this->database->exec(
                'CREATE TABLE IF NOT EXISTS bank_transactions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    transaction_id TEXT NOT NULL UNIQUE,
                    actor_uuid TEXT NOT NULL,
                    actor_name TEXT NOT NULL,
                    from_uuid TEXT NOT NULL,
                    from_name TEXT NOT NULL,
                    to_uuid TEXT NOT NULL,
                    to_name TEXT NOT NULL,
                    action TEXT NOT NULL,
                    amount_minor INTEGER NOT NULL CHECK(amount_minor >= 0),
                    from_bucket TEXT NOT NULL CHECK(from_bucket IN (\'wallet\', \'bank\')),
                    to_bucket TEXT NOT NULL CHECK(to_bucket IN (\'wallet\', \'bank\')),
                    from_balance_before INTEGER NOT NULL,
                    from_balance_after INTEGER NOT NULL,
                    to_balance_before INTEGER NOT NULL,
                    to_balance_after INTEGER NOT NULL,
                    created_at INTEGER NOT NULL
                )'
            );
            $this->database->exec('CREATE INDEX IF NOT EXISTS idx_bank_transactions_from ON bank_transactions(from_uuid, id DESC)');
            $this->database->exec('CREATE INDEX IF NOT EXISTS idx_bank_transactions_to ON bank_transactions(to_uuid, id DESC)');
            $this->database->exec('CREATE INDEX IF NOT EXISTS idx_bank_transactions_actor ON bank_transactions(actor_uuid, id DESC)');
            $this->database->exec('PRAGMA user_version = 1');
            $this->secureStoragePermissions();
            $this->database->exec('COMMIT');
            $transactionOpen = false;
        }catch(Throwable $exception){
            if($transactionOpen){
                try{
                    $this->database->exec('ROLLBACK');
                }catch(Throwable){
                }
            }
            throw $exception;
        }
    }

    public function ensureAccount(string $uuid, string $playerName, int $initialWallet, int $initialBank) : void{
        $now = time();
        $this->execute(
            'INSERT INTO bank_accounts (uuid, player_name, wallet_balance, bank_balance, created_at, updated_at)
             VALUES (:uuid, :player_name, :wallet, :bank, :created_at, :updated_at)
             ON CONFLICT(uuid) DO UPDATE SET player_name = excluded.player_name WHERE bank_accounts.player_name <> excluded.player_name',
            [
                ':uuid' => $uuid,
                ':player_name' => $playerName,
                ':wallet' => $initialWallet,
                ':bank' => $initialBank,
                ':created_at' => $now,
                ':updated_at' => $now
            ]
        );
    }

    public function getAccount(string $uuid) : ?BankAccount{
        $row = $this->fetchOne('SELECT * FROM bank_accounts WHERE uuid = :uuid', [':uuid' => $uuid]);
        return $row === null ? null : $this->accountFromRow($row);
    }

    /**
     * Moves money between two account buckets and writes the ledger row atomically.
     *
     * @return array<string, mixed>
     */
    public function move(
        string $actorUuid,
        string $actorName,
        string $fromUuid,
        string $fromName,
        string $toUuid,
        string $toName,
        string $action,
        int $amount,
        string $fromBucket,
        string $toBucket,
        int $maximumTransactionMinor
    ) : array{
        if($amount <= 0){
            throw new BankException('The amount must be greater than zero.');
        }
        if($maximumTransactionMinor < 0 || $amount > $maximumTransactionMinor){
            throw new BankException('The amount exceeds the configured transaction limit.');
        }

        $fromColumn = $this->columnForBucket($fromBucket);
        $toColumn = $this->columnForBucket($toBucket);
        if($fromUuid === $toUuid && $fromColumn === $toColumn){
            throw new BankException('You cannot transfer money to the same balance.');
        }

        $this->database->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try{
            $source = $this->fetchOne('SELECT * FROM bank_accounts WHERE uuid = :uuid', [':uuid' => $fromUuid]);
            $target = $this->fetchOne('SELECT * FROM bank_accounts WHERE uuid = :uuid', [':uuid' => $toUuid]);
            if($source === null || $target === null){
                throw new BankException('A bank account could not be found.');
            }

            $fromBefore = (int) $source[$fromColumn];
            $toBefore = (int) $target[$toColumn];
            if($fromBefore < $amount){
                throw new BankException('There are not enough funds in that balance.');
            }
            if($toBefore > PHP_INT_MAX - $amount){
                throw new BankException('The destination balance would exceed the supported limit.');
            }

            $now = time();
            $sourceChanges = $this->execute(
                'UPDATE bank_accounts SET ' . $fromColumn . ' = ' . $fromColumn . ' - :amount, updated_at = :now
                 WHERE uuid = :uuid AND ' . $fromColumn . ' >= :minimum',
                [':amount' => $amount, ':minimum' => $amount, ':now' => $now, ':uuid' => $fromUuid]
            );
            if($sourceChanges !== 1){
                throw new BankException('The source balance changed unexpectedly; please retry.');
            }

            $targetChanges = $this->execute(
                'UPDATE bank_accounts SET ' . $toColumn . ' = ' . $toColumn . ' + :amount, updated_at = :now
                 WHERE uuid = :uuid AND ' . $toColumn . ' <= :maximum',
                [':amount' => $amount, ':maximum' => PHP_INT_MAX - $amount, ':now' => $now, ':uuid' => $toUuid]
            );
            if($targetChanges !== 1){
                throw new BankException('The destination balance could not be updated safely.');
            }

            $record = [
                'transaction_id' => bin2hex(random_bytes(16)),
                'actor_uuid' => $actorUuid,
                'actor_name' => $actorName,
                'from_uuid' => $fromUuid,
                'from_name' => $fromName,
                'to_uuid' => $toUuid,
                'to_name' => $toName,
                'action' => $action,
                'amount_minor' => $amount,
                'from_bucket' => $fromBucket,
                'to_bucket' => $toBucket,
                'from_balance_before' => $fromBefore,
                'from_balance_after' => $fromBefore - $amount,
                'to_balance_before' => $toBefore,
                'to_balance_after' => $toBefore + $amount,
                'created_at' => $now
            ];
            $record['id'] = $this->insertTransaction($record);
            $this->secureStoragePermissions();
            $this->database->exec('COMMIT');
            $transactionOpen = false;
            return $record;
        }catch(Throwable $exception){
            if($transactionOpen){
                try{
                    $this->database->exec('ROLLBACK');
                }catch(Throwable){
                }
            }
            throw $exception;
        }
    }

    /**
     * Adjusts or sets a balance and writes the matching audit row atomically.
     *
     * @return array<string, mixed>
     */
    public function adjustBalance(
        string $actorUuid,
        string $actorName,
        string $targetUuid,
        string $targetName,
        string $bucket,
        string $mode,
        int $amount,
        int $maximumChangeMinor
    ) : array{
        $column = $this->columnForBucket($bucket);
        if(!in_array($mode, ['give', 'take', 'set'], true)){
            throw new BankException('Unsupported balance adjustment.');
        }
        if($amount < 0 || ($mode !== 'set' && $amount === 0)){
            throw new BankException('The amount must be greater than zero.');
        }
        if($maximumChangeMinor < 0){
            throw new BankException('The configured transaction limit cannot be negative.');
        }

        $this->database->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try{
            $account = $this->fetchOne('SELECT * FROM bank_accounts WHERE uuid = :uuid', [':uuid' => $targetUuid]);
            if($account === null){
                throw new BankException('The target bank account could not be found.');
            }

            $before = (int) $account[$column];
            if($mode === 'give'){
                if($before > PHP_INT_MAX - $amount){
                    throw new BankException('The new balance would exceed the supported limit.');
                }
                $after = $before + $amount;
            }elseif($mode === 'take'){
                if($before < $amount){
                    throw new BankException('The account does not have enough funds.');
                }
                $after = $before - $amount;
            }else{
                $after = $amount;
            }

            // Enforce the cap against the actual balance read under BEGIN IMMEDIATE.
            // For "set", the configured cap applies to the delta, not the final balance.
            $changeMinor = abs($after - $before);
            if($changeMinor > $maximumChangeMinor){
                throw new BankException('The balance change exceeds the configured transaction limit.');
            }

            $now = time();
            $changes = $this->execute(
                'UPDATE bank_accounts SET ' . $column . ' = :balance, updated_at = :now WHERE uuid = :uuid',
                [':balance' => $after, ':now' => $now, ':uuid' => $targetUuid]
            );
            if($changes !== 1){
                throw new BankException('The balance could not be updated.');
            }

            $record = [
                'transaction_id' => bin2hex(random_bytes(16)),
                'actor_uuid' => $actorUuid,
                'actor_name' => $actorName,
                'from_uuid' => $targetUuid,
                'from_name' => $targetName,
                'to_uuid' => $targetUuid,
                'to_name' => $targetName,
                'action' => 'admin_' . $mode . '_' . $bucket,
                'amount_minor' => $changeMinor,
                'from_bucket' => $bucket,
                'to_bucket' => $bucket,
                'from_balance_before' => $before,
                'from_balance_after' => $after,
                'to_balance_before' => $before,
                'to_balance_after' => $after,
                'created_at' => $now
            ];
            $record['id'] = $this->insertTransaction($record);
            $this->secureStoragePermissions();
            $this->database->exec('COMMIT');
            $transactionOpen = false;
            return $record;
        }catch(Throwable $exception){
            if($transactionOpen){
                try{
                    $this->database->exec('ROLLBACK');
                }catch(Throwable){
                }
            }
            throw $exception;
        }
    }

    /**
     * @return list<BankAccount>
     */
    public function getTopAccounts(int $limit) : array{
        $limit = max(1, min(100, $limit));
        $rows = $this->fetchAll(
            'SELECT * FROM bank_accounts ORDER BY bank_balance DESC, player_name COLLATE NOCASE ASC LIMIT :limit',
            [':limit' => $limit]
        );

        return array_map(fn(array $row) : BankAccount => $this->accountFromRow($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHistory(string $uuid, int $limit, int $offset) : array{
        $limit = max(1, min(50, $limit));
        $offset = max(0, min(100000, $offset));

        return $this->fetchAll(
            'SELECT * FROM bank_transactions
             WHERE from_uuid = :from_uuid OR to_uuid = :to_uuid OR actor_uuid = :actor_uuid
             ORDER BY id DESC LIMIT :limit OFFSET :offset',
            [
                ':from_uuid' => $uuid,
                ':to_uuid' => $uuid,
                ':actor_uuid' => $uuid,
                ':limit' => $limit,
                ':offset' => $offset
            ]
        );
    }

    private function columnForBucket(string $bucket) : string{
        return match($bucket){
            'wallet' => 'wallet_balance',
            'bank' => 'bank_balance',
            default => throw new BankException('Balance type must be wallet or bank.')
        };
    }

    /**
     * @param array<string, mixed> $record
     */
    private function insertTransaction(array $record) : int{
        $this->execute(
            'INSERT INTO bank_transactions (
                transaction_id, actor_uuid, actor_name, from_uuid, from_name, to_uuid, to_name, action,
                amount_minor, from_bucket, to_bucket, from_balance_before, from_balance_after,
                to_balance_before, to_balance_after, created_at
             ) VALUES (
                :transaction_id, :actor_uuid, :actor_name, :from_uuid, :from_name, :to_uuid, :to_name, :action,
                :amount_minor, :from_bucket, :to_bucket, :from_balance_before, :from_balance_after,
                :to_balance_before, :to_balance_after, :created_at
             )',
            [
                ':transaction_id' => $record['transaction_id'],
                ':actor_uuid' => $record['actor_uuid'],
                ':actor_name' => $record['actor_name'],
                ':from_uuid' => $record['from_uuid'],
                ':from_name' => $record['from_name'],
                ':to_uuid' => $record['to_uuid'],
                ':to_name' => $record['to_name'],
                ':action' => $record['action'],
                ':amount_minor' => $record['amount_minor'],
                ':from_bucket' => $record['from_bucket'],
                ':to_bucket' => $record['to_bucket'],
                ':from_balance_before' => $record['from_balance_before'],
                ':from_balance_after' => $record['from_balance_after'],
                ':to_balance_before' => $record['to_balance_before'],
                ':to_balance_after' => $record['to_balance_after'],
                ':created_at' => $record['created_at']
            ]
        );

        return (int) $this->database->lastInsertRowID();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function accountFromRow(array $row) : BankAccount{
        return new BankAccount(
            (string) $row['uuid'],
            (string) $row['player_name'],
            (int) $row['wallet_balance'],
            (int) $row['bank_balance'],
            (int) $row['created_at'],
            (int) $row['updated_at']
        );
    }

    /**
     * @param array<string, string|int|float|null> $parameters
     */
    private function prepareAndBind(string $sql, array $parameters) : \SQLite3Stmt{
        $statement = $this->database->prepare($sql);
        if($statement === false){
            throw new RuntimeException('Failed to prepare a bank database statement.');
        }

        try{
            foreach($parameters as $name => $value){
                $type = match(true){
                    $value === null => SQLITE3_NULL,
                    is_int($value), is_bool($value) => SQLITE3_INTEGER,
                    is_float($value) => SQLITE3_FLOAT,
                    default => SQLITE3_TEXT
                };
                if(!$statement->bindValue((string) $name, $value, $type)){
                    throw new RuntimeException('Failed to bind a bank database parameter.');
                }
            }
        }catch(Throwable $exception){
            $statement->close();
            throw $exception;
        }

        return $statement;
    }

    /**
     * @param array<string, string|int|float|null> $parameters
     */
    private function execute(string $sql, array $parameters = []) : int{
        $statement = $this->prepareAndBind($sql, $parameters);
        try{
            $result = $statement->execute();
            if($result === false){
                throw new RuntimeException('Failed to execute a bank database statement.');
            }
            $result->finalize();
            return $this->database->changes();
        }finally{
            $statement->close();
        }
    }

    /**
     * @param array<string, string|int|float|null> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $parameters = []) : ?array{
        $statement = $this->prepareAndBind($sql, $parameters);
        try{
            $result = $statement->execute();
            if($result === false){
                throw new RuntimeException('Failed to execute a bank database query.');
            }
            try{
                $row = $result->fetchArray(SQLITE3_ASSOC);
                return $row === false ? null : $row;
            }finally{
                $result->finalize();
            }
        }finally{
            $statement->close();
        }
    }

    /**
     * @param array<string, string|int|float|null> $parameters
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $parameters = []) : array{
        $statement = $this->prepareAndBind($sql, $parameters);
        try{
            $result = $statement->execute();
            if($result === false){
                throw new RuntimeException('Failed to execute a bank database query.');
            }

            $rows = [];
            try{
                while(($row = $result->fetchArray(SQLITE3_ASSOC)) !== false){
                    $rows[] = $row;
                }
            }finally{
                $result->finalize();
            }
            return $rows;
        }finally{
            $statement->close();
        }
    }
}
