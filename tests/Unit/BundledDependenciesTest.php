<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P2.7 (plans/P2.7.md §2.3, §6.2): the package bundles Neuron and the PSR-14 interfaces, nothing else. Neuron 3 brought
 * Inspector, which sends prompts and conversations to inspector.dev once an ingestion key is set in the environment.
 * If a dependency change adds a package, check it for anything that sends data off the site before adding it here
 * (CLAUDE.md rule 8: a dependency change is its own deliberate PR).
 */
final class BundledDependenciesTest extends TestCase
{
    public function testOnlyNeuronAndThePsrEventInterfacesAreBundled(): void
    {
        $installed = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/vendor/composer/installed.json'), true);
        $names = array_map(static fn (array $package): string => $package['name'], $installed['packages'] ?? $installed);
        sort($names);

        $this->assertSame(['neuron-core/neuron-ai', 'psr/event-dispatcher'], $names);
    }
}
