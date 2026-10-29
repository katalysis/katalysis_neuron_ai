<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\CheckResult;
use Katalysis\NeuronAi\Tests\Eval\ReplyChecks;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

/**
 * P3.7-2 (P3.11-1's proposal): reply_matches_changed. A write tool's result can say what it changed in one line
 * (`changed`, batch_update_content_headings since P3.3-1); the heading levels and counts the reply claims must match
 * it. The replies are recorded ones.
 */
final class ReplyMatchesChangedCheckTest extends TestCase
{
    private const HEADINGS = '{"ok":true,"changed":"Changed 2 headings to <h2>: \"Seed Heading Three A\", \"Seed Heading Three B\"."}';
    private const BOLD = '{"ok":true,"changed":"Converted 2 bold paragraphs to <h3>: \"Column One Bold Heading A\", \"Column One Bold Heading B\". No existing headings were changed."}';

    private function check(string $reply, string ...$results): CheckResult
    {
        $calls = array_map(static fn (string $r): array => ['name' => 'batch_update_content_headings', 'inputs' => [], 'result' => $r, 'ok' => true], $results);
        $snapshot = S::make([S::page(1, '/')]);

        return ReplyChecks::replyMatchesChanged(new CheckContext($snapshot, $snapshot, $snapshot, $calls, $reply, 'Change the headings.'));
    }

    public function testTheRecordedRepliesThatMatchTheirChangedLinePass(): void
    {
        $this->assertTrue($this->check('I changed the two h3 headings "Seed Heading Three A" and "Seed Heading Three B" to h2 headings. The body text was not altered.', self::HEADINGS)->passed);
        $this->assertTrue($this->check('Both headings have been successfully changed from `<h3>` to `<h2>` on your page.', self::HEADINGS)->passed);
        $this->assertTrue($this->check('I have converted 2 bold paragraphs in Column 1 into h3 headings. Everything else on the page remains unchanged.', self::BOLD)->passed);
        $this->assertTrue($this->check('The h3 headings were changed to h2.', self::HEADINGS, self::HEADINGS)->passed, 'two calls, one line each');
    }

    public function testScenario05sRecordedClaimFailsAndIsPrimary(): void
    {
        // P3.3-1: a bold_paragraph call converted bold paragraphs to h3, and the reply said the h3 headings became h2.
        $result = $this->check('The h3 headings "Seed Heading Three A" and "Seed Heading Three B" have been changed to h2.', self::BOLD);

        $this->assertFalse($result->passed);
        $this->assertTrue($result->primary);
        $this->assertStringContainsString('h2', $result->detail);
    }

    public function testACountTheChangedLineDoesNotGiveFails(): void
    {
        $this->assertFalse($this->check('I changed 3 headings to h2.', self::HEADINGS)->passed);
        $this->assertTrue($this->check('I changed 4 headings to h2.', self::HEADINGS, self::HEADINGS)->passed, 'the total of the calls');
    }

    public function testNoChangedLineMeansNothingToCompare(): void
    {
        $this->assertTrue($this->check('I changed 9 headings to h5.', '{"ok":true,"blockID":12}')->passed);
        $this->assertTrue($this->check('I changed 9 headings to h5.', '{"ok":false,"changed":"Changed 2 headings to <h2>."}')->passed, 'a failed call changed nothing');
    }
}
