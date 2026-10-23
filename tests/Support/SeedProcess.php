<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

/**
 * Runs documentation/fixtures/seed_test_content.php in its own PHP process, exactly as SETUP.md documents it
 * (`php concrete/bin/concrete c5:exec <seed> -- [options]` from httpdocs/), and returns the exit code and what it
 * printed. Used by the scenario runner's Fixture and by SeedResetTest.
 */
final class SeedProcess
{
    public function __construct(
        private string $siteDir,
        private string $seedScript,
        private string $php = PHP_BINARY,
    ) {
    }

    /**
     * The seed in this package, for the site this package is installed in.
     */
    public static function forThisPackage(): self
    {
        $packageDir = dirname(__DIR__, 2);

        return new self(dirname($packageDir, 2), $packageDir . '/documentation/fixtures/seed_test_content.php');
    }

    /**
     * @param list<string> $options for the seed, such as ['--reset']
     * @param array<string, string>|null $env the process environment; null inherits this one (and so the fixture lock, WP-2)
     *
     * @return array{code: int, output: string} output is stdout then stderr
     *
     * @throws \RuntimeException when the process could not be started
     */
    public function run(array $options = [], ?array $env = null): array
    {
        $command = [$this->php, 'concrete/bin/concrete', 'c5:exec', $this->seedScript];
        if ($options !== []) {
            $command[] = '--';
            array_push($command, ...$options);
        }

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->siteDir, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the seed script.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'output' => trim($stdout . "\n" . $stderr)];
    }
}
