<?php

declare(strict_types=1);

namespace BankSystem\Event;

use pocketmine\event\Event;

final class BankTransactionEvent extends Event{
    /**
     * @param array<string, mixed> $record A committed record returned by BankRepository.
     */
    public function __construct(private readonly array $record){
    }

    public function getTransactionId() : string{
        return (string) $this->record['transaction_id'];
    }

    public function getActorUuid() : string{
        return (string) $this->record['actor_uuid'];
    }

    public function getActorName() : string{
        return (string) $this->record['actor_name'];
    }

    public function getFromUuid() : string{
        return (string) $this->record['from_uuid'];
    }

    public function getToUuid() : string{
        return (string) $this->record['to_uuid'];
    }

    public function getAction() : string{
        return (string) $this->record['action'];
    }

    public function getAmountMinor() : int{
        return (int) $this->record['amount_minor'];
    }

    public function getFromBucket() : string{
        return (string) $this->record['from_bucket'];
    }

    public function getToBucket() : string{
        return (string) $this->record['to_bucket'];
    }

    public function getFromBalanceBefore() : int{
        return (int) $this->record['from_balance_before'];
    }

    public function getFromBalanceAfter() : int{
        return (int) $this->record['from_balance_after'];
    }

    public function getToBalanceBefore() : int{
        return (int) $this->record['to_balance_before'];
    }

    public function getToBalanceAfter() : int{
        return (int) $this->record['to_balance_after'];
    }

    public function getCreatedAt() : int{
        return (int) $this->record['created_at'];
    }
}
