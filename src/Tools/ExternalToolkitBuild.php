<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

/**
 * What ExternalToolkitRegistry::build() made: the toolkits that built, and the ones that were skipped.
 */
final class ExternalToolkitBuild
{
    /**
     * @param list<object> $toolkits toolkits (each with its tools already built) and any other items a factory returned
     * @param list<array{provider: string, toolkit: ?string, reason: string}> $skipped toolkit is null when the factory itself failed
     */
    public function __construct(
        private readonly array $toolkits,
        private readonly array $skipped,
    ) {
    }

    /**
     * @return list<object>
     */
    public function toolkits(): array
    {
        return $this->toolkits;
    }

    /**
     * @return list<array{provider: string, toolkit: ?string, reason: string}>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }
}
