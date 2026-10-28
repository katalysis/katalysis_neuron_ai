<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Access;

use Katalysis\NeuronAi\Extension\Tier;
use Katalysis\NeuronAi\Tools\FilteredToolkit;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * Tier gating (P3.2-1, BEHAVIOUR_SPEC B-ACC-2): a tool the user's tier doesn't allow doesn't exist for that request.
 * Tiers are cumulative, so a user is offered every tool whose tier is at or below theirs.
 *
 * Toolkits lose their tools through FilteredToolkit, as DisabledTools does, so the prompt's tool list never names a
 * tool that isn't offered; a toolkit left with no tools is dropped, guidelines and all.
 */
final class TierGate
{
    /**
     * @param array<int, mixed> $items toolkits and tools
     * @param int $userTier 0 (no access) to 3 (NeuronPermissions::tier())
     * @param array<string, Tier> $tiers tool name => the tier it needs; a tool not listed needs Tier 3
     * @return array<int, mixed>
     */
    public static function apply(array $items, int $userTier, array $tiers): array
    {
        $allowed = static fn (ToolInterface $tool): bool => ($tiers[$tool->getName()] ?? Tier::GENERATE)->value <= $userTier;

        $result = [];
        foreach ($items as $item) {
            if ($item instanceof ToolInterface) {
                if ($allowed($item)) {
                    $result[] = $item;
                }
            } elseif ($item instanceof ToolkitInterface) {
                $tools = $item->tools();
                $kept = array_values(array_filter($tools, static fn ($tool): bool => !$tool instanceof ToolInterface || $allowed($tool)));
                if ($kept === []) {
                    continue;
                }
                $result[] = count($kept) === count($tools) ? $item : FilteredToolkit::from($item, $kept);
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }
}
