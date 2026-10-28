<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval\Preview;

use Concrete\Core\Tree\Node\Node;
use Concrete\Core\Tree\Node\Type\Topic as TopicNode;
use Concrete\Core\Tree\Type\Topic as TopicTree;

/**
 * The approval cards of the topic writes (P3.6-2). Topics have no draft: the change takes effect at once. Reads only.
 */
final class TopicPreviews
{
    public static function createTree(array $inputs): array
    {
        $name = PreviewSupport::string($inputs, 'tree_name');

        return [
            PreviewSupport::entry('Create the topic tree', '"' . $name . '"', '', [], PreviewSupport::LIVE_NOTE),
            ['exists' => $name !== '' && TopicTree::getByName($name) !== null],
        ];
    }

    public static function createTopic(array $inputs): array
    {
        $treeName = PreviewSupport::string($inputs, 'tree_name');
        $tree = $treeName !== '' ? TopicTree::getByName($treeName) : null;
        $parentID = PreviewSupport::int($inputs, 'parent_topic_id');
        $parent = $parentID > 0 ? TopicNode::getByID($parentID) : ($tree ? $tree->getRootTreeNodeObject() : null);
        $where = $parent instanceof Node
            ? ($parentID > 0 ? '"' . $parent->getTreeNodeDisplayName() . '" in ' : 'the top of ') . 'the tree "' . $treeName . '"'
            : 'a place that was not found';

        return [
            PreviewSupport::entry('Add the topic', '"' . PreviewSupport::string($inputs, 'topic_name') . '" under ' . $where, '', [], PreviewSupport::LIVE_NOTE),
            ['parent' => $parent instanceof Node ? (int) $parent->getTreeNodeID() : 0, 'children' => self::children($parent)],
        ];
    }

    public static function rename(array $inputs): array
    {
        $id = PreviewSupport::int($inputs, 'topic_id');
        $node = $id > 0 ? TopicNode::getByID($id) : null;
        $old = $node instanceof Node ? (string) $node->getTreeNodeDisplayName() : null;

        return [
            PreviewSupport::entry('Rename the topic', $old !== null ? '"' . $old . '"' : 'topic ' . $id, '', [PreviewSupport::change('Name', $old, PreviewSupport::string($inputs, 'new_name'))], PreviewSupport::LIVE_NOTE),
            ['node' => $id, 'name' => $old],
        ];
    }

    /** @return list<string> */
    private static function children(?Node $parent): array
    {
        if (!$parent instanceof Node) {
            return [];
        }
        $parent->populateDirectChildrenOnly();
        $names = array_map(static fn (Node $child): string => (string) $child->getTreeNodeName(), $parent->getChildNodes());
        sort($names);

        return $names;
    }
}
