<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Access\Entity\UserEntity;
use Concrete\Core\Permission\Key\Key as PermissionKey;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Core\Workflow\Type as WorkflowType;
use Concrete\Core\Workflow\Workflow;
use Katalysis\NeuronAi\Chat\TurnChanges;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\PermissionCleanup;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;
use Katalysis\NeuronAi\Tools\Utilities\WorkflowOutcome;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Tools\ToolCall;

/**
 * P3.6-2 (B-WRT-4, plans/P3.6-2.md §4): a change Concrete sends through a workflow is reported as "submitted for
 * approval". delete_page and move_page (disabled for the model until P3.6-3) go through Concrete's own workflow
 * requests. A throwaway page gets a basic workflow on its Delete and Move keys, for a user who may delete and move it
 * but not approve the workflow, so the request waits. Everything made here is removed. No AI provider call.
 */
final class WorkflowOutcomeTest extends ConcreteTestCase
{
    private const USER = 'neuron_content_editor';

    private ThrowawayPages $pages;
    private ?Workflow $workflow = null;

    /** @var list<int> */
    private array $accesses = [];

    /** @var array<string, int> the highest ID of each table Concrete's workflows write to, before the test */
    private array $marks = [];

    /** What a workflow request leaves behind, also when it ran at once (the empty workflow): table => ID column. */
    private const WORKFLOW_ROWS = [
        'NotificationAlerts' => 'naID',
        'WorkflowProgressNotifications' => 'nID',
        'Notifications' => 'nID',
        'WorkflowProgressHistory' => 'wphID',
        'WorkflowRequestObjects' => 'wrID',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pages = new ThrowawayPages($this->db());
        foreach (self::WORKFLOW_ROWS as $table => $column) {
            $this->marks[$table] = (int) $this->db()->fetchOne("SELECT COALESCE(MAX(`{$column}`), 0) FROM `{$table}`");
        }
    }

    protected function tearDown(): void
    {
        ThrowawayPages::actAs('superuser');
        $db = $this->db();
        if ($this->workflow !== null) {
            $wfID = (int) $this->workflow->getWorkflowID();
            // The requests the workflow is holding, and what Concrete recorded for them.
            $wpIDs = array_map('intval', $db->fetchFirstColumn('SELECT wpID FROM WorkflowProgress WHERE wfID = ?', [$wfID]));
            $wrIDs = array_map('intval', $db->fetchFirstColumn('SELECT wrID FROM WorkflowProgress WHERE wfID = ?', [$wfID]));
            foreach ($wpIDs as $wpID) {
                foreach (['PageWorkflowProgress', 'BasicWorkflowProgressData', 'WorkflowProgressHistory', 'WorkflowProgress'] as $table) {
                    $db->executeStatement("DELETE FROM `{$table}` WHERE wpID = ?", [$wpID]);
                }
            }
            foreach ($wrIDs as $wrID) {
                $db->executeStatement('DELETE FROM WorkflowRequestObjects WHERE wrID = ?', [$wrID]);
            }
            $db->executeStatement('DELETE FROM PermissionAccessWorkflows WHERE wfID = ?', [$wfID]);
            $this->workflow->delete();
            $this->workflow = null;
        }
        $this->pages->restore();
        // The notifications and the request history of this test's requests, in the order the keys need.
        $notifications = $this->marks['Notifications'];
        $db->executeStatement('DELETE FROM NotificationAlerts WHERE naID > ? OR nID > ?', [$this->marks['NotificationAlerts'], $notifications]);
        $db->executeStatement('DELETE FROM WorkflowProgressNotifications WHERE nID > ?', [$notifications]);
        $db->executeStatement('DELETE FROM Notifications WHERE nID > ?', [$notifications]);
        $db->executeStatement('DELETE FROM WorkflowProgressHistory WHERE wphID > ?', [$this->marks['WorkflowProgressHistory']]);
        $db->executeStatement('DELETE FROM WorkflowRequestObjects WHERE wrID > ?', [$this->marks['WorkflowRequestObjects']]);
        foreach ($this->accesses as $paID) {
            PermissionCleanup::deleteAccessIfUnused($db, $paID);
        }
        $this->accesses = [];
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * Gives USER the key on the page, with a basic workflow nobody but the super user may approve.
     */
    private function underWorkflow(Page $page, string $handle): void
    {
        ThrowawayPages::actAs('superuser');
        $this->workflow ??= Workflow::add(WorkflowType::getByHandle('basic'), 'Neuron test workflow ' . bin2hex(random_bytes(3)));
        $page->setPermissionsToOverride();
        $key = PermissionKey::getByHandle($handle);
        $key->setPermissionObject($page);
        $access = $key->getPermissionAccessObject()->duplicate();
        $this->accesses[] = (int) $access->getPermissionAccessID();
        $access->addListItem(UserEntity::getOrCreate($this->app()->make(UserInfoRepository::class)->getByUserName(self::USER)));
        $access->attachWorkflow($this->workflow);
        $key->getPermissionAssignmentObject()->assignPermissionAccess($access);
        $page->refreshCache();
        $this->app()->make('cache/request')->flush();
    }

    private function asUser(): void
    {
        ThrowawayPages::actAs(self::USER);
        $this->app()->make('cache/request')->flush();
        CacheLocal::flush();
    }

    public function testADeleteHeldByAWorkflowIsSubmittedForApprovalNotDone(): void
    {
        $page = $this->pages->create('Workflow delete');
        $cID = (int) $page->getCollectionID();
        $this->underWorkflow($page, 'delete_page');
        $this->asUser();

        $output = ToolByName::run('delete_page', ['pageID' => $cID]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $data = json_decode($output, true);
        $this->assertSame(WorkflowOutcome::SUBMITTED, $data['workflow']);
        $this->assertFalse($data['deleted']);
        $this->assertStringContainsString('submitted for approval', $data['note']);
        CacheLocal::flush();
        $this->assertFalse(Page::getByID($cID)->isInTrash(), 'nothing happened yet');

        $changes = new TurnChanges();
        $call = new ToolCall('delete_page', 'call_wf', ['pageID' => $cID]);
        $call->setResult($output);
        $changes(new ToolCalled($call));
        $this->assertStringStartsWith('Submitted for approval: delete Workflow delete', (string) $changes->line());
        $this->assertSame([], $changes->all(), 'not listed as changed');
    }

    public function testAMoveHeldByAWorkflowIsSubmittedForApprovalNotDone(): void
    {
        $target = $this->pages->create('Workflow target');
        $page = $this->pages->create('Workflow move');
        $cID = (int) $page->getCollectionID();
        $path = (string) $page->getCollectionPath();
        $this->underWorkflow($page, 'move_or_copy_page');
        $this->asUser();

        $output = ToolByName::run('move_page', ['pageID' => $cID, 'newParentID' => (int) $target->getCollectionID()]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertSame(WorkflowOutcome::SUBMITTED, json_decode($output, true)['workflow']);
        CacheLocal::flush();
        $this->assertSame($path, (string) Page::getByID($cID)->getCollectionPath(), 'not moved yet');
    }

    public function testWithoutAWorkflowTheChangeIsDone(): void
    {
        $page = $this->pages->create('Workflow none');
        $cID = (int) $page->getCollectionID();
        ThrowawayPages::actAs('superuser');

        $output = ToolByName::run('delete_page', ['pageID' => $cID]);

        $data = json_decode($output, true);
        $this->assertSame('done', $data['workflow'], $output);
        $this->assertTrue($data['deleted']);
        CacheLocal::flush();
        $this->assertTrue(Page::getByID($cID)->isInTrash());
    }
}
