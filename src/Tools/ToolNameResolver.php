<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Support\Facade\Log;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * Builds the agent's tool list from the core toolkits and the toolkits other
 * packages registered, so that no tool name appears twice (providers reject
 * duplicate function names).
 *
 * Rules (P1.6):
 * - A tool from an external, package-owned toolkit beats a core tool of the
 *   same name. The core tool is dropped for the request.
 * - Between two external toolkits, the first one registered wins.
 * - Every dropped tool is logged as a warning.
 */
final class ToolNameResolver
{
    /**
     * @param array<int, mixed> $core     core toolkits and tools
     * @param array<int, mixed> $external toolkits and tools from ExternalToolkitRegistry, in registration order
     * @param callable(string, string): void|null $logger called as ($level, $message); defaults to the Concrete log
     * @return array<int, mixed> core items first, then external items
     */
    public static function resolve(array $core, array $external, ?callable $logger = null): array
    {
        $logger ??= static function (string $level, string $message): void {
            Log::$level($message);
        };

        // Pass 1: external tools. The first registered owner of a name keeps it.
        $externalOwners = [];
        $externalItems = [];
        foreach ($external as $item) {
            $externalItems[] = self::filter($item, static function (ToolInterface $tool) use (&$externalOwners, $item, $logger): bool {
                $name = $tool->getName();
                if (isset($externalOwners[$name])) {
                    $logger('warning', sprintf(
                        'Katalysis Neuron AI: tool "%s" from %s dropped; already provided by %s (first registered wins).',
                        $name,
                        $item::class,
                        $externalOwners[$name]
                    ));

                    return false;
                }
                $externalOwners[$name] = $item::class;

                return true;
            });
        }

        // Pass 2: core tools lose to any external tool of the same name.
        $coreItems = [];
        foreach ($core as $item) {
            $coreItems[] = self::filter($item, static function (ToolInterface $tool) use ($externalOwners, $item, $logger): bool {
                $name = $tool->getName();
                if (isset($externalOwners[$name])) {
                    $logger('warning', sprintf(
                        'Katalysis Neuron AI: core tool "%s" from %s dropped; replaced by the tool from %s.',
                        $name,
                        $item::class,
                        $externalOwners[$name]
                    ));

                    return false;
                }

                return true;
            });
        }

        // A single tool that was dropped comes back as null.
        return array_values(array_filter(
            array_merge($coreItems, $externalItems),
            static fn (mixed $item): bool => $item !== null
        ));
    }

    /**
     * Keeps the tools for which $keep returns true. A toolkit that loses
     * nothing is returned untouched. Items that are neither tools nor
     * toolkits pass through.
     *
     * Note: for toolkits this calls tools() once more than Neuron does when it
     * builds the list. That is harmless for in-process toolkits, but an MCP
     * connector toolkit would connect once more. None is registered (the
     * unused McpServerIntegration was deleted in P3.3-3).
     *
     * @param callable(ToolInterface): bool $keep
     */
    private static function filter(mixed $item, callable $keep): mixed
    {
        if ($item instanceof ToolInterface) {
            return $keep($item) ? $item : null;
        }
        if (!$item instanceof ToolkitInterface) {
            return $item;
        }

        $kept = [];
        $removed = false;
        foreach ($item->tools() as $tool) {
            if ($tool instanceof ToolInterface && !$keep($tool)) {
                $removed = true;
                continue;
            }
            $kept[] = $tool;
        }

        return $removed ? FilteredToolkit::from($item, $kept) : $item;
    }
}
