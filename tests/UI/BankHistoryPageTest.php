<?php

declare(strict_types=1);

namespace BankSystem\Tests\UI;

use BankSystem\UI\BankHistoryPage;
use PHPUnit\Framework\TestCase;

final class BankHistoryPageTest extends TestCase{
    public function testOnlyPageSizeRowsAreVisibleAndLookAheadEnablesNext() : void{
        $rows = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3]
        ];

        $page = BankHistoryPage::fromRows(1, 2, $rows);

        self::assertSame(1, $page->page);
        self::assertSame([['id' => 1], ['id' => 2]], $page->entries);
        self::assertFalse($page->hasPrevious);
        self::assertTrue($page->hasNext);
    }

    public function testLastPageHasPreviousButNoNextWhenThereIsNoLookAheadRow() : void{
        $page = BankHistoryPage::fromRows(2, 2, [['id' => 3]]);

        self::assertSame([['id' => 3]], $page->entries);
        self::assertTrue($page->hasPrevious);
        self::assertFalse($page->hasNext);
    }

    public function testEmptyFirstPageAndMaximumPageAreHandledSafely() : void{
        $empty = BankHistoryPage::fromRows(1, 10, []);
        self::assertSame([], $empty->entries);
        self::assertFalse($empty->hasPrevious);
        self::assertFalse($empty->hasNext);

        self::assertSame(1, BankHistoryPage::clampPage(0));
        self::assertSame(5000, BankHistoryPage::clampPage(999999));
        $maximum = BankHistoryPage::fromRows(5000, 1, [['id' => 1], ['id' => 2]]);
        self::assertTrue($maximum->hasPrevious);
        self::assertFalse($maximum->hasNext);
    }

    public function testPageSizeIsBoundedToTheSupportedMaximum() : void{
        $rows = [];
        for($i = 1; $i <= 22; ++$i){
            $rows[] = ['id' => $i];
        }

        $page = BankHistoryPage::fromRows(1, 500, $rows);
        self::assertCount(20, $page->entries);
        self::assertTrue($page->hasNext);
    }
}
