<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\ResultsWriter;
use PHPUnit\Framework\TestCase;

final class ResultsWriterTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function makeRun(int $n, string $outcome, ?array $usage, array $over = []): array
    {
        return array_merge(['n' => $n, 'outcome' => $outcome, 'error' => null, 'durationSec' => 10.0, 'usage' => $usage, 'turns' => [], 'checks' => []], $over);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $runsById
     *
     * @return array<string, mixed>
     */
    private function results(array $runsById): array
    {
        $scenarios = [];
        foreach ($runsById as $id => $runs) {
            $scenarios[$id] = ['id' => $id, 'title' => $id, 'knownToFailToday' => $id === '09-x' ? 'because' : null, 'runs' => $runs];
        }

        return ['meta' => [
            'startedAt' => '2026-10-19 15:00:00', 'durationSec' => 42.0, 'repeat' => 3, 'filter' => null, 'model' => 'openai gpt-4o',
            'commit' => 'abc1234', 'neuronVersion' => '3.15.26', 'concreteVersion' => '9.5.5', 'phpVersion' => '8.4.0',
        ], 'scenarios' => $scenarios];
    }

    public function testOutcomeLabelShowsAPassRateWhenRepeated(): void
    {
        $this->assertSame('pass', ResultsWriter::outcomeLabel([$this->makeRun(1, 'pass', null)]));
        $this->assertSame('fail', ResultsWriter::outcomeLabel([$this->makeRun(1, 'fail', null)]));
        $this->assertSame('2/3 pass, 1/3 partial', ResultsWriter::outcomeLabel([$this->makeRun(1, 'pass', null), $this->makeRun(2, 'partial', null), $this->makeRun(3, 'pass', null)]));
        $this->assertSame('1/4 pass, 2/4 fail, 1/4 error', ResultsWriter::outcomeLabel([$this->makeRun(1, 'fail', null), $this->makeRun(2, 'pass', null), $this->makeRun(3, 'fail', null), $this->makeRun(4, 'error', null)]));
    }

    public function testUsageIsSummedAndSaysWhenNotReported(): void
    {
        $runs = [$this->makeRun(1, 'pass', ['input' => 1000, 'output' => 200]), $this->makeRun(2, 'pass', null), $this->makeRun(3, 'pass', ['input' => 500, 'output' => 50])];

        $this->assertSame(['input' => 1500, 'output' => 250], ResultsWriter::usageOf($runs));
        $this->assertSame('1,500 / 250', ResultsWriter::usageLabel(ResultsWriter::usageOf($runs)));
        $this->assertNull(ResultsWriter::usageOf([$this->makeRun(1, 'pass', null)]));
        $this->assertSame('not reported', ResultsWriter::usageLabel(null));
    }

    public function testMarkdownHasATableAPassRateAndTotals(): void
    {
        $md = ResultsWriter::toMarkdown($this->results([
            '01-a' => [$this->makeRun(1, 'pass', ['input' => 1000, 'output' => 100]), $this->makeRun(2, 'partial', ['input' => 2000, 'output' => 300]), $this->makeRun(3, 'pass', ['input' => 10, 'output' => 1])],
            '09-x' => [$this->makeRun(1, 'fail', ['input' => 5, 'output' => 5]), $this->makeRun(2, 'fail', ['input' => 5, 'output' => 5]), $this->makeRun(3, 'fail', ['input' => 5, 'output' => 5])],
        ]));

        $this->assertStringContainsString('| 01-a | 2/3 pass, 1/3 partial | 3,010 / 401 |', $md);
        $this->assertStringContainsString('| 09-x | 3/3 fail |', $md);
        $this->assertMatchesRegularExpression('/\| 09-x \|.*\| yes \|/', $md, 'known_to_fail_today is shown as an expectation');
        $this->assertStringContainsString('**Total:** 2 pass, 1 partial, 3 fail of 6 runs.', $md);
        $this->assertStringContainsString('3,025 / 416', $md);
    }

    public function testMarkdownSaysWhenNoUsageWasReported(): void
    {
        $md = ResultsWriter::toMarkdown($this->results(['01-a' => [$this->makeRun(1, 'pass', null)]]));

        $this->assertStringContainsString('| 01-a | pass | not reported |', $md);
        $this->assertStringContainsString('the provider returned no usage data on any message', $md);
    }

    public function testDetailsListFailedChecksAndTheRealErrorCause(): void
    {
        $failedCheck = ['group' => 'state', 'name' => 'nothing_changed', 'passed' => false, 'primary' => true, 'where' => 'turn 1', 'detail' => 'pages changed: /x'];
        $secondary = ['group' => 'reply', 'name' => 'matches_all', 'passed' => false, 'primary' => false, 'where' => 'end', 'detail' => 'no match for: draft'];
        $turn = ['say' => 's', 'page' => '/p', 'reply' => 'r', 'agentError' => true, 'durationSec' => 1.0, 'usage' => null, 'toolCalls' => [], 'logErrors' => ['Neuron AI Error: 401 Unauthorized'], 'checks' => [$failedCheck]];

        $md = ResultsWriter::toMarkdown($this->results([
            '01-a' => [$this->makeRun(1, 'error', null, ['turns' => [$turn], 'checks' => [$secondary]])],
            '02-b' => [$this->makeRun(1, 'pass', null)],
        ]));

        $this->assertStringContainsString('### 01-a (run 1): error', $md);
        $this->assertStringContainsString('logged error: Neuron AI Error: 401 Unauthorized', $md);
        $this->assertStringContainsString('the agent returned its generic error reply', $md);
        $this->assertStringContainsString('turn 1, state check `nothing_changed` failed: pages changed: /x', $md);
        $this->assertStringContainsString('end, secondary reply check `matches_all` failed', $md);
        $this->assertStringNotContainsString('02-b (run', $md, 'passing runs have no details');
    }

    public function testJsonCarriesTheSameResultsForALaterCompare(): void
    {
        $json = json_decode(ResultsWriter::toJson($this->results([
            '01-a' => [$this->makeRun(1, 'pass', ['input' => 10, 'output' => 2]), $this->makeRun(2, 'fail', null)],
        ])), true);

        $this->assertSame(ResultsWriter::SCHEMA, $json['schema']);
        $this->assertSame('1/2 pass, 1/2 fail', $json['scenarios']['01-a']['summary']);
        $this->assertSame(['input' => 10, 'output' => 2], $json['scenarios']['01-a']['usage']);
        $this->assertSame('fail', $json['scenarios']['01-a']['runs'][1]['outcome']);
        $this->assertSame(['pass' => 1, 'fail' => 1], $json['totals']['outcomes']);
        $this->assertSame(2, $json['totals']['runs']);
        $this->assertSame('3.15.26', $json['meta']['neuronVersion']);
    }

    public function testWriteCreatesTheFolderAndBothFilesWithTheSameTimestamp(): void
    {
        $dir = sys_get_temp_dir() . '/neuron-results-' . bin2hex(random_bytes(4)) . '/nested';

        $paths = ResultsWriter::write($dir, '20261019-150000', $this->results(['01-a' => [$this->makeRun(1, 'pass', null)]]));

        try {
            $this->assertFileExists($paths['md']);
            $this->assertFileExists($paths['json']);
            $this->assertSame('20261019-150000.md', basename($paths['md']));
            $this->assertSame('20261019-150000.json', basename($paths['json']));
        } finally {
            unlink($paths['md']);
            unlink($paths['json']);
            rmdir($dir);
            rmdir(dirname($dir));
        }
    }
}
