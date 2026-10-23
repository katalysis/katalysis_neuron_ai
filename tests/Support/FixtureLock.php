<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

/**
 * WP-2: one lock for the dev site's shared fixtures (/neuron-test and the rest of seed_test_content.php's content).
 * The seed, the integration tests (ConcreteBoot::boot()) and the scenario runner each take it before they touch the
 * fixture, so whichever starts second refuses, saying what holds the lock and since when.
 *
 * The lock is an flock() on one file in the package folder. The operating system releases it when the holding process
 * ends, however it ends (a crash, a fatal error, Ctrl-C, kill -9), so a crashed run never leaves a lock behind: what
 * is left is only the file, whose details are ignored and overwritten by the next holder. A run that is still alive but
 * stuck does keep the lock; the refusal names its process ID so it can be stopped.
 *
 * A holder's child processes share its lock: the seed that the tests or the runner start in its own process finds
 * the holder's token in the environment (inherited from putenv()) and goes ahead without locking again.
 *
 * No Concrete classes, so the seed script can require this file before Concrete's state matters, and the unit tests
 * can run it against a temporary file.
 */
final class FixtureLock
{
    public const ENV = 'NEURON_FIXTURE_LOCK';

    /**
     * @param resource|null $handle null for a lock shared with the parent process
     */
    private function __construct(
        private $handle,
        private string $env,
        public readonly bool $inherited,
    ) {
    }

    public function __destruct()
    {
        $this->release();
    }

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/.fixture-lock';
    }

    /**
     * @param string $holder what is taking the lock, as the refusal will name it to the next one ("the scenario runner")
     *
     * @throws FixtureLockHeld when another process holds the lock
     * @throws \RuntimeException when the lock file cannot be opened
     */
    public static function acquire(string $holder, ?string $path = null, string $env = self::ENV): self
    {
        $path ??= self::path();
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open the fixture lock file {$path}.");
        }

        if (flock($handle, LOCK_EX | LOCK_NB)) {
            $token = bin2hex(random_bytes(16));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode([
                'holder' => $holder,
                'pid' => getmypid(),
                'since' => gmdate('Y-m-d H:i:s') . ' UTC',
                'token' => $token,
            ]));
            fflush($handle);
            putenv($env . '=' . $token);

            return new self($handle, $env, false);
        }

        $held = self::details($handle);
        fclose($handle);

        $token = getenv($env);
        if (is_string($token) && $token !== '' && hash_equals((string) ($held['token'] ?? ''), $token)) {
            return new self(null, $env, true);
        }

        throw new FixtureLockHeld(self::refusal($holder, $held, $path));
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        ftruncate($this->handle, 0);
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
        putenv($this->env);
    }

    /**
     * @param array<string, mixed> $held what the holder wrote in the lock file (empty if nothing could be read)
     */
    public static function refusal(string $holder, array $held, string $path): string
    {
        if (isset($held['holder'], $held['pid'], $held['since'])) {
            $by = sprintf('%s (process %d, since %s)', (string) $held['holder'], (int) $held['pid'], (string) $held['since']);
            $stuck = sprintf('If process %d is stuck, stop it; ', (int) $held['pid']);
        } else {
            $by = 'another process (it has not written its details yet)';
            $stuck = '';
        }

        return "Refusing to start {$holder}: the dev site's test fixture is in use by {$by}. "
            . 'The seed reset, the integration tests and the scenario runner share one lock, so run them one at a time. '
            . "Wait for it to finish and start again. The lock is released when that process ends, even if it crashes. {$stuck}"
            . "the lock file is {$path}.";
    }

    /**
     * @param resource $handle
     *
     * @return array<string, mixed>
     */
    private static function details($handle): array
    {
        rewind($handle);
        $data = json_decode((string) stream_get_contents($handle), true);

        return is_array($data) ? $data : [];
    }
}
