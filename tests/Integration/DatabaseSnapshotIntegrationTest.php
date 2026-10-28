<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\DatabaseSnapshot;

/**
 * P1.12: the snapshot sees a change to the real dev database, in the row count and in the content, and sees
 * nothing once the change is rolled back. Everything here runs inside a transaction that is rolled back.
 */
final class DatabaseSnapshotIntegrationTest extends ConcreteTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->app()->make(Connection::class);
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
    }

    public function testAnAddedRowIsDetectedAndARollbackClearsIt(): void
    {
        $before = DatabaseSnapshot::take($this->db);
        $this->assertGreaterThan(300, count($before->counts), 'Every table should be counted');
        $this->assertArrayHasKey('AreaPermissionAssignments', $before->counts);
        $this->assertArrayHasKey('Config', $before->checksums);

        // The shape of the P1.12 leak: an area override row for a page that does not exist.
        $this->db->insert('AreaPermissionAssignments', ['cID' => 2147483000, 'arHandle' => 'Main', 'pkID' => 1, 'paID' => 1]);

        $this->assertSame(
            ['AreaPermissionAssignments: ' . $before->counts['AreaPermissionAssignments'] . ' -> ' . ($before->counts['AreaPermissionAssignments'] + 1) . ' (+1 rows)'],
            DatabaseSnapshot::differences($before, DatabaseSnapshot::take($this->db))
        );

        $this->db->rollBack();
        $this->assertSame([], DatabaseSnapshot::differences($before, DatabaseSnapshot::take($this->db)));
    }

    public function testAChangedConfigValueIsDetectedWithoutACountChange(): void
    {
        $row = $this->db->fetchAssociative('SELECT configNamespace, configGroup, configItem, configValue FROM Config LIMIT 1');
        $this->assertIsArray($row, 'The Config table should not be empty');
        $before = DatabaseSnapshot::take($this->db);

        $this->db->update(
            'Config',
            ['configValue' => (string) $row['configValue'] . 'x'],
            ['configNamespace' => $row['configNamespace'], 'configGroup' => $row['configGroup'], 'configItem' => $row['configItem']]
        );

        $this->assertSame(
            ['Config: still ' . $before->counts['Config'] . ' rows, but the content changed'],
            DatabaseSnapshot::differences($before, DatabaseSnapshot::take($this->db))
        );
    }

    public function testLogRowsAreNotCounted(): void
    {
        $before = DatabaseSnapshot::take($this->db);
        $this->db->insert('Logs', ['channel' => 'p112-snapshot-test', 'time' => time(), 'message' => 'x', 'level' => 100, 'uID' => 0, 'cID' => 0]);

        $this->assertSame($before->counts['Logs'] + 1, DatabaseSnapshot::take($this->db)->counts['Logs']);
        $this->assertSame([], DatabaseSnapshot::differences($before, DatabaseSnapshot::take($this->db)));
    }

    /**
     * P3.6-2 (Glyn): the approval mechanism's two tables (P3.6-1). Rows a test leaves behind are counted like any
     * table's, and a test that rewrites one (decides a call, or advances a paused run) without adding or removing a row
     * is caught too.
     */
    public function testTheApprovalTablesAreCheckedForLeftoversAndRewrittenRows(): void
    {
        $chatId = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) + 1000000 FROM KatalysisNeuronAiChats');
        $this->db->insert('KatalysisNeuronAiChats', ['id' => $chatId, 'sessionId' => 'p362-snapshot-test', 'createdBy' => 1, 'started' => date('Y-m-d H:i:s'), 'createdDate' => date('Y-m-d H:i:s'), 'userMessageCount' => 0]);
        $before = DatabaseSnapshot::take($this->db);

        $this->db->insert('KatalysisNeuronAiApprovals', [
            'chatId' => $chatId, 'callId' => 'call_snapshot', 'toolName' => 'update_page', 'inputs' => '{}', 'preview' => '{}',
            'fingerprint' => str_repeat('0', 64), 'status' => 'pending', 'requestedBy' => 1, 'requestedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->db->insert('KatalysisNeuronAiWorkflowStore', ['`partition`' => bin2hex('chat-' . $chatId), '`key`' => bin2hex('control'), '`value`' => 'eA==']);
        $this->assertContains(
            'KatalysisNeuronAiApprovals: ' . $before->counts['KatalysisNeuronAiApprovals'] . ' -> ' . ($before->counts['KatalysisNeuronAiApprovals'] + 1) . ' (+1 rows)',
            DatabaseSnapshot::differences($before, DatabaseSnapshot::take($this->db))
        );

        $withRows = DatabaseSnapshot::take($this->db);
        $this->db->update('KatalysisNeuronAiApprovals', ['status' => 'approved'], ['chatId' => $chatId]);
        $this->db->update('KatalysisNeuronAiWorkflowStore', ['`value`' => 'eQ=='], ['`partition`' => bin2hex('chat-' . $chatId)]);

        $this->assertSame(
            [
                'KatalysisNeuronAiApprovals: still ' . $withRows->counts['KatalysisNeuronAiApprovals'] . ' rows, but the content changed',
                'KatalysisNeuronAiWorkflowStore: still ' . $withRows->counts['KatalysisNeuronAiWorkflowStore'] . ' rows, but the content changed',
            ],
            DatabaseSnapshot::differences($withRows, DatabaseSnapshot::take($this->db))
        );
    }
}
