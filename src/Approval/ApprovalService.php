<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tools\ToolKind;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * The approval mechanism's own layer over Neuron 4's pause and resume (P3.6-1, plans/P3.6-1.md). Neuron pauses a turn
 * before a gated tool runs and holds the whole batch until every gated call in it is decided. This service keeps one
 * KatalysisNeuronAiApprovals row per waiting call: what the user was shown, the fingerprint of the state it was read
 * from, and what they decided.
 *
 * - **Gating:** gate() gives each gated tool a policy that always returns its fixed reason, never false. Neuron asks
 *   the policy again on every pass and on resume; a false there would run the call without approval.
 * - **Deciding:** each click is recorded on its own row (decide(), atomic: the first decision wins). Nothing goes to
 *   Neuron until every call in the batch is decided. Then claimDecided() rechecks the fingerprint of every approved
 *   call, all before any of them runs, so a call's own write never makes the next call on the same page stale. A
 *   changed page turns the approval into a rejection (stale). The decisions then go to Neuron at once.
 * - **A new message while calls wait:** claimAllAsNotApproved() rejects every call not yet sent to Neuron.
 *
 * Every claim is fenced by submittedAt under a row lock, so two tabs or two requests never send a batch twice.
 */
final class ApprovalService
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DENIED = 'denied';
    public const STALE = 'stale';
    public const NOT_APPROVED = 'not_approved';

    /**
     * The fixed reason the policy returns for the page tools (P3.6-1). Since P3.6-2 every write tool is gated
     * (ToolKind::isWrite()); the others return DEFAULT_REASON.
     */
    public const POLICIES = [
        'update_page' => 'Changes a page: the user approves the change first.',
        'set_page_attribute_value' => 'Changes a page attribute: the user approves the change first.',
        'create_page' => 'Creates a page: the user approves it first.',
    ];

    public const DEFAULT_REASON = 'Changes the site: the user approves the change first.';

    /** What the model is told for each kind of rejection. */
    public const DENY_REASON = 'The user chose Deny.';
    public const STALE_REASON = 'The page changed after the preview, so nothing was changed. Show the user a new preview if they still want this.';
    public const NEW_MESSAGE_REASON = 'The user sent a new message instead of approving; this was not done.';

    /** What the panel shows once a call is decided. */
    public const STATUS_TEXT = [
        self::PENDING => '',
        self::APPROVED => 'Approved',
        self::DENIED => 'Denied',
        self::STALE => 'Not done: the page changed since you saw the preview.',
        self::NOT_APPROVED => "Not approved: you sent a new message, so this wasn't done.",
    ];

    private const TABLE = 'KatalysisNeuronAiApprovals';
    private const MAX_NOTE = 500;

    public function __construct(private readonly Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Application::getFacadeApplication()->make(Connection::class));
    }

    /**
     * Puts the approval policy on every gated tool offered, in a toolkit or on its own. Applied to the tools the
     * agent offers for each segment, after the scanner's wrap, so the policy sits on the instances Neuron runs.
     *
     * @param array<int, mixed> $items tools and toolkits
     * @return array<int, mixed> the same items
     */
    public static function gate(array $items): array
    {
        foreach ($items as $item) {
            $tools = $item instanceof ToolkitInterface ? $item->tools() : [$item];
            foreach ($tools as $tool) {
                if ($tool instanceof ToolInterface && self::isGated($tool->getName())) {
                    $reason = self::reason($tool->getName());
                    $tool->withApprovalPolicy(static fn (): string => $reason);
                }
            }
        }

        return $items;
    }

    /**
     * P3.6-2: every write waits for approval, external tools included (B-ACC-8). ToolKind treats any tool that doesn't
     * start with a read word as a write, so a new tool is gated until someone says otherwise; ApprovalRegistryTest checks
     * this against the hand-kept classification.
     */
    public static function isGated(string $tool): bool
    {
        return ToolKind::isWrite($tool);
    }

    /** The fixed reason a gated tool's policy returns. */
    public static function reason(string $tool): string
    {
        return self::POLICIES[$tool] ?? self::DEFAULT_REASON;
    }

    public static function currentUserId(): int
    {
        $user = Application::getFacadeApplication()->make(User::class);

        return $user->isRegistered() ? (int) $user->getUserID() : 0;
    }

    /**
     * Records the calls a paused turn waits on: the preview and fingerprint of each, read now. A call already
     * recorded (the same pause seen again) is left as it is.
     *
     * @param Action[] $actions Neuron's pending approvals
     */
    public function record(int $chatId, array $actions, int $userId): void
    {
        foreach ($actions as $action) {
            $preview = ChangePreview::of($action->name, $action->inputs);
            $this->db->executeStatement(
                'INSERT INTO ' . self::TABLE . ' (chatId, callId, toolName, inputs, preview, fingerprint, status, requestedBy, requestedAt)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                [
                    $chatId,
                    $action->id,
                    $action->name,
                    SecretRedactor::redact((string) json_encode($action->inputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)),
                    (string) json_encode($preview['entry'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    $preview['fingerprint'],
                    self::PENDING,
                    $userId,
                    date('Y-m-d H:i:s'),
                ]
            );
        }
    }

    /**
     * The cards of the calls not yet sent to Neuron: waiting, or decided while another call in the batch waits.
     *
     * @return list<array<string, mixed>>
     */
    public function openCards(int $chatId): array
    {
        return array_map(
            [self::class, 'card'],
            $this->db->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE chatId = ? AND submittedAt IS NULL ORDER BY id', [$chatId])
        );
    }

    /**
     * The cards of these calls, whatever their state.
     *
     * @param list<string> $callIds
     * @return list<array<string, mixed>>
     */
    public function cards(int $chatId, array $callIds): array
    {
        if ($callIds === []) {
            return [];
        }

        return array_map(
            [self::class, 'card'],
            $this->db->fetchAllAssociative(
                'SELECT * FROM ' . self::TABLE . ' WHERE chatId = ? AND callId IN (' . implode(',', array_fill(0, count($callIds), '?')) . ') ORDER BY id',
                [$chatId, ...array_values($callIds)]
            )
        );
    }

    /**
     * What the panel shows for one call: the change entry and the decision, all text written here.
     *
     * @param array<string, mixed> $row
     * @return array{callId: string, tool: string, action: string, target: string, path: string, details: list<string>, note: string, warnings: list<string>, status: string, statusText: string}
     */
    public static function card(array $row): array
    {
        $entry = json_decode((string) $row['preview'], true);
        $entry = is_array($entry) ? $entry : [];
        $status = (string) $row['status'];

        return [
            'callId' => (string) $row['callId'],
            'tool' => (string) $row['toolName'],
            'action' => (string) ($entry['action'] ?? ''),
            'target' => (string) ($entry['target'] ?? ''),
            'path' => (string) ($entry['path'] ?? ''),
            'details' => array_values(array_map('strval', (array) ($entry['details'] ?? []))),
            'note' => (string) ($entry['note'] ?? ''),
            'warnings' => array_values(array_map('strval', (array) ($entry['warnings'] ?? []))),
            'status' => $status,
            'statusText' => self::STATUS_TEXT[$status] ?? '',
        ];
    }

    /**
     * Records one decision. Only a call still waiting and not yet sent to Neuron can be decided: the first decision
     * wins (two tabs), and a call rejected by a new message stays rejected.
     *
     * @return bool whether the decision was recorded
     */
    public function decide(int $chatId, string $callId, bool $approve, ?string $reason, int $userId): bool
    {
        $note = $approve ? null : self::note($reason);

        return $this->db->executeStatement(
            'UPDATE ' . self::TABLE . ' SET status = ?, note = ?, decidedBy = ?, decidedAt = ?'
            . ' WHERE chatId = ? AND callId = ? AND status = ? AND submittedAt IS NULL',
            [$approve ? self::APPROVED : self::DENIED, $note, $userId, date('Y-m-d H:i:s'), $chatId, $callId, self::PENDING]
        ) === 1;
    }

    /**
     * Once every call not yet sent to Neuron is decided, claims them: each approved call's fingerprint is computed
     * again and compared, all before any call runs; a call whose page changed becomes stale and is rejected. Returns
     * the decisions for Neuron, keyed by call ID, or null when a call still waits or there is nothing to send (another
     * request sent it).
     *
     * @return array<string, string|array{0: string, 1: string}>|null
     */
    public function claimDecided(int $chatId): ?array
    {
        return $this->claim($chatId, function (array $rows): ?array {
            foreach ($rows as $row) {
                if ($row['status'] === self::PENDING) {
                    return null;
                }
            }

            $decisions = [];
            foreach ($rows as $row) {
                if ($row['status'] === self::APPROVED) {
                    $inputs = json_decode((string) $row['inputs'], true);
                    if (!hash_equals((string) $row['fingerprint'], ChangePreview::fingerprint((string) $row['toolName'], is_array($inputs) ? $inputs : []))) {
                        $this->setStatus((int) $row['id'], self::STALE, null);
                        $decisions[(string) $row['callId']] = ['reject', self::STALE_REASON];
                        continue;
                    }
                    $decisions[(string) $row['callId']] = 'approve';
                    continue;
                }
                $decisions[(string) $row['callId']] = ['reject', self::rejectionFor($row)];
            }

            return $decisions;
        });
    }

    /**
     * A new message while calls wait: every call not yet sent to Neuron (waiting, or decided while another call in
     * its batch waits) is not approved. Returns the rejections for Neuron, keyed by call ID ([] when none).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function claimAllAsNotApproved(int $chatId, int $userId): array
    {
        return $this->claim($chatId, function (array $rows) use ($userId): array {
            $decisions = [];
            foreach ($rows as $row) {
                $this->db->executeStatement(
                    'UPDATE ' . self::TABLE . ' SET status = ?, note = NULL, decidedBy = ?, decidedAt = ? WHERE id = ?',
                    [self::NOT_APPROVED, $userId, date('Y-m-d H:i:s'), (int) $row['id']]
                );
                $decisions[(string) $row['callId']] = ['reject', self::NEW_MESSAGE_REASON];
            }

            return $decisions;
        }) ?? [];
    }

    /**
     * Rows of the chat's unsent calls, locked; $decide returns the decisions (or null to send nothing), and the rows
     * are then marked sent, in the same transaction.
     *
     * @param \Closure(list<array<string, mixed>>): ?array $decide
     */
    private function claim(int $chatId, \Closure $decide): ?array
    {
        $this->db->beginTransaction();
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . self::TABLE . ' WHERE chatId = ? AND submittedAt IS NULL ORDER BY id FOR UPDATE',
                [$chatId]
            );
            $decisions = $rows === [] ? null : $decide($rows);
            if ($decisions !== null) {
                $this->db->executeStatement(
                    'UPDATE ' . self::TABLE . ' SET submittedAt = ? WHERE id IN (' . implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows)) . ')',
                    [date('Y-m-d H:i:s')]
                );
            }
            $this->db->commit();

            return $decisions;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function setStatus(int $id, string $status, ?string $note): void
    {
        $this->db->executeStatement('UPDATE ' . self::TABLE . ' SET status = ?, note = ? WHERE id = ?', [$status, $note, $id]);
    }

    /**
     * Marks calls not approved because they could not be sent to Neuron (the run was gone or refused them).
     *
     * @param list<string> $callIds
     */
    public function markNotApproved(int $chatId, array $callIds): void
    {
        foreach ($callIds as $callId) {
            $this->db->executeStatement(
                'UPDATE ' . self::TABLE . ' SET status = ? WHERE chatId = ? AND callId = ? AND status IN (?, ?)',
                [self::NOT_APPROVED, $chatId, $callId, self::PENDING, self::APPROVED]
            );
        }
    }

    /** The status of each of these calls, keyed by call ID. */
    public function statuses(int $chatId, array $callIds): array
    {
        $statuses = [];
        foreach ($this->cards($chatId, $callIds) as $card) {
            $statuses[$card['callId']] = $card['status'];
        }

        return $statuses;
    }

    private static function rejectionFor(array $row): string
    {
        $note = trim((string) ($row['note'] ?? ''));

        return $note === '' ? self::DENY_REASON : 'The user chose Deny and said: ' . $note;
    }

    /** The user's reason for a Deny: trimmed, at most MAX_NOTE characters, redacted, or null. */
    private static function note(?string $reason): ?string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return null;
        }

        return SecretRedactor::redact(mb_substr($reason, 0, self::MAX_NOTE));
    }
}
