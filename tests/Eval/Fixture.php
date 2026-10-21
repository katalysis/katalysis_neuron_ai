<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

use Katalysis\NeuronAi\Tests\Support\SeedProcess;

/**
 * Puts the dev site back to the known test content by running the seed script with --reset
 * in its own PHP process, so the seed script runs exactly as documented in SETUP.md.
 */
final class Fixture
{
    private SeedProcess $seed;

    public function __construct(
        string $siteDir,
        string $seedScript,
        string $php = PHP_BINARY,
    ) {
        $this->seed = new SeedProcess($siteDir, $seedScript, $php);
    }

    /**
     * @throws \RuntimeException when the reset did not succeed; the fixture state is then unknown
     */
    public function reset(): void
    {
        $result = $this->seed->run(['--reset']);

        if ($result['code'] !== 0) {
            $tail = implode("\n", array_slice(explode("\n", $result['output']), -8));
            throw new \RuntimeException("The seed script --reset failed (exit code {$result['code']}):\n{$tail}");
        }
    }
}
