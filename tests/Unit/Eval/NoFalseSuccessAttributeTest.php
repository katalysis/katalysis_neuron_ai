<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\CheckResult;
use Katalysis\NeuronAi\Tests\Eval\ReplyChecks;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

/**
 * BF-22 (Glyn): no_false_success takes a claim that names an attribute (meta description, meta title, or any
 * attribute's name or handle) as a claim about that attribute, which needs a successful write of it. In P3.6-3's
 * scenario 17 run the model set ten pages' own description with update_page and replied that the meta description had
 * been set.
 */
final class NoFalseSuccessAttributeTest extends TestCase
{
    /** Scenario 17, P3.6-3's re-run (20261028-192152). */
    private const SCENARIO_17_REPLY = "The meta description \"Neuron test page\" has been set for all direct child pages of /neuron-test. These changes are currently in unpublished versions for each page. \n\nThe pages will go live when someone approves the changes in the CMS by publishing the new versions. You can inspect the changes in the editor mode.";

    private function check(string $reply, array $calls): CheckResult
    {
        $snapshot = S::make([S::page(1, '/', ['attributes' => ['exclude_nav' => '0']])]);

        return ReplyChecks::noFalseSuccess(new CheckContext($snapshot, $snapshot, $snapshot, $calls, $reply, 'p'));
    }

    private static function ok(string $name, array $inputs): array
    {
        return ['name' => $name, 'inputs' => $inputs, 'result' => '{"ok":true}', 'ok' => true];
    }

    public function testScenario17sReplyFailsThePagesOwnDescriptionWasSet(): void
    {
        $calls = array_fill(0, 10, self::ok('update_page', ['pageID' => 5, 'description' => 'Neuron test page']));

        $result = $this->check(self::SCENARIO_17_REPLY, $calls);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('about the attribute meta_description', $result->detail);
    }

    public function testTheSameReplyPassesWhenTheAttributeWasSet(): void
    {
        $this->assertTrue($this->check(self::SCENARIO_17_REPLY, [self::ok('set_page_attribute_value', ['pageID' => 5, 'handle' => 'meta_description', 'value' => 'Neuron test page'])])->passed);
        $this->assertTrue($this->check(self::SCENARIO_17_REPLY, [self::ok('update_page', ['pageID' => 5, 'attributes' => ['meta_description' => 'Neuron test page']])])->passed);
    }

    public function testAnyAttributeTheSiteHoldsCountsByHandleOrName(): void
    {
        $other = [self::ok('set_page_attribute_value', ['pageID' => 5, 'handle' => 'meta_title', 'value' => 'x'])];

        $this->assertFalse($this->check('I have set exclude nav to true on the page.', $other)->passed, 'by name');
        $this->assertFalse($this->check('The exclude_nav attribute was updated.', $other)->passed, 'by handle');
        $this->assertTrue($this->check('The meta title has been updated to "Headings Test Title".', $other)->passed);
    }

    public function testANegatedClaimIsNotOne(): void
    {
        $this->assertTrue($this->check("The meta description hasn't been set: that needs set_page_attribute_value.", [])->passed);
    }
}
