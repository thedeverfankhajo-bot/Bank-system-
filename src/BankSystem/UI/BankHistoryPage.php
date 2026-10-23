<?php

declare(strict_types=1);

namespace BankSystem\UI;

final class BankHistoryPage{
    /**
     * @param list<array<string, mixed>> $entries
     */
    private function __construct(
        public readonly int $page,
        public readonly array $entries,
        public readonly bool $hasPrevious,
        public readonly bool $hasNext
    ){
    }

    public static function clampPage(int $page, int $maximumPage = 5000) : int{
        $maximumPage = max(1, $maximumPage);
        return max(1, min($maximumPage, $page));
    }

    /**
     * The caller supplies one look-ahead row beyond pageSize to determine if a
     * Next button should be rendered. Extra rows are never shown in the current page.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function fromRows(int $page, int $pageSize, array $rows, int $maximumPage = 5000) : self{
        $pageSize = max(1, min(20, $pageSize));
        $page = self::clampPage($page, $maximumPage);

        return new self(
            $page,
            array_slice($rows, 0, $pageSize),
            $page > 1,
            count($rows) > $pageSize && $page < max(1, $maximumPage)
        );
    }
}
