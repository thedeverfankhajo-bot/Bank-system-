<?php

declare(strict_types=1);

namespace BankSystem;

use BankSystem\Command\BankCommand;
use BankSystem\Database\BankRepository;
use BankSystem\Service\BankService;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\plugin\PluginBase;
use Throwable;

final class Main extends PluginBase implements Listener{
    private BankRepository $repository;
    private BankService $bankService;
    private BankCommand $bankCommand;

    protected function onEnable() : void{
        $this->saveDefaultConfig();

        if(!extension_loaded('sqlite3') || !class_exists(\SQLite3::class)){
            $this->getLogger()->critical('The sqlite3 PHP extension is required. The plugin will be disabled.');
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        try{
            $encryptionSetting = $this->getConfig()->getNested('storage.encryption-at-rest', false);
            if(!is_bool($encryptionSetting)){
                throw new \RuntimeException('The storage.encryption-at-rest setting must be a YAML boolean.');
            }
            $encryptionEnabled = $encryptionSetting;
            $environmentKey = getenv('BANKSYSTEM_SQLCIPHER_KEY');
            $encryptionKey = is_string($environmentKey) ? $environmentKey : null;

            $this->repository = new BankRepository(
                $this->getDataFolder() . 'bank.sqlite3',
                $encryptionEnabled ? $encryptionKey : null,
                $encryptionEnabled
            );
            $this->bankService = new BankService($this, $this->repository);
            $this->bankCommand = new BankCommand($this, $this->bankService);
            $this->getServer()->getPluginManager()->registerEvents($this, $this);
            $this->getLogger()->info('BankSystem enabled. SQLite storage is ready.');
        }catch(Throwable $exception){
            $this->getLogger()->critical('Could not initialize BankSystem: ' . $exception->getMessage());
            $this->getServer()->getPluginManager()->disablePlugin($this);
        }
    }

    protected function onDisable() : void{
        if(isset($this->repository)){
            $this->repository->close();
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if(strtolower($command->getName()) !== 'bank'){
            return false;
        }

        if(!isset($this->bankCommand)){
            $sender->sendMessage('§cBankSystem is not ready. Check the server log.');
            return true;
        }

        return $this->bankCommand->execute($sender, $args);
    }

    public function onPlayerJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        try{
            $this->bankService->registerOnlineAccount(
                $player->getUniqueId()->toString(),
                $player->getName(),
                $player->getXuid()
            );
        }catch(Throwable $exception){
            $this->getLogger()->error('Failed to prepare a bank account for ' . $player->getName() . ': ' . $exception->getMessage());
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event) : void{
        if(isset($this->bankCommand)){
            $this->bankCommand->forgetPlayer($event->getPlayer()->getUniqueId()->toString());
        }
    }

    public function getBankService() : BankService{
        return $this->bankService;
    }

    public function getCurrencySymbol() : string{
        $symbol = $this->getConfig()->getNested('currency.symbol', '$');
        return is_string($symbol) ? $symbol : '$';
    }
}
