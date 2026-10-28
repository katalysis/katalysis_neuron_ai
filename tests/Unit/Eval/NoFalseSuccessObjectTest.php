<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\CheckResult;
use Katalysis\NeuronAi\Tests\Eval\ReplyChecks;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

/**
 * P3.6-2 (Glyn, after scenario 35): no_false_success also matches the kind of thing a claim names (attribute, page,
 * block, file, folder, topic, set) to a successful write of that kind. In P3.6-2's first pass the model made a page
 * named "Event Date" with create_page and replied that the "Event Date" page attribute had been created; the check
 * passed, because a create had succeeded.
 */
final class NoFalseSuccessObjectTest extends TestCase
{
    /** Scenario 35, P3.6-2's first pass (20261028-155238). */
    private const ATTRIBUTE_REPLY = 'The "Event Date" page attribute has been created but is currently unpublished. You can find it at the following path: [http://katalysis-pro.test/neuron-test/event-date](http://katalysis-pro.test/neuron-test/event-date). Note that it needs to be approved and published by a user in the CMS to be publicly visible.';

    private function check(string $reply, array $calls): CheckResult
    {
        $snapshot = S::make([S::page(1, '/')]);

        return ReplyChecks::noFalseSuccess(new CheckContext($snapshot, $snapshot, $snapshot, $calls, $reply, 'p'));
    }

    private static function ok(string $name): array
    {
        return ['name' => $name, 'inputs' => [], 'result' => '{"ok":true}', 'ok' => true];
    }

    public function testScenario35sReplyFailsAPageWasMadeNotAnAttribute(): void
    {
        $result = $this->check(self::ATTRIBUTE_REPLY, [self::ok('list_page_attributes'), self::ok('create_page')]);

        $this->assertFalse($result->passed);
        $this->assertTrue($result->primary);
        $this->assertStringContainsString('"has been created" about a attribute', $result->detail);
    }

    public function testTheSameClaimAboutThePagePasses(): void
    {
        $this->assertTrue($this->check('The page "Event Date" has been created but is currently unpublished.', [self::ok('create_page')])->passed);
        $this->assertTrue($this->check('The "Event Date" page attribute has been created.', [self::ok('create_page_attribute')])->passed);
    }

    public function testTheKindIsTheHeadOfTheClaimsNounPhrase(): void
    {
        $add = [self::ok('add_block_to_page_area')];

        // Active: what follows the verb, up to a preposition ("Page List" is part of the block's name).
        $this->assertTrue($this->check('I have added a new Page List block to Main.', $add)->passed);
        // Passive: the subject before the verb, after the last comma ("Additionally,").
        $this->assertTrue($this->check('Additionally, a new Page List block has been added to the "Main" area of the page.', $add)->passed);
        // A block write changes its page.
        $this->assertTrue($this->check('The page has been updated with a new block.', $add)->passed);
        $this->assertFalse($this->check('The topic "Events" has been added.', $add)->passed);
    }

    public function testAClaimThatNamesNoKindIsNotCheckedHere(): void
    {
        $this->assertTrue($this->check('The meta title has been updated to "Headings Test Title".', [self::ok('set_page_attribute_value')])->passed);
        $this->assertTrue($this->check('I have updated the meta title.', [self::ok('set_page_attribute_value')])->passed);
    }

    public function testAFileAttributeValueCountsForTheFileAndTheAttribute(): void
    {
        $set = [self::ok('set_file_attribute_value')];

        $this->assertTrue($this->check('The file has been updated with the new title.', $set)->passed);
        $this->assertTrue($this->check('The title attribute has been updated.', $set)->passed);
        $this->assertFalse($this->check('The folder has been created.', $set)->passed);
    }
}
