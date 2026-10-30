<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tools\ToolKind;
use Katalysis\NeuronAi\Tools\Utilities\DraftCleanup;
use Katalysis\NeuronAi\Tools\Utilities\WorkflowOutcome;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

/**
 * The pages and blocks a turn changed (BF-7, brought forward from P3.6; plans/P2.7.md §5). A listener on Neuron's
 * ToolCalled, held by the agent: the event fires once a call has settled, also after a tool error, and the record is
 * still there when the turn fails. The chat response hands it to the panel, which reloads the page when it changed.
 *
 * - A call counts when it is a write (ToolKind), it succeeded, and its result carries the `versionState` every page
 *   draft write reports (DraftState), which names the page.
 * - Neuron may emit the event again on a retry (src/Observability/AGENTS.md:28), so each call is taken once, keyed by
 *   its call ID (or, with none, its name and inputs).
 * - Every string recorded goes through SecretRedactor. Nothing is logged here.
 *
 * P3.6-1 (plans/P3.6-1.md §4) adds what the turn's "Changed: …" line needs: each page's newest version and whether the
 * turn created it; every other successful write (a file, a topic, a version, …), so every write tool is recorded
 * (TurnChangesCoverageTest); and the writes that were refused, failed or not approved. line() writes the text, which
 * the agent stores on the turn's reply (META_KEY) and the panel shows under it.
 */
final class TurnChanges
{
    /** Metadata key on the turn's stored reply: {line} (P3.6-1). */
    public const META_KEY = 'katalysis_changes';

    /** The input names that identify what a write that isn't a page draft changed, in order of preference. */
    private const OBJECT_INPUTS = ['fileID', 'fID', 'topicID', 'treeNodeID', 'nodeID', 'userID', 'uID', 'versionID', 'cvID', 'pageID', 'id', 'handle', 'name'];

    /** @var array<string, true> calls already taken */
    private array $seen = [];

    /** @var array<int, array{pageID: int, path: string, name: string, tools: list<string>, blockIDs: list<int>, replacedBlockIDs: array<string, int>, versionID: int, isNewPage: bool, kind: string}> */
    private array $pages = [];

    /** @var array<int, array<string, true>> P3.6a: by page, the global areas changed through it, as labels */
    private array $shared = [];

    /** @var array<string, array{kind: string, object: string, tools: list<string>}> successful writes that aren't page drafts */
    private array $others = [];

    /** @var list<array{tool: string, target: string, reason: string}> */
    private array $refused = [];

    /** @var list<string> P3.6-2: partly written drafts (or pages) failed calls kept (DraftCleanup), as labels */
    private array $kept = [];

    /** @var list<string> P3.6-2: changes Concrete sent through a workflow, as labels (B-WRT-4) */
    private array $submitted = [];

    /** Whether the turn called a write tool at all, so "Nothing was changed" is only said after one. */
    private bool $attempted = false;

    public function __invoke(ToolCalled $event): void
    {
        $this->record($event->tool);
    }

    public function record(ToolCall $call): void
    {
        $name = $call->getName();
        if (!ToolKind::isWrite($name) || !$call->hasResult()) {
            return;
        }

        $key = $call->getCallId() ?? $name . ' ' . json_encode($call->getInputs());
        if (isset($this->seen[$key])) {
            return;
        }

        $this->attempted = true;
        $result = $call->getResult();
        if (!ToolKind::succeeded($result)) {
            $this->seen[$key] = true;
            $this->refuse($name, self::object($call->getInputs()), 'failed');
            // P3.6-2: a failed call may have kept a partly written draft (BF-3); it is listed, not hidden.
            foreach (DraftCleanup::takeKept() as $kept) {
                $this->kept[] = $this->keptLabel($kept);
            }

            return;
        }
        $data = json_decode($result instanceof ToolOutput ? $result->getText() : $result, true);
        $pageID = is_array($data) ? (int) ($data['versionState']['pageID'] ?? 0) : 0;
        $this->seen[$key] = true;
        if (is_array($data) && ($data['workflow'] ?? null) === WorkflowOutcome::SUBMITTED) {
            // P3.6-2 (B-WRT-4): Concrete sent the change through a workflow; it hasn't happened yet.
            $this->submitted[] = SecretRedactor::redact((string) ($data['workflowSummary'] ?? $name));

            return;
        }
        if ($pageID <= 0) {
            // Any other write (a file, a topic, a version, a user, …): recorded by what it names (P3.6-1), with the
            // undo line a structural change carries (BF-24: move_page).
            $this->recordOther($name, $call->getInputs(), is_array($data) && is_string($data['undo'] ?? null) ? $data['undo'] : '', self::movedTo($name, $data));

            return;
        }

        $entry = $this->pages[$pageID] ?? $this->newEntry($pageID);
        $entry['versionID'] = max($entry['versionID'], (int) ($data['versionState']['versionID'] ?? 0));
        $entry['isNewPage'] = $entry['isNewPage'] || $name === 'create_page';
        $toolName = SecretRedactor::redact($name);
        if (!in_array($toolName, $entry['tools'], true)) {
            $entry['tools'][] = $toolName;
        }
        foreach (self::blocks($call->getInputs(), $data) as [$old, $new]) {
            if (!in_array($new, $entry['blockIDs'], true)) {
                $entry['blockIDs'][] = $new;
            }
            if ($old !== null && $old !== $new) {
                $entry['replacedBlockIDs'][(string) $old] = $new;
            }
        }
        $this->pages[$pageID] = $entry;
        // P3.6a: a change to a global area through this page is in the stack's draft, tied to the page's.
        if (is_array($data['sharedArea'] ?? null)) {
            $label = 'with the ' . SecretRedactor::redact((string) ($data['sharedArea']['kind'] ?? 'global area')) . ' "'
                . SecretRedactor::redact((string) ($data['sharedArea']['name'] ?? '')) . '", shown on every page that has it';
            $this->shared[$pageID][$label] = true;
        }
    }

    /**
     * @return list<array{pageID: int, path: string, name: string, tools: list<string>, blockIDs: list<int>, replacedBlockIDs: array<string, int>, versionID: int, isNewPage: bool, kind: string}>
     */
    public function all(): array
    {
        return array_values($this->pages);
    }

    /**
     * The successful writes that aren't page drafts.
     *
     * @return list<array{kind: string, object: string, tools: list<string>}>
     */
    public function others(): array
    {
        return array_values($this->others);
    }

    /**
     * @return list<array{tool: string, target: string, reason: string}>
     */
    public function refused(): array
    {
        return $this->refused;
    }

    /**
     * A write that didn't happen: it failed, or the user didn't approve it (ApprovalService). $reason is a short fixed
     * phrase, never an error message.
     */
    public function refuse(string $tool, string $target, string $reason): void
    {
        $this->attempted = true;
        $this->refused[] = [
            'tool' => SecretRedactor::redact($tool),
            'target' => SecretRedactor::redact($target),
            'reason' => SecretRedactor::redact($reason),
        ];
    }

    /**
     * The turn's "Changed: …" line, or null when the turn called no write tool. After a failed turn it starts "Before the
     * error: ". Every write tool is recorded (TurnChangesCoverageTest), so "Nothing was changed" is true when it is said.
     */
    public function line(bool $failed = false): ?string
    {
        if (!$this->attempted) {
            return null;
        }

        $changed = [];
        foreach ($this->pages as $page) {
            $label = ($page['name'] !== '' ? $page['name'] : 'page ' . $page['pageID']) . ($page['path'] !== '' ? ' (' . $page['path'] . ')' : '');
            // P3.12 (Glyn): a draft says how to undo it, where it is discarded: Page Versions for a page, the global
            // area's own place for a stack draft (P3.6a).
            $shared = isset($this->shared[$page['pageID']]) ? array_keys($this->shared[$page['pageID']]) : [];
            $draft = !$page['isNewPage'] && $page['versionID'] > 0;
            $undo = $draft
                ? 'undo: discard unpublished version ' . $page['versionID'] . ' in ' . ($shared !== [] ? 'Dashboard > Stacks & Global Areas' : 'Page Versions')
                : null;
            $changed[] = $label . ($page['isNewPage'] ? ', a new unpublished page' : ($draft ? ', unpublished version ' . $page['versionID'] : ''))
                . ($shared !== [] ? ' (' . implode('; ', [...$shared, ...($undo !== null ? [$undo] : [])]) . ')' : ($undo !== null ? ' (' . $undo . ')' : ''));
        }
        foreach ($this->others as $other) {
            $changed[] = $other['object'];
        }

        $line = $changed === []
            ? ($this->submitted === [] && $this->kept === [] ? 'Nothing was changed.' : '')
            : ($failed ? 'Before the error: changed ' : 'Changed: ') . implode('; ', $changed) . '.';
        if ($this->submitted !== []) {
            $line .= ' Submitted for approval: ' . implode('; ', $this->submitted) . '.';
        }
        if ($this->kept !== []) {
            $line .= ' Kept, partly written: ' . implode('; ', $this->kept) . '.';
        }
        if ($this->refused !== []) {
            $line .= ' Not done: ' . implode('; ', array_map(
                static fn (array $r): string => $r['tool'] . ($r['target'] !== '' ? ' on ' . $r['target'] : '') . ' (' . $r['reason'] . ')',
                $this->refused
            )) . '.';
        }

        return trim($line);
    }

    public function reset(): void
    {
        $this->seen = [];
        $this->pages = [];
        $this->shared = [];
        $this->others = [];
        $this->refused = [];
        $this->kept = [];
        $this->submitted = [];
        $this->attempted = false;
        // Kept drafts from before this turn (a tool run outside a turn) are not this turn's.
        DraftCleanup::takeKept();
    }

    /** "Name (path), unpublished version N", or for a page create_page made and kept, "the new page Name (path)". */
    private function keptLabel(array $kept): string
    {
        $entry = $this->newEntry((int) $kept['cID']);
        $label = ($entry['name'] !== '' ? $entry['name'] : 'page ' . $kept['cID']) . ($entry['path'] !== '' ? ' (' . $entry['path'] . ')' : '');

        return $kept['kind'] === 'page' ? 'the new page ' . $label : $label . ', unpublished version ' . (int) $kept['cvID'];
    }

    /**
     * Adds the stored line to each reply that has one (META_KEY), as `changes`, for the panel's history.
     */
    public static function annotateHistoryJson(string $json): string
    {
        $messages = json_decode($json, true);
        if (!is_array($messages) || $messages === []) {
            return $json;
        }

        $changed = false;
        foreach ($messages as $i => $message) {
            if (!is_array($message) || ($message['role'] ?? null) !== 'assistant') {
                continue;
            }
            $meta = is_array($message['__meta'] ?? null) ? $message['__meta'] : $message;
            $line = $meta[self::META_KEY]['line'] ?? null;
            if (is_string($line) && $line !== '') {
                $messages[$i]['changes'] = $line;
                $changed = true;
            }
        }
        if (!$changed) {
            return $json;
        }

        $encoded = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? $json : $encoded;
    }

    private function recordOther(string $tool, array $inputs, string $undo = '', ?string $named = null): void
    {
        $kind = self::kind($tool);
        $object = self::object($inputs);
        $what = $named ?? ($object !== '' ? $kind . ' ' . $object : $kind . ' (' . $tool . ')');
        $label = SecretRedactor::redact($what . ($undo !== '' ? ' (' . rtrim($undo, '.') . ')' : ''));
        $entry = $this->others[$label] ?? ['kind' => $kind, 'object' => $label, 'tools' => []];
        if (!in_array($tool, $entry['tools'], true)) {
            $entry['tools'][] = SecretRedactor::redact($tool);
        }
        $this->others[$label] = $entry;
    }

    /**
     * P3.12 (Glyn, after 09): a moved page named by where it is now, "Name (/new/path)", from move_page's result; the
     * old path is in its undo text. Null for anything else.
     *
     * @param mixed $data the decoded result
     */
    private static function movedTo(string $tool, mixed $data): ?string
    {
        if ($tool !== 'move_page' || !is_array($data) || !is_string($data['path'] ?? null) || $data['path'] === '') {
            return null;
        }
        $name = is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : 'page ' . (int) ($data['id'] ?? 0);

        return $name . ' (' . $data['path'] . ')';
    }

    /** What kind of thing a write that isn't a page draft changed, from the tool's name. */
    private static function kind(string $tool): string
    {
        return match (true) {
            str_contains($tool, 'file') => 'file',
            str_contains($tool, 'topic') => 'topic',
            str_contains($tool, 'version') => 'version',
            str_contains($tool, 'user'), str_contains($tool, 'people'), str_contains($tool, 'person') => 'person',
            str_contains($tool, 'attribute') => 'attribute',
            str_contains($tool, 'page') => 'page',
            default => 'item',
        };
    }

    /** The first input that names what was written, as text, or ''. */
    private static function object(array $inputs): string
    {
        foreach (self::OBJECT_INPUTS as $name) {
            $value = $inputs[$name] ?? null;
            if (is_int($value) || (is_string($value) && $value !== '' && mb_strlen($value) <= 100)) {
                return (string) $value;
            }
        }

        return '';
    }

    private function newEntry(int $pageID): array
    {
        $page = Page::getByID($pageID);
        $found = $page && !$page->isError();

        return [
            'pageID' => $pageID,
            'path' => $found ? SecretRedactor::redact((string) $page->getCollectionPath()) : '',
            'name' => $found ? SecretRedactor::redact((string) $page->getCollectionName()) : '',
            'tools' => [],
            'blockIDs' => [],
            'replacedBlockIDs' => [],
            'versionID' => 0,
            'isNewPage' => false,
            'kind' => 'page',
        ];
    }

    /**
     * The blocks a result says were written, as [old ID or null, ID now]: one block (`blockID`), a batch update
     * (`blocks[]` with oldBlockID and newBlockID, ok only) or a heading update (`updated[]`).
     *
     * @return list<array{?int, int}>
     */
    private static function blocks(array $inputs, array $data): array
    {
        $blocks = [];
        if (isset($data['blockID']) && is_numeric($data['blockID'])) {
            $input = $inputs['blockID'] ?? null;
            $blocks[] = [is_numeric($input) ? (int) $input : null, (int) $data['blockID']];
        }
        foreach ((array) ($data['blocks'] ?? []) as $block) {
            if (is_array($block) && ($block['ok'] ?? false) === true && is_numeric($block['newBlockID'] ?? null)) {
                $old = $block['oldBlockID'] ?? null;
                $blocks[] = [is_numeric($old) ? (int) $old : null, (int) $block['newBlockID']];
            }
        }
        foreach ((array) ($data['updated'] ?? []) as $block) {
            if (is_array($block) && is_numeric($block['blockID'] ?? null)) {
                $old = $block['oldBlockID'] ?? null;
                $blocks[] = [is_numeric($old) ? (int) $old : null, (int) $block['blockID']];
            }
        }

        return $blocks;
    }
}
