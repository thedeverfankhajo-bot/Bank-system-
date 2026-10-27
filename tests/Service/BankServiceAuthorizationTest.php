<?php

declare(strict_types=1);

namespace BankSystem\Tests\Service;

use BankSystem\BankException;
use BankSystem\Service\BankService;
use PHPUnit\Framework\TestCase;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReflectionClass;

final class BankServiceAuthorizationTest extends TestCase{
    private function serviceWithoutConstructor() : BankService{
        /** @var BankService $service */
        $service = (new ReflectionClass(BankService::class))->newInstanceWithoutConstructor();
        return $service;
    }

    public function testLegacyAdminApiRejectsCallerSuppliedActorIdentity() : void{
        $service = $this->serviceWithoutConstructor();
        $calls = [
            'give' => static fn() => $service->give('target-uuid', 'Target', 'wallet', 100, 'spoofed-actor', 'Spoofed'),
            'take' => static fn() => $service->take('target-uuid', 'Target', 'wallet', 100, 'spoofed-actor', 'Spoofed'),
            'setBalance' => static fn() => $service->setBalance('target-uuid', 'Target', 'wallet', 100, 'spoofed-actor', 'Spoofed')
        ];

        foreach($calls as $operation => $call){
            try{
                $call();
                self::fail('Legacy privileged API operation unexpectedly succeeded: ' . $operation);
            }catch(BankException $exception){
                self::assertStringContainsString('legacy privileged API is disabled', $exception->getMessage());
            }
        }
    }

    public function testGetAccountDoesNotCreateOrRenameAnAccountFromCallerSuppliedName() : void{
        $repository = new \BankSystem\Database\BankRepository(':memory:');
        $repository->ensureAccount('victim-uuid', 'Victim', 500, 750);
        $service = $this->serviceWithoutConstructor();
        $repositoryProperty = new \ReflectionProperty(BankService::class, 'repository');
        $repositoryProperty->setValue($service, $repository);

        try{
            $account = $service->getAccount('victim-uuid', 'ImpersonatedName');
            self::assertSame('Victim', $account->playerName);
            self::assertSame(500, $account->walletBalance);
            self::assertSame(750, $account->bankBalance);

            try{
                $service->getAccount('new-arbitrary-uuid', 'InventedPlayer');
                self::fail('getAccount() unexpectedly created a new account from caller-supplied identity.');
            }catch(BankException){
            }

            self::assertNull($repository->getAccount('new-arbitrary-uuid'));
        }finally{
            $repository->close();
        }
    }

    public function testLegacyPlayerTransactionApiRejectsCallerSuppliedIdentity() : void{
        $service = $this->serviceWithoutConstructor();
        $calls = [
            'registerOnlineAccount' => static fn() => $service->registerOnlineAccount('victim-uuid', 'Victim', '123456789'),
            'deposit' => static fn() => $service->deposit('victim-uuid', 'Victim', 100),
            'withdraw' => static fn() => $service->withdraw('victim-uuid', 'Victim', 100),
            'transfer' => static fn() => $service->transfer('victim-uuid', 'Victim', 'attacker-uuid', 'Attacker', 100),
            'transferToName' => static fn() => $service->transferToName('victim-uuid', 'Victim', 'Attacker', 100)
        ];

        foreach($calls as $operation => $call){
            try{
                $call();
                self::fail('Legacy player API operation unexpectedly succeeded: ' . $operation);
            }catch(BankException $exception){
                self::assertStringContainsString('legacy', strtolower($exception->getMessage()), $operation);
            }
        }
    }

    public function testActorBoundPlayerTransactionsRequireUsePermissionBeforeStorageAccess() : void{
        $service = $this->serviceWithoutConstructor();
        $player = new Player();
        $calls = [
            'depositAs' => static fn() => $service->depositAs($player, 100),
            'withdrawAs' => static fn() => $service->withdrawAs($player, 100),
            'transferAs' => static fn() => $service->transferAs($player, 'target-uuid', 100),
            'transferToNameAs' => static fn() => $service->transferToNameAs($player, 'Target', 100)
        ];

        foreach($calls as $operation => $call){
            try{
                $call();
                self::fail('Unauthorized player transaction unexpectedly succeeded: ' . $operation);
            }catch(BankException $exception){
                self::assertStringContainsString('banksystem.use', strtolower($exception->getMessage()), $operation);
            }
        }
    }

    public function testPrivilegedApiRequiresAdminPermissionBeforeAccessingStorage() : void{
        $service = $this->serviceWithoutConstructor();
        $sender = new class implements CommandSender{
            public function hasPermission(string $permission) : bool{
                return false;
            }

            public function getName() : string{
                return 'RegularPlayer';
            }
        };

        $calls = [
            'giveAs' => static fn() => $service->giveAs($sender, 'target-uuid', 'Target', 'wallet', 100),
            'takeAs' => static fn() => $service->takeAs($sender, 'target-uuid', 'Target', 'wallet', 100),
            'setBalanceAs' => static fn() => $service->setBalanceAs($sender, 'target-uuid', 'Target', 'wallet', 100)
        ];

        foreach($calls as $operation => $call){
            try{
                $call();
                self::fail('Unauthorized privileged API operation unexpectedly succeeded: ' . $operation);
            }catch(BankException $exception){
                self::assertStringContainsString('banksystem.admin', $exception->getMessage());
            }
        }
    }
}
