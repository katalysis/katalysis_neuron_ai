<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tools\ToolKind;
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
 */
final class TurnChanges
{
    /** @var array<string, true> calls already taken */
    private array $seen = [];

    /** @var array<int, array{pageID: int, path: string, name: string, tools: list<string>, blockIDs: list<int>, replacedBlockIDs: array<string, int>}> */
    private array $pages = [];

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

        $result = $call->getResult();
        if (!ToolKind::succeeded($result)) {
            return;
        }
        $data = json_decode($result instanceof ToolOutput ? $result->getText() : $result, true);
        $pageID = is_array($data) ? (int) ($data['versionState']['pageID'] ?? 0) : 0;
        if ($pageID <= 0) {
            return;
        }
        $this->seen[$key] = true;

        $entry = $this->pages[$pageID] ?? $this->newEntry($pageID);
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
    }

    /**
     * @return list<array{pageID: int, path: string, name: string, tools: list<string>, blockIDs: list<int>, replacedBlockIDs: array<string, int>}>
     */
    public function all(): array
    {
        return array_values($this->pages);
    }

    public function reset(): void
    {
        $this->seen = [];
        $this->pages = [];
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
