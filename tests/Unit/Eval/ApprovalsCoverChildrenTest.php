<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\CheckResult;
use Katalysis\NeuronAi\Tests\Eval\ToolChecks;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

/**
 * P3.6-3 (Glyn, scenario 17): the approval cards are the confirmation of a change to several pages. The check passes
 * when the turn's cards named every direct child of the page, as the fixture recorded them, and no other page.
 */
final class ApprovalsCoverChildrenTest extends TestCase
{
    private function check(array $paths): CheckResult
    {
        $snapshot = S::make([
            S::page(1, '/neuron-test', ['children' => [2, 3]]),
            S::page(2, '/neuron-test/a', ['parentID' => 1]),
            S::page(3, '/neuron-test/b', ['parentID' => 1]),
            S::page(4, '/neuron-test/a/grandchild', ['parentID' => 2]),
        ]);
        $approvals = array_map(static fn (string $p): array => ['tool' => 'set_page_attribute_value', 'callId' => 'c' . $p, 'decision' => 'approve', 'status' => 'approved', 'path' => $p], $paths);

        return ToolChecks::run(
            ['approvals_cover_children' => '/neuron-test'],
            new CheckContext($snapshot, $snapshot, $snapshot, [], '', 'p', null, null, null, $approvals)
        )[0];
    }

    public function testACardForEveryChildPasses(): void
    {
        $this->assertTrue($this->check(['/neuron-test/a', '/neuron-test/b'])->passed);
    }

    public function testAMissingChildOrAnotherPageFails(): void
    {
        $missing = $this->check(['/neuron-test/a']);
        $this->assertFalse($missing->passed);
        $this->assertSame('no card for: /neuron-test/b', $missing->detail);

        $other = $this->check(['/neuron-test/a', '/neuron-test/b', '/neuron-test/a/grandchild']);
        $this->assertFalse($other->passed);
        $this->assertSame('cards for other pages: /neuron-test/a/grandchild', $other->detail);
    }

    public function testNoCardsFail(): void
    {
        $this->assertFalse($this->check([])->passed);
    }
}
