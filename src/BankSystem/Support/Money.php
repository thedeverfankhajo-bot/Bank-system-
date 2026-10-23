<?php

declare(strict_types=1);

namespace BankSystem\Support;

final class Money{
    private function __construct(){
    }

    /**
     * Parses a decimal money amount into integer minor units (two decimal places).
     * Returns null for malformed input, overflow, or disallowed zero amounts.
     */
    public static function parse(string $input, bool $allowZero = false) : ?int{
        $input = trim($input);
        if(!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $input, $matches)){
            return null;
        }

        $whole = (int) $matches[1];
        if($whole > intdiv(PHP_INT_MAX, 100)){
            return null;
        }

        $fraction = isset($matches[2]) ? (int) str_pad($matches[2], 2, '0', STR_PAD_RIGHT) : 0;
        $base = $whole * 100;
        if($fraction > PHP_INT_MAX - $base){
            return null;
        }
        $minor = $base + $fraction;

        if($minor < 0 || (!$allowZero && $minor === 0)){
            return null;
        }

        return $minor;
    }

    public static function format(int $minor, string $symbol = '$') : string{
        // Divide before taking the absolute value so PHP_INT_MIN is handled without integer overflow.
        $negative = $minor < 0;
        $whole = (string) abs(intdiv($minor, 100));
        $whole = preg_replace('/\\B(?=(\\d{3})+(?!\\d))/', ',', $whole) ?? $whole;
        $fraction = abs($minor % 100);

        return ($negative ? '-' : '') . $symbol . $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }
}
