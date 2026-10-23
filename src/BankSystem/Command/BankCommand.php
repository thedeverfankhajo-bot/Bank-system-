<?php

declare(strict_types=1);

namespace BankSystem\Command;

use BankSystem\BankAccount;
use BankSystem\BankException;
use BankSystem\Main;
use BankSystem\Service\BankService;
use BankSystem\Support\Money;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use Throwable;

final class BankCommand{
    /** @var array<string, float> */
    private array $lastTransactionAt = [];

    public function __construct(
        private readonly Main $plugin,
        private readonly BankService $service
    ){
    }

    /**
     * @param list<string> $args
     */
    public function execute(CommandSender $sender, array $args) : bool{
        if(!$sender->hasPermission('banksystem.use')){
            $this->message($sender, 'You do not have permission to use banking.');
            return true;
        }

        $subcommand = strtolower($args[0] ?? 'balance');
        switch($subcommand){
            case 'help':
                $this->showHelp($sender);
                return true;
            case 'balance':
            case 'bal':
                $this->handleBalance($sender, $args);
                return true;
            case 'deposit':
            case 'dep':
                $this->handleDeposit($sender, $args);
                return true;
            case 'withdraw':
            case 'wd':
                $this->handleWithdraw($sender, $args);
                return true;
            case 'pay':
            case 'transfer':
                $this->handlePay($sender, $args);
                return true;
            case 'top':
                $this->handleTop($sender);
                return true;
            case 'history':
                $this->handleHistory($sender, $args);
                return true;
            case 'admin':
                $this->handleAdmin($sender, array_slice($args, 1));
                return true;
            default:
                $this->showHelp($sender);
                return true;
        }
    }

    /**
     * @param list<string> $args
     */
    private function handleBalance(CommandSender $sender, array $args) : void{
        if(isset($args[1])){
            if(!$sender->hasPermission('banksystem.admin')){
                $this->message($sender, 'Only administrators can inspect another player account.');
                return;
            }
            $target = $this->findOnlinePlayer($sender, $args[1]);
            if($target === null){
                return;
            }
            $player = $target;
        }else{
            $player = $this->requirePlayer($sender);
            if($player === null){
                return;
            }
        }

        try{
            $account = $this->service->getAccount($player->getUniqueId()->toString(), $player->getName());
            $this->showBalance($sender, $account);
        }catch(Throwable $exception){
            $this->logReadError('balance', $exception);
            $this->message($sender, 'Could not read the account. Check the server log.');
        }
    }

    /**
     * @param list<string> $args
     */
    private function handleDeposit(CommandSender $sender, array $args) : void{
        $player = $this->requirePlayer($sender);
        if($player === null){
            return;
        }
        if(count($args) !== 2){
            $this->message($sender, 'Usage: /bank deposit <amount>');
            return;
        }
        $amount = $this->parseAmount($sender, $args[1]);
        if($amount === null){
            return;
        }

        $this->runTransaction($sender, function() use ($player, $amount) : void{
            $this->service->deposit($player->getUniqueId()->toString(), $player->getName(), $amount);
            $this->message($player, 'Deposited ' . $this->formatMoney($amount) . ' into your bank.');
        });
    }

    /**
     * @param list<string> $args
     */
    private function handleWithdraw(CommandSender $sender, array $args) : void{
        $player = $this->requirePlayer($sender);
        if($player === null){
            return;
        }
        if(count($args) !== 2){
            $this->message($sender, 'Usage: /bank withdraw <amount>');
            return;
        }
        $amount = $this->parseAmount($sender, $args[1]);
        if($amount === null){
            return;
        }

        $this->runTransaction($sender, function() use ($player, $amount) : void{
            $this->service->withdraw($player->getUniqueId()->toString(), $player->getName(), $amount);
            $this->message($player, 'Withdrew ' . $this->formatMoney($amount) . ' into your wallet.');
        });
    }

    /**
     * @param list<string> $args
     */
    private function handlePay(CommandSender $sender, array $args) : void{
        $player = $this->requirePlayer($sender);
        if($player === null){
            return;
        }
        if(count($args) !== 3){
            $this->message($sender, 'Usage: /bank pay <online-player> <amount>');
            return;
        }
        $target = $this->findOnlinePlayer($sender, $args[1]);
        if($target === null){
            return;
        }
        $amount = $this->parseAmount($sender, $args[2]);
        if($amount === null){
            return;
        }

        $this->runTransaction($sender, function() use ($player, $target, $amount) : void{
            $this->service->transfer(
                $player->getUniqueId()->toString(),
                $player->getName(),
                $target->getUniqueId()->toString(),
                $target->getName(),
                $amount
            );
            $this->message($player, 'Sent ' . $this->formatMoney($amount) . ' to ' . $target->getName() . '.');
            $this->message($target, 'Received ' . $this->formatMoney($amount) . ' from ' . $player->getName() . '.');
        });
    }

    private function handleTop(CommandSender $sender) : void{
        try{
            $accounts = $this->service->getTopAccounts(10);
            if($accounts === []){
                $this->message($sender, 'No bank accounts have been created yet.');
                return;
            }
            $this->message($sender, 'Top bank balances:');
            foreach($accounts as $index => $account){
                $this->message(
                    $sender,
                    '#' . ($index + 1) . ' ' . $account->playerName . ': ' . $this->formatMoney($account->bankBalance)
                );
            }
        }catch(Throwable $exception){
            $this->logReadError('leaderboard', $exception);
            $this->message($sender, 'Could not load the leaderboard. Check the server log.');
        }
    }

    /**
     * @param list<string> $args
     */
    private function handleHistory(CommandSender $sender, array $args) : void{
        $player = $this->requirePlayer($sender);
        if($player === null){
            return;
        }

        $page = 1;
        if(isset($args[1])){
            if(!ctype_digit($args[1]) || (int) $args[1] < 1 || (int) $args[1] > 5000){
                $this->message($sender, 'Page must be a whole number between 1 and 5000.');
                return;
            }
            $page = (int) $args[1];
        }
        $perPage = (int) $this->plugin->getConfig()->getNested('history.entries-per-page', 10);
        $perPage = max(1, min(20, $perPage));

        try{
            $uuid = $player->getUniqueId()->toString();
            $rows = $this->service->getHistory($uuid, $perPage, ($page - 1) * $perPage);
            if($rows === []){
                $this->message($sender, 'No transactions found on page ' . $page . '.');
                return;
            }

            $this->message($sender, 'Bank history - page ' . $page . ':');
            foreach($rows as $row){
                $line = '#' . $row['id'] . ' ' . strtoupper((string) $row['action']) . ' ' .
                    $this->formatMoney((int) $row['amount_minor']) . ' (' .
                    (string) $row['from_name'] . ' -> ' . (string) $row['to_name'] . ') ' .
                    date('Y-m-d H:i', (int) $row['created_at']);
                $this->message($sender, $line);
            }
        }catch(Throwable $exception){
            $this->logReadError('history', $exception);
            $this->message($sender, 'Could not load transaction history. Check the server log.');
        }
    }

    /**
     * @param list<string> $args
     */
    private function handleAdmin(CommandSender $sender, array $args) : void{
        if(!$sender->hasPermission('banksystem.admin')){
            $this->message($sender, 'You do not have permission to administer bank balances.');
            return;
        }
        if(count($args) !== 4){
            $this->message($sender, 'Usage: /bank admin <give|take|set> <online-player> <wallet|bank> <amount>');
            return;
        }

        $mode = strtolower($args[0]);
        $bucket = strtolower($args[2]);
        if(!in_array($mode, ['give', 'take', 'set'], true)){
            $this->message($sender, 'Admin action must be give, take, or set.');
            return;
        }
        if(!in_array($bucket, ['wallet', 'bank'], true)){
            $this->message($sender, 'Balance type must be wallet or bank.');
            return;
        }

        $target = $this->findOnlinePlayer($sender, $args[1]);
        if($target === null){
            return;
        }
        $amount = $this->parseAmount($sender, $args[3], $mode === 'set');
        if($amount === null){
            return;
        }

        $actorUuid = $sender instanceof Player ? $sender->getUniqueId()->toString() : 'console';
        $actorName = $sender->getName();

        $this->runTransaction($sender, function() use ($sender, $target, $mode, $bucket, $amount, $actorUuid, $actorName) : void{
            $targetUuid = $target->getUniqueId()->toString();
            $targetName = $target->getName();
            if($mode === 'give'){
                $this->service->give($targetUuid, $targetName, $bucket, $amount, $actorUuid, $actorName);
            }elseif($mode === 'take'){
                $this->service->take($targetUuid, $targetName, $bucket, $amount, $actorUuid, $actorName);
            }else{
                $this->service->setBalance($targetUuid, $targetName, $bucket, $amount, $actorUuid, $actorName);
            }
            if($mode === 'set'){
                $this->message($sender, 'Admin set: ' . $targetName . ' ' . $bucket . ' balance set to ' . $this->formatMoney($amount) . '.');
            }else{
                $verb = $mode === 'give' ? 'Added ' : 'Removed ';
                $this->message($sender, 'Admin ' . $mode . ': ' . $verb . $this->formatMoney($amount) . ' ' . ($mode === 'give' ? 'to ' : 'from ') . $targetName . ' ' . $bucket . '.');
            }
            if($target !== $sender){
                $this->message($target, 'An administrator updated your ' . $bucket . ' balance.');
            }
        });
    }

    private function showBalance(CommandSender $sender, BankAccount $account) : void{
        $this->message($sender, 'Balance for ' . $account->playerName . ':');
        $this->message($sender, 'Wallet: ' . $this->formatMoney($account->walletBalance));
        $this->message($sender, 'Bank: ' . $this->formatMoney($account->bankBalance));
        $this->message($sender, 'Total: ' . $this->formatMoney($account->totalBalance()));
    }

    private function showHelp(CommandSender $sender) : void{
        $this->message($sender, 'Commands: /bank balance, /bank deposit <amount>, /bank withdraw <amount>');
        $this->message($sender, '/bank pay <online-player> <amount>, /bank top, /bank history [page]');
        $this->message($sender, 'Admin: /bank admin <give|take|set> <online-player> <wallet|bank> <amount>');
    }

    private function requirePlayer(CommandSender $sender) : ?Player{
        if(!($sender instanceof Player)){
            $this->message($sender, 'This command requires a player. Console may use /bank admin with an online target.');
            return null;
        }
        return $sender;
    }

    private function findOnlinePlayer(CommandSender $sender, string $name) : ?Player{
        $target = $this->plugin->getServer()->getPlayerExact($name);
        if($target === null){
            $this->message($sender, 'Player ' . $name . ' is not online. Use the exact in-game name.');
            return null;
        }
        return $target;
    }

    private function parseAmount(CommandSender $sender, string $text, bool $allowZero = false) : ?int{
        $amount = Money::parse($text, $allowZero);
        if($amount === null){
            $this->message($sender, $allowZero
                ? 'Invalid amount. Use 0 or a non-negative number with at most two decimal places.'
                : 'Invalid amount. Use a positive number with at most two decimal places.');
            return null;
        }
        return $amount;
    }

    private function runTransaction(CommandSender $sender, callable $operation) : void{
        $playerUuid = $sender instanceof Player ? $sender->getUniqueId()->toString() : null;
        $cooldown = (int) $this->plugin->getConfig()->getNested('security.transaction-cooldown-seconds', 1);
        if($playerUuid !== null && $cooldown > 0){
            $last = $this->lastTransactionAt[$playerUuid] ?? 0.0;
            if(microtime(true) - $last < $cooldown){
                $this->message($sender, 'Please wait before making another transaction.');
                return;
            }
        }

        try{
            $operation();
            if($playerUuid !== null && $cooldown > 0){
                $this->lastTransactionAt[$playerUuid] = microtime(true);
            }
        }catch(BankException $exception){
            $this->message($sender, $exception->getMessage());
        }catch(Throwable $exception){
            $this->plugin->getLogger()->critical('Bank transaction failed: ' . $exception->getMessage());
            $this->message($sender, 'The transaction failed due to an internal error. Check the server log.');
        }
    }

    private function formatMoney(int $amountMinor) : string{
        return Money::format($amountMinor, $this->plugin->getCurrencySymbol());
    }

    private function message(CommandSender $sender, string $message) : void{
        $sender->sendMessage('§6[Bank] §r' . $message);
    }

    private function logReadError(string $context, Throwable $exception) : void{
        $this->plugin->getLogger()->error('Failed to read ' . $context . ': ' . $exception->getMessage());
    }
}
