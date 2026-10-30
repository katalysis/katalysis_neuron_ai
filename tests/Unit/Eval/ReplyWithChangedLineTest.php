<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\ReplyChecks;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

/**
 * P3.12 fixes (Glyn): a reply check marked `with_changed_line` reads the reply and the turn's stored "Changed: …" line
 * together, since the panel shows that line under the reply (P3.6-1; BF-24 put the path and undo there). Without the
 * flag only the reply is read, as before.
 */
final class ReplyWithChangedLineTest extends TestCase
{
    private const REPLY = 'Done. You can see it on the page now; it goes live when you publish.';
    private const LINE = 'Changed: Headings (/neuron-test/headings), unpublished version 2.';

    private function context(?string $line): CheckContext
    {
        $s = S::make([S::page(1, '/')]);

        return new CheckContext($s, $s, $s, [], self::REPLY, 'Change the headings.', changesLine: $line);
    }

    public function testWithTheFlagThePathInTheChangedLineCounts(): void
    {
        $results = ReplyChecks::run(['matches_all' => ['with_changed_line' => true, 'patterns' => ['neuron-test/headings', 'unpublished']]], $this->context(self::LINE));

        $this->assertTrue($results[0]->passed, $results[0]->detail);
        $this->assertFalse($results[0]->primary, 'still secondary unless marked primary');
    }

    public function testWithoutTheFlagOnlyTheReplyIsRead(): void
    {
        $results = ReplyChecks::run(['matches_all' => ['neuron-test/headings']], $this->context(self::LINE));

        $this->assertFalse($results[0]->passed);
    }

    public function testWithTheFlagButNoLineOnlyTheReplyIsRead(): void
    {
        $results = ReplyChecks::run(['matches_all' => ['with_changed_line' => true, 'patterns' => ['neuron-test/headings']]], $this->context(null));

        $this->assertFalse($results[0]->passed);
    }
}
