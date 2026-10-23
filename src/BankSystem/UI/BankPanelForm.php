<?php

declare(strict_types=1);

namespace BankSystem\UI;

use Closure;
use pocketmine\form\Form;
use pocketmine\player\Player;

final class BankPanelForm implements Form{
    /**
     * @param list<array{text: string, action: string}> $buttons
     * @param list<array<string, mixed>> $elements
     */
    private function __construct(
        private readonly string $type,
        private readonly string $title,
        private readonly string $text,
        private readonly array $buttons,
        private readonly array $elements,
        private readonly Closure $handler
    ){
    }

    /**
     * @param list<array{text: string, action: string}> $buttons
     * @param callable(Player, string): void $handler
     */
    public static function simple(string $title, string $content, array $buttons, callable $handler) : self{
        foreach($buttons as $button){
            if(!isset($button['text'], $button['action']) || !is_string($button['text']) || !is_string($button['action'])){
                throw new \InvalidArgumentException('Every bank panel button must have a text and action.');
            }
        }

        return new self('form', $title, $content, array_values($buttons), [], Closure::fromCallable($handler));
    }

    /**
     * Creates a confirmation form. "true" means the first button was selected;
     * "false" means the second button was selected. A closed form never calls
     * the handler.
     *
     * @param callable(Player, bool): void $handler
     */
    public static function modal(
        string $title,
        string $content,
        string $confirmLabel,
        string $cancelLabel,
        callable $handler
    ) : self{
        return new self(
            'modal',
            $title,
            $content,
            [
                ['text' => $confirmLabel, 'action' => 'confirm'],
                ['text' => $cancelLabel, 'action' => 'cancel']
            ],
            [],
            Closure::fromCallable($handler)
        );
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @param callable(Player, mixed): void $handler
     */
    public static function custom(string $title, array $elements, callable $handler) : self{
        return new self('custom_form', $title, '', [], array_values($elements), Closure::fromCallable($handler));
    }

    /**
     * Returns the Bedrock form JSON consumed by PocketMine-MP's native form transport.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize() : array{
        if($this->type === 'custom_form'){
            return [
                'type' => 'custom_form',
                'title' => $this->title,
                'content' => $this->elements
            ];
        }

        if($this->type === 'modal'){
            return [
                'type' => 'modal',
                'title' => $this->title,
                'content' => $this->text,
                'button1' => $this->buttons[0]['text'],
                'button2' => $this->buttons[1]['text']
            ];
        }

        return [
            'type' => 'form',
            'title' => $this->title,
            'content' => $this->text,
            // Keep internal action identifiers server-side; clients receive labels only.
            'buttons' => array_map(
                static fn(array $button) : array => ['text' => $button['text']],
                $this->buttons
            )
        ];
    }

    public function handleResponse(Player $player, $data) : void{
        // Closing a Bedrock form produces null. Treat it as cancellation, never as a
        // default choice or an implicit transaction.
        if($data === null){
            return;
        }

        if($this->type === 'modal'){
            if(!is_bool($data)){
                return;
            }
            ($this->handler)($player, $data);
            return;
        }

        if($this->type === 'form'){
            if(!is_int($data) || !array_key_exists($data, $this->buttons)){
                return;
            }
            ($this->handler)($player, $this->buttons[$data]['action']);
            return;
        }

        if(!is_array($data)){
            return;
        }
        ($this->handler)($player, $data);
    }
}
