<?php

declare(strict_types=1);

namespace BankSystem\Command;

use BankSystem\BankAccount;
use BankSystem\BankException;
use BankSystem\Main;
use BankSystem\Service\BankService;
use BankSystem\Support\Money;
use BankSystem\Support\PlayerIdentity;
use BankSystem\UI\BankHistoryPage;
use BankSystem\UI\BankPanelForm;
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

        $subcommand = strtolower($args[0] ?? 'panel');
        switch($subcommand){
            case 'help':
                $this->showHelp($sender);
                return true;
            case 'panel':
            case 'menu':
            case 'gui':
                $player = $this->requirePlayer($sender);
                if($player !== null){
                    $this->openPanel($player);
                }
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
     * Opens the in-game Bedrock form panel. All mutations are delegated to the same
     * transaction handlers used by commands, so permission, amount and cooldown rules
     * remain consistent between both entry points.
     */
    public function openPanel(Player $player) : void{
        if(!$player->hasPermission('banksystem.use')){
            $this->message($player, 'You do not have permission to use banking.');
            return;
        }

        try{
            $account = $this->service->getAccount($player->getUniqueId()->toString(), $player->getName());
            $buttons = [
                ['text' => '§a[1] §fDeposit to bank', 'action' => 'deposit'],
                ['text' => '§e[2] §fWithdraw to wallet', 'action' => 'withdraw'],
                ['text' => '§b[3] §fSend money to player', 'action' => 'pay'],
                ['text' => '§6[4] §fTransaction history', 'action' => 'history'],
                ['text' => '§d[5] §fBank leaderboard', 'action' => 'top']
            ];
            if($player->hasPermission('banksystem.admin')){
                $buttons[] = ['text' => '§c[+] §fAdministrator tools', 'action' => 'admin'];
            }

            $content = "§8==============================\n" .
                "§6§lACCOUNT OVERVIEW\n" .
                "§8==============================\n\n" .
                "§7WALLET BALANCE\n§f" . $this->formatMoney($account->walletBalance) . "\n\n" .
                "§7BANK SAVINGS\n§a" . $this->formatMoney($account->bankBalance) . "\n\n" .
                "§8------------------------------\n" .
                "§7TOTAL FUNDS  §f§l" . $this->formatMoney($account->totalBalance()) . "\n" .
                "§8------------------------------\n\n" .
                "§7Choose a service below.\n" .
                "§8Your account is linked to your player UUID.";

            $player->sendForm(BankPanelForm::simple(
                '§l§6BANKSYSTEM §8• §fBANKING',
                $content,
                $buttons,
                function(Player $formPlayer, string $action) : void{
                    $this->openPanelPage($formPlayer, $action);
                }
            ));
        }catch(Throwable $exception){
            $this->logReadError('panel', $exception);
            $this->message($player, 'Could not open the bank panel. Check the server log.');
        }
    }

    private function openPanelPage(Player $player, string $page) : void{
        if(!$player->hasPermission('banksystem.use')){
            $this->message($player, 'You do not have permission to use banking.');
            return;
        }

        if(preg_match('/^history_page_([0-9]+)$/D', $page, $matches) === 1){
            $this->openHistoryPanel($player, BankHistoryPage::clampPage((int) $matches[1]));
            return;
        }

        switch($page){
            case 'menu':
                $this->openPanel($player);
                return;
            case 'deposit':
                $this->showAmountPanel($player, 'deposit', 'Deposit to bank', 'Enter a positive amount to move from your wallet into the bank.');
                return;
            case 'withdraw':
                $this->showAmountPanel($player, 'withdraw', 'Withdraw to wallet', 'Enter a positive amount to move from your bank into your wallet.');
                return;
            case 'pay':
                $player->sendForm(BankPanelForm::custom(
                    '§l§6BANKSYSTEM §8• §fTRANSFER',
                    [
                        ['type' => 'input', 'text' => 'Exact online player name', 'placeholder' => 'PlayerName', 'default' => ''],
                        ['type' => 'input', 'text' => 'Amount', 'placeholder' => '10.00', 'default' => '']
                    ],
                    function(Player $formPlayer, mixed $data) : void{
                        $this->handlePanelSubmission($formPlayer, 'pay', $data);
                    }
                ));
                return;
            case 'history':
                $this->openHistoryPanel($player, 1);
                return;
            case 'top':
                $this->openLeaderboardPanel($player);
                return;
            case 'admin':
                $this->openAdminPanel($player);
                return;
            default:
                $this->openPanel($player);
                return;
        }
    }

    private function showAmountPanel(Player $player, string $action, string $title, string $description) : void{
        $formTitle = $action === 'deposit'
            ? '§l§6BANKSYSTEM §8• §fDEPOSIT'
            : '§l§6BANKSYSTEM §8• §fWITHDRAW';

        $player->sendForm(BankPanelForm::custom(
            $formTitle,
            [
                ['type' => 'label', 'text' => "§6BANKSYSTEM\n§7" . $description],
                ['type' => 'input', 'text' => 'Amount (up to 2 decimal places)', 'placeholder' => '10.00', 'default' => '']
            ],
            function(Player $formPlayer, mixed $data) use ($action) : void{
                $this->handlePanelSubmission($formPlayer, $action, $data);
            }
        ));
    }

    private function openHistoryPanel(Player $player, int $page = 1) : void{
        try{
            $perPage = (int) $this->plugin->getConfig()->getNested('history.entries-per-page', 10);
            $perPage = max(1, min(20, $perPage));
            $page = BankHistoryPage::clampPage($page);
            // Fetch one extra row to determine whether a forward page exists.
            $rows = $this->service->getHistory(
                $player->getUniqueId()->toString(),
                $perPage + 1,
                ($page - 1) * $perPage
            );
            $historyPage = BankHistoryPage::fromRows($page, $perPage, $rows);
            $entries = $historyPage->entries;

            $content = $entries === []
                ? ($page === 1 ? '§7No transactions have been recorded yet.' : '§7There are no transactions on this page.')
                : "§7Recent account activity\n§8Page " . $historyPage->page . "\n§8------------------------------";

            foreach($entries as $row){
                $action = (string) $row['action'];
                $actionColor = match($action){
                    'deposit' => '§a',
                    'withdraw' => '§c',
                    'pay' => '§b',
                    default => str_starts_with($action, 'admin_') ? '§d' : '§e'
                };
                $content .= "\n\n§8#" . (int) $row['id'] . ' ' .
                    $actionColor . strtoupper($action) . ' §f' .
                    $this->formatMoney((int) $row['amount_minor']) .
                    "\n§7" . (string) $row['from_name'] . ' §8> §7' . (string) $row['to_name'] .
                    "\n§8" . date('Y-m-d H:i', (int) $row['created_at']);
            }

            $buttons = [];
            if($historyPage->hasPrevious){
                $buttons[] = ['text' => '§e< §fPrevious page', 'action' => 'history_page_' . ($historyPage->page - 1)];
            }
            if($historyPage->hasNext){
                $buttons[] = ['text' => '§a> §fNext page', 'action' => 'history_page_' . ($historyPage->page + 1)];
            }
            $buttons[] = ['text' => '§6[0] §fBack to BankSystem', 'action' => 'menu'];

            $this->showPanelInformation(
                $player,
                '§l§6BANKSYSTEM §8• §fHISTORY §7(' . $historyPage->page . ')',
                $content,
                $buttons
            );
        }catch(Throwable $exception){
            $this->logReadError('panel history', $exception);
            $this->showPanelInformation(
                $player,
                '§l§6BANKSYSTEM §8• §fHISTORY',
                '§cCould not load transaction history. Check the server log.'
            );
        }
    }

    private function openLeaderboardPanel(Player $player) : void{
        try{
            $accounts = $this->service->getTopAccounts(10);
            $content = $accounts === []
                ? '§7No bank accounts have been created yet.'
                : "§7Ranked by bank savings\n§8------------------------------";
            foreach($accounts as $index => $account){
                $rankColor = match($index){
                    0 => '§6§l',
                    1 => '§f§l',
                    2 => '§c§l',
                    default => '§e'
                };
                $content .= "\n\n" . $rankColor . '#' . ($index + 1) . ' §f' . $account->playerName .
                    "\n§7BANK SAVINGS  §a" . $this->formatMoney($account->bankBalance);
            }
            $this->showPanelInformation(
                $player,
                '§l§6BANKSYSTEM §8• §fLEADERBOARD',
                $content
            );
        }catch(Throwable $exception){
            $this->logReadError('panel leaderboard', $exception);
            $this->showPanelInformation($player, 'Bank leaderboard', '§cCould not load the leaderboard. Check the server log.');
        }
    }

    /**
     * @param list<array{text: string, action: string}> $buttons
     */
    private function showPanelInformation(Player $player, string $title, string $content, array $buttons = []) : void{
        if($buttons === []){
            $buttons = [['text' => '§6[0] §fBack to BankSystem', 'action' => 'menu']];
        }

        $player->sendForm(BankPanelForm::simple(
            $title,
            $content,
            $buttons,
            function(Player $formPlayer, string $action) : void{
                $this->openPanelPage($formPlayer, $action);
            }
        ));
    }

    private function openAdminPanel(Player $player) : void{
        // Never rely on the visibility of a button as an authorization check.
        if(!$player->hasPermission('banksystem.admin')){
            $this->message($player, 'You do not have permission to administer bank balances.');
            $this->openPanel($player);
            return;
        }

        $player->sendForm(BankPanelForm::custom(
            '§l§6BANKSYSTEM §8• §fADMIN',
            [
                ['type' => 'dropdown', 'text' => 'Action', 'options' => ['Give', 'Take', 'Set balance'], 'default' => 0],
                ['type' => 'input', 'text' => 'Exact online player name', 'placeholder' => 'PlayerName', 'default' => ''],
                ['type' => 'dropdown', 'text' => 'Balance type', 'options' => ['Wallet', 'Bank'], 'default' => 1],
                ['type' => 'input', 'text' => 'Amount (Set may be zero)', 'placeholder' => '10.00', 'default' => '']
            ],
            function(Player $formPlayer, mixed $data) : void{
                $this->handlePanelSubmission($formPlayer, 'admin', $data);
            }
        ));
    }

    private function handlePanelSubmission(Player $player, string $action, mixed $data) : void{
        // Permissions may change while a form is open; re-check on every submission.
        if(!$player->hasPermission('banksystem.use')){
            $this->message($player, 'You do not have permission to use banking.');
            return;
        }

        if(!is_array($data)){
            $this->message($player, 'The form response was invalid. Please try again.');
            $this->openPanel($player);
            return;
        }

        if($action === 'deposit' || $action === 'withdraw'){
            $amount = $data[1] ?? null; // Element zero is the explanatory label.
            if(!is_string($amount)){
                $this->message($player, 'Please enter a valid amount.');
            }else{
                if($action === 'deposit'){
                    $this->handleDeposit($player, ['deposit', $amount]);
                }else{
                    $this->handleWithdraw($player, ['withdraw', $amount]);
                }
            }
            $this->openPanel($player);
            return;
        }

        if($action === 'pay'){
            $targetName = $data[0] ?? null;
            $amount = $data[1] ?? null;
            if(!is_string($targetName) || trim($targetName) === '' || strlen($targetName) > 16 || !is_string($amount)){
                $this->message($player, 'Enter a valid online player name and amount.');
                $this->openPanel($player);
                return;
            }
            $this->showTransferConfirmation($player, trim($targetName), $amount);
            return;
        }

        if($action === 'admin'){
            if(!$player->hasPermission('banksystem.admin')){
                $this->message($player, 'You do not have permission to administer bank balances.');
                $this->openPanel($player);
                return;
            }
            $modeIndex = $data[0] ?? null;
            $targetName = $data[1] ?? null;
            $bucketIndex = $data[2] ?? null;
            $amount = $data[3] ?? null;
            if(!is_int($modeIndex) || !isset([0, 1, 2][$modeIndex]) ||
                !is_string($targetName) || trim($targetName) === '' || strlen($targetName) > 16 ||
                !is_int($bucketIndex) || !isset([0, 1][$bucketIndex]) || !is_string($amount)){
                $this->message($player, 'Enter valid administrator form values.');
                $this->openPanel($player);
                return;
            }

            $mode = ['give', 'take', 'set'][$modeIndex];
            $bucket = ['wallet', 'bank'][$bucketIndex];
            $this->showAdminConfirmation($player, $mode, trim($targetName), $bucket, $amount);
        }
    }

    private function showTransferConfirmation(Player $player, string $targetName, string $amountInput) : void{
        $amountMinor = Money::parse($amountInput);
        if($amountMinor === null){
            $this->message($player, 'Invalid amount. Use a positive number with at most two decimal places.');
            $this->openPanel($player);
            return;
        }

        $target = $this->plugin->getServer()->getPlayerExact($targetName);
        if($target === null){
            $this->message($player, 'Player ' . $targetName . ' is no longer online.');
            $this->openPanel($player);
            return;
        }
        if($target->getUniqueId()->toString() === $player->getUniqueId()->toString()){
            $this->message($player, 'You cannot pay yourself.');
            $this->openPanel($player);
            return;
        }

        // A confirmation must remain bound to the account the player actually saw.
        // Names can be reused after disconnect; the UUID cannot be silently substituted.
        $targetName = $target->getName();
        $targetUuid = $target->getUniqueId()->toString();

        $player->sendForm(BankPanelForm::modal(
            '§l§6BANKSYSTEM §8• §fCONFIRM TRANSFER',
            "§eSend §f" . $this->formatMoney($amountMinor) . " §eto §f" . $target->getName() .
                "§e?\n\n§cThis transfer cannot be automatically reversed.",
            'Confirm transfer',
            'Cancel',
            function(Player $formPlayer, bool $confirmed) use ($targetName, $targetUuid, $amountInput) : void{
                if(!$formPlayer->hasPermission('banksystem.use')){
                    $this->message($formPlayer, 'You do not have permission to use banking.');
                    return;
                }
                if($confirmed){
                    $currentTarget = $this->plugin->getServer()->getPlayerExact($targetName);
                    if($currentTarget === null || !PlayerIdentity::matches(
                        $targetUuid,
                        $currentTarget->getUniqueId()->toString()
                    )){
                        $this->message($formPlayer, 'The intended recipient is no longer online under the same account. No transfer was made.');
                        $this->openPanel($formPlayer);
                        return;
                    }
                    // Re-resolve and validate again in the common transaction handler.
                    $this->handlePay($formPlayer, ['pay', $targetName, $amountInput]);
                }
                $this->openPanel($formPlayer);
            }
        ));
    }

    private function showAdminConfirmation(
        Player $player,
        string $mode,
        string $targetName,
        string $bucket,
        string $amountInput
    ) : void{
        if(!$player->hasPermission('banksystem.admin')){
            $this->message($player, 'You do not have permission to administer bank balances.');
            $this->openPanel($player);
            return;
        }

        $allowZero = $mode === 'set';
        $amountMinor = Money::parse($amountInput, $allowZero);
        if($amountMinor === null){
            $this->message($player, $allowZero
                ? 'Invalid amount. Use 0 or a non-negative number with at most two decimal places.'
                : 'Invalid amount. Use a positive number with at most two decimal places.');
            $this->openPanel($player);
            return;
        }

        $target = $this->plugin->getServer()->getPlayerExact($targetName);
        if($target === null){
            $this->message($player, 'Player ' . $targetName . ' is no longer online.');
            $this->openPanel($player);
            return;
        }

        // Preserve the confirmed target identity across the asynchronous form round-trip.
        $targetName = $target->getName();
        $targetUuid = $target->getUniqueId()->toString();

        $description = match($mode){
            'give' => 'Add ' . $this->formatMoney($amountMinor) . ' to ' . $target->getName() . "'s " . $bucket . ' balance?',
            'take' => 'Remove ' . $this->formatMoney($amountMinor) . ' from ' . $target->getName() . "'s " . $bucket . ' balance?',
            'set' => 'Set ' . $target->getName() . "'s " . $bucket . ' balance to ' . $this->formatMoney($amountMinor) . '?',
            default => throw new \LogicException('Unsupported administrator panel action.')
        };

        $player->sendForm(BankPanelForm::modal(
            '§l§cBANKSYSTEM §8• §fCONFIRM ADMIN ACTION',
            "§cReview this privileged balance change:\n\n§f" . $description .
                "\n\n§7The operation will be written to the bank audit history.",
            'Confirm change',
            'Cancel',
            function(Player $formPlayer, bool $confirmed) use ($mode, $targetName, $targetUuid, $bucket, $amountInput) : void{
                if(!$formPlayer->hasPermission('banksystem.use') || !$formPlayer->hasPermission('banksystem.admin')){
                    $this->message($formPlayer, 'Administrator permission is required.');
                    return;
                }
                if($confirmed){
                    $currentTarget = $this->plugin->getServer()->getPlayerExact($targetName);
                    if($currentTarget === null || !PlayerIdentity::matches(
                        $targetUuid,
                        $currentTarget->getUniqueId()->toString()
                    )){
                        $this->message($formPlayer, 'The intended target is no longer online under the same account. No balance change was made.');
                        $this->openPanel($formPlayer);
                        return;
                    }
                    // handleAdmin still executes amount, audit, permission and cooldown checks.
                    $this->handleAdmin($formPlayer, [$mode, $targetName, $bucket, $amountInput]);
                }
                $this->openPanel($formPlayer);
            }
        ));
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
        $this->message($sender, 'Commands: /bank [panel], /bank balance, /bank deposit <amount>, /bank withdraw <amount>');
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
