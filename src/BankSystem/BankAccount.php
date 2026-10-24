<?php

declare(strict_types=1);

namespace BankSystem;

final class BankAccount{
    public function __construct(
        public readonly string $uuid,
        public readonly string $playerName,
        public readonly int $walletBalance,
        public readonly int $bankBalance,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly ?string $xuid = null
    ){
    }

    /**
     * Saturates rather than overflowing if a server configures extreme balances.
     */
    public function totalBalance() : int{
        if($this->walletBalance > PHP_INT_MAX - $this->bankBalance){
            return PHP_INT_MAX;
        }
        return $this->walletBalance + $this->bankBalance;
    }
}
