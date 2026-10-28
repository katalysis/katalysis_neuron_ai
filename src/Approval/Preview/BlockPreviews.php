<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval\Preview;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Katalysis\NeuronAi\Approval\ApprovalService;
use Katalysis\NeuronAi\Approval\ApprovalSettings;
use Katalysis\NeuronAi\Approval\DraftOwner;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\Utilities\HtmlToText;

/**
 * The approval cards of the block writes (P3.6-2, plans/P3.6-2.md §1): what the call would add, change, remove or move,
 * and the state it was read from (the page's newest version, the blocks' stored fields, the draft's author). The batch
 * cards give the count and list every block (B-CON-4). Reads only.
 */
final class BlockPreviews
{
    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    public static function add(array $inputs): array
    {
        $area = PreviewSupport::string($inputs, 'areaHandle');
        $typeHandle = PreviewSupport::string($inputs, 'blockType');
        $type = $typeHandle !== '' ? BlockType::getByHandle($typeHandle) : null;
        $typeName = $type ? (string) $type->getBlockTypeName() : $typeHandle;
        $details = [];
        foreach ((array) ($inputs['value'] ?? []) as $field => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $details[] = $field . ': "' . PreviewSupport::fieldText($value) . '"';
            }
        }

        return self::onPage($inputs, 'Add a ' . $typeName . ' block to ' . $area, $details, static fn (Page $page): array => [
            'area' => self::areaBlocks($page, $area),
        ]);
    }

    public static function update(array $inputs): array
    {
        $area = PreviewSupport::string($inputs, 'areaHandle');
        $bID = PreviewSupport::int($inputs, 'blockID');
        $page = PreviewSupport::newest(PreviewSupport::int($inputs, 'pageID'));
        $block = $page ? PreviewSupport::block($page, $area, $bID) : null;
        $state = $block ? PreviewSupport::blockState($block) : ['record' => [], 'rows' => []];

        $details = [];
        $mode = strtolower(PreviewSupport::string($inputs, 'changeMode'));
        if ($mode === 'formatting_only') {
            $details[] = 'Formatting only: the text must stay the same (checked when it runs).';
        }
        foreach ((array) ($inputs['value'] ?? []) as $field => $value) {
            if (!is_scalar($value) && $value !== null) {
                $details[] = $field . ': changed (' . (is_array($value) ? count($value) . ' entries' : 'a value') . ')';
                continue;
            }
            $old = array_key_exists($field, $state['record']) ? PreviewSupport::fieldText($state['record'][$field]) : '';
            $new = PreviewSupport::fieldText($value);
            if ($old !== $new) {
                $details[] = PreviewSupport::change((string) $field, $old, $new);
            }
        }
        if ($details === []) {
            $details[] = 'The stored text stays as it is; only formatting or settings change.';
        }
        $type = $block ? PreviewSupport::blockType($block) : 'block';

        return self::onPage($inputs, 'Change the ' . $type . ' block in ' . $area, $details, static fn (): array => [
            'block' => $bID,
            'fields' => hash('sha256', (string) json_encode($state)),
        ]);
    }

    public static function delete(array $inputs): array
    {
        $area = PreviewSupport::string($inputs, 'areaHandle');
        $bID = PreviewSupport::int($inputs, 'blockID');
        $page = PreviewSupport::newest(PreviewSupport::int($inputs, 'pageID'));
        $block = $page ? PreviewSupport::block($page, $area, $bID) : null;
        $state = $block ? PreviewSupport::blockState($block) : ['record' => [], 'rows' => []];
        $text = PreviewSupport::firstText($state['record']);

        return self::onPage(
            $inputs,
            'Remove the ' . ($block ? PreviewSupport::blockType($block) : 'block') . ' block in ' . $area,
            $text !== '' ? ['It shows: "' . $text . '"'] : [],
            static fn (): array => ['block' => $bID, 'fields' => hash('sha256', (string) json_encode($state))]
        );
    }

    public static function move(array $inputs): array
    {
        $from = PreviewSupport::string($inputs, 'fromAreaHandle');
        $to = PreviewSupport::string($inputs, 'toAreaHandle');
        $bID = PreviewSupport::int($inputs, 'blockID');
        $page = PreviewSupport::newest(PreviewSupport::int($inputs, 'pageID'));
        $block = $page ? PreviewSupport::block($page, $from, $bID) : null;

        return self::onPage(
            $inputs,
            'Move the ' . ($block ? PreviewSupport::blockType($block) : 'block') . ' block from ' . $from . ' to ' . $to,
            [],
            static fn (Page $page): array => ['block' => $bID, 'from' => self::areaBlocks($page, $from), 'to' => self::areaBlocks($page, $to)]
        );
    }

    /** batch_update_page_content: the count, and every block (area, type, what changes). */
    public static function batchContent(array $inputs): array
    {
        $page = PreviewSupport::newest(PreviewSupport::int($inputs, 'pageID'));
        $items = array_values(array_filter((array) ($inputs['blocks'] ?? []), 'is_array'));
        $details = [];
        $blocks = [];
        foreach ($items as $item) {
            $area = is_string($item['areaHandle'] ?? null) ? $item['areaHandle'] : '';
            $bID = is_numeric($item['blockID'] ?? null) ? (int) $item['blockID'] : 0;
            $block = $page ? PreviewSupport::block($page, $area, $bID) : null;
            $state = $block ? PreviewSupport::blockState($block) : ['record' => [], 'rows' => []];
            $blocks[$bID] = hash('sha256', (string) json_encode($state));
            $changes = [];
            foreach ((array) ($item['value'] ?? []) as $field => $value) {
                if (is_scalar($value) && PreviewSupport::fieldText($state['record'][$field] ?? '') !== PreviewSupport::fieldText($value)) {
                    $changes[] = $field;
                }
            }
            $details[] = $area . ': ' . ($block ? PreviewSupport::blockType($block) : 'block ' . $bID)
                . ($changes !== [] ? ' (' . implode(', ', $changes) . ')' : ' (formatting)');
        }
        $count = count($items);
        $warning = ApprovalSettings::blockWarning($count);

        return self::onPage(
            $inputs,
            'Change ' . $count . ' ' . ($count === 1 ? 'block' : 'blocks'),
            $details,
            static fn (): array => ['blocks' => $blocks],
            $warning !== null ? [$warning] : []
        );
    }

    /** batch_update_content_headings: the read pass the call itself runs, so the list is what it would write. */
    public static function batchHeadings(array $inputs): array
    {
        $plan = (new BatchEditToolkit())->previewHeadings($inputs);
        if ($plan === null) {
            return self::onPage($inputs, 'Change heading levels', ['The call will be refused: check its inputs.'], static fn (): array => ['plan' => null]);
        }

        $details = [];
        $headings = 0;
        $fingerprint = [];
        foreach ($plan['planned'] as $change) {
            $block = PreviewSupport::block($plan['page'], (string) $change['areaHandle'], (int) $change['blockID']);
            $state = $block ? PreviewSupport::blockState($block) : [];
            $fingerprint[(int) $change['blockID']] = hash('sha256', (string) json_encode($state));
            foreach ($change['headings'] as $heading) {
                $headings++;
                $details[] = $change['areaHandle'] . ' (' . ($block ? PreviewSupport::blockType($block) : 'Content') . '): "'
                    . PreviewSupport::short((string) $heading['text'], 80) . '" → <' . $plan['targetTag'] . '>';
            }
        }
        foreach ($plan['failed'] as $failed) {
            $details[] = 'Not changed, ' . $failed['areaHandle'] . ': ' . PreviewSupport::short(HtmlToText::preview((string) $failed['error'], 120), 120);
        }
        foreach ($plan['pendingHeadings'] as $text) {
            $details[] = 'Not found: "' . PreviewSupport::short((string) $text, 80) . '"';
        }
        $blocks = count($plan['planned']);
        $warning = ApprovalSettings::blockWarning($blocks);

        return self::onPage(
            $inputs,
            'Change ' . $headings . ' ' . ($headings === 1 ? 'heading' : 'headings') . ' in ' . $blocks . ' ' . ($blocks === 1 ? 'block' : 'blocks'),
            $details,
            static fn (): array => ['blocks' => $fingerprint, 'tag' => $plan['targetTag'], 'mode' => $plan['matchMode']],
            $warning !== null ? [$warning] : []
        );
    }

    /**
     * The entry for a write on one page: "{action} on {page}", the page's newest version and draft in the state.
     *
     * @param \Closure(Page): array<string, mixed> $state
     * @param list<string> $warnings
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function onPage(array $inputs, string $action, array $details, \Closure $state, array $warnings = []): array
    {
        $page = PreviewSupport::newest(PreviewSupport::int($inputs, 'pageID'));
        if ($page === null) {
            return [PreviewSupport::entry($action, 'a page that was not found', '', $details, ''), ['missing' => $inputs['pageID'] ?? null]];
        }
        $draft = DraftOwner::of($page, ApprovalService::currentUserId());

        return [
            PreviewSupport::entry($action . ' on', PreviewSupport::name($page), PreviewSupport::path($page), $details, PreviewSupport::VERSION_NOTE, [...$draft['warnings'], ...$warnings]),
            ['cID' => (int) $page->getCollectionID(), 'cvID' => (int) $page->getVersionID()] + $draft['state'] + $state($page),
        ];
    }

    /** @return list<int> the block IDs in an area of the page's newest version, in order */
    private static function areaBlocks(Page $page, string $area): array
    {
        return array_map('intval', Application::getFacadeApplication()->make(Connection::class)->fetchFirstColumn(
            'SELECT bID FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? AND arHandle = ? ORDER BY cbDisplayOrder',
            [(int) $page->getCollectionID(), (int) $page->getVersionID(), $area]
        ));
    }
}
