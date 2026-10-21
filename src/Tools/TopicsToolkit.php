<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Tree\Type\Topic as TopicTree;
use Concrete\Core\Tree\Node\Type\Topic as TopicNode;
use Concrete\Core\User\User;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * Topics toolkit for Neuron AI
 * 
 * Provides tools for working with topic trees in Concrete CMS.
 * Topic trees are hierarchical taxonomies used for categorization.
 */
class TopicsToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "Use these tools to work with topic trees in Concrete CMS. Topic trees are hierarchical taxonomies used for categorization (e.g., Specialisms, Industries, Categories). You can list available topic trees, browse their structure, search for specific topics by name, and get detailed information about topics including their children. Topic trees are used both directly in custom entities (like People) and through Concrete's attribute system (topics-type attributes on pages/users/files). When working with topics, you can identify them by handle/name or by treeNodeID.";
    }

    public function provide(): array
    {
        return [
            $this->makeListTopicTreesTool(),
            $this->makeGetTopicTreeStructureTool(),
            $this->makeGetTopicByNameTool(),
            $this->makeGetTopicDetailsTool(),
            $this->makeCreateTopicTreeTool(),
            $this->makeCreateTopicTool(),
            $this->makeRenameTopicTool(),
            $this->makeDeleteTopicTool(),
        ];
    }

    protected function makeListTopicTreesTool(): Tool
    {
        $tool = new CallableTool(
            'list_topic_trees',
            'List all topic trees available in the system with their names and IDs.'
        );

        $tool->setHandler($this->guarded(function (): array {
            $trees = TopicTree::getList();
            $results = [];

            foreach ($trees as $tree) {
                $rootNode = $tree->getRootTreeNodeObject();
                $rootNode->populateChildren();
                $childCount = count($rootNode->getChildNodes());

                $results[] = [
                    'treeID' => $tree->getTreeID(),
                    'name' => $tree->getTreeName(),
                    'handle' => method_exists($tree, 'getTreeHandle') ? $tree->getTreeHandle() : null,
                    'topLevelTopicCount' => $childCount,
                ];
            }

            return [
                'success' => true,
                'totalTrees' => count($results),
                'trees' => $results,
            ];
        }));

        return $tool;
    }

    protected function makeGetTopicTreeStructureTool(): Tool
    {
        $tool = new CallableTool(
            'get_topic_tree_structure',
            'Get the complete hierarchical structure of a topic tree with all topics and their children.'
        );

        $tool->addProperty(new ToolProperty(
            'tree_name',
            PropertyType::STRING,
            'Name of the topic tree (e.g., "Specialisms", "Industries", "Categories")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'max_depth',
            PropertyType::INTEGER,
            'Maximum depth to traverse (default: 10). Use lower values for large trees.',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?string $tree_name = null,
            ?int $max_depth = 10
        ): array {
            if (!$tree_name) {
                throw new \RuntimeException('tree_name is required');
            }

            $tree = TopicTree::getByName($tree_name);
            if (!$tree) {
                throw new \RuntimeException("Topic tree '{$tree_name}' not found");
            }

            $rootNode = $tree->getRootTreeNodeObject();
            if (!$rootNode) {
                throw new \RuntimeException("Could not load root node for tree '{$tree_name}'");
            }

            $structure = $this->buildNodeStructure($rootNode, 0, $max_depth);

            return [
                'success' => true,
                'tree' => [
                    'treeID' => $tree->getTreeID(),
                    'name' => $tree->getTreeName(),
                ],
                'topics' => $structure,
                'totalTopics' => $this->countNodes($structure),
            ];
        }));

        return $tool;
    }

    protected function makeGetTopicByNameTool(): Tool
    {
        $tool = new CallableTool(
            'get_topic_by_name',
            'Search for a topic by name within a specific topic tree. Supports partial/fuzzy matching.'
        );

        $tool->addProperty(new ToolProperty(
            'tree_name',
            PropertyType::STRING,
            'Name of the topic tree to search in (e.g., "Specialisms")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'topic_name',
            PropertyType::STRING,
            'Name of the topic to find (case-insensitive, partial matching supported)',
            true
        ));

        $tool->setHandler($this->guarded(function (
            ?string $tree_name = null,
            ?string $topic_name = null
        ): array {
            if (!$tree_name || !$topic_name) {
                throw new \RuntimeException('Both tree_name and topic_name are required');
            }

            $tree = TopicTree::getByName($tree_name);
            if (!$tree) {
                throw new \RuntimeException("Topic tree '{$tree_name}' not found");
            }

            $rootNode = $tree->getRootTreeNodeObject();
            $matches = $this->searchTopicsByName($rootNode, $topic_name);

            if (empty($matches)) {
                throw new \RuntimeException("No topics found matching '{$topic_name}' in tree '{$tree_name}'");
            }

            return [
                'success' => true,
                'matchCount' => count($matches),
                'matches' => $matches,
            ];
        }));

        return $tool;
    }

    protected function makeGetTopicDetailsTool(): Tool
    {
        $tool = new CallableTool(
            'get_topic_details',
            'Get detailed information about a specific topic including its parent, children, and path.'
        );

        $tool->addProperty(new ToolProperty(
            'topic_id',
            PropertyType::INTEGER,
            'Tree node ID of the topic',
            true
        ));

        $tool->setHandler($this->guarded(function (?int $topic_id = null): array {
            if (!$topic_id) {
                throw new \RuntimeException('topic_id is required');
            }

            $node = TopicNode::getByID($topic_id);
            if (!$node || !$node instanceof TopicNode) {
                throw new \RuntimeException("Topic with ID {$topic_id} not found");
            }

            // Get parent info
            $parent = null;
            $parentNode = $node->getTreeNodeParentObject();
            if ($parentNode && $parentNode instanceof TopicNode) {
                $parent = [
                    'treeNodeID' => $parentNode->getTreeNodeID(),
                    'name' => $parentNode->getTreeNodeDisplayName(),
                ];
            }

            // Get children
            $node->populateChildren();
            $children = [];
            foreach ($node->getChildNodes() as $child) {
                if ($child instanceof TopicNode) {
                    $children[] = [
                        'treeNodeID' => $child->getTreeNodeID(),
                        'name' => $child->getTreeNodeDisplayName(),
                    ];
                }
            }

            // Build path from root
            $path = $this->buildTopicPath($node);

            return [
                'success' => true,
                'topic' => [
                    'treeNodeID' => $node->getTreeNodeID(),
                    'name' => $node->getTreeNodeDisplayName(),
                    'parent' => $parent,
                    'children' => $children,
                    'childCount' => count($children),
                    'path' => $path,
                ],
            ];
        }));

        return $tool;
    }

    protected function makeCreateTopicTreeTool(): Tool
    {
        $tool = new CallableTool(
            'create_topic_tree',
            'Create a new topic tree in the system.'
        );

        $tool->addProperty(new ToolProperty(
            'tree_name',
            PropertyType::STRING,
            'Name for the new topic tree (e.g., "Categories", "Industries")',
            true
        ));

        $tool->setHandler($this->guarded(function (?string $tree_name = null): array {
            if (!$tree_name) {
                throw new \RuntimeException('tree_name is required');
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException('Permission denied: Only super users can create topic trees');
            }

            // Check if tree already exists
            $existingTree = TopicTree::getByName($tree_name);
            if ($existingTree) {
                throw new \RuntimeException("Topic tree '{$tree_name}' already exists");
            }

            // Create the new topic tree
            $tree = TopicTree::add($tree_name);
            
            if (!$tree || !$tree instanceof TopicTree) {
                throw new \RuntimeException("Failed to create topic tree '{$tree_name}'");
            }

            return [
                'success' => true,
                'message' => "Topic tree '{$tree_name}' created successfully",
                'tree' => [
                    'treeID' => $tree->getTreeID(),
                    'name' => $tree->getTreeName(),
                ],
            ];
        }));

        return $tool;
    }

    protected function makeCreateTopicTool(): Tool
    {
        $tool = new CallableTool(
            'create_topic',
            'Create a new topic in a topic tree. Optionally specify a parent topic to create a child topic.'
        );

        $tool->addProperty(new ToolProperty(
            'tree_name',
            PropertyType::STRING,
            'Name of the topic tree (e.g., "Specialisms", "Industries")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'topic_name',
            PropertyType::STRING,
            'Name for the new topic',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'parent_topic_id',
            PropertyType::INTEGER,
            'Optional parent topic node ID. If not provided, topic will be created at the top level.',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?string $tree_name = null,
            ?string $topic_name = null,
            ?int $parent_topic_id = null
        ): array {
            if (!$tree_name || !$topic_name) {
                throw new \RuntimeException('Both tree_name and topic_name are required');
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException('Permission denied: Only super users can create topics');
            }

            $tree = TopicTree::getByName($tree_name);
            if (!$tree) {
                throw new \RuntimeException("Topic tree '{$tree_name}' not found");
            }

            // Determine parent node
            $parentNode = null;
            if ($parent_topic_id) {
                $parentNode = TopicNode::getByID($parent_topic_id);
                if (!$parentNode || !$parentNode instanceof TopicNode) {
                    throw new \RuntimeException("Parent topic with ID {$parent_topic_id} not found");
                }
            } else {
                // Use tree root as parent for top-level topics
                $parentNode = $tree->getRootTreeNodeObject();
                if (!$parentNode) {
                    throw new \RuntimeException("Could not load root node for tree '{$tree_name}'");
                }
            }

            // Create the new topic node
            // Topic class overrides TreeNode::add() with signature: add($topicName, $parent)
            // Use call_user_func_array to bypass static analyzer which sees parent class signature
            $newTopic = call_user_func_array([TopicNode::class, 'add'], [$topic_name, $parentNode]);
            
            if (!$newTopic || !$newTopic instanceof TopicNode) {
                throw new \RuntimeException("Failed to create topic '{$topic_name}'");
            }

            // Get parent info for response
            $parentInfo = null;
            if ($parent_topic_id) {
                $parentInfo = [
                    'treeNodeID' => $parentNode->getTreeNodeID(),
                    'name' => $parentNode->getTreeNodeDisplayName(),
                ];
            }

            return [
                'success' => true,
                'message' => "Topic '{$topic_name}' created successfully",
                'topic' => [
                    'treeNodeID' => $newTopic->getTreeNodeID(),
                    'name' => $newTopic->getTreeNodeDisplayName(),
                    'parent' => $parentInfo,
                ],
                'tree' => [
                    'treeID' => $tree->getTreeID(),
                    'name' => $tree->getTreeName(),
                ],
            ];
        }));

        return $tool;
    }

    protected function makeRenameTopicTool(): Tool
    {
        $tool = new CallableTool(
            'rename_topic',
            'Rename an existing topic in a topic tree.'
        );

        $tool->addProperty(new ToolProperty(
            'topic_id',
            PropertyType::INTEGER,
            'Tree node ID of the topic to rename',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'new_name',
            PropertyType::STRING,
            'New name for the topic',
            true
        ));

        $tool->setHandler($this->guarded(function (
            ?int $topic_id = null,
            ?string $new_name = null
        ): array {
            if (!$topic_id || !$new_name) {
                throw new \RuntimeException('Both topic_id and new_name are required');
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException('Permission denied: Only super users can rename topics');
            }

            $node = TopicNode::getByID($topic_id);
            if (!$node || !$node instanceof TopicNode) {
                throw new \RuntimeException("Topic with ID {$topic_id} not found");
            }

            $oldName = $node->getTreeNodeDisplayName('text');
            
            // Update the topic name
            $node->setTreeNodeName($new_name);

            return [
                'success' => true,
                'message' => "Topic renamed from '{$oldName}' to '{$new_name}'",
                'topic' => [
                    'treeNodeID' => $node->getTreeNodeID(),
                    'oldName' => $oldName,
                    'newName' => $new_name,
                ],
            ];
        }));

        return $tool;
    }

    protected function makeDeleteTopicTool(): Tool
    {
        $tool = new CallableTool(
            'delete_topic',
            'Delete a topic from a topic tree. WARNING: This will also delete all child topics and associations.'
        );

        $tool->addProperty(new ToolProperty(
            'topic_id',
            PropertyType::INTEGER,
            'Tree node ID of the topic to delete',
            true
        ));

        $tool->setHandler($this->guarded(function (?int $topic_id = null): array {
            if (!$topic_id) {
                throw new \RuntimeException('topic_id is required');
            }

            // Check if user has permission
            $u = new User();
            if (!$u->isSuperUser()) {
                throw new \RuntimeException('Permission denied: Only super users can delete topics');
            }

            $node = TopicNode::getByID($topic_id);
            if (!$node || !$node instanceof TopicNode) {
                throw new \RuntimeException("Topic with ID {$topic_id} not found");
            }

            $topicName = $node->getTreeNodeDisplayName();
            
            // Check if topic has children
            $node->populateChildren();
            $children = $node->getChildNodes();
            $hasChildren = !empty($children);
            $childCount = count($children);

            // Delete the topic node
            $node->delete();

            return [
                'success' => true,
                'message' => "Topic '{$topicName}' deleted successfully" . 
                    ($hasChildren ? " (including {$childCount} child topic(s))" : ""),
                'deletedTopic' => [
                    'treeNodeID' => $topic_id,
                    'name' => $topicName,
                    'childrenDeleted' => $childCount,
                ],
            ];
        }));

        return $tool;
    }

    /**
     * Recursively build topic tree structure
     */
    private function buildNodeStructure($node, int $currentDepth, int $maxDepth): array
    {
        $topics = [];

        if ($currentDepth >= $maxDepth) {
            return $topics;
        }

        $node->populateChildren();
        $children = $node->getChildNodes();

        foreach ($children as $child) {
            if ($child instanceof TopicNode) {
                $childStructure = $this->buildNodeStructure($child, $currentDepth + 1, $maxDepth);
                
                $topics[] = [
                    'treeNodeID' => $child->getTreeNodeID(),
                    'name' => $child->getTreeNodeDisplayName(),
                    'hasChildren' => !empty($childStructure),
                    'children' => $childStructure,
                ];
            }
        }

        return $topics;
    }

    /**
     * Count total nodes in structure
     */
    private function countNodes(array $structure): int
    {
        $count = count($structure);
        foreach ($structure as $node) {
            if (!empty($node['children'])) {
                $count += $this->countNodes($node['children']);
            }
        }
        return $count;
    }

    /**
     * Search for topics by name (recursive)
     */
    private function searchTopicsByName($node, string $searchName, array &$matches = []): array
    {
        $node->populateChildren();
        $children = $node->getChildNodes();

        foreach ($children as $child) {
            if ($child instanceof TopicNode) {
                $nodeName = $child->getTreeNodeDisplayName();
                
                // Exact match
                if (strcasecmp($nodeName, $searchName) === 0) {
                    $matches[] = [
                        'treeNodeID' => $child->getTreeNodeID(),
                        'name' => $nodeName,
                        'matchType' => 'exact',
                        'path' => $this->buildTopicPath($child),
                    ];
                }
                // Case-insensitive partial match
                elseif (stripos($nodeName, $searchName) !== false) {
                    $matches[] = [
                        'treeNodeID' => $child->getTreeNodeID(),
                        'name' => $nodeName,
                        'matchType' => 'partial',
                        'path' => $this->buildTopicPath($child),
                    ];
                }

                // Recurse to children
                $this->searchTopicsByName($child, $searchName, $matches);
            }
        }

        return $matches;
    }

    /**
     * Build path from root to topic
     */
    private function buildTopicPath($node): array
    {
        $path = [];
        $current = $node;

        while ($current) {
            if ($current instanceof TopicNode) {
                // Don't include the root node (tree node)
                $parent = $current->getTreeNodeParentObject();
                if ($parent && $parent instanceof TopicNode) {
                    array_unshift($path, $current->getTreeNodeDisplayName());
                } elseif (!$parent || !($parent instanceof TopicNode)) {
                    // This is a top-level topic (direct child of root)
                    array_unshift($path, $current->getTreeNodeDisplayName());
                    break;
                }
                $current = $parent;
            } else {
                break;
            }
        }

        return $path;
    }

    /**
     * Wrapper for guarding tool execution with permission checks
     */
    protected function guarded(callable $callable): callable
    {
        return function (...$args) use ($callable) {
            // Basic authentication check
            $u = new User();
            if (!$u->isRegistered()) {
                throw new \RuntimeException('Authentication required: Please log in to use this tool.');
            }

            return $callable(...$args);
        };
    }
}
