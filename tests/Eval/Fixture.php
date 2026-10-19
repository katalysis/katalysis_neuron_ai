<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * Puts the dev site back to the known test content by running the seed script with --reset
 * in its own PHP process, so the seed script runs exactly as documented in SETUP.md.
 */
final class Fixture
{
    public function __construct(
        private string $siteDir,
        private string $seedScript,
        private string $php = PHP_BINARY,
    ) {
    }

    /**
     * @throws \RuntimeException when the reset did not succeed; the fixture state is then unknown
     */
    public function reset(): void
    {
        $command = [$this->php, 'concrete/bin/concrete', 'c5:exec', $this->seedScript, '--', '--reset'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->siteDir);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the seed script.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            $tail = implode("\n", array_slice(explode("\n", trim($stdout . "\n" . $stderr)), -8));
            throw new \RuntimeException("The seed script --reset failed (exit code {$code}):\n{$tail}");
        }
    }
}
