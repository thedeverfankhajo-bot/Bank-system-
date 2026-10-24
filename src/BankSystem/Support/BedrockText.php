<?php

declare(strict_types=1);

namespace BankSystem\Support;

final class BedrockText{
    private function __construct(){
    }

    /**
     * Removes client formatting codes and control bytes before displaying stored text.
     *
     * Control bytes are removed first so a line break cannot split a formatting code
     * and let it become active after the formatting pass.
     */
    public static function sanitize(string $text) : string{
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text) ?? '';
        return preg_replace('/§[0-9a-gk-or]/i', '', $text) ?? '';
    }
}
