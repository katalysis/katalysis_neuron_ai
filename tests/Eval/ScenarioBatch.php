<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * Runs each scenario $repeat times, then resets the fixtures once more (WP-3). Each run starts from a reset
 * (ScenarioRunner::run()), but a scenario that writes leaves its change behind after the last run, and the next
 * integration test run then fails with "fixture drift" (found after BF-8 and BF-12: scenario 19's turn 3 left a draft
 * on /neuron-test/columns). The final reset runs even when a failed reset stopped the batch.
 */
final class ScenarioBatch
{
    /**
     * @param array<string, array<string, mixed>> $scenarios by ID
     * @param \Closure(array<string, mixed>, int): array<string, mixed> $runOne ScenarioRunner::run(); throws a
     *        RuntimeException when the fixture reset before a run fails, which stops the batch
     * @param \Closure(): void $reset Fixture::reset()
     * @param (\Closure(string, int, ?array<string, mixed>): void)|null $progress called before a run (null) and after it
     *
     * @return array{scenarios: array<string, array<string, mixed>>, exitCode: int, messages: list<string>}
     */
    public static function run(array $scenarios, int $repeat, \Closure $runOne, \Closure $reset, ?\Closure $progress = null): array
    {
        $results = [];
        $exitCode = 0;
        $messages = [];

        try {
            foreach ($scenarios as $id => $scenario) {
                $results[$id] = [
                    'id' => $id,
                    'title' => $scenario['title'],
                    'knownToFailToday' => $scenario['known_to_fail_today'] ?? null,
                    'runs' => [],
                ];

                for ($n = 1; $n <= $repeat; $n++) {
                    if ($progress !== null) {
                        $progress($id, $n, null);
                    }
                    $run = $runOne($scenario, $n);
                    $results[$id]['runs'][] = $run;
                    if ($progress !== null) {
                        $progress($id, $n, $run);
                    }

                    if ($run['outcome'] === Expectations::ERROR) {
                        $exitCode = 1;
                    }
                }
            }
        } catch (\RuntimeException $e) {
            // A failed fixture reset: the state is unknown, so stop here and still report what was done.
            $messages[] = $e->getMessage();
            $exitCode = 1;
        } finally {
            try {
                $reset();
                $messages[] = 'Reset the fixtures after the last run.';
            } catch (\RuntimeException $e) {
                $messages[] = 'The fixtures could not be reset after the last run, so the integration tests may report fixture drift: ' . $e->getMessage();
                $exitCode = 1;
            }
        }

        return ['scenarios' => $results, 'exitCode' => $exitCode, 'messages' => $messages];
    }
}
