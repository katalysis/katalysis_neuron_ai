<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use NeuronAI\Tools\ToolInterface;

/**
 * Checks on a list of tools, kept apart from the registry test so that they can
 * be unit-tested with synthetic tools (they must be shown to fail).
 */
final class ToolListChecks
{
    /**
     * @param ToolInterface[] $tools
     * @return string[] names used by more than one tool, each listed once
     */
    public static function duplicateNames(array $tools): array
    {
        $counts = array_count_values(array_map(static fn (ToolInterface $t): string => $t->getName(), $tools));

        return array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));
    }

    /**
     * @param ToolInterface[] $tools
     * @return string[] "tool.property" for each array property whose JSON schema has no item type, sorted
     */
    public static function arraysWithoutItems(array $tools): array
    {
        $found = [];
        foreach ($tools as $tool) {
            foreach ($tool->getProperties() as $property) {
                $schema = $property->getJsonSchema();
                if (($schema['type'] ?? null) === 'array' && !isset($schema['items']['type'])) {
                    $found[] = $tool->getName() . '.' . $property->getName();
                }
            }
        }
        sort($found);

        return $found;
    }
}
