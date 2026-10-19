<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Support\Facade\Log;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * The single list of tools the agent must never offer, whichever toolkit
 * (core or external) defines them. The agent applies it to the whole tool list
 * after name-collision resolution, and logs each tool it removes.
 *
 * To disable a tool, add its name and the reason here. Remove the entry only
 * when the tool has the permission and approval checks its reason refers to.
 */
final class DisabledTools
{
    /**
     * @var array<string, string> tool name => reason
     */
    private const LIST = [
        'delete_person' => 'destructive; disabled until P3.5 permission and approval work '
            . '(baseline scenario 04 shows injected delete attempts)',
        'delete_all_people' => 'destructive; disabled until P3.5 permission and approval work '
            . '(baseline scenario 04 shows injected delete attempts)',
        'delete_file' => 'File::delete() is permanent and Concrete 9.5 has no file trash (B-T1-11); '
            . 'disabled in P1.7, redirected to a review folder in P3.5 (baseline scenario 18)',
        'set_user_attribute_value' => 'user accounts are read-only for the assistant at every tier (B-ACC-7); '
            . 'disabled in P1.7, code removed in P3.5',
        'approve_page_version' => 'publishing stays a human action until P3.6 approval exists '
            . '(baseline scenario 14 published a live change from injected text)',
    ];

    /**
     * @return array<string, string> tool name => reason
     */
    public static function all(): array
    {
        return self::LIST;
    }

    /**
     * @return string[] sorted tool names
     */
    public static function names(): array
    {
        $names = array_keys(self::LIST);
        sort($names);

        return $names;
    }

    /**
     * Removes disabled tools from a list of tools and toolkits. Toolkits with
     * nothing removed are returned as they are; the others are replaced by a
     * FilteredToolkit. Items that are neither tools nor toolkits pass through.
     *
     * @param array<int, mixed> $items
     * @param callable(string, string): void|null $logger called as ($level, $message); defaults to the Concrete log
     * @return array<int, mixed>
     */
    public static function apply(array $items, ?callable $logger = null, ?array $disabled = null): array
    {
        $disabled ??= self::LIST;
        $logger ??= static function (string $level, string $message): void {
            Log::$level($message);
        };

        $result = [];
        foreach ($items as $item) {
            if ($item instanceof ToolInterface) {
                if (isset($disabled[$item->getName()])) {
                    self::log($logger, $item->getName(), $disabled);
                    continue;
                }
                $result[] = $item;
            } elseif ($item instanceof ToolkitInterface) {
                $kept = [];
                $removed = false;
                foreach ($item->tools() as $tool) {
                    if ($tool instanceof ToolInterface && isset($disabled[$tool->getName()])) {
                        self::log($logger, $tool->getName(), $disabled);
                        $removed = true;
                        continue;
                    }
                    $kept[] = $tool;
                }
                // Note: tools() is called here as well as by Neuron when it builds the
                // tool list. That is harmless for in-process toolkits, but an MCP
                // connector (McpServerIntegration) would connect once more. None is
                // registered today.
                $result[] = $removed ? FilteredToolkit::from($item, $kept) : $item;
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    private static function log(callable $logger, string $name, array $disabled): void
    {
        $logger('info', sprintf('Katalysis Neuron AI: tool "%s" is disabled and not offered: %s', $name, $disabled[$name]));
    }
}
