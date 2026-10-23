<?php

declare(strict_types=1);

namespace BankSystem\Tests\UI;

use BankSystem\UI\BankDashboardActivity;
use PHPUnit\Framework\TestCase;

final class BankDashboardActivityTest extends TestCase{
    public function testEmptyHistoryShowsFriendlyEmptyState() : void{
        $text = BankDashboardActivity::render('player-a', [], static fn(int $amount) : string => '$' . $amount);

        self::assertStringContainsString('RECENT ACTIVITY', $text);
        self::assertStringContainsString('No recent transactions yet', $text);
    }

    public function testRecognizesDepositWithdrawalAndTransferDirection() : void{
        $rows = [
            [
                'id' => 7, 'action' => 'pay', 'from_uuid' => 'player-b', 'from_name' => 'Blake',
                'to_uuid' => 'player-a', 'to_name' => 'Alex', 'amount_minor' => 1250, 'created_at' => 100
            ],
            [
                'id' => 6, 'action' => 'pay', 'from_uuid' => 'player-a', 'from_name' => 'Alex',
                'to_uuid' => 'player-c', 'to_name' => 'Casey', 'amount_minor' => 500, 'created_at' => 90
            ],
            [
                'id' => 5, 'action' => 'deposit', 'from_uuid' => 'player-a', 'from_name' => 'Alex',
                'to_uuid' => 'player-a', 'to_name' => 'Alex', 'amount_minor' => 250, 'created_at' => 80
            ],
            [
                'id' => 4, 'action' => 'withdraw', 'from_uuid' => 'player-a', 'from_name' => 'Alex',
                'to_uuid' => 'player-a', 'to_name' => 'Alex', 'amount_minor' => 100, 'created_at' => 70
            ]
        ];

        $text = BankDashboardActivity::render('player-a', $rows, static fn(int $amount) : string => '$' . $amount);

        self::assertStringContainsString('RECEIVED FROM Blake', $text);
        self::assertStringContainsString('SENT TO Casey', $text);
        self::assertStringContainsString('DEPOSIT TO BANK', $text);
        self::assertStringNotContainsString('WITHDRAW TO WALLET', $text);
        self::assertStringNotContainsString('#4', $text);
    }

    public function testAdminAdjustmentsAreNotMisrepresentedAsPlayerTransfers() : void{
        $text = BankDashboardActivity::render('player-a', [[
            'id' => 2, 'action' => 'admin_give_bank', 'from_uuid' => 'player-a', 'from_name' => 'Alex',
            'to_uuid' => 'player-a', 'to_name' => 'Alex', 'amount_minor' => 500, 'created_at' => 0
        ]], static fn(int $amount) : string => '$' . $amount);

        self::assertStringContainsString('ADMIN • GIVE BANK', $text);
        self::assertStringNotContainsString('SENT TO', $text);
        self::assertStringNotContainsString('RECEIVED FROM', $text);
    }

    public function testStoredNamesCannotInjectFormattingCodes() : void{
        $text = BankDashboardActivity::render('player-a', [[
            'id' => 2, 'action' => 'pay', 'from_uuid' => 'player-b', 'from_name' => "Blake§c\nINJECTED",
            'to_uuid' => 'player-a', 'to_name' => 'Alex', 'amount_minor' => 500, 'created_at' => 0
        ]], static fn(int $amount) : string => '$' . $amount);

        self::assertStringContainsString('RECEIVED FROM BlakeINJECTED', $text);
        self::assertStringNotContainsString('§c', $text);
    }
}
