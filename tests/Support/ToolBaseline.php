<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Katalysis\NeuronAi\Tools\AttributeSetsToolkit;
use Katalysis\NeuronAi\Tools\AttributesToolkit;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\FileAttributesToolkit;
use Katalysis\NeuronAi\Tools\FilesToolkit;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\PeopleToolkit;
use Katalysis\NeuronAi\Tools\TopicsToolkit;
use Katalysis\NeuronAi\Tools\UserAttributesToolkit;
use Katalysis\NeuronAi\Tools\UsersToolkit;
use Katalysis\NeuronAi\Tools\VersionsToolkit;
use NeuronAI\Providers\OpenAI\ToolMapper;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Tools\ToolPropertyInterface;

/**
 * What the model is told about the core tools, in a form that is the same on Neuron 3 and 4 (P2.3).
 *
 * The v3 baseline (tool-baseline-v3.json) was written by capture_tool_baseline.php on `development` before the
 * conversion; ToolBaselineTest builds the same arrays on v4 and compares them exactly. Only methods that exist with the
 * same meaning on both versions are used: no Neuron 4 type, no Neuron 3 one.
 *
 * Left out on purpose (P2.3 plan §12.1): the heading Neuron puts above each toolkit's guidelines in the prompt. The
 * guideline text itself is compared.
 */
final class ToolBaseline
{
    public const FILE = __DIR__ . '/tool-baseline-v3.json';

    /**
     * Tools whose definition was changed on purpose after the v3 capture, with the task and the reason. ToolBaselineTest
     * holds every other tool to the capture, and checks that each tool listed here really differs from it.
     *
     * @var array<string, string> "Toolkit::tool" => reason
     */
    public const DELIBERATE_CHANGES = [
        'PagesToolkit::update_block_in_page_area' => 'BF-5: changeMode, textFields and replacedText inputs; the description says existing text is kept',
        'PagesToolkit::batch_update_page_content' => 'BF-5: changeMode keep_text by default, freeform removed; replacedText per block; BF-3: changeMode per block, every item checked before saving, continueOnError described',
        'PagesToolkit::get_page' => 'BF-10: reads the newest version by default; version takes "newest" or "published"',
        'PagesToolkit::get_page_content' => 'BF-10: reads the newest version by default; version takes "newest" or "published"',
        'PagesToolkit::get_page_blocks' => 'BF-10: version takes "newest" (default) or "published"; the description no longer calls it a draft',
        'PagesToolkit::get_current_page_context' => 'BF-10: the description says it shows the newest version',
        'BlocksToolkit::get_block_data' => 'BF-10: versionID defaults to the newest version',
        'BlocksToolkit::list_page_blocks_by_type' => 'BF-10: version (newest by default) replaces includeInactive',
        'BlocksToolkit::search_blocks_by_type' => 'BF-10: version (newest by default) replaces includeInactive',
        'BlocksToolkit::list_container_blocks' => 'BF-10: version (newest by default) replaces includeInactive',
    ];

    /**
     * Every core toolkit: the ones the agent registers, in its order, then AttributesToolkit (not registered, P1.8a).
     *
     * @return list<ToolkitInterface>
     */
    public static function coreToolkits(): array
    {
        return [
            new PagesToolkit(),
            new BlocksToolkit(),
            new FilesToolkit(),
            new UsersToolkit(),
            new PageAttributesToolkit(),
            new UserAttributesToolkit(),
            new FileAttributesToolkit(),
            new AttributeSetsToolkit(),
            new TopicsToolkit(),
            new PeopleToolkit(),
            new VersionsToolkit(),
            new AttributesToolkit(),
        ];
    }

    /**
     * @param list<ToolkitInterface> $toolkits
     * @return list<array<string, mixed>>
     */
    public static function definitions(array $toolkits): array
    {
        $out = [];
        foreach ($toolkits as $toolkit) {
            $out[] = [
                'toolkit' => (new \ReflectionClass($toolkit))->getShortName(),
                'guidelines' => $toolkit->guidelines(),
                'tools' => array_map([self::class, 'tool'], array_values($toolkit->tools())),
            ];
        }

        return $out;
    }

    /**
     * The agent's offered items (toolkits or single tools): guidelines as delivered, and tool names in order.
     *
     * @param array<int, mixed> $items
     * @return list<array<string, mixed>>
     */
    public static function offered(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item instanceof ToolkitInterface) {
                $out[] = [
                    'guidelines' => $item->guidelines(),
                    'tools' => array_map(static fn (ToolInterface $t): string => $t->getName(), array_values($item->tools())),
                ];
            } elseif ($item instanceof ToolInterface) {
                $out[] = ['guidelines' => null, 'tools' => [$item->getName()]];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function tool(ToolInterface $tool): array
    {
        return [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'properties' => array_map(static fn (ToolPropertyInterface $p): array => [
                'name' => $p->getName(),
                'type' => $p->getType()->value,
                'description' => $p->getDescription(),
                'required' => $p->isRequired(),
            ], array_values($tool->getProperties())),
            // Exactly what the OpenAI provider sends, as JSON decoded to arrays (the same on both versions).
            'payload' => json_decode((string) json_encode((new ToolMapper())->map([$tool])[0]), true),
        ];
    }
}
