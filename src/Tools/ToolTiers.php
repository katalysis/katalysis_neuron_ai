<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Katalysis\NeuronAi\Extension\Tier;

/**
 * The tier each core tool needs (P3.2-1, BEHAVIOUR_SPEC B-ACC-2). The agent offers a tool only to a user whose tier is
 * at least this (TierGate); external tools carry their own (ExternalToolkitRegistry, B-ACC-8).
 *
 * Every core tool is listed, disabled ones too (for when P3.6-3 re-enables them): TierAccessTest fails if a core tool
 * has no entry, so a new tool gets a deliberate tier. A tool with no entry is offered at Tier 3 only (TierGate).
 *
 * Tier 2 (Configure) is the site's structure (B-T2-6): attribute definitions and their options, attribute sets, and
 * topic trees and topics. Setting a value (an attribute on a page or file) and reading any of them is Tier 1.
 */
final class ToolTiers
{
    private const CONFIGURE = [
        // Attribute definitions
        'create_page_attribute',
        'create_page_attribute_and_add_to_set',
        'create_user_attribute',
        'create_file_attribute',
        'update_page_select_attribute_options',
        'update_user_select_attribute_options',
        'update_file_select_attribute_options',
        // Attribute sets
        'create_attribute_set',
        'add_attribute_to_set',
        'remove_attribute_from_set',
        'delete_attribute_set',
        // Topic trees
        'create_topic_tree',
        'create_topic',
        'rename_topic',
        'delete_topic',
    ];

    private const CONTENT = [
        // Pages
        'get_current_page_context',
        'list_pages',
        'list_page_types',
        'get_page',
        'get_page_content',
        'get_page_blocks',
        'get_page_children',
        'update_page',
        'batch_update_page_content',
        'batch_update_content_headings',
        'create_page',
        'add_block_to_page_area',
        'update_block_in_page_area',
        'delete_block_from_page_area',
        'move_block_between_areas',
        'move_page',
        'delete_page',
        // Blocks
        'list_page_blocks_by_type',
        'search_blocks_by_type',
        'get_block_data',
        'get_block_controller_data',
        'count_blocks_by_type',
        'list_container_blocks',
        // Files: a folder organises files as an upload does (B-T1-12), under Concrete's Add File check
        'resolve_file_id_from_path',
        'list_files',
        'get_file',
        'list_file_folders',
        'create_file_folder',
        'mark_file_for_deletion',
        'delete_file',
        // Users (read-only at every tier, B-ACC-7)
        'list_users',
        'get_user',
        'get_current_user',
        'search_users',
        // Attribute reads, and attribute values
        'list_page_attributes',
        'list_attribute_types',
        'get_page_attribute_details',
        'set_page_attribute_value',
        'list_user_attributes',
        'get_user_attribute_details',
        'list_file_attributes',
        'get_file_attribute_details',
        'set_file_attribute_value',
        'list_attribute_sets',
        // Topic reads
        'list_topic_trees',
        'get_topic_tree_structure',
        'get_topic_by_name',
        'get_topic_details',
        // People
        'list_people',
        'get_person_details',
        'get_people_image_file_ids',
        'update_person_specialisms',
        'update_person_topics',
        // Versions
        'list_page_versions',
        'create_page_draft',
        'discard_draft_version',
        'get_version_content',
        'approve_page_version',
    ];

    /**
     * @return array<string, Tier> tool name => the tier it needs
     */
    public static function all(): array
    {
        return array_fill_keys(self::CONTENT, Tier::CONTENT) + array_fill_keys(self::CONFIGURE, Tier::CONFIGURE);
    }
}
