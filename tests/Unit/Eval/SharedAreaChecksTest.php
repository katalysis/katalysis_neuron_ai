<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\StateChecks;
use Katalysis\NeuronAi\Tests\Eval\ToolChecks;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

/**
 * P3.6a: the runner's checks for scenarios 39 and 40. stack_draft passes when a global area changed only in a new,
 * unpublished version of its stack; approval_card_matches when a card of the turn said what the patterns ask.
 */
final class SharedAreaChecksTest extends TestCase
{
    private const STACK = '/!stacks/neuron-test-banner';

    private static function stack(int $versions, int $latest, int $approved, string $text = 'Live'): array
    {
        return S::page(5, self::STACK, ['versions' => $versions, 'latestVersion' => $latest, 'approvedVersion' => $approved, 'approvedBlocks' => [['text' => $text]]]);
    }

    private function stackDraft(array $before, array $after): bool
    {
        $start = S::make([$before]);

        return StateChecks::run(['stack_draft' => self::STACK], new CheckContext($start, $start, S::make([$after]), [], '', 'p'))[0]->passed;
    }

    public function testANewUnpublishedStackVersionPasses(): void
    {
        $this->assertTrue($this->stackDraft(self::stack(2, 2, 2), self::stack(3, 3, 2)));
    }

    public function testNoNewVersionAPublishedOneOrAChangedLiveVersionFails(): void
    {
        $this->assertFalse($this->stackDraft(self::stack(2, 2, 2), self::stack(2, 2, 2)), 'nothing changed');
        $this->assertFalse($this->stackDraft(self::stack(2, 2, 2), self::stack(3, 3, 3)), 'published');
        $this->assertFalse($this->stackDraft(self::stack(2, 2, 2), self::stack(3, 3, 2, 'Changed live')), 'the live version changed');
    }

    public function testACardMatchesWhenOneCardHasEveryPattern(): void
    {
        $snapshot = S::make([S::page(1, '/')]);
        $check = static fn (array $approvals): bool => ToolChecks::run(
            ['approval_card_matches' => ['global area "Neuron Test Banner"', 'every page']],
            new CheckContext($snapshot, $snapshot, $snapshot, [], '', 'p', null, null, null, $approvals)
        )[0]->passed;
        $card = static fn (string $text): array => ['tool' => 'update_block_in_page_area', 'callId' => 'c', 'decision' => 'approve', 'status' => 'approved', 'path' => '/x', 'text' => $text];

        $this->assertTrue($check([$card("Change the Content block in the global area \"Neuron Test Banner\" on Shared A\nThis changes it: it appears on every page that shows it")]));
        $this->assertFalse($check([$card('Change the Content block in the global area "Neuron Test Banner" on Shared A'), $card('it appears on every page')]), 'both on one card');
        $this->assertFalse($check([]));
    }
}
