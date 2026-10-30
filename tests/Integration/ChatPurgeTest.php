<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\Chat\ChatPurge;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;

/**
 * P3.9-2 (plans/P3.9-2.md, B-PII-5): a purge removes a chat with everything kept about it: its messages (and the
 * Changed-line data in their meta), its approval records, its paused run, and the log lines we write. Chats are aged
 * by their last activity. A chat with a call waiting for approval has the call ended, so nothing waiting runs.
 *
 * Every row here is dated 2000 and the cutoff is 1 January 2001, so no real chat or log line on the dev site is ever
 * in range; anything the test makes and the purge keeps is removed in tearDown.
 */
final class ChatPurgeTest extends ConcreteTestCase
{
    private const CUTOFF = '2001-01-01 00:00:00';
    private const OLD = '2000-06-01 12:00:00';
    private const RECENT = '2002-06-01 12:00:00';
    private const OWNER = 999999901;
    private const OTHER_OWNER = 999999902;
    private const LOG_MARK = 'p392-test-';

    /** @var int[] */
    private array $chatIds = [];

    /** @var int[] chats whose run was ended */
    private array $ended = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach ($this->chatIds as $id) {
            $db->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
        }
        $db->executeStatement("DELETE FROM Logs WHERE message LIKE ?", ['%' . self::LOG_MARK . '%']);
        $db->executeStatement('DELETE FROM KatalysisNeuronAiWorkflowStore WHERE `partition` LIKE ?', [bin2hex(self::LOG_MARK) . '%']);
        $this->chatIds = [];
        parent::tearDown();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function purge(): ChatPurge
    {
        return new ChatPurge($this->db(), function (int $chatId): void {
            $this->ended[] = $chatId;
        });
    }

    /** A chat with one message and, if asked, an approval with that status; dated by its last activity. */
    private function chat(string $lastActivity, int $owner = self::OWNER, ?string $approval = null): int
    {
        $db = $this->db();
        $db->insert('KatalysisNeuronAiChats', [
            'sessionId' => 'neuron-test-p392-' . bin2hex(random_bytes(4)),
            'userMessageCount' => 1, 'createdBy' => $owner, 'createdDate' => self::OLD, 'updatedDate' => $lastActivity,
        ]);
        $id = (int) $db->lastInsertId();
        $this->chatIds[] = $id;
        $db->insert('KatalysisNeuronAiChatMessages', [
            'chatId' => $id, 'messageId' => 'm' . bin2hex(random_bytes(6)), 'role' => 'assistant', 'content' => 'Done.',
            'meta' => '{"katalysis_changes_line":"Changed: Headings"}', 'formatVersion' => 1, 'createdAt' => $lastActivity,
        ]);
        if ($approval !== null) {
            $db->insert('KatalysisNeuronAiApprovals', [
                'chatId' => $id, 'callId' => 'c' . bin2hex(random_bytes(6)), 'toolName' => 'update_page', 'inputs' => '{}',
                'preview' => '{}', 'fingerprint' => str_repeat('0', 64), 'status' => $approval, 'requestedBy' => $owner,
                'requestedAt' => $lastActivity,
            ]);
        }

        return $id;
    }

    private function log(string $message, string $when): void
    {
        $this->db()->insert('Logs', ['channel' => 'application', 'time' => strtotime($when), 'message' => $message, 'level' => 200]);
    }

    private function rowsOf(string $table, int $chatId): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM {$table} WHERE chatId = ?", [$chatId]);
    }

    public function testAnOldChatGoesWithEverythingKeptAboutItAndARecentOneStays(): void
    {
        $old = $this->chat(self::OLD, approval: 'approved');
        $recent = $this->chat(self::RECENT);
        $this->db()->executeStatement('INSERT INTO KatalysisNeuronAiWorkflowStore (`partition`, `key`, `value`, updated_at) VALUES (?, ?, ?, ?)', [bin2hex(self::LOG_MARK . 'old-run'), 'k', 'v', self::OLD]);
        $this->log('Katalysis Neuron AI: tool update_page, call c1 in chat ' . $old . ': ok ' . self::LOG_MARK . 'old', self::OLD);
        $this->log('Neuron AI Error: ' . self::LOG_MARK . 'old error', self::OLD);
        $this->log('Some other package: ' . self::LOG_MARK . 'not ours', self::OLD);
        $this->log('Katalysis Neuron AI: ' . self::LOG_MARK . 'recent', self::RECENT);

        $result = $this->purge()->purge(new \DateTimeImmutable(self::CUTOFF));

        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM KatalysisNeuronAiChats WHERE id = ?', [$old]));
        $this->assertSame(0, $this->rowsOf('KatalysisNeuronAiChatMessages', $old), 'messages and their Changed-line meta');
        $this->assertSame(0, $this->rowsOf('KatalysisNeuronAiApprovals', $old));
        $this->assertSame(1, $this->rowsOf('KatalysisNeuronAiChatMessages', $recent), 'the recent chat stays');
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM KatalysisNeuronAiWorkflowStore WHERE `partition` = ?', [bin2hex(self::LOG_MARK . 'old-run')]), 'an old run is swept');
        $logs = $this->db()->fetchFirstColumn('SELECT message FROM Logs WHERE message LIKE ?', ['%' . self::LOG_MARK . '%']);
        $this->assertEqualsCanonicalizing(['Some other package: ' . self::LOG_MARK . 'not ours', 'Katalysis Neuron AI: ' . self::LOG_MARK . 'recent'], $logs, 'only our old lines go');
        $this->assertGreaterThanOrEqual(1, $result['chats']);
        $this->assertGreaterThanOrEqual(1, $result['messages']);
        $this->assertSame([], $this->ended, 'no call was waiting, so no run had to be ended');
    }

    public function testAChatWithACallWaitingHasTheCallEndedBeforeItGoes(): void
    {
        $waiting = $this->chat(self::OLD, approval: 'pending');

        $counted = $this->purge()->count(new \DateTimeImmutable(self::CUTOFF));
        $this->purge()->purge(new \DateTimeImmutable(self::CUTOFF));

        $this->assertGreaterThanOrEqual(1, $counted['waiting'], 'the confirmation can say a change is waiting');
        $this->assertContains($waiting, $this->ended, 'its waiting call is ended (not approved) and its run abandoned');
        $this->assertSame(0, $this->rowsOf('KatalysisNeuronAiApprovals', $waiting));
    }

    public function testAnOwnersPurgeTakesOnlyTheirChatsAndTheirChatsLogLines(): void
    {
        $mine = $this->chat(self::OLD);
        $theirs = $this->chat(self::OLD, self::OTHER_OWNER);
        $this->log('Katalysis Neuron AI: approval for tool update_page, call c2 in chat ' . $mine . ': denied ' . self::LOG_MARK, self::RECENT);

        $counted = $this->purge()->count(new \DateTimeImmutable(self::CUTOFF), self::OWNER);
        $this->purge()->purge(new \DateTimeImmutable(self::CUTOFF), self::OWNER);

        $this->assertSame(1, $counted['chats'], 'only the owner\'s, counted before anything is deleted');
        $this->assertSame(0, $this->rowsOf('KatalysisNeuronAiChatMessages', $mine));
        $this->assertSame(1, $this->rowsOf('KatalysisNeuronAiChatMessages', $theirs), 'another user\'s chat stays');
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM Logs WHERE message LIKE ?', ['%in chat ' . $mine . ':%']), 'the deleted chat\'s log lines go, whatever their age');
    }

    public function testABatchStopsAtTheLimit(): void
    {
        $this->chat(self::OLD);
        $this->chat(self::OLD);

        $this->assertSame(1, $this->purge()->purge(new \DateTimeImmutable(self::CUTOFF), self::OWNER, 1)['chats']);
        $this->assertSame(1, $this->purge()->count(new \DateTimeImmutable(self::CUTOFF), self::OWNER)['chats'], 'the next run continues');
    }
}
