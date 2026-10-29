<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Katalysis\NeuronAi\Tools\AttributeSetsToolkit;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\FileAttributesToolkit;
use Katalysis\NeuronAi\Tools\DisabledTools;
use Katalysis\NeuronAi\Tools\FilesToolkit;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\PeopleToolkit;
use Katalysis\NeuronAi\Tools\ToolGroupsToolkit;
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
        'ContentToolkit::update_block_in_page_area' => 'BF-5: changeMode, textFields and replacedText inputs; the description says existing text is kept; P3.3-5: value says the fields are named as get_block_data shows them',
        'ContentToolkit::add_block_to_page_area' => 'P3.3-5: value says the fields are named as get_block_data shows them, and how table entries are sent',
        'BatchEditToolkit::batch_update_page_content' => 'BF-5: changeMode keep_text by default, freeform removed; replacedText per block; BF-3: changeMode per block, every item checked before saving, continueOnError described; P3.3-5: blocks declares its items (an object with its fields)',
        'BatchEditToolkit::batch_update_content_headings' => 'P3.3-5: includeInactive removed (ignored since P1.8); headingTexts declares its items; BF-20: the description says it changes heading levels only, and that a rename uses update_block_in_page_area',
        'PageAttributesToolkit::create_page_attribute' => 'P3.3-5: options declares its items (strings)',
        'PageAttributesToolkit::create_page_attribute_and_add_to_set' => 'P3.3-5: options declares its items (strings)',
        'PageAttributesToolkit::update_page_select_attribute_options' => 'P3.3-5: options declares its items (strings)',
        'UserAttributesToolkit::create_user_attribute' => 'P3.3-5: options declares its items (strings)',
        'UserAttributesToolkit::update_user_select_attribute_options' => 'P3.3-5: options declares its items (strings)',
        'FileAttributesToolkit::create_file_attribute' => 'P3.3-5: options declares its items (strings)',
        'FileAttributesToolkit::update_file_select_attribute_options' => 'P3.3-5: options declares its items (strings)',
        'PagesToolkit::get_page' => 'BF-10: reads the newest version by default; version takes "newest" or "published"',
        'PagesToolkit::get_page_content' => 'BF-10: reads the newest version by default; version takes "newest" or "published"; P3.3-1: pagePath input, pageID no longer required',
        'ContentToolkit::get_page_blocks' => 'BF-10: version takes "newest" (default) or "published"; the description no longer calls it a draft',
        'PagesToolkit::get_current_page_context' => 'BF-10: the description says it shows the newest version',
        'BlocksToolkit::get_block_data' => 'BF-10: versionID defaults to the newest version',
        'BlocksToolkit::list_page_blocks_by_type' => 'BF-10: version (newest by default) replaces includeInactive',
        'BlocksToolkit::search_blocks_by_type' => 'BF-10: version (newest by default) replaces includeInactive; BF-6: offset input, only pages the user can view, the description says the result gives the total',
        'BlocksToolkit::list_container_blocks' => 'BF-10: version (newest by default) replaces includeInactive',
        'PagesToolkit::list_pages' => 'BF-6: offset input; limit names its default; the description says the result gives the total and whether there are more; P3.3-5: onlyVisible input (the prompt named it; the tool refused it)',
        'PagesToolkit::get_page_children' => 'BF-6: offset input; limit names its range; the description no longer says "all" and says the result gives the total; P3.3-5: parentID and onlyVisible inputs',
        'FilesToolkit::list_files' => 'BF-6: offset input; limit names its default; the description says newest first and that the result gives the total',
        'UsersToolkit::list_users' => 'BF-6: offset input; limit names its default; the description says newest first and that the result gives the total; P3.5-2: fields input (email on request)',
        'UsersToolkit::search_users' => 'BF-6: offset input; limit names its range; the description says the result gives the total; P3.5-2: fields input (email on request)',
        'UsersToolkit::get_user' => 'P3.5-2: fields and attributes inputs (email, lastLogin, timezone and named attributes on request, B-PII-2)',
        'UsersToolkit::get_current_user' => 'P3.5-2: fields and attributes inputs (lastLogin, timezone and named attributes on request, B-PII-2)',
        'VersionsToolkit::list_page_versions' => 'BF-6: offset input; the description says newest first and that the result gives the total',
        'PeopleToolkit::list_people' => 'BF-6: offset input; limit capped at 100; the description says the result gives the total; P3.5-2: fields input (contact details and the full biography on request)',
        'PeopleToolkit::get_person_details' => 'P3.5-2: fields input (contact details and the full biography on request, B-PII-2)',
        'TopicsToolkit::get_topic_tree_structure' => 'BF-6: the description says what totalTopics counts and how a cut at max_depth is shown; P3.3-1: the description says categories are shown and counted',
        'PagesToolkit::update_page' => 'BF-22: description says it is the page\'s own description, not the Meta Description attribute, which set_page_attribute_value sets',
        'PagesToolkit::move_page' => 'P3.6-3: re-enabled behind approval; the description says the pages below move too, the old addresses redirect, and a workflow may hold it',
        'PagesToolkit::delete_page' => 'P3.6-3: re-enabled behind approval; the description says it goes to the trash with the pages below, never permanently',
        'FilesToolkit::mark_file_for_deletion' => 'P3.6-3: move_file_to_folder renamed (RENAMED); only moves a file to "Assistant – to delete" for a person to delete (B-T1-11); folderID optional and refused unless that folder',
    ];

    /**
     * Tools renamed on purpose after the v3 capture: the captured name, the new one and why. capturedDefinitions() and
     * capturedOffered() use the new name; the capture is never edited. A renamed tool's definition is in
     * DELIBERATE_CHANGES under its new name.
     *
     * @var array<string, array{to: string, reason: string}> "Toolkit::captured name" => the rename
     */
    public const RENAMED = [
        'FilesToolkit::move_file_to_folder' => ['to' => 'mark_file_for_deletion', 'reason' => 'P3.6-3 (Glyn): it came back only as the delete redirect (B-T1-11), so its name says what it is for'],
    ];

    /**
     * Tools the capture didn't offer (disabled then) that are offered again since, with the task and the reason, under
     * their current name. capturedOffered() puts them back where the toolkit defines them.
     *
     * @var array<string, string> "Toolkit::tool" => reason
     */
    public const ENABLED_SINCE = [
        'PagesToolkit::move_page' => 'P3.6-3: re-enabled behind approval (Glyn)',
        'PagesToolkit::delete_page' => 'P3.6-3: re-enabled behind approval, to the trash only (Glyn)',
        'FilesToolkit::mark_file_for_deletion' => 'P3.6-3: re-enabled behind approval as the delete redirect (Glyn)',
    ];

    /**
     * Toolkits whose guidelines changed on purpose after the capture. They are part of the prompt, so the prompt
     * baseline records the change too.
     *
     * @var array<string, string> toolkit => reason
     */
    public const GUIDELINES_CHANGED = [
        'FilesToolkit' => 'P3.6-3: names mark_file_for_deletion for a delete request, in place of move_file_to_folder for organising files',
        'PagesToolkit' => 'P3.6-3: no longer says moving and deleting pages are not available (they are, behind approval)',
    ];

    /**
     * Tools removed on purpose after the v3 capture, with the task and the reason. The capture itself is never edited;
     * capturedDefinitions() leaves these out.
     *
     * @var array<string, string> "Toolkit::tool" => reason
     */
    public const REMOVED = [
        'UserAttributesToolkit::set_user_attribute_value' => 'P3.5-2: removed; user accounts are read-only at every tier (B-ACC-7)',
        'PagesToolkit::sync_multi_links_to_epra_resources_list' => 'P3.4: EPRA code deleted from core (it was disabled); an EPRA package would bring it back',
        'BlocksToolkit::get_doc_download_files_on_page' => 'P3.4: EPRA code deleted from core; an EPRA package would bring it back',
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
     * Toolkits added after the v3 capture, with the task and the reason, in the order the agent registers them (after
     * the captured ones). There is nothing captured to compare them with: capturedDefinitions() takes them as they are
     * defined, and capturedOffered() offers them last, with no guidelines.
     *
     * @var array<string, array{class: class-string<ToolkitInterface>, tools: list<string>, reason: string}>
     */
    public const ADDED_TOOLKITS = [
        'ToolGroupsToolkit' => ['class' => ToolGroupsToolkit::class, 'tools' => ['get_tool_group'], 'reason' => 'P3.2-2: each request offers only some tool groups; get_tool_group adds one'],
    ];

    /**
     * P3.3-4: toolkits split out of a captured one, with the tools they took, in the order the agent registers them
     * (straight after the toolkit they came from). The tools are unchanged; they are compared under their new toolkit,
     * which has no guidelines. The capture is never edited.
     *
     * @var array<string, array{from: string, tools: list<string>}>
     */
    public const SPLIT = [
        'ContentToolkit' => ['from' => 'PagesToolkit', 'tools' => ['get_page_blocks', 'add_block_to_page_area', 'update_block_in_page_area', 'delete_block_from_page_area', 'move_block_between_areas']],
        'BatchEditToolkit' => ['from' => 'PagesToolkit', 'tools' => ['batch_update_page_content', 'batch_update_content_headings']],
    ];

    /**
     * The capture's offered list without the tools in DISABLED_SINCE and REMOVED (P3.4: a removed tool that was
     * offered, get_doc_download_files_on_page, is no longer offered either), with the SPLIT toolkits' tools offered
     * by their own toolkit, straight after the one they came from.
     *
     * @param list<array{guidelines: ?string, tools: list<string>}> $offered the capture's "offered"
     * @return list<array{guidelines: ?string, tools: list<string>}>
     */
    public static function capturedOffered(array $offered): array
    {
        $disabled = array_map(
            static fn (string $key): string => substr($key, (int) strpos($key, '::') + 2),
            array_merge(array_keys(self::DISABLED_SINCE), array_keys(self::REMOVED))
        );

        // P3.6-3: renamed tools by their new name; tools offered again put back where their toolkit defines them; and
        // the changed guidelines as they are now.
        $renames = [];
        foreach (self::RENAMED as $key => $rename) {
            $renames[substr($key, (int) strpos($key, '::') + 2)] = $rename['to'];
        }
        $current = [];
        foreach (self::coreToolkits() as $toolkit) {
            $current[(new \ReflectionClass($toolkit))->getShortName()] = $toolkit->guidelines();
        }
        // The capture's own order (before SPLIT), so an entry as captured can be matched to its toolkit.
        $capturedGuidelines = [];
        $order = [];
        foreach (json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR)['definitions'] as $toolkit) {
            $capturedGuidelines[$toolkit['toolkit']] = $toolkit['guidelines'];
            $order[$toolkit['toolkit']] = array_map(static fn (array $t): string => $renames[$t['name']] ?? $t['name'], $toolkit['tools']);
        }

        $result = [];
        foreach ($offered as $entry) {
            $entry['tools'] = array_map(static fn (string $t): string => $renames[$t] ?? $t, $entry['tools']);
            foreach ($order as $name => $tools) {
                if ($entry['tools'] === [] || array_diff($entry['tools'], $tools) !== []) {
                    continue;
                }
                $enabled = [];
                foreach (array_keys(self::ENABLED_SINCE) as $key) {
                    if (str_starts_with($key, $name . '::')) {
                        $enabled[] = substr($key, strlen($name) + 2);
                    }
                }
                $entry['tools'] = array_values(array_intersect($tools, [...$entry['tools'], ...$enabled]));
                if (isset(self::GUIDELINES_CHANGED[$name]) && is_string($entry['guidelines']) && is_string($capturedGuidelines[$name] ?? null)) {
                    $entry['guidelines'] = str_replace((string) $capturedGuidelines[$name], (string) $current[$name], $entry['guidelines']);
                }
                // A toolkit with none of its tools disabled any more is offered as it is, with no "Toolkit:" line
                // (FilteredToolkit::built(), P2.4).
                if (array_intersect($tools, DisabledTools::names()) === [] && is_string($entry['guidelines'])) {
                    $entry['guidelines'] = (string) preg_replace('/^Toolkit: ' . preg_quote($name, '/') . '\R/', '', $entry['guidelines']);
                }
                break;
            }
            $entry['tools'] = array_values(array_diff($entry['tools'], $disabled));
            $split = [];
            foreach (self::SPLIT as $toolkit) {
                $taken = array_values(array_intersect($entry['tools'], $toolkit['tools']));
                if ($taken !== []) {
                    $split[] = ['guidelines' => null, 'tools' => $taken];
                    $entry['tools'] = array_values(array_diff($entry['tools'], $taken));
                }
            }
            $result[] = $entry;
            array_push($result, ...$split);
        }
        foreach (self::ADDED_TOOLKITS as $toolkit) {
            $result[] = ['guidelines' => null, 'tools' => $toolkit['tools']];
        }

        return $result;
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
     * The v3 capture's definitions without the tools in REMOVED and the toolkits in REMOVED_TOOLKITS, with the SPLIT
     * toolkits' tools under their own toolkit (no guidelines), straight after the one they came from.
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
            foreach ($toolkit['tools'] as $i => $tool) {
                $renamed = self::RENAMED[$toolkit['toolkit'] . '::' . $tool['name']] ?? null;
                if ($renamed !== null) {
                    $toolkit['tools'][$i]['name'] = $renamed['to'];
                }
            }
            $split = [];
            foreach (self::SPLIT as $name => $into) {
                if ($into['from'] !== $toolkit['toolkit']) {
                    continue;
                }
                $taken = array_values(array_filter($toolkit['tools'], static fn (array $tool): bool => in_array($tool['name'], $into['tools'], true)));
                $toolkit['tools'] = array_values(array_filter($toolkit['tools'], static fn (array $tool): bool => !in_array($tool['name'], $into['tools'], true)));
                $split[] = ['toolkit' => $name, 'guidelines' => null, 'tools' => $taken];
            }
            $definitions[] = $toolkit;
            array_push($definitions, ...$split);
        }

        return [...$definitions, ...self::definitions(array_map(static fn (array $added): ToolkitInterface => new $added['class'](), array_values(self::ADDED_TOOLKITS)))];
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
            new ContentToolkit(),
            new BatchEditToolkit(),
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
            new ToolGroupsToolkit(),
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
