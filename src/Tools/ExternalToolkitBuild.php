<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Katalysis\NeuronAi\Extension\Tier;

/**
 * What ExternalToolkitRegistry::build() made: the toolkits that built, the ones that were skipped, and each external
 * tool's tier (P3.2-1).
 */
final class ExternalToolkitBuild
{
    /**
     * @param list<object> $toolkits toolkits (each with its tools already built) and any other items a factory returned
     * @param list<array{provider: string, toolkit: ?string, reason: string}> $skipped toolkit is null when the factory itself failed
     * @param array<string, Tier> $tiers tool name => the tier it needs, declared or by default (B-ACC-8)
     */
    public function __construct(
        private readonly array $toolkits,
        private readonly array $skipped,
        private readonly array $tiers = [],
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

    /**
     * @return array<string, Tier>
     */
    public function tiers(): array
    {
        return $this->tiers;
    }
}
