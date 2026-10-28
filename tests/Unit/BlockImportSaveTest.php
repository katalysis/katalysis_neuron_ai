<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\Utilities\BlockImportSave;
use PHPUnit\Framework\TestCase;

/**
 * P3.3-7: a block's save() may delete rows in a side table the export doesn't describe (as Image's does with its
 * thumbnail types). An update that lost such rows, which the call didn't ask about, is refused; rows that changed only
 * their row ID are not a loss.
 */
final class BlockImportSaveTest extends TestCase
{
    public function testRowsASaveRemovedAreNamedByTable(): void
    {
        $before = ['btSide' => [['id' => 1, 'handle' => 'a'], ['id' => 2, 'handle' => 'b']], 'btOther' => [['id' => 3, 'x' => '1']]];
        $after = ['btSide' => [['id' => 7, 'handle' => 'a']], 'btOther' => [['id' => 9, 'x' => '1']]];

        $this->assertSame(['btSide'], BlockImportSave::lostSideRows($before, $after));
    }

    public function testRowsWrittenBackWithNewIDsAreNotALoss(): void
    {
        $before = ['btSide' => [['id' => 1, 'handle' => 'a'], ['id' => 2, 'handle' => 'b']]];
        $after = ['btSide' => [['id' => 5, 'handle' => 'b'], ['id' => 6, 'handle' => 'a']]];

        $this->assertSame([], BlockImportSave::lostSideRows($before, $after));
        $this->assertSame([], BlockImportSave::lostSideRows([], ['btSide' => [['id' => 1, 'handle' => 'new']]]));
    }
}
