<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\Approval\ApprovalService;
use Katalysis\NeuronAi\ConcreteCmsAgent;

/**
 * Deletes chats with everything kept about them (P3.9-2, plans/P3.9-2.md §1, B-PII-5). Every route uses it: the
 * scheduled purge, the admin's purge, a user's delete of one chat or of all theirs.
 *
 * A chat goes with its messages (and the Changed-line data and notices in their meta) and its approval records, by
 * ON DELETE CASCADE. A chat with a call waiting for approval first has its run ended (endRun: the calls are marked not
 * approved and the run abandoned, ConcreteCmsAgent::discardRun()), so nothing waiting runs. An age purge also sweeps
 * paused runs not updated since the cutoff (a run whose chat is gone), and the log lines we write (LOG_PREFIXES) older
 * than the cutoff; a chat's own delete removes the log lines that name it.
 *
 * A chat's age is its last activity: updatedDate, else createdDate (a chat with neither counts as old).
 */
class ChatPurge
{
    /** At most this many chats per run; the next run continues. */
    public const BATCH = 500;

    /** The start of every line we write to Concrete's log. Other packages' and core's lines are never touched. */
    public const LOG_PREFIXES = ['Katalysis Neuron AI: ', 'Neuron AI Error: ', 'Neuron AI Tool Error: '];

    private const LAST_ACTIVITY = "COALESCE(c.updatedDate, c.createdDate, '1970-01-01 00:00:00')";

    /** @var \Closure(int): void */
    private \Closure $endRun;

    /**
     * @param (\Closure(int): void)|null $endRun ends a chat's waiting calls and run; null uses the agent's discardRun()
     */
    public function __construct(private readonly Connection $db, ?\Closure $endRun = null)
    {
        $this->endRun = $endRun ?? static function (int $chatId): void {
            (new ConcreteCmsAgent())->setChatId($chatId)->discardRun();
        };
    }

    /**
     * What purge() would delete, for the confirmation: chats, their messages, their owners, and how many have a call
     * waiting for approval.
     *
     * @return array{chats: int, messages: int, users: int, waiting: int}
     */
    public function count(\DateTimeInterface $cutoff, ?int $ownerId = null): array
    {
        [$where, $params] = $this->where($cutoff, $ownerId);
        $row = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS chats, COUNT(DISTINCT c.createdBy) AS users,'
            . ' (SELECT COUNT(*) FROM KatalysisNeuronAiChatMessages m JOIN KatalysisNeuronAiChats c ON c.id = m.chatId WHERE ' . $where . ') AS messages,'
            . ' (SELECT COUNT(DISTINCT a.chatId) FROM KatalysisNeuronAiApprovals a JOIN KatalysisNeuronAiChats c ON c.id = a.chatId WHERE a.status = ? AND ' . $where . ') AS waiting'
            . ' FROM KatalysisNeuronAiChats c WHERE ' . $where,
            [...$params, ApprovalService::PENDING, ...$params, ...$params]
        ) ?: [];

        return [
            'chats' => (int) ($row['chats'] ?? 0),
            'messages' => (int) ($row['messages'] ?? 0),
            'users' => (int) ($row['users'] ?? 0),
            'waiting' => (int) ($row['waiting'] ?? 0),
        ];
    }

    /**
     * Deletes the chats last active before $cutoff (only $ownerId's, when given), at most $limit of them. An age purge
     * (no owner) also sweeps old paused runs and our old log lines; an owner's purge removes the log lines of the chats
     * it deletes.
     *
     * @return array{chats: int, messages: int, approvals: int, runs: int, logs: int}
     */
    public function purge(\DateTimeInterface $cutoff, ?int $ownerId = null, int $limit = self::BATCH): array
    {
        [$where, $params] = $this->where($cutoff, $ownerId);
        $ids = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT c.id FROM KatalysisNeuronAiChats c WHERE ' . $where . ' ORDER BY ' . self::LAST_ACTIVITY . ' LIMIT ' . max(1, $limit),
            $params
        ));

        $result = $this->deleteChats($ids, $ownerId !== null);
        if ($ownerId === null) {
            $result['runs'] += $this->db->executeStatement(
                'DELETE FROM ' . ConcreteCmsAgent::WORKFLOW_TABLE . ' WHERE updated_at < ?',
                [$cutoff->format('Y-m-d H:i:s')]
            );
            $result['logs'] += $this->deleteLogs('time < ?', [$cutoff->getTimestamp()]);
        }

        return $result;
    }

    /**
     * A user's delete of one chat (the caller checks it is theirs): the chat, everything kept about it, and the log
     * lines that name it.
     *
     * @return array{chats: int, messages: int, approvals: int, runs: int, logs: int}
     */
    public function deleteChat(int $chatId): array
    {
        return $this->deleteChats([$chatId], true);
    }

    /**
     * @param list<int> $ids
     * @return array{chats: int, messages: int, approvals: int, runs: int, logs: int}
     */
    private function deleteChats(array $ids, bool $withTheirLogs): array
    {
        $result = ['chats' => 0, 'messages' => 0, 'approvals' => 0, 'runs' => 0, 'logs' => 0];
        foreach ($ids as $id) {
            $waiting = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM KatalysisNeuronAiApprovals WHERE chatId = ? AND status = ?',
                [$id, ApprovalService::PENDING]
            );
            if ($waiting > 0) {
                // Its waiting calls are ended and its run abandoned before the rows go: nothing waiting runs.
                ($this->endRun)($id);
                $result['runs']++;
            }
            $result['messages'] += (int) $this->db->fetchOne('SELECT COUNT(*) FROM KatalysisNeuronAiChatMessages WHERE chatId = ?', [$id]);
            $result['approvals'] += (int) $this->db->fetchOne('SELECT COUNT(*) FROM KatalysisNeuronAiApprovals WHERE chatId = ?', [$id]);
            // The messages and approvals go with it (ON DELETE CASCADE).
            $result['chats'] += $this->db->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
            if ($withTheirLogs) {
                $result['logs'] += $this->deleteLogs('message LIKE ?', ['%in chat ' . $id . ':%']);
            }
        }

        return $result;
    }

    /**
     * Our log lines matching $condition.
     *
     * @param list<mixed> $params
     */
    private function deleteLogs(string $condition, array $params): int
    {
        $ours = implode(' OR ', array_fill(0, count(self::LOG_PREFIXES), 'message LIKE ?'));
        $prefixes = array_map(static fn (string $p): string => addcslashes($p, '%_\\') . '%', self::LOG_PREFIXES);

        return $this->db->executeStatement("DELETE FROM Logs WHERE ({$ours}) AND {$condition}", [...$prefixes, ...$params]);
    }

    /**
     * @return array{string, list<mixed>}
     */
    private function where(\DateTimeInterface $cutoff, ?int $ownerId): array
    {
        $where = self::LAST_ACTIVITY . ' < ?';
        $params = [$cutoff->format('Y-m-d H:i:s')];
        if ($ownerId !== null) {
            $where .= ' AND c.createdBy = ?';
            $params[] = $ownerId;
        }

        return [$where, $params];
    }
}
