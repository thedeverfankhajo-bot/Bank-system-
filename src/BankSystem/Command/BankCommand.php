<?php

declare(strict_types=1);

namespace BankSystem\Command;

use BankSystem\BankAccount;
use BankSystem\BankException;
use BankSystem\Main;
use BankSystem\Service\BankService;
use BankSystem\Support\BedrockText;
use BankSystem\Support\Money;
use BankSystem\Support\PlayerIdentity;
use BankSystem\Support\TransactionCooldown;
use BankSystem\UI\BankDashboardActivity;
use BankSystem\UI\BankHistoryPage;
use BankSystem\UI\BankPanelForm;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use Throwable;

final class BankCommand{
    private TransactionCooldown $transactionCooldown;

    public function __construct(
        private readonly Main $plugin,
        private readonly BankService $service
    ){
        $this->transactionCooldown = new TransactionCooldown();
    }

    /**
     * Clears per-player cooldown state when that player disconnects.
     */
    public function forgetPlayer(string $uuid) : void{
        $this->transactionCooldown->forget($uuid);
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
            $accountUuid = $player->getUniqueId()->toString();
            $account = $this->service->getAccount($accountUuid, $player->getName());
            $activityText = "§6§lRECENT ACTIVITY\n§8------------------------------\n§7Recent activity is temporarily unavailable.";
            try{
                $activityText = BankDashboardActivity::render(
                    $accountUuid,
                    $this->service->getHistory($accountUuid, 3, 0),
                    fn(int $amount) : string => $this->formatMoney($amount)
                );
            }catch(Throwable $exception){
                // Keep the balance dashboard available if the optional activity preview cannot be read.
                $this->logReadError('dashboard activity', $exception);
            }
            $buttons = [
                ['text' => '§a[1] §fDeposit to bank', 'action' => 'deposit'],
                ['text' => '§e[2] §fWithdraw to wallet', 'action' => 'withdraw'],
                ['text' => '§b[3] §fSend money to player', 'action' => 'pay'],
                ['text' => '§6[4] §fTransaction history', 'action' => 'history'],
                ['text' => '§d[5] §fBank leaderboard', 'action' => 'top'],
                ['text' => '§b↻ §fRefresh balances', 'action' => 'refresh']
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
                "§8Your account is linked to your player UUID.\n\n" .
                $activityText;

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
            case 'refresh':
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
                        ['type' => 'input', 'text' => 'Player name (online or known offline account)', 'placeholder' => 'PlayerName', 'default' => ''],
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
                $action = BedrockText::sanitize((string) $row['action']);
                $actionColor = match($action){
                    'deposit' => '§a',
                    'withdraw' => '§c',
                    'pay' => '§b',
                    default => str_starts_with($action, 'admin_') ? '§d' : '§e'
                };
                $content .= "\n\n§8#" . (int) $row['id'] . ' ' .
                    $actionColor . strtoupper($action) . ' §f' .
                    $this->formatMoney((int) $row['amount_minor']) .
                    "\n§7" . BedrockText::sanitize((string) $row['from_name']) . ' §8> §7' . BedrockText::sanitize((string) $row['to_name']) .
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
                $content .= "\n\n" . $rankColor . '#' . ($index + 1) . ' §f' . BedrockText::sanitize($account->playerName) .
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
                ['type' => 'input', 'text' => 'Player name (online or known offline account)', 'placeholder' => 'PlayerName', 'default' => ''],
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
                $this->message($player, 'Enter a valid player name and amount.');
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
                $this->message($player, 'Enter a valid player name and administrator values.');
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

        if(!$this->validateTransactionAmount($player, $amountMinor)){
            $this->openPanel($player);
            return;
        }

        $target = $this->findBankAccount($player, $targetName);
        if($target === null){
            $this->openPanel($player);
            return;
        }
        if($target->uuid === $player->getUniqueId()->toString()){
            $this->message($player, 'You cannot pay yourself.');
            $this->openPanel($player);
            return;
        }

        // Bind confirmation to the saved UUID, not merely a display name. If the
        // owner disconnects, the same persisted account remains a valid recipient.
        $targetName = $target->playerName;
        $targetUuid = $target->uuid;
        $targetPlayer = $this->onlinePlayerForAccount($target);
        $recipientStatus = $targetPlayer === null ? 'offline account' : 'online player';

        $player->sendForm(BankPanelForm::modal(
            '§l§6BANKSYSTEM §8• §fCONFIRM TRANSFER',
            "§eSend §f" . $this->formatMoney($amountMinor) . " §eto §f" . $targetName .
                " §8(" . $recipientStatus . ")§e?\n\n§cThis transfer cannot be automatically reversed.",
            'Confirm transfer',
            'Cancel',
            function(Player $formPlayer, bool $confirmed) use ($targetName, $targetUuid, $amountInput) : void{
                if(!$formPlayer->hasPermission('banksystem.use')){
                    $this->message($formPlayer, 'You do not have permission to use banking.');
                    return;
                }
                if($confirmed){
                    try{
                        $currentTarget = $this->resolveAccountByName($targetName);
                    }catch(Throwable){
                        $currentTarget = null;
                    }
                    if($currentTarget === null || !PlayerIdentity::matches($targetUuid, $currentTarget->uuid)){
                        $this->message($formPlayer, 'The intended recipient account could not be verified. No transfer was made.');
                        $this->openPanel($formPlayer);
                        return;
                    }
                    // Re-resolve and apply the same amount/cooldown/transaction rules.
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

        if($mode !== 'set' && !$this->validateTransactionAmount($player, $amountMinor)){
            $this->openPanel($player);
            return;
        }

        $target = $this->findBankAccount($player, $targetName);
        if($target === null){
            $this->openPanel($player);
            return;
        }

        // Preserve target UUID across the form round-trip, including offline accounts.
        $targetName = $target->playerName;
        $targetUuid = $target->uuid;
        $targetPlayer = $this->onlinePlayerForAccount($target);
        $targetLabel = $targetName . ($targetPlayer === null ? ' (offline)' : '');

        $description = match($mode){
            'give' => 'Add ' . $this->formatMoney($amountMinor) . ' to ' . $targetLabel . "'s " . $bucket . ' balance?',
            'take' => 'Remove ' . $this->formatMoney($amountMinor) . ' from ' . $targetLabel . "'s " . $bucket . ' balance?',
            'set' => 'Set ' . $targetLabel . "'s " . $bucket . ' balance to ' . $this->formatMoney($amountMinor) . '?',
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
                    try{
                        $currentTarget = $this->resolveAccountByName($targetName);
                    }catch(Throwable){
                        $currentTarget = null;
                    }
                    if($currentTarget === null || !PlayerIdentity::matches($targetUuid, $currentTarget->uuid)){
                        $this->message($formPlayer, 'The intended target account could not be verified. No balance change was made.');
                        $this->openPanel($formPlayer);
                        return;
                    }
                    // handleAdmin still executes permission, cap, audit and cooldown checks.
                    $this->handleAdmin($formPlayer, [$mode, $targetName, $bucket, $amountInput]);
                }
                $this->openPanel($formPlayer);
            }
        ));
    }

    private function handleBalance(CommandSender $sender, array $args) : void{
        if(isset($args[1])){
            if(!$sender->hasPermission('banksystem.admin')){
                $this->message($sender, 'Only administrators can inspect another player account.');
                return;
            }
            $account = $this->findBankAccount($sender, $args[1]);
            if($account !== null){
                $this->showBalance($sender, $account);
            }
            return;
        }

        $player = $this->requirePlayer($sender);
        if($player === null){
            return;
        }

        try{
            $account = $this->service->getAccount($player->getUniqueId()->toString(), $player->getName());
            $this->showBalance($sender, $account);
        }catch(Throwable $exception){
            $this->logReadError('balance', $exception);
            $this->message($sender, 'Could not read the account. Check the server log.');
        }
    }

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
            $this->message($sender, 'Usage: /bank pay <player> <amount>');
            return;
        }

        // Validate user input before resolving the target: an offline lookup may
        // bootstrap a persisted account from PocketMine's saved XUID metadata.
        $amount = $this->parseAmount($sender, $args[2]);
        if($amount === null){
            return;
        }

        // Reject amounts above the configured cap before an offline lookup can create an account.
        if(!$this->validateTransactionAmount($sender, $amount)){
            return;
        }

        $target = $this->findBankAccount($sender, $args[1]);
        if($target === null){
            return;
        }
        $sourceUuid = $player->getUniqueId()->toString();
        if($target->uuid === $sourceUuid){
            $this->message($sender, 'You cannot pay yourself.');
            return;
        }

        $this->runTransaction($sender, function() use ($player, $target, $amount) : void{
            $this->service->transfer(
                $player->getUniqueId()->toString(),
                $player->getName(),
                $target->uuid,
                $target->playerName,
                $amount
            );
            $this->message($player, 'Sent ' . $this->formatMoney($amount) . ' to ' . $target->playerName . '.');
            $targetPlayer = $this->onlinePlayerForAccount($target);
            if($targetPlayer !== null && $targetPlayer !== $player){
                $this->message($targetPlayer, 'Received ' . $this->formatMoney($amount) . ' from ' . $player->getName() . '.');
            }
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
                    '#' . ($index + 1) . ' ' . BedrockText::sanitize($account->playerName) . ': ' . $this->formatMoney($account->bankBalance)
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
                $line = '#' . $row['id'] . ' ' . strtoupper(BedrockText::sanitize((string) $row['action'])) . ' ' .
                    $this->formatMoney((int) $row['amount_minor']) . ' (' .
                    BedrockText::sanitize((string) $row['from_name']) . ' -> ' . BedrockText::sanitize((string) $row['to_name']) . ') ' .
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
            $this->message($sender, 'Usage: /bank admin <give|take|set> <player> <wallet|bank> <amount>');
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

        // Reject malformed amounts before the offline lookup can create an account.
        $amount = $this->parseAmount($sender, $args[3], $mode === 'set');
        if($amount === null){
            return;
        }

        // Give/take use a fixed delta, so their cap can be checked without resolving a target.
        // Set is different: its cap applies to the delta from the current saved balance.
        if($mode !== 'set' && !$this->validateTransactionAmount($sender, $amount)){
            return;
        }

        $target = $this->findBankAccount($sender, $args[1]);
        if($target === null){
            return;
        }

        $actorUuid = $sender instanceof Player ? $sender->getUniqueId()->toString() : 'console';
        $actorName = $sender->getName();

        $this->runTransaction($sender, function() use ($sender, $target, $mode, $bucket, $amount, $actorUuid, $actorName) : void{
            if($mode === 'give'){
                $this->service->give($target->uuid, $target->playerName, $bucket, $amount, $actorUuid, $actorName);
            }elseif($mode === 'take'){
                $this->service->take($target->uuid, $target->playerName, $bucket, $amount, $actorUuid, $actorName);
            }else{
                $this->service->setBalance($target->uuid, $target->playerName, $bucket, $amount, $actorUuid, $actorName);
            }

            if($mode === 'set'){
                $this->message($sender, 'Admin set: ' . $target->playerName . ' ' . $bucket . ' balance set to ' . $this->formatMoney($amount) . '.');
            }else{
                $verb = $mode === 'give' ? 'Added ' : 'Removed ';
                $this->message($sender, 'Admin ' . $mode . ': ' . $verb . $this->formatMoney($amount) . ' ' . ($mode === 'give' ? 'to ' : 'from ') . $target->playerName . ' ' . $bucket . '.');
            }

            $targetPlayer = $this->onlinePlayerForAccount($target);
            if($targetPlayer !== null && !($sender instanceof Player && $targetPlayer === $sender)){
                $this->message($targetPlayer, 'An administrator updated your ' . $bucket . ' balance.');
            }
        });
    }

    private function showBalance(CommandSender $sender, BankAccount $account) : void{
        $this->message($sender, 'Balance for ' . BedrockText::sanitize($account->playerName) . ':');
        $this->message($sender, 'Wallet: ' . $this->formatMoney($account->walletBalance));
        $this->message($sender, 'Bank: ' . $this->formatMoney($account->bankBalance));
        $this->message($sender, 'Total: ' . $this->formatMoney($account->totalBalance()));
    }

    private function showHelp(CommandSender $sender) : void{
        $this->message($sender, 'Commands: /bank [panel], /bank balance [player], /bank deposit <amount>, /bank withdraw <amount>');
        $this->message($sender, '/bank pay <player> <amount>, /bank top, /bank history [page]');
        $this->message($sender, 'Admin: /bank admin <give|take|set> <player> <wallet|bank> <amount>');
    }

    private function requirePlayer(CommandSender $sender) : ?Player{
        if(!($sender instanceof Player)){
            $this->message($sender, 'This command requires a player. Console can use /bank admin with any saved bank account.');
            return null;
        }
        return $sender;
    }

    /**
     * Prefers a live player's UUID; otherwise resolves a saved account or verified
     * PocketMine offline identity from its saved XUID metadata.
     */
    private function resolveAccountByName(string $name) : ?BankAccount{
        $onlinePlayer = $this->plugin->getServer()->getPlayerExact($name);
        if($onlinePlayer !== null){
            return $this->service->getAccount($onlinePlayer->getUniqueId()->toString(), $onlinePlayer->getName());
        }
        return $this->service->findAccountByName($name);
    }

    private function findBankAccount(CommandSender $sender, string $name) : ?BankAccount{
        try{
            $account = $this->resolveAccountByName($name);
        }catch(BankException $exception){
            $this->message($sender, $exception->getMessage());
            return null;
        }catch(Throwable $exception){
            $this->logReadError('account lookup', $exception);
            $this->message($sender, 'Could not read that bank account. Check the server log.');
            return null;
        }

        if($account === null){
            $this->message(
                $sender,
                'No saved or verifiable offline identity was found for ' . $name . '. Offline banking requires a saved PocketMine XUID or a previously registered bank account.'
            );
            return null;
        }

        return $account;
    }

    private function onlinePlayerForAccount(BankAccount $account) : ?Player{
        $player = $this->plugin->getServer()->getPlayerExact($account->playerName);
        if($player === null || !PlayerIdentity::matches($account->uuid, $player->getUniqueId()->toString())){
            return null;
        }
        return $player;
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

    /**
     * Applies the service-level amount cap before resolving an offline target.
     */
    private function validateTransactionAmount(CommandSender $sender, int $amountMinor) : bool{
        try{
            $this->service->validateTransactionAmount($amountMinor);
            return true;
        }catch(BankException $exception){
            $this->message($sender, $exception->getMessage());
            return false;
        }catch(Throwable $exception){
            $this->logReadError('transaction amount validation', $exception);
            $this->message($sender, 'Could not validate that amount. Check the server log.');
            return false;
        }
    }

    private function runTransaction(CommandSender $sender, callable $operation) : void{
        $playerUuid = $sender instanceof Player ? $sender->getUniqueId()->toString() : null;
        $cooldown = max(0, (int) $this->plugin->getConfig()->getNested('security.transaction-cooldown-seconds', 1));
        $now = microtime(true);
        if($playerUuid !== null && $cooldown > 0){
            if($this->transactionCooldown->isActive($playerUuid, $now, $cooldown)){
                $this->message($sender, 'Please wait before making another transaction.');
                return;
            }
        }elseif($playerUuid !== null){
            $this->transactionCooldown->forget($playerUuid);
        }

        try{
            $operation();
            if($playerUuid !== null && $cooldown > 0){
                $this->transactionCooldown->recordSuccess($playerUuid, microtime(true));
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
