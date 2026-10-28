<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Katalysis\NeuronAi\Tools\AttributeSetsToolkit;
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
        'PagesToolkit::get_page_content' => 'BF-10: reads the newest version by default; version takes "newest" or "published"; P3.3-1: pagePath input, pageID no longer required',
        'PagesToolkit::get_page_blocks' => 'BF-10: version takes "newest" (default) or "published"; the description no longer calls it a draft',
        'PagesToolkit::get_current_page_context' => 'BF-10: the description says it shows the newest version',
        'BlocksToolkit::get_block_data' => 'BF-10: versionID defaults to the newest version',
        'BlocksToolkit::list_page_blocks_by_type' => 'BF-10: version (newest by default) replaces includeInactive',
        'BlocksToolkit::search_blocks_by_type' => 'BF-10: version (newest by default) replaces includeInactive; BF-6: offset input, only pages the user can view, the description says the result gives the total',
        'BlocksToolkit::list_container_blocks' => 'BF-10: version (newest by default) replaces includeInactive',
        'PagesToolkit::list_pages' => 'BF-6: offset input; limit names its default; the description says the result gives the total and whether there are more',
        'PagesToolkit::get_page_children' => 'BF-6: offset input; limit names its range; the description no longer says "all" and says the result gives the total',
        'FilesToolkit::list_files' => 'BF-6: offset input; limit names its default; the description says newest first and that the result gives the total',
        'UsersToolkit::list_users' => 'BF-6: offset input; limit names its default; the description says newest first and that the result gives the total; P3.5-2: fields input (email on request)',
        'UsersToolkit::search_users' => 'BF-6: offset input; limit names its range; the description says the result gives the total; P3.5-2: fields input (email on request)',
        'UsersToolkit::get_user' => 'P3.5-2: fields and attributes inputs (email, lastLogin, timezone and named attributes on request, B-PII-2)',
        'UsersToolkit::get_current_user' => 'P3.5-2: fields and attributes inputs (lastLogin, timezone and named attributes on request, B-PII-2)',
        'VersionsToolkit::list_page_versions' => 'BF-6: offset input; the description says newest first and that the result gives the total',
        'PeopleToolkit::list_people' => 'BF-6: offset input; limit capped at 100; the description says the result gives the total; P3.5-2: fields input (contact details and the full biography on request)',
        'PeopleToolkit::get_person_details' => 'P3.5-2: fields input (contact details and the full biography on request, B-PII-2)',
        'TopicsToolkit::get_topic_tree_structure' => 'BF-6: the description says what totalTopics counts and how a cut at max_depth is shown; P3.3-1: the description says categories are shown and counted',
    ];

    /**
     * Tools removed on purpose after the v3 capture, with the task and the reason. The capture itself is never edited;
     * capturedDefinitions() leaves these out.
     *
     * @var array<string, string> "Toolkit::tool" => reason
     */
    public const REMOVED = [
        'UserAttributesToolkit::set_user_attribute_value' => 'P3.5-2: removed; user accounts are read-only at every tier (B-ACC-7)',
    ];

    /**
     * Toolkits deleted on purpose after the v3 capture, with the task and the reason. capturedDefinitions() leaves them
     * out; the capture is never edited.
     *
     * @var array<string, string> toolkit => reason
     */
    public const REMOVED_TOOLKITS = [
        'AttributesToolkit' => 'P3.3-3: deleted; the agent never registered it (P1.8a) and it duplicated PageAttributesToolkit',
    ];

    /**
     * Tools disabled on purpose after the v3 capture (DisabledTools), with the task and the reason. They are still
     * defined as captured, but no longer offered; capturedOffered() leaves them out. The capture is never edited.
     *
     * @var array<string, string> "Toolkit::tool" => reason
     */
    public const DISABLED_SINCE = [
        'AttributeSetsToolkit::remove_attribute_from_set' => 'BF-16: in Concrete 9.5.5 the call it uses only adds to a set; fix in Phase 6',
    ];

    /**
     * The capture's offered list without the tools in DISABLED_SINCE.
     *
     * @param list<array{guidelines: ?string, tools: list<string>}> $offered the capture's "offered"
     * @return list<array{guidelines: ?string, tools: list<string>}>
     */
    public static function capturedOffered(array $offered): array
    {
        $disabled = array_map(static fn (string $key): string => substr($key, (int) strpos($key, '::') + 2), array_keys(self::DISABLED_SINCE));

        return array_map(static function (array $entry) use ($disabled): array {
            $entry['tools'] = array_values(array_diff($entry['tools'], $disabled));

            return $entry;
        }, $offered);
    }

    /**
     * How many tools the capture holds in the toolkits in REMOVED_TOOLKITS.
     */
    public static function removedToolkitToolCount(): int
    {
        $baseline = json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
        $count = 0;
        foreach ($baseline['definitions'] as $toolkit) {
            if (isset(self::REMOVED_TOOLKITS[$toolkit['toolkit']])) {
                $count += count($toolkit['tools']);
            }
        }

        return $count;
    }

    /**
     * The v3 capture's definitions without the tools in REMOVED and the toolkits in REMOVED_TOOLKITS.
     *
     * @return list<array{toolkit: string, guidelines: ?string, tools: list<array<string, mixed>>}>
     */
    public static function capturedDefinitions(): array
    {
        $baseline = json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
        $definitions = [];
        foreach ($baseline['definitions'] as $toolkit) {
            if (isset(self::REMOVED_TOOLKITS[$toolkit['toolkit']])) {
                continue;
            }
            $toolkit['tools'] = array_values(array_filter(
                $toolkit['tools'],
                static fn (array $tool): bool => !isset(self::REMOVED[$toolkit['toolkit'] . '::' . $tool['name']])
            ));
            $definitions[] = $toolkit;
        }

        return $definitions;
    }

    /**
     * Every core toolkit, in the order the agent registers them. (AttributesToolkit, which the agent never registered,
     * was the last until P3.3-3 deleted it.)
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
