<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\File\Filesystem;
use Concrete\Core\Http\Request;
use Concrete\Core\Tree\Node\Type\Topic as TopicNode;
use Concrete\Core\Tree\Type\Topic as TopicTree;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\PermissionCleanup;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;
use NeuronAI\Tools\PropertyType;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P3.5-1 (B-ACC-4, B-ACC-6), the Files, Users, People, Topics and attribute half: every write tool checks Concrete's
 * permission on its object, or the Dashboard page Concrete itself gates that management with, and refuses with the
 * permission and who can grant it. Attribute, set and topic-tree management is no longer super-user only (decision 1).
 *
 * As neuron_editor, who has no file manager rights and no Dashboard access on the dev site. The class's leak check
 * shows that no refused call created anything. Tools are called by name (ToolByName). No AI provider call.
 */
final class FilesTopicsAttributesPermissionTest extends ConcreteTestCase
{
    private ?TopicTree $tree = null;
    private int $lastAccess = 0;

    protected function tearDown(): void
    {
        if ($this->tree !== null) {
            ThrowawayPages::actAs('superuser');
            $this->tree->delete();
            $this->tree = null;
            // A new tree copies its root's permissions into a new access, which deleting the tree leaves behind.
            foreach ($this->db()->fetchFirstColumn('SELECT paID FROM PermissionAccess WHERE paID > ?', [$this->lastAccess]) as $paID) {
                PermissionCleanup::deleteAccessIfUnused($this->db(), (int) $paID);
            }
        }
        Request::getInstance()->setCustomRequestUser(-1);
        $this->app()->make('cache/request')->flush();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function seedFileID(): int
    {
        $fID = (int) $this->db()->fetchOne("SELECT MIN(fID) FROM FileVersions WHERE fvTitle = 'Neuron Test File'");
        if ($fID === 0) {
            $this->markTestSkipped('The seed\'s "Neuron Test File" is not on this site: run the seed.');
        }

        return $fID;
    }

    /**
     * A throwaway topic tree with one topic, made as the super user (tearDown removes it).
     *
     * @return array{string, int} the tree's name and its topic's node ID
     */
    private function topicTree(): array
    {
        ThrowawayPages::actAs('superuser');
        $this->lastAccess = (int) $this->db()->fetchOne('SELECT COALESCE(MAX(paID), 0) FROM PermissionAccess');
        $this->tree = TopicTree::add('Neuron Test Topics ' . bin2hex(random_bytes(3)));
        $topic = call_user_func_array([TopicNode::class, 'add'], ['Neuron Test Topic', $this->tree->getRootTreeNodeObject()]);

        return [(string) $this->tree->getTreeName(), (int) $topic->getTreeNodeID()];
    }

    /**
     * @return array<string, array{string, string}> case => [tool, the permission's display name or the Dashboard page]
     */
    public static function writeProvider(): array
    {
        return [
            'create_file_folder' => ['create_file_folder', 'Add File'],
            'move_file_to_folder' => ['move_file_to_folder', 'Edit File Properties'],
            'delete_file' => ['delete_file', 'Delete File'],
            'set_file_attribute_value' => ['set_file_attribute_value', 'Edit File Properties'],
            'create_page_attribute' => ['create_page_attribute', '/dashboard/pages/attributes'],
            'create_page_attribute_and_add_to_set' => ['create_page_attribute_and_add_to_set', '/dashboard/pages/attributes'],
            'update_page_select_attribute_options' => ['update_page_select_attribute_options', '/dashboard/pages/attributes'],
            'create_user_attribute' => ['create_user_attribute', '/dashboard/users/attributes'],
            'update_user_select_attribute_options' => ['update_user_select_attribute_options', '/dashboard/users/attributes'],
            'create_file_attribute' => ['create_file_attribute', '/dashboard/files/attributes'],
            'update_file_select_attribute_options' => ['update_file_select_attribute_options', '/dashboard/files/attributes'],
            'create_attribute_set' => ['create_attribute_set', '/dashboard/system/attributes/sets'],
            'delete_attribute_set' => ['delete_attribute_set', '/dashboard/system/attributes/sets'],
            'add_attribute_to_set' => ['add_attribute_to_set', '/dashboard/system/attributes/sets'],
            'remove_attribute_from_set' => ['remove_attribute_from_set', '/dashboard/system/attributes/sets'],
            'create_topic_tree' => ['create_topic_tree', 'Add Topic Tree'],
            'create_topic' => ['create_topic', 'Dashboard > Topics'],
            'rename_topic' => ['rename_topic', 'Dashboard > Topics'],
            'delete_topic' => ['delete_topic', 'Dashboard > Topics'],
            'update_person_specialisms' => ['update_person_specialisms', '/dashboard/katalysis_pro/katalysis_people'],
            'update_person_topics' => ['update_person_topics', '/dashboard/katalysis_pro/katalysis_people'],
        ];
    }

    #[DataProvider('writeProvider')]
    public function testTheWriteIsRefusedWithThePermission(string $tool, string $permission): void
    {
        $inputs = $this->inputs($tool);
        ThrowawayPages::actAs('neuron_editor');

        $output = ToolByName::run($tool, $inputs);

        $this->assertFalse(ToolKind::succeeded($output), "{$tool} did not refuse: {$output}");
        $this->assertStringContainsString('Permission denied', $output, $tool);
        $this->assertStringContainsString($permission, $output, "{$tool} does not name the permission: {$output}");
        $this->assertStringContainsString('site administrator', $output, "{$tool} does not say who can grant it: {$output}");
        $this->assertStringContainsString('Nothing was changed', $output, $tool);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function loginProvider(): array
    {
        return [
            'list_files' => ['list_files'],
            'create_file_folder' => ['create_file_folder'],
            'list_users' => ['list_users'],
        ];
    }

    #[DataProvider('loginProvider')]
    public function testFilesAndUsersRefuseAGuest(string $tool): void
    {
        $inputs = $this->inputs($tool);
        Request::getInstance()->setCustomRequestUser(-1);
        $this->app()->make('cache/request')->flush();

        $output = ToolByName::run($tool, $inputs);

        $this->assertFalse(ToolKind::succeeded($output), "{$tool} ran for a guest: {$output}");
        $this->assertStringContainsString('Authentication required', $output);
    }

    public function testTheSuperUserMayStillCreateAFolder(): void
    {
        ThrowawayPages::actAs('superuser');
        $name = 'Neuron Test Folder ' . bin2hex(random_bytes(3));

        $output = ToolByName::run('create_file_folder', ['name' => $name]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $folderID = (int) (json_decode($output, true)['folder']['id'] ?? 0);
        $this->assertGreaterThan(0, $folderID, $output);
        \Concrete\Core\Tree\Node\Node::getByID($folderID)?->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function inputs(string $tool): array
    {
        $root = (int) $this->app()->make(Filesystem::class)->getRootFolder()->getTreeNodeID();
        $known = match ($tool) {
            'create_file_folder' => ['name' => 'Neuron Test Refused Folder'],
            'move_file_to_folder' => ['fileID' => (string) $this->seedFileID(), 'folderID' => $root],
            'delete_file' => ['fileID' => $this->seedFileID()],
            'set_file_attribute_value' => ['fileID' => $this->seedFileID(), 'handle' => 'width', 'value' => '10'],
            'update_page_select_attribute_options', 'update_user_select_attribute_options', 'update_file_select_attribute_options'
                => ['handle' => 'neuron_test_refused', 'options' => ['Refused']],
            'create_topic' => ['tree_name' => $this->topicTree()[0], 'topic_name' => 'Refused'],
            'rename_topic' => ['topic_id' => $this->topicTree()[1], 'new_name' => 'Refused'],
            'delete_topic' => ['topic_id' => $this->topicTree()[1]],
            default => [],
        };

        // Every other required input gets a placeholder of its type: the check comes before the inputs are used.
        foreach (ToolByName::tool($tool)->getProperties() as $property) {
            if (!$property->isRequired() || array_key_exists($property->getName(), $known)) {
                continue;
            }
            $known[$property->getName()] = match ($property->getType()) {
                PropertyType::INTEGER, PropertyType::NUMBER => 1,
                PropertyType::BOOLEAN => false,
                PropertyType::ARRAY => [],
                PropertyType::OBJECT => [],
                default => 'neuron_test_refused',
            };
        }

        return $known;
    }
}
