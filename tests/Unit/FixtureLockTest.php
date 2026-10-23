<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tests\Support\FixtureLock;
use Katalysis\NeuronAi\Tests\Support\FixtureLockHeld;
use PHPUnit\Framework\TestCase;

/**
 * WP-2: the shared fixture lock, against a temporary lock file and its own environment variable, so it never touches
 * the real lock that this PHPUnit run may hold. Other processes are plain `php -r` children.
 */
final class FixtureLockTest extends TestCase
{
    private const ENV = 'NEURON_FIXTURE_LOCK_UNIT_TEST';

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/neuron-fixture-lock-test-' . bin2hex(random_bytes(6));
        putenv(self::ENV);
    }

    protected function tearDown(): void
    {
        putenv(self::ENV);
        @unlink($this->path);
    }

    public function testWhoeverStartsSecondIsRefusedWithWhatHoldsTheLockAndSinceWhen(): void
    {
        $first = FixtureLock::acquire('the scenario runner (run_scenarios.php --repeat=3)', $this->path, self::ENV);
        putenv(self::ENV); // a separate run, not a child of the first

        try {
            FixtureLock::acquire('the PHPUnit integration tests', $this->path, self::ENV);
            $this->fail('The second acquire must be refused.');
        } catch (FixtureLockHeld $e) {
            $message = $e->getMessage();
        }

        $this->assertStringStartsWith('Refusing to start the PHPUnit integration tests:', $message);
        $this->assertStringContainsString('in use by the scenario runner (run_scenarios.php --repeat=3) (process ' . getmypid() . ', since ', $message);
        $this->assertMatchesRegularExpression('/since \d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC\)/', $message);
        $this->assertStringContainsString('released when that process ends, even if it crashes', $message);
        $this->assertStringContainsString($this->path, $message);
        $this->assertFalse($first->inherited);
    }

    public function testTheLockCanBeTakenAgainOnceReleased(): void
    {
        FixtureLock::acquire('the seed', $this->path, self::ENV)->release();
        $this->assertFalse(getenv(self::ENV), 'Releasing removes the token from the environment.');

        $again = FixtureLock::acquire('the scenario runner', $this->path, self::ENV);

        $this->assertFalse($again->inherited);
        $again->release();
    }

    public function testAnotherProcessIsRefused(): void
    {
        $lock = FixtureLock::acquire('the PHPUnit integration tests', $this->path, self::ENV);

        $child = $this->child('the seed', withToken: false);

        $this->assertSame(3, $child['code'], $child['output']);
        $this->assertStringContainsString('Refusing to start the seed: ', $child['output']);
        $this->assertStringContainsString('in use by the PHPUnit integration tests (process ' . getmypid(), $child['output']);
        $lock->release();
    }

    public function testAChildWithTheHoldersTokenSharesTheLockAndLeavesItHeld(): void
    {
        $lock = FixtureLock::acquire('the scenario runner', $this->path, self::ENV);

        $child = $this->child('the seed', withToken: true);

        $this->assertSame(0, $child['code'], $child['output']);
        $this->assertStringContainsString('inherited', $child['output']);
        // The child's end must not have released the parent's lock.
        $this->assertSame(3, $this->child('the PHPUnit integration tests', withToken: false)['code']);
        $lock->release();
    }

    /**
     * Stale locks: the lock is an flock(), which the operating system drops when the holding process ends, however
     * it ends. A holder killed with SIGKILL (no destructor, no release) leaves only its details in the file.
     */
    public function testALockLeftByAKilledProcessDoesNotBlock(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the posix extension to kill the child.');
        }

        $killed = $this->php(
            '$held = FixtureLock::acquire("the scenario runner", $argv[1], $argv[2]); posix_kill(getmypid(), 9);',
            [$this->path, self::ENV],
            null
        );
        $this->assertNotSame(0, $killed['code']);
        $this->assertStringContainsString('the scenario runner', (string) file_get_contents($this->path), 'The killed holder left its details.');

        $lock = FixtureLock::acquire('the PHPUnit integration tests', $this->path, self::ENV);

        $this->assertFalse($lock->inherited);
        $this->assertStringContainsString('the PHPUnit integration tests', (string) file_get_contents($this->path), 'The new holder overwrote them.');
        $lock->release();
    }

    public function testATokenFromADeadHolderDoesNotCountAsTheLock(): void
    {
        FixtureLock::acquire('the scenario runner', $this->path, self::ENV)->release();
        putenv(self::ENV . '=a-token-nobody-holds');

        $lock = FixtureLock::acquire('the seed', $this->path, self::ENV);

        $this->assertFalse($lock->inherited, 'Nobody holds the lock, so the seed takes it itself.');
        $lock->release();
    }

    public function testTheRefusalSaysSoWhenTheHolderHasWrittenNoDetails(): void
    {
        $message = FixtureLock::refusal('the seed', [], '/x/.fixture-lock');

        $this->assertStringContainsString('in use by another process (it has not written its details yet)', $message);
        $this->assertStringNotContainsString('If process', $message);
    }

    /**
     * A child that tries to take the lock: exit 0 and "inherited" or "own" when it got it, 3 and the message when not.
     *
     * @return array{code: int, output: string}
     */
    private function child(string $holder, bool $withToken): array
    {
        $env = getenv();
        if (!$withToken) {
            unset($env[self::ENV]);
        }

        return $this->php(
            'try { $l = FixtureLock::acquire($argv[3], $argv[1], $argv[2]); echo $l->inherited ? "inherited" : "own"; }'
            . ' catch (FixtureLockHeld $e) { echo $e->getMessage(); exit(3); }',
            [$this->path, self::ENV, $holder],
            $env
        );
    }

    /**
     * @param list<string> $args
     * @param array<string, string>|null $env
     *
     * @return array{code: int, output: string}
     */
    private function php(string $code, array $args, ?array $env): array
    {
        $support = dirname(__DIR__) . '/Support';
        $prelude = sprintf(
            'require %s; require %s; use Katalysis\NeuronAi\Tests\Support\FixtureLock; use Katalysis\NeuronAi\Tests\Support\FixtureLockHeld; ',
            var_export($support . '/FixtureLock.php', true),
            var_export($support . '/FixtureLockHeld.php', true)
        );
        $process = proc_open([PHP_BINARY, '-r', $prelude . $code, '--', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'output' => $output];
    }
}
