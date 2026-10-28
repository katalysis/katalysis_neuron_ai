<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\Utilities\ListWindow;
use PHPUnit\Framework\TestCase;

/**
 * BF-6: the window every capped list tool shows (limit and offset), and what it says when there are more (B-TRU-3).
 * No Concrete.
 */
final class ListWindowTest extends TestCase
{
    public function testTheLimitIsClampedAndDefaults(): void
    {
        $this->assertSame(20, ListWindow::of(null, null, 20, 100)->limit);
        $this->assertSame(1, ListWindow::of(0, null, 20, 100)->limit);
        $this->assertSame(1, ListWindow::of(-5, null, 20, 100)->limit);
        $this->assertSame(100, ListWindow::of(5000, null, 20, 100)->limit);
        $this->assertSame(37, ListWindow::of(37, null, 20, 100)->limit);
    }

    public function testTheOffsetIsNeverNegative(): void
    {
        $this->assertSame(0, ListWindow::of(10, null, 20, 100)->offset);
        $this->assertSame(0, ListWindow::of(10, -3, 20, 100)->offset);
        $this->assertSame(40, ListWindow::of(10, 40, 20, 100)->offset);
    }

    public function testSliceTakesTheWindow(): void
    {
        $items = range(1, 10);

        $this->assertSame([1, 2, 3], ListWindow::of(3, 0, 20, 100)->slice($items));
        $this->assertSame([4, 5, 6], ListWindow::of(3, 3, 20, 100)->slice($items));
        $this->assertSame([10], ListWindow::of(3, 9, 20, 100)->slice($items));
        $this->assertSame([], ListWindow::of(3, 10, 20, 100)->slice($items));
        $this->assertSame(['b' => 2], ListWindow::of(1, 1, 20, 100)->slice(['a' => 1, 'b' => 2]), 'keys are kept for maps');
    }

    public function testASummaryWithMoreSaysHowToGetThem(): void
    {
        $summary = ListWindow::of(20, 0, 20, 100)->summary(312, 20, 'list_pages', 'parentPath or pageType');

        $this->assertSame(312, $summary['total']);
        $this->assertSame(0, $summary['offset']);
        $this->assertSame(20, $summary['returned']);
        $this->assertTrue($summary['more']);
        $this->assertSame(
            'Showing 1–20 of 312. For more, call list_pages again with offset 20, or narrow with parentPath or pageType.',
            $summary['note']
        );
    }

    /**
     * P3.3-5 (Glyn, starting BF-8): scenario 15 run 4 paged list_pages one item at a time (limit 1, ten calls). A small
     * limit with more to come suggests a larger one.
     */
    public function testASmallLimitWithMoreToComeSuggestsALargerOne(): void
    {
        $note = ListWindow::of(1, 0, 20, 100)->note(10, 1, 'list_pages');

        $this->assertSame('Showing 1–1 of 10. For more, call list_pages again with offset 1, with a larger limit (up to 100) rather than a few at a time.', $note);
        $this->assertStringNotContainsString('larger limit', (string) ListWindow::of(20, 0, 20, 100)->note(45, 20, 'list_pages'));
    }

    /**
     * P3.3-5 (large sites): a list that stopped counting reports what it counted as a lower bound, and that there is more.
     */
    public function testATotalCountedOnlyInPartSaysAtLeast(): void
    {
        $summary = ListWindow::of(20, 0, 20, 100)->summary(500, 20, 'list_pages', 'parentPath or pageType', false);

        $this->assertSame(500, $summary['total']);
        $this->assertTrue($summary['totalIsAtLeast']);
        $this->assertTrue($summary['more']);
        $this->assertSame(
            'Showing 1–20 of at least 500 (counting stopped there, so there may be more). For more, call list_pages again with offset 20, or narrow with parentPath or pageType.',
            $summary['note']
        );
        $this->assertArrayNotHasKey('totalIsAtLeast', ListWindow::of(20, 0, 20, 100)->summary(312, 20, 'list_pages'));
    }

    public function testTheNoteNamesTheNextOffsetFromTheCurrentOne(): void
    {
        $note = ListWindow::of(20, 20, 20, 100)->note(45, 20, 'list_users');

        $this->assertSame('Showing 21–40 of 45. For more, call list_users again with offset 40.', $note);
    }

    public function testTheLastWindowHasNoMoreAndNoNote(): void
    {
        $summary = ListWindow::of(20, 40, 20, 100)->summary(45, 5, 'list_users');

        $this->assertFalse($summary['more'], 'offset + returned == total is the end');
        $this->assertArrayNotHasKey('note', $summary);
        $this->assertNull(ListWindow::of(20, 0, 20, 100)->note(3, 3, 'list_users'));
    }

    public function testAnOffsetPastTheEndSaysSo(): void
    {
        $summary = ListWindow::of(20, 400, 20, 100)->summary(312, 0, 'list_pages');

        $this->assertFalse($summary['more']);
        $this->assertSame('offset 400 is past the end: there are 312.', $summary['note']);
    }

    public function testAnEmptyListHasNoNote(): void
    {
        $this->assertNull(ListWindow::of(20, 0, 20, 100)->note(0, 0, 'list_files'));
    }

    public function testTheOffsetInputIsDescribed(): void
    {
        $this->assertStringContainsString('offset', ListWindow::OFFSET_DESCRIPTION);
        $this->assertStringContainsString('default 0', ListWindow::OFFSET_DESCRIPTION);
    }
}
