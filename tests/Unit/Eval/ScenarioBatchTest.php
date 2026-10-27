<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\Expectations;
use Katalysis\NeuronAi\Tests\Eval\ScenarioBatch;
use PHPUnit\Framework\TestCase;

/**
 * WP-3: the runner resets the fixtures after its final run as well as before each run, so the integration tests never
 * start with fixture drift. Found after BF-8 and BF-12: scenario 19's turn 3 left a draft on /neuron-test/columns.
 */
final class ScenarioBatchTest extends TestCase
{
    /** @var list<string> what happened, in order */
    private array $log = [];

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function scenarios(string ...$ids): array
    {
        $scenarios = [];
        foreach ($ids as $id) {
            $scenarios[$id] = ['id' => $id, 'title' => 'Title ' . $id];
        }

        return $scenarios;
    }

    private function runOne(?string $failOn = null): \Closure
    {
        return function (array $scenario, int $n) use ($failOn): array {
            $this->log[] = "run {$scenario['id']} {$n}";
            if ($scenario['id'] === $failOn) {
                // A failed fixture reset before a run: ScenarioRunner::run() throws.
                throw new \RuntimeException('The seed script --reset failed');
            }

            return ['n' => $n, 'outcome' => Expectations::PASS, 'error' => null, 'durationSec' => 1.0, 'usage' => null, 'turns' => [], 'checks' => []];
        };
    }

    private function reset(bool $fails = false): \Closure
    {
        return function () use ($fails): void {
            $this->log[] = 'reset';
            if ($fails) {
                throw new \RuntimeException('The seed script --reset failed (exit code 1)');
            }
        };
    }

    public function testTheFixturesAreResetOnceAfterTheLastRun(): void
    {
        $batch = ScenarioBatch::run(self::scenarios('01-a', '02-b'), 2, $this->runOne(), $this->reset());

        $this->assertSame(['run 01-a 1', 'run 01-a 2', 'run 02-b 1', 'run 02-b 2', 'reset'], $this->log);
        $this->assertSame(0, $batch['exitCode']);
        $this->assertCount(2, $batch['scenarios']['01-a']['runs']);
        $this->assertSame('Title 02-b', $batch['scenarios']['02-b']['title']);
        $this->assertContains('Reset the fixtures after the last run.', $batch['messages']);
    }

    public function testTheFixturesAreResetAfterARunStopsTheBatch(): void
    {
        $batch = ScenarioBatch::run(self::scenarios('01-a', '02-b'), 1, $this->runOne('01-a'), $this->reset());

        $this->assertSame(['run 01-a 1', 'reset'], $this->log, 'a failed run stops the batch, and the reset still runs');
        $this->assertSame(1, $batch['exitCode']);
        $this->assertStringContainsString('--reset failed', $batch['messages'][0]);
    }

    public function testAFailedFinalResetIsReportedAndFailsTheExitCode(): void
    {
        $batch = ScenarioBatch::run(self::scenarios('01-a'), 1, $this->runOne(), $this->reset(true));

        $this->assertSame(['run 01-a 1', 'reset'], $this->log);
        $this->assertSame(1, $batch['exitCode']);
        $this->assertStringContainsString('The fixtures could not be reset after the last run', implode("\n", $batch['messages']));
    }

    public function testAnErrorRunFailsTheExitCodeAndTheBatchGoesOn(): void
    {
        $error = fn (array $scenario, int $n): array => ($this->log[] = "run {$scenario['id']} {$n}") !== null
            ? ['n' => $n, 'outcome' => Expectations::ERROR, 'error' => 'boom', 'durationSec' => 1.0, 'usage' => null, 'turns' => [], 'checks' => []]
            : [];

        $batch = ScenarioBatch::run(self::scenarios('01-a', '02-b'), 1, $error, $this->reset());

        $this->assertSame(['run 01-a 1', 'run 02-b 1', 'reset'], $this->log);
        $this->assertSame(1, $batch['exitCode']);
    }

    public function testTheProgressCallbackSeesEachRunBeforeAndAfter(): void
    {
        $seen = [];
        ScenarioBatch::run(
            self::scenarios('01-a'),
            1,
            $this->runOne(),
            $this->reset(),
            function (string $id, int $n, ?array $run) use (&$seen): void {
                $seen[] = $id . ' ' . $n . ' ' . ($run === null ? 'starting' : $run['outcome']);
            }
        );

        $this->assertSame(['01-a 1 starting', '01-a 1 pass'], $seen);
    }
}
