<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use NeuronAI\Tools\ToolInterface;

/**
 * The tool groups (P3.2-2, plans/P3.2-2.md §1). A request offers the "always" tools and the groups GroupRouter picks,
 * at most MAX_TOOLS, so the model reads about 25 tool definitions instead of 60 or more. The registry still holds
 * every tool the user's tier allows (TierGate), so a call to one not offered still runs, behind the same checks.
 *
 * The order is fixed, so the start of every request is the same whenever the same groups are offered (Glyn: OpenAI
 * caches a request's prefix): the "always" tools first, then the groups in ORDER, whatever their score, and each
 * group's tools in the order written here. External tools follow the order their package registered them in.
 *
 * Every core tool is in exactly one group, disabled ones too (ToolRoutingTest). A tool in no list (another package's)
 * is in "external"; an external tool that takes a core tool's name (ToolNameResolver) takes its place too.
 */
final class ToolGroups
{
    public const MAX_TOOLS = 25;

    public const GET_TOOL_GROUP = 'get_tool_group';

    public const ALWAYS = [
        'get_current_page_context',
        'get_page',
        'get_page_content',
        'get_page_blocks',
        self::GET_TOOL_GROUP,
    ];

    /** The groups' fixed order; "external" is every tool not listed in GROUPS. */
    public const ORDER = ['content', 'pages', 'site_search', 'attributes', 'files', 'users', 'topics', 'external'];

    public const GROUPS = [
        'content' => [
            'add_block_to_page_area',
            'update_block_in_page_area',
            'delete_block_from_page_area',
            'move_block_between_areas',
            'batch_update_page_content',
            'batch_update_content_headings',
            'get_block_data',
            'get_block_controller_data',
            'list_page_blocks_by_type',
            'list_container_blocks',
        ],
        'pages' => [
            'list_pages',
            'get_page_children',
            'create_page',
            'update_page',
            'move_page',
            'delete_page',
            'list_page_versions',
            'get_version_content',
            'create_page_draft',
            'discard_draft_version',
            'approve_page_version',
        ],
        'site_search' => [
            'search_blocks_by_type',
            'count_blocks_by_type',
            'list_page_types',
        ],
        'attributes' => [
            'list_page_attributes',
            'get_page_attribute_details',
            'set_page_attribute_value',
            'create_page_attribute',
            'create_page_attribute_and_add_to_set',
            'update_page_select_attribute_options',
            'list_attribute_types',
            'list_user_attributes',
            'get_user_attribute_details',
            'create_user_attribute',
            'update_user_select_attribute_options',
            'list_file_attributes',
            'get_file_attribute_details',
            'set_file_attribute_value',
            'create_file_attribute',
            'update_file_select_attribute_options',
            'list_attribute_sets',
            'create_attribute_set',
            'add_attribute_to_set',
            'remove_attribute_from_set',
            'delete_attribute_set',
        ],
        'files' => [
            'list_files',
            'get_file',
            'list_file_folders',
            'resolve_file_id_from_path',
            'create_file_folder',
            'mark_file_for_deletion',
            'delete_file',
        ],
        'users' => [
            'list_users',
            'get_user',
            'get_current_user',
            'search_users',
        ],
        'topics' => [
            'list_topic_trees',
            'get_topic_tree_structure',
            'get_topic_by_name',
            'get_topic_details',
            'create_topic_tree',
            'create_topic',
            'rename_topic',
            'delete_topic',
        ],
    ];

    /** What each group is for, in get_tool_group's description. */
    public const DESCRIPTIONS = [
        'content' => 'add, change, move and delete blocks on a page; read a block\'s fields',
        'pages' => 'list, create, rename, move and delete pages; page versions and drafts',
        'site_search' => 'find and count blocks of a type across the site; page types',
        'attributes' => 'page, user and file attributes (SEO title, meta description, etc.) and attribute sets',
        'files' => 'files and file folders in the file manager',
        'users' => 'user accounts (read only)',
        'topics' => 'topic trees and topics',
        'external' => 'people, places, reviews and other tools added by other packages',
    ];

    /**
     * The group a tool is in: "always", a group in GROUPS, or "external".
     */
    public static function groupOf(string $tool): string
    {
        if (in_array($tool, self::ALWAYS, true)) {
            return 'always';
        }
        foreach (self::GROUPS as $group => $tools) {
            if (in_array($tool, $tools, true)) {
                return $group;
            }
        }

        return 'external';
    }

    /**
     * The tools to offer: the "always" tools and those of $groups, in the fixed order. $tools is the registry, in the
     * order the agent registered them (that order is kept for external tools).
     *
     * @param ToolInterface[] $tools
     * @param list<string> $groups
     * @return list<ToolInterface>
     */
    public static function offer(array $tools, array $groups): array
    {
        $rank = [];
        foreach (self::ALWAYS as $i => $name) {
            $rank[$name] = [0, $i];
        }
        foreach (self::ORDER as $g => $group) {
            foreach (self::GROUPS[$group] ?? [] as $i => $name) {
                $rank[$name] = [$g + 1, $i];
            }
        }
        $external = array_search('external', self::ORDER, true) + 1;

        $offered = [];
        foreach (array_values($tools) as $position => $tool) {
            $group = self::groupOf($tool->getName());
            if ($group !== 'always' && !in_array($group, $groups, true)) {
                continue;
            }
            $offered[] = [$rank[$tool->getName()] ?? [$external, $position], $tool];
        }
        usort($offered, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $entry): ToolInterface => $entry[1], $offered);
    }

    /**
     * How many of $tools are in each group ("always" included).
     *
     * @param ToolInterface[] $tools
     * @return array<string, int>
     */
    public static function sizes(array $tools): array
    {
        $sizes = array_fill_keys(['always', ...self::ORDER], 0);
        foreach ($tools as $tool) {
            $sizes[self::groupOf($tool->getName())]++;
        }

        return $sizes;
    }
}
