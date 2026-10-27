<?php

declare(strict_types=1);

// Test-only stand-ins for the small PocketMine form contract used by BankPanelForm.
// They are loaded by PHPUnit only; they are not part of the production plugin.
namespace pocketmine\command;

interface CommandSender{
    public function hasPermission(string $permission) : bool;
    public function getName() : string;
}

namespace pocketmine\form;

interface Form extends \JsonSerializable{
    public function handleResponse(\pocketmine\player\Player $player, $data) : void;
}

namespace pocketmine\player;

class Player{
}
