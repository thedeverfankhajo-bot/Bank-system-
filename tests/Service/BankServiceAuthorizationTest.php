<?php

declare(strict_types=1);

namespace BankSystem\Tests\Service;

use BankSystem\BankException;
use BankSystem\Service\BankService;
use PHPUnit\Framework\TestCase;
use pocketmine\command\CommandSender;
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
