<?php

declare(strict_types=1);

namespace BankSystem\Tests\UI;

use BankSystem\UI\BankPanelForm;
use pocketmine\player\Player;
use PHPUnit\Framework\TestCase;

final class BankPanelFormTest extends TestCase{
    public function testSimpleFormUsesBedrockSchemaAndKeepsActionsServerSide() : void{
        $selected = null;
        $form = BankPanelForm::simple(
            'BankSystem',
            'Wallet: $12.00',
            [
                ['text' => 'Deposit', 'action' => 'deposit'],
                ['text' => 'Withdraw', 'action' => 'withdraw']
            ],
            static function(Player $player, string $action) use (&$selected) : void{
                $selected = $action;
            }
        );

        self::assertSame([
            'type' => 'form',
            'title' => 'BankSystem',
            'content' => 'Wallet: $12.00',
            'buttons' => [
                ['text' => 'Deposit'],
                ['text' => 'Withdraw']
            ]
        ], $form->jsonSerialize());

        $form->handleResponse(new Player(), 1);
        self::assertSame('withdraw', $selected);
    }

    public function testInvalidButtonResponseAndDismissalDoNotRunAnAction() : void{
        $calls = 0;
        $form = BankPanelForm::simple(
            'BankSystem',
            'Choose',
            [['text' => 'Deposit', 'action' => 'deposit']],
            static function(Player $player, string $action) use (&$calls) : void{
                ++$calls;
            }
        );

        $form->handleResponse(new Player(), 7);
        $form->handleResponse(new Player(), '0');
        $form->handleResponse(new Player(), null);
        self::assertSame(0, $calls);
    }

    public function testModalFormUsesNativeSchemaAndDispatchesBothButtons() : void{
        $responses = [];
        $form = BankPanelForm::modal(
            'Confirm transfer',
            'Send $10.00 to Alex?',
            'Confirm',
            'Cancel',
            static function(Player $player, bool $confirmed) use (&$responses) : void{
                $responses[] = $confirmed;
            }
        );

        self::assertSame([
            'type' => 'modal',
            'title' => 'Confirm transfer',
            'content' => 'Send $10.00 to Alex?',
            'button1' => 'Confirm',
            'button2' => 'Cancel'
        ], $form->jsonSerialize());

        $form->handleResponse(new Player(), true);
        $form->handleResponse(new Player(), false);
        self::assertSame([true, false], $responses);
    }

    public function testModalFormIgnoresInvalidResponsesAndClosingTheForm() : void{
        $calls = 0;
        $form = BankPanelForm::modal(
            'Confirm',
            'Are you sure?',
            'Yes',
            'No',
            static function(Player $player, bool $confirmed) use (&$calls) : void{
                ++$calls;
            }
        );

        $form->handleResponse(new Player(), 0);
        $form->handleResponse(new Player(), 'true');
        $form->handleResponse(new Player(), null);
        self::assertSame(0, $calls);
    }

    public function testCustomFormUsesNativeSchemaAndForwardsOnlyArrayResponses() : void{
        $received = null;
        $form = BankPanelForm::custom(
            'Deposit',
            [['type' => 'input', 'text' => 'Amount', 'placeholder' => '10.00', 'default' => '']],
            static function(Player $player, mixed $data) use (&$received) : void{
                $received = $data;
            }
        );

        self::assertSame([
            'type' => 'custom_form',
            'title' => 'Deposit',
            'content' => [['type' => 'input', 'text' => 'Amount', 'placeholder' => '10.00', 'default' => '']]
        ], $form->jsonSerialize());

        $form->handleResponse(new Player(), ['10.00']);
        self::assertSame(['10.00'], $received);
        $form->handleResponse(new Player(), 'invalid');
        self::assertSame(['10.00'], $received);
    }
}
