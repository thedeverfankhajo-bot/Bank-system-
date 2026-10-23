<?php

declare(strict_types=1);

namespace BankSystem\UI;

final class BankDashboardActivity{
    private function __construct(){
    }

    /**
     * Renders a compact, safe preview for the main bank dashboard.
     *
     * @param list<array<string, mixed>> $rows Newest-first transaction rows
     * @param callable(int): string $formatMoney
     */
    public static function render(string $playerUuid, array $rows, callable $formatMoney) : string{
        $lines = [
            '§6§lRECENT ACTIVITY',
            '§8Last 3 transactions • newest first',
            '§8------------------------------'
        ];
        $rendered = 0;

        foreach(array_slice($rows, 0, 3) as $row){
            if(!isset($row['action'], $row['amount_minor'], $row['created_at']) ||
                !is_numeric($row['amount_minor']) || !is_numeric($row['created_at'])){
                continue;
            }

            $action = strtolower((string) $row['action']);
            $fromUuid = (string) ($row['from_uuid'] ?? '');
            $toUuid = (string) ($row['to_uuid'] ?? '');
            $fromName = self::safeName((string) ($row['from_name'] ?? 'Unknown'));
            $toName = self::safeName((string) ($row['to_name'] ?? 'Unknown'));
            [$color, $label] = self::describeAction($action, $playerUuid, $fromUuid, $fromName, $toName);

            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
            $amount = $formatMoney((int) $row['amount_minor']);
            $createdAt = (int) $row['created_at'];

            $lines[] = $color . $label . ' §8#' . $id .
                "\n§f" . $amount . ' §8• §7' . date('Y-m-d H:i', $createdAt);
            ++$rendered;
        }

        if($rendered === 0){
            $lines[] = '§7No recent transactions yet. Make your first deposit to get started.';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function describeAction(
        string $action,
        string $playerUuid,
        string $fromUuid,
        string $fromName,
        string $toName
    ) : array{
        if(str_starts_with($action, 'admin_')){
            return ['§d', 'ADMIN • ' . strtoupper(str_replace('_', ' ', substr($action, 6)))];
        }

        return match($action){
            'deposit' => ['§a', '↑ DEPOSIT TO BANK'],
            'withdraw' => ['§c', '↓ WITHDRAW TO WALLET'],
            'pay' => $fromUuid === $playerUuid
                ? ['§e', '↗ SENT TO ' . $toName]
                : ['§b', '↙ RECEIVED FROM ' . $fromName],
            default => ['§7', strtoupper(str_replace('_', ' ', $action))]
        };
    }

    private static function safeName(string $name) : string{
        // Strip formatting code pairs, not just the section sign (which would leave
        // the code letter visible), and remove line breaks that could spoof entries.
        $name = preg_replace('/§[0-9a-fk-or]/iu', '', $name) ?? $name;
        return str_replace(["\r", "\n"], '', $name);
    }
}
