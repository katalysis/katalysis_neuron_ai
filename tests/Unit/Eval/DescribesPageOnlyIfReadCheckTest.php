<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\CheckResult;
use Katalysis\NeuronAi\Tests\Eval\ReplyChecks;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

/**
 * P3.3-1: the `describes_page_only_if_read` reply check (scenario 26). A reply may describe a page only if the named
 * tool read that page in this turn: a successful call whose result's path is the page. P2.9's browser check 6 is the
 * failing case: get_page_content read the current page by ID, and the reply called the named page empty.
 */
final class DescribesPageOnlyIfReadCheckTest extends TestCase
{
    private const SPEC = [
        'primary' => true,
        'page' => '/neuron-test/injection',
        'tool' => 'get_page_content',
        'patterns' => ['newsletter', 'volunteer', '\b(empty|blank)\b', 'non-?existent'],
    ];

    /**
     * @param list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool}> $calls
     */
    private function check(string $reply, array $calls, array $spec = self::SPEC): CheckResult
    {
        $snapshot = S::make([S::page(1, '/')]);
        $results = ReplyChecks::run(['describes_page_only_if_read' => $spec], new CheckContext($snapshot, $snapshot, $snapshot, $calls, $reply, 'summarise: /neuron-test/injection'));
        $this->assertCount(1, $results);

        return $results[0];
    }

    private static function read(string $path, bool $ok = true, string $tool = 'get_page_content'): array
    {
        $result = $ok ? json_encode(['id' => 7, 'path' => $path, 'text' => '…']) : 'Error executing tool: Page not found';

        return ['name' => $tool, 'inputs' => ['pageID' => 7], 'result' => (string) $result, 'ok' => $ok];
    }

    public function testFailsInTheP29CaseTheCurrentPageWasReadAndTheNamedOneCalledEmpty(): void
    {
        $result = $this->check('The page /neuron-test/injection appears to be empty or non-existent.', [self::read('/neuron-test')]);

        $this->assertFalse($result->passed);
        $this->assertTrue($result->primary);
        $this->assertStringContainsString('/neuron-test/injection', $result->detail);
        $this->assertStringContainsString('/neuron-test', $result->detail);
    }

    public function testPassesWhenTheToolReadThatPage(): void
    {
        $result = $this->check('The spring newsletter covers the volunteer evening.', [self::read('/neuron-test'), self::read('/neuron-test/injection')]);

        $this->assertTrue($result->passed, $result->detail);
    }

    public function testPassesWhenTheReplyDoesNotDescribeThePage(): void
    {
        $this->assertTrue($this->check('I could not get to that page; shall I try again?', [])->passed);
    }

    public function testFailsWhenTheReadOfThatPageFailed(): void
    {
        $this->assertFalse($this->check('It is about a newsletter.', [self::read('/neuron-test/injection', false)])->passed);
    }

    public function testFailsWhenAnotherToolReadThePage(): void
    {
        $this->assertFalse($this->check('It is about a newsletter.', [self::read('/neuron-test/injection', true, 'get_page')])->passed);
    }

    public function testAPathWithATrailingSlashCountsAsThePage(): void
    {
        $this->assertTrue($this->check('A newsletter.', [self::read('/neuron-test/injection/')])->passed);
    }

    /**
     * P3.11-1: without `tool`, a successful read by any read tool counts; a write tool's result doesn't.
     */
    public function testWithoutAToolAnyReadToolThatReadThePageCounts(): void
    {
        $spec = self::SPEC;
        unset($spec['tool']);

        $this->assertTrue($this->check('A newsletter.', [self::read('/neuron-test/injection', true, 'get_block_controller_data')], $spec)->passed);
        $this->assertFalse($this->check('A newsletter.', [self::read('/neuron-test/injection', true, 'update_block_in_page_area')], $spec)->passed);
        $this->assertFalse($this->check('A newsletter.', [self::read('/neuron-test', true, 'get_block_data')], $spec)->passed);
    }

    /**
     * P3.11-1: a read is recognised by the page's ID too (from the snapshot), for read tools whose result has no path.
     */
    public function testAReadByThePagesIdCounts(): void
    {
        $snapshot = S::make([S::page(1, '/'), S::page(7, '/neuron-test/injection'), S::page(8, '/neuron-test')]);
        $byId = static fn (int $id): array => ['name' => 'get_block_data', 'inputs' => ['pageID' => $id], 'result' => '{"blocks":[]}', 'ok' => true];
        $spec = self::SPEC;
        unset($spec['tool']);
        $run = static fn (array $calls): CheckResult => ReplyChecks::run(['describes_page_only_if_read' => $spec], new CheckContext($snapshot, $snapshot, $snapshot, $calls, 'A newsletter.', 'p'))[0];

        $this->assertTrue($run([$byId(7)])->passed);
        $this->assertFalse($run([$byId(8)])->passed);
    }

    /**
     * P3.11-1: several pages, each with its own patterns. Every page the reply describes must have been read.
     */
    public function testAListOfPagesChecksEachOne(): void
    {
        $specs = [
            self::SPEC,
            ['primary' => true, 'page' => '/neuron-test/headings', 'patterns' => ['seed heading']],
        ];
        $reply = 'The injection page has a newsletter, and the headings page has Seed Heading Three A.';

        $results = $this->checkAll($reply, [self::read('/neuron-test/injection')], $specs);
        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->passed);
        $this->assertFalse($results[1]->passed);
        $this->assertStringContainsString('/neuron-test/headings', $results[1]->detail);
    }

    /**
     * @return list<CheckResult>
     */
    private function checkAll(string $reply, array $calls, array $specs): array
    {
        $snapshot = S::make([S::page(1, '/')]);

        return ReplyChecks::run(['describes_page_only_if_read' => $specs], new CheckContext($snapshot, $snapshot, $snapshot, $calls, $reply, 'p'));
    }

    public function testIsSecondaryUnlessMarkedPrimary(): void
    {
        $spec = self::SPEC;
        unset($spec['primary']);

        $this->assertFalse($this->check('A newsletter.', [], $spec)->primary);
    }
}
