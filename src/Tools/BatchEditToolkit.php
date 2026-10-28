<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use Katalysis\NeuronAi\Tools\Utilities\BlockIdRemapper;
use Katalysis\NeuronAi\Tools\Utilities\BlockFields;
use Katalysis\NeuronAi\Tools\Utilities\BlockImportSave;
use Katalysis\NeuronAi\Tools\Utilities\BlockNotFound;
use Katalysis\NeuronAi\Tools\Utilities\BlockRemapException;
use Katalysis\NeuronAi\Tools\Utilities\DraftState;
use Katalysis\NeuronAi\Tools\Utilities\EditModeGuard;
use Katalysis\NeuronAi\Tools\Utilities\SystemPageGuard;
use Katalysis\NeuronAi\Tools\Utilities\TextIntegrity;
use Katalysis\NeuronAi\Tools\Utilities\DryRunReached;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;
use Katalysis\NeuronAi\Tools\Utilities\WriteRefusal;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * Changes to many blocks of a page in one call: batch_update_page_content and batch_update_content_headings (P3.3-4: split
 * out of PagesToolkit, unchanged). No guidelines of its own, so the prompt adds no text for it.
 */
class BatchEditToolkit extends AbstractToolkit
{
    use PageToolGuards;
    use BlockWriteSupport;

    public function provide(): array
    {
        return [
            $this->makeBatchUpdatePageContentTool(),
            $this->makeBatchUpdateContentHeadingsTool(),
        ];
    }

    protected function makeBatchUpdatePageContentTool(): Tool
    {
        $tool = new CallableTool(
            'batch_update_page_content',
            'Safely update multiple blocks on a page. Creates a new page version, remaps block IDs automatically, then updates each block. Does not auto-approve the version.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to update',
            true
        ));

        // P3.3-5: the items are declared (P2.3's list of arrays without items).
        $tool->addProperty(new ArrayProperty(
            'blocks',
            'Array of block updates. Each item must have: areaHandle (string), blockID (number from current version), value (object with block data). An item may have its own changeMode, which applies to that block instead of the top-level one. With changeMode "scoped_text", the item also has textFields (fields whose text may change) and replacedText (the stored text the change replaces or removes, word for word). Fields left out of value keep their stored values. Every item is checked before anything is saved: a refused item is left unchanged and keeps its block ID.',
            true,
            new ObjectProperty('block', 'One block update', true, null, [
                new ToolProperty('areaHandle', PropertyType::STRING, 'Handle of the area containing the block', true),
                new ToolProperty('blockID', PropertyType::INTEGER, 'ID of an existing block, from the current version', true),
                new ToolProperty('value', PropertyType::OBJECT, BlockImportSave::VALUE_DESCRIPTION, true),
                new ToolProperty('changeMode', PropertyType::STRING, 'This block\'s text safety mode, instead of the top-level one', false),
                new ArrayProperty('textFields', 'With changeMode "scoped_text": the fields whose text may change', false, new ToolProperty('field', PropertyType::STRING, 'A field name of the block', true)),
                new ArrayProperty('replacedText', 'With changeMode "scoped_text": the stored text the change replaces or removes, word for word', false, new ToolProperty('text', PropertyType::STRING, 'Stored text', true)),
            ])
        ));

        $tool->addProperty(new ToolProperty(
            'changeMode',
            PropertyType::STRING,
            'Text safety mode: "keep_text" (default: existing text must stay; text may be added), "formatting_only" (text must remain identical), or "scoped_text" (text may change only in each block\'s textFields, and stored text may go only if it is listed in that block\'s replacedText)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'continueOnError',
            PropertyType::BOOLEAN,
            'Whether to apply the block updates that pass their checks when another one fails (default: true). With false, nothing is applied if any block update fails its check.',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?array $blocks = [],
            ?string $changeMode = null,
            ?bool $continueOnError = true
        ): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }

            if (empty($blocks) || !is_array($blocks)) {
                throw new \RuntimeException("blocks must be a non-empty array. Provide at least one block update.");
            }

            // Neuron passes null for an input the model left out: the default is true, as the description says.
            $continueOnError ??= true;
            $mode = TextIntegrity::mode($changeMode);

            // Validate block update structure
            $updates = [];
            foreach ($blocks as $index => $block) {
                if (!isset($block['areaHandle']) || !isset($block['blockID']) || !isset($block['value'])) {
                    throw new \RuntimeException("blocks[{$index}] must have areaHandle, blockID, and value. Got: " . json_encode(array_keys($block)));
                }

                if (!is_string($block['areaHandle']) || empty($block['areaHandle'])) {
                    throw new \RuntimeException("blocks[{$index}].areaHandle must be a non-empty string, got: " . gettype($block['areaHandle']));
                }

                if (!is_int($block['blockID']) || $block['blockID'] <= 0) {
                    // P3.3-5 (scenario 27 in P3.3-7): a new block sent as a batch item, then reported as added.
                    throw new \RuntimeException("blocks[{$index}].blockID must be the ID of an existing block, got: " . var_export($block['blockID'], true)
                        . '. This tool only changes blocks that exist; to add a new block, use add_block_to_page_area. Nothing was changed.');
                }

                if (!is_array($block['value'])) {
                    throw new \RuntimeException("blocks[{$index}].value must be an object/array, got: " . gettype($block['value']));
                }

                // An item's own changeMode overrides the top-level one for that item (BF-3).
                $itemMode = $mode;
                if (isset($block['changeMode'])) {
                    if (!is_string($block['changeMode']) || !in_array(strtolower(trim($block['changeMode'])), TextIntegrity::MODES, true)) {
                        throw new \RuntimeException("blocks[{$index}].changeMode must be one of: " . implode(', ', TextIntegrity::MODES) . '. Nothing was changed.');
                    }
                    $itemMode = TextIntegrity::mode($block['changeMode']);
                }

                $textFields = null;
                if (isset($block['textFields'])) {
                    if (!is_array($block['textFields'])) {
                        throw new \RuntimeException("blocks[{$index}].textFields must be an array of field names when provided");
                    }
                    $textFields = array_values(array_filter(array_map(static fn($v) => is_string($v) ? trim($v) : '', $block['textFields']), static fn($v) => $v !== ''));
                }

                $replacedText = null;
                if (isset($block['replacedText'])) {
                    if (!is_array($block['replacedText'])) {
                        throw new \RuntimeException("blocks[{$index}].replacedText must be an array of the stored text being replaced when provided");
                    }
                    $replacedText = array_values(array_filter($block['replacedText'], 'is_string'));
                }

                $updates[] = [
                    'areaHandle' => $block['areaHandle'],
                    'blockID' => $block['blockID'],
                    'value' => $block['value'],
                    'changeMode' => $itemMode,
                    'textFields' => $textFields,
                    'replacedText' => $replacedText,
                ];
            }

            try {
                $page = Page::getByID($pageID, 'RECENT');
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to load page {$pageID}: " . $e->getMessage());
            }
            
            if (!$page || $page->isError()) {
                throw new \RuntimeException(WriteRefusal::pageNotFound((int) $pageID));
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            foreach ($updates as $i => $update) {
                SystemPageGuard::refuseGlobalArea($page, (string) $update['areaHandle']);
                EditModeGuard::refuseOpenBlock($page, (int) $update['blockID']);
                // P3.6-2: a block an earlier call in this request copied into the draft is changed in its copy.
                $updates[$i]['blockID'] = PageDraft::followCopies($page, (string) $update['areaHandle'], (int) $update['blockID']);
            }

            ObjectPermissions::deny(ObjectPermissions::editPageContents($page));

            // Blocks in container or area layout columns can't be remapped between versions (the remap
            // only sees top-level areas). Refuse them now, before any draft exists, so nothing is left behind.
            $this->refuseBlocksInSubAreas($page, $updates);
            // A block the newest version doesn't hold gets the reason and the ID to use, before any draft (BF-7).
            foreach ($updates as $update) {
                BlockNotFound::refuseUnlessInNewestVersion($page, (string) $update['areaHandle'], (int) $update['blockID']);
                // P3.5-1: and each block's own Edit Block permission, as update_block_in_page_area checks it.
                $block = Block::getByID((int) $update['blockID'], $page, (string) $update['areaHandle']);
                if ($block) {
                    ObjectPermissions::deny(ObjectPermissions::editBlock($block));
                }
            }

            // Check every item on the newest version before any draft exists (BF-3): the draft shares its blocks with
            // it, so a refused item is never copied and its block ID stays valid for a retry.
            $blockResults = [];
            $checked = [];
            foreach ($updates as $index => $update) {
                try {
                    $checked[$index] = $this->checkBatchItem($page, $update);
                } catch (\Exception $e) {
                    $blockResults[$index] = [
                        'areaHandle' => $update['areaHandle'],
                        'oldBlockID' => $update['blockID'],
                        'changeMode' => $update['changeMode'],
                        'ok' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $failureCount = count($blockResults);
            if ($checked === [] || ($failureCount > 0 && !$continueOnError)) {
                // Nothing is applied: no draft is made.
                foreach ($checked as $index => $item) {
                    $blockResults[$index] = [
                        'areaHandle' => $updates[$index]['areaHandle'],
                        'oldBlockID' => $updates[$index]['blockID'],
                        'changeMode' => $updates[$index]['changeMode'],
                        'ok' => false,
                        'error' => 'Not applied: another block failed its check and continueOnError is false. Nothing was changed.',
                    ];
                }
                ksort($blockResults);

                return $this->batchResult($page, $blockResults, $mode, (bool) $continueOnError) + [
                    'note' => 'Nothing was changed and no draft was made. Fix the blocks listed with ok: false and send the call again; '
                        . 'the block IDs are unchanged.',
                ];
            }

            // Capture block layout BEFORE creating new version
            try {
                $beforeAreas = BlockIdRemapper::captureAreaSnapshot($page);
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to capture block layout before versioning: " . $e->getMessage());
            }

            // Create new version
            try {
                $pageToModify = $this->getDraftOf($page);
            } catch (DryRunReached $e) {
                // An approval check's dry run stops here (P3.6-2).
                throw $e;
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to create new page version: " . $e->getMessage() . ". This may indicate a database or permissions issue.");
            }

            // Capture block layout AFTER creating new version
            try {
                $afterAreas = BlockIdRemapper::captureAreaSnapshot($pageToModify);
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to capture block layout after versioning: " . $e->getMessage());
            }

            // Remap block IDs, for the items that passed their check
            $indexes = array_keys($checked);
            try {
                $remapped = BlockIdRemapper::remap($beforeAreas, $afterAreas, array_map(static fn (int $i): array => $updates[$i], $indexes));
            } catch (BlockRemapException $e) {
                throw new \RuntimeException("Block ID remapping failed: " . $e->getMessage() . ". This usually means blocks couldn't be matched between versions. Use get_page_blocks tool to verify block IDs and areas.");
            }

            // Update each block
            $successCount = 0;

            foreach ($remapped as $position => $update) {
                $index = $indexes[$position];
                $item = $checked[$index];
                $areaHandle = $update['areaHandle'];
                $newBlockID = $update['newBlockID'];
                $itemMode = $updates[$index]['changeMode'];

                try {
                    // Get the area and block
                    $area = $pageToModify->getArea($areaHandle);
                    if (!$area) {
                        throw new \RuntimeException("Area '{$areaHandle}' not found on page version {$pageToModify->getVersionID()}");
                    }

                    $block = Block::getByID($newBlockID, $pageToModify, $area);
                    if (!$block) {
                        throw new \RuntimeException("Block {$newBlockID} not found in area '{$areaHandle}' on page version {$pageToModify->getVersionID()}");
                    }

                    // A new version shares its blocks with the live one. Copy this block into the
                    // draft first (as Concrete's own editor does), so the update can't change the live version.
                    $block = $this->getBlockToEdit($pageToModify, $area, $areaHandle, $newBlockID);
                    if (!$block) {
                        throw new \RuntimeException("Block {$newBlockID} could not be prepared for editing in area '{$areaHandle}'");
                    }
                    $newBlockID = (int) $block->getBlockID();

                    $watchedFields = $item['textPolicy']['watchedFields'];
                    $beforeChecksums = TextIntegrity::checksums($item['beforeRecord'], $watchedFields);

                    // Update the block, in import mode (P3.3-7)
                    BlockImportSave::update($pageToModify, $block, $item['fields'], $item['prepared']);

                    $afterRecord = $this->getBlockTableRecord($item['dbTable'], $newBlockID);
                    $afterChecksums = TextIntegrity::checksums($afterRecord, $watchedFields);

                    $blockResults[$index] = [
                        'areaHandle' => $areaHandle,
                        'oldBlockID' => $update['oldBlockID'],
                        'newBlockID' => $newBlockID,
                        'blockType' => $block->getBlockTypeHandle(),
                        'changeMode' => $itemMode,
                        'watchedTextFields' => $watchedFields,
                        'textCheck' => $item['textPolicy']['fields'],
                        'keptFields' => $item['keptFields'],
                        'textChecksumsBefore' => $beforeChecksums,
                        'textChecksumsAfter' => $afterChecksums,
                        'ok' => true,
                    ];
                    $successCount++;
                } catch (\Exception $e) {
                    $blockResults[$index] = [
                        'areaHandle' => $areaHandle,
                        'oldBlockID' => $update['oldBlockID'],
                        'newBlockID' => $newBlockID,
                        'changeMode' => $itemMode,
                        'ok' => false,
                        'error' => $e->getMessage(),
                    ];

                    if (!$continueOnError) {
                        break;
                    }
                }
            }
            ksort($blockResults);

            $result = $this->batchResult($pageToModify, $blockResults, $mode, (bool) $continueOnError);
            if ($successCount > 0) {
                $result += DraftState::of($pageToModify);
            }

            return $result;
        }));

        return $tool;
    }

    /**
     * One batch item's checks, on the newest version and before any draft (BF-3): the value as it will be saved, and
     * the text check with the item's own mode.
     *
     * @param array{areaHandle: string, blockID: int, value: array, changeMode: string, textFields: ?array, replacedText: ?array} $update
     * @return array{value: array, keptFields: array, textPolicy: array, beforeRecord: array, dbTable: mixed, fields: BlockFields, prepared: array}
     * @throws \RuntimeException with the reason the item is refused; nothing has been changed
     */
    private function checkBatchItem(Page $page, array $update): array
    {
        $areaHandle = (string) $update['areaHandle'];
        $blockID = (int) $update['blockID'];
        $area = $page->getArea($areaHandle);
        $block = $area ? Block::getByID($blockID, $page, $area) : null;
        if (!$block) {
            throw new \RuntimeException(BlockNotFound::explain($page, $areaHandle, $blockID));
        }

        $controller = $block->getController();
        $dbTable = $controller ? $controller->getBlockTypeDatabaseTable() : null;
        $beforeRecord = $this->getBlockTableRecord($dbTable, $blockID);

        $value = (array) ($update['value'] ?? []);
        $handle = (string) $block->getBlockTypeHandle();
        // P3.3-7: the Level 0 checks (BF-17's fields, references, child rows); fields the update leaves out keep their
        // stored values (BF-5), as the full row the import-mode save takes.
        $fields = BlockFields::ofController($handle, $controller);
        $prepared = BlockImportSave::prepareUpdate($fields, $blockID, $beforeRecord, $value);

        $textPolicy = TextIntegrity::evaluate($prepared['checkBefore'], $prepared['checkAfter'], $update['changeMode'], BlockImportSave::textFields($fields, $update['textFields'], array_keys($prepared['checkBefore'] + $prepared['checkAfter'])), $update['replacedText']);
        if (!$textPolicy['ok']) {
            throw new \RuntimeException($textPolicy['message']);
        }
        // The block type's own check, as update_block_in_page_area runs it (P3.3-7: the batch tool didn't).
        $validation = WriteRefusal::blockValidation($controller->validate($prepared['value']), $handle);
        if ($validation !== null) {
            throw new \RuntimeException($validation);
        }

        return [
            'value' => $prepared['value'],
            'keptFields' => $prepared['keptFields'],
            'textPolicy' => $textPolicy,
            'beforeRecord' => $beforeRecord,
            'dbTable' => $dbTable,
            'fields' => $fields,
            'prepared' => $prepared,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $blockResults
     * @return array<string, mixed>
     */
    private function batchResult(Page $page, array $blockResults, string $mode, bool $continueOnError): array
    {
        $successCount = count(array_filter($blockResults, static fn (array $r): bool => $r['ok'] === true));
        $failureCount = count($blockResults) - $successCount;

        // P3.3-1 (B-TRU-2): a batch in which every item failed is not a success, so the checks that read `ok` see it.
        $result = $successCount === 0 && $blockResults !== [] ? ['ok' => false] : [];
        $result += [
            'id' => $page->getCollectionID(),
            'path' => $page->getCollectionPath(),
            'name' => $page->getCollectionName(),
            'version' => [
                'cvID' => $page->getVersionID(),
                'isActive' => $page->isActive(),
            ],
            'summary' => [
                'totalBlocks' => count($blockResults),
                'successCount' => $successCount,
                'failureCount' => $failureCount,
                'changeMode' => $mode,
                'continueOnError' => $continueOnError,
            ],
            'blocks' => array_values($blockResults),
            'safety' => [
                'textPolicy' => $mode,
                'checksumAlgorithm' => 'sha1(normalizedText)',
            ],
        ];

        if ($failureCount > 0) {
            $result['warning'] = "{$failureCount} block update(s) failed. See blocks array for details.";
        }

        return $result;
    }

    /**
     * Refuses a batch update that names a block inside a container or core area layout column.
     * Those blocks are in sub-areas (an Areas row with a parent area), which the block ID remap
     * doesn't see: it fails once the draft exists and leaves the draft behind. update_block_in_page_area
     * does edit blocks in sub-areas (DraftIsolationTest), one block at a time, so the message names it.
     *
     * @param list<array{areaHandle: string, blockID: int}> $updates
     * @throws \RuntimeException naming the first nested block; nothing has been changed
     */
    private function refuseBlocksInSubAreas(Page $page, array $updates): void
    {
        $db = Application::getFacadeApplication()->make('database')->connection();
        $cID = (int) $page->getCollectionID();
        $cvID = (int) $page->getVersionID();

        foreach ($updates as $index => $update) {
            $blockID = (int) $update['blockID'];
            $areaHandle = (string) $update['areaHandle'];

            // The area the block is really in, if that is a sub-area ...
            $actualHandle = $db->fetchOne(
                'SELECT cvb.arHandle FROM CollectionVersionBlocks cvb
                 JOIN Areas a ON a.cID = cvb.cID AND a.arHandle = cvb.arHandle
                 WHERE cvb.cID = ? AND cvb.cvID = ? AND cvb.bID = ? AND a.arParentID > 0
                 LIMIT 1',
                [$cID, $cvID, $blockID]
            );
            // ... or the area the caller named, if that is a sub-area.
            $namedIsSubArea = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM Areas WHERE cID = ? AND arHandle = ? AND arParentID > 0',
                [$cID, $areaHandle]
            ) > 0;

            if ($actualHandle !== false || $namedIsSubArea) {
                $handle = $actualHandle !== false ? (string) $actualHandle : $areaHandle;
                throw new \RuntimeException(
                    "blocks[{$index}]: block {$blockID} is in the area '{$handle}', which is inside a container or area layout column. "
                    . "batch_update_page_content can't update blocks there yet. Nothing was changed. "
                    . "To edit a block in a container or layout column, use update_block_in_page_area with areaHandle '{$handle}', one block at a time."
                );
            }
        }
    }

    protected function makeBatchUpdateContentHeadingsTool(): Tool
    {
        $tool = new CallableTool(
            'batch_update_content_headings',
            'Find Content blocks on a page by heading text and change heading levels only (for example a bold paragraph to h3, or h3 to h2), keeping all text. '
            . 'It never changes a heading\'s words: to rename a heading, change the block\'s text with update_block_in_page_area.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to update',
            true
        ));

        $tool->addProperty(new ArrayProperty(
            'headingTexts',
            'Optional heading texts to find in Content blocks (exact text match after normalization). Required when matchMode="explicit".',
            false,
            new ToolProperty('text', PropertyType::STRING, 'One heading text', true)
        ));

        $tool->addProperty(new ToolProperty(
            'matchMode',
            PropertyType::STRING,
            'Matching mode: "explicit" (default, uses headingTexts) or "bold_paragraph" (auto-detects <p><strong>...</strong></p> headings).',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandleContains',
            PropertyType::STRING,
            'Optional area handle substring filter (for example: " : Column 1")',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'targetTag',
            PropertyType::STRING,
            'Heading tag to apply (h1-h6). Default: h3',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'continueOnError',
            PropertyType::BOOLEAN,
            'Continue processing remaining headings after an error (default: true)',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?array $headingTexts = null,
            ?string $matchMode = 'explicit',
            ?string $areaHandleContains = null,
            ?string $targetTag = 'h3',
            ?bool $continueOnError = true
        ): array {
            if (!$pageID) {
                throw new \RuntimeException('pageID is required');
            }

            [$normalizedTitles, $matchMode, $areaHandleContains, $targetTag] = $this->headingOptions($headingTexts, $matchMode, $areaHandleContains, $targetTag);

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException(WriteRefusal::pageNotFound((int) $pageID));
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);

            ObjectPermissions::deny(ObjectPermissions::editPageContents($page));

            // Neuron passes null for an input the model left out: the default is true, as the description says.
            $continueOnError ??= true;

            $plan = $this->planHeadings($page, (int) $pageID, $normalizedTitles, $matchMode, $areaHandleContains, $targetTag, (bool) $continueOnError);
            if ($plan['empty']) {
                return [
                    'ok' => false,
                    'summary' => [
                        'requestedHeadings' => count($normalizedTitles),
                        'updatedCount' => 0,
                        'failedCount' => 0,
                        'notFoundCount' => count($normalizedTitles),
                    ],
                    'updated' => [],
                    'failed' => [],
                    'notFoundHeadings' => array_values($normalizedTitles),
                    'note' => 'No content block placements found for this page.',
                ];
            }
            ['planned' => $planned, 'failed' => $failed, 'pendingHeadings' => $pendingHeadings] = $plan;
            $updated = [];

            // Write pass: only when there is something to change, and (with continueOnError false) nothing failed.
            $pageToModify = null;
            if ($planned !== [] && ($continueOnError || $failed === [])) {
                $pageToModify = $this->getDraftOf($page);

                foreach ($planned as $change) {
                    $blockID = $change['blockID'];
                    $areaHandle = $change['areaHandle'];
                    try {
                        $area = $pageToModify->getArea($areaHandle);
                        if (!$area) {
                            $area = new Area($areaHandle);
                            $area->c = $pageToModify;
                        }
                        $oldBlockID = $blockID;

                        // Copy the block into the draft before changing it (see getBlockToEdit), so the live
                        // version keeps its text. The copy has a new block ID.
                        $editBlock = $this->getBlockToEdit($pageToModify, $area, $areaHandle, $blockID);
                        if (!$editBlock) {
                            throw new \RuntimeException("Block {$blockID} could not be prepared for editing in area '{$areaHandle}'");
                        }
                        $blockID = (int) $editBlock->getBlockID();
                        $editBlock->update(['content' => $change['transformedContent']]);

                        $afterRecord = $this->getBlockTableRecord($change['dbTable'], $blockID);
                        $afterContent = is_string($afterRecord['content'] ?? null) ? (string) $afterRecord['content'] : '';

                        // One entry per heading, as before; headings in the same block share its block IDs.
                        foreach ($change['headings'] as $heading) {
                            $updated[] = [
                                'blockID' => $blockID,
                                'oldBlockID' => $oldBlockID,
                                'areaHandle' => $areaHandle,
                                'headingText' => $heading['text'],
                                'targetTag' => $targetTag,
                                'matchMode' => $matchMode,
                                'textChecksumBefore' => sha1($change['beforeText']),
                                'textChecksumAfter' => sha1(TextIntegrity::normalize($afterContent)),
                            ];
                        }
                    } catch (\Throwable $e) {
                        $failed[] = [
                            'blockID' => $blockID,
                            'areaHandle' => $areaHandle,
                            'matchMode' => $matchMode,
                            'error' => $e->getMessage(),
                        ];
                        foreach ($change['headings'] as $heading) {
                            if (isset($normalizedTitles[$heading['key']])) {
                                $pendingHeadings[$heading['key']] = $normalizedTitles[$heading['key']];
                            }
                        }

                        if (!$continueOnError) {
                            break;
                        }
                    }
                }
            }

            // P3.3-1 (B-TRU-2): nothing updated while a heading failed, wasn't found, or (bold_paragraph) none was
            // detected is not a success, so the checks that read `ok` see it.
            $result = [];
            if ($updated !== []) {
                // What changed, in words the model can repeat (Glyn, after scenario 05 in P3.3-1: a bold_paragraph call
                // converted bold paragraphs and the reply said the h3 headings had changed).
                $texts = implode(', ', array_map(static fn (array $u): string => '"' . $u['headingText'] . '"', $updated));
                $count = count($updated);
                $result['changed'] = $matchMode === 'bold_paragraph'
                    ? "Converted {$count} bold paragraph" . ($count === 1 ? '' : 's') . " to <{$targetTag}>: {$texts}. No existing headings were changed."
                    : "Changed {$count} heading" . ($count === 1 ? '' : 's') . " to <{$targetTag}>: {$texts}.";
            }
            if ($updated === []) {
                if ($failed !== [] || $pendingHeadings !== []) {
                    $result = ['ok' => false];
                } elseif ($matchMode === 'bold_paragraph') {
                    $result = ['ok' => false, 'note' => 'No bold-paragraph headings were found, so nothing was changed.'];
                }
            }

            return $result + [
                'summary' => [
                    'requestedHeadings' => count($normalizedTitles),
                    'updatedCount' => count($updated),
                    'failedCount' => count($failed),
                    'notFoundCount' => count($pendingHeadings),
                    'matchMode' => $matchMode,
                    'areaHandleContains' => $areaHandleContains,
                    'targetTag' => $targetTag,
                    'continueOnError' => (bool) $continueOnError,
                ],
                'updated' => $updated,
                'failed' => $failed,
                'notFoundHeadings' => array_values($pendingHeadings),
                'safety' => [
                    'mode' => 'formatting_only',
                    'checksumAlgorithm' => 'sha1(normalizedText)',
                    'note' => 'Updates are skipped if normalized text content changes.',
                ],
            ] + ($updated !== [] && $pageToModify !== null ? DraftState::of($pageToModify) : []);
        }));

        return $tool;
    }

    /**
     * batch_update_content_headings' inputs, checked and normalised: the heading texts by their lookup key, the match
     * mode, the area filter and the target tag. Shared by the call and its approval preview (P3.6-2).
     *
     * @return array{array<string, string>, string, ?string, string}
     * @throws \RuntimeException with the reason the inputs are refused; nothing has been changed
     */
    private function headingOptions(?array $headingTexts, ?string $matchMode, ?string $areaHandleContains, ?string $targetTag): array
    {
        $matchMode = strtolower(trim((string) ($matchMode ?? 'explicit')));
        if (!in_array($matchMode, ['explicit', 'bold_paragraph'], true)) {
            throw new \RuntimeException('matchMode must be either explicit or bold_paragraph');
        }

        $areaHandleContains = is_string($areaHandleContains) ? trim($areaHandleContains) : null;
        if ($areaHandleContains === '') {
            $areaHandleContains = null;
        }

        $targetTag = strtolower(trim((string) ($targetTag ?? 'h3')));
        if (!in_array($targetTag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            throw new \RuntimeException('targetTag must be one of h1-h6');
        }

        $normalizedTitles = [];
        // With bold_paragraph, no headingTexts means every bold paragraph (P1.12: a null here raised a warning).
        foreach ($headingTexts ?? [] as $text) {
            if (!is_string($text)) {
                continue;
            }
            $trimmed = trim($text);
            if ($trimmed === '') {
                continue;
            }
            $normalizedTitles[$this->normalizeHeadingLookupText($trimmed)] = $trimmed;
        }

        if ($matchMode === 'explicit' && empty($normalizedTitles)) {
            // Scenario 05 (P3.3-1): after a shorter refusal the model retried with bold_paragraph, which changed the
            // wrong content. Nothing was changed here.
            throw new \RuntimeException(
                'With matchMode explicit (the default) this tool needs the exact heading texts in headingTexts. '
                . 'get_page_content shows the page\'s headings. matchMode bold_paragraph is only for converting bold paragraphs into headings, '
                . 'never for changing the level of existing headings. Nothing was changed.'
            );
        }

        return [$normalizedTitles, $matchMode, $areaHandleContains, $targetTag];
    }

    /**
     * P3.6-2: what batch_update_content_headings would change with these inputs, for its approval card: the read pass
     * (planHeadings()), with nothing written. Null when the inputs or the page are refused (the call is refused then
     * too, with the reason).
     *
     * @param array<string, mixed> $inputs
     * @return array{page: Page, targetTag: string, matchMode: string, planned: list<array<string, mixed>>, failed: list<array<string, mixed>>, pendingHeadings: array<string, string>}|null
     */
    public function previewHeadings(array $inputs): ?array
    {
        try {
            [$titles, $matchMode, $area, $targetTag] = $this->headingOptions(
                is_array($inputs['headingTexts'] ?? null) ? $inputs['headingTexts'] : null,
                is_string($inputs['matchMode'] ?? null) ? $inputs['matchMode'] : null,
                is_string($inputs['areaHandleContains'] ?? null) ? $inputs['areaHandleContains'] : null,
                is_string($inputs['targetTag'] ?? null) ? $inputs['targetTag'] : null
            );
            $pageID = is_numeric($inputs['pageID'] ?? null) ? (int) $inputs['pageID'] : 0;
            $page = $pageID > 0 ? Page::getByID($pageID, 'RECENT') : null;
            if (!$page || $page->isError()) {
                return null;
            }
            $plan = $this->planHeadings($page, $pageID, $titles, $matchMode, $area, $targetTag, ($inputs['continueOnError'] ?? true) !== false);
        } catch (\Throwable) {
            return null;
        }

        return ['page' => $page, 'targetTag' => $targetTag, 'matchMode' => $matchMode] + $plan;
    }

    /**
     * P3.6-2: the read pass of batch_update_content_headings, on the newest version and before any draft (BF-3): the
     * blocks and headings the call would change. The approval card lists exactly these, and the write pass then
     * writes them (plans/P3.6-2.md §1). Reads only.
     *
     * @param array<string, string> $normalizedTitles
     * @return array{empty: bool, planned: list<array<string, mixed>>, failed: list<array<string, mixed>>, pendingHeadings: array<string, string>}
     */
    public function planHeadings(Page $page, int $pageID, array $normalizedTitles, string $matchMode, ?string $areaHandleContains, string $targetTag, bool $continueOnError): array
    {
        $contentBt = BlockType::getByHandle('content');
        if (!$contentBt) {
            throw new \RuntimeException('Content block type is not available');
        }

        // Read pass, on the newest version and before any draft (BF-3): find the headings to change. A new draft
        // shares its blocks with the newest version, so the block IDs found here are the draft's until copied.
        $conn = Application::getFacadeApplication()->make('database')->connection();
        $qb = $conn->createQueryBuilder();
        $qb->select('b.bID', 'cvb.arHandle', 'cvb.cvID', 'cv.cvIsApproved')
            ->from('Blocks', 'b')
            ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
            ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
            ->where('cvb.cID = :cID')
            // Only the blocks of the version being edited: the draft shares blocks with the live
            // version until they are copied, and the live copies must never be updated.
            ->andWhere('cvb.cvID = :cvID')
            ->andWhere('b.btID = :btID')
            ->setParameter('cID', $pageID)
            ->setParameter('cvID', $page->getVersionID())
            ->setParameter('btID', $contentBt->getBlockTypeID())
            ->orderBy('b.bID', 'ASC')
            ->addOrderBy('cv.cvIsApproved', 'DESC')
            ->addOrderBy('cvb.cvID', 'DESC');

        $placements = $qb->execute()->fetchAllAssociative();
        if (empty($placements)) {
            return ['empty' => true, 'planned' => [], 'failed' => [], 'pendingHeadings' => $normalizedTitles];
        }

        // Keep preferred placement per block: approved first, then newest version.
        $preferredPlacements = [];
        foreach ($placements as $placement) {
            $bID = (int) ($placement['bID'] ?? 0);
            if ($bID <= 0) {
                continue;
            }

            if (!isset($preferredPlacements[$bID])) {
                $preferredPlacements[$bID] = $placement;
                continue;
            }

            $current = $preferredPlacements[$bID];
            $currentApproved = (int) ($current['cvIsApproved'] ?? 0) === 1;
            $candidateApproved = (int) ($placement['cvIsApproved'] ?? 0) === 1;
            if ($candidateApproved && !$currentApproved) {
                $preferredPlacements[$bID] = $placement;
                continue;
            }
            if ($candidateApproved === $currentApproved && (int) ($placement['cvID'] ?? 0) > (int) ($current['cvID'] ?? 0)) {
                $preferredPlacements[$bID] = $placement;
            }
        }

        $pendingHeadings = $normalizedTitles;
        $planned = [];
        $failed = [];

        foreach ($preferredPlacements as $blockID => $placement) {
            if ($matchMode === 'explicit' && empty($pendingHeadings)) {
                break;
            }

            $areaHandle = (string) ($placement['arHandle'] ?? '');
            if ($areaHandleContains !== null && stripos($areaHandle, $areaHandleContains) === false) {
                continue;
            }

            try {
                $area = $page->getArea($areaHandle);
                if (!$area) {
                    $area = new Area($areaHandle);
                    $area->c = $page;
                }

                $block = Block::getByID((int) $blockID, $page, $area);
                if (!$block) {
                    continue;
                }

                $controller = $block->getController();
                $dbTable = $controller ? $controller->getBlockTypeDatabaseTable() : null;
                $record = $this->getBlockTableRecord($dbTable, (int) $blockID);
                $content = is_string($record['content'] ?? null) ? (string) $record['content'] : null;
                if ($content === null || trim($content) === '') {
                    continue;
                }

                // P3.3-1: every matching heading in the block, not only the first (scenarios 05, 06 and 23).
                $matches = [];
                if ($matchMode === 'explicit') {
                    $matches = $this->findMatchingHeadingTextsInContent($content, $pendingHeadings);
                } else {
                    foreach ($this->extractBoldParagraphHeadingTexts($content) as $detectedHeading) {
                        $detectedKey = $this->normalizeHeadingLookupText($detectedHeading);
                        if (empty($pendingHeadings) || isset($pendingHeadings[$detectedKey])) {
                            $matches[$detectedKey] = [
                                'key' => $detectedKey,
                                'text' => empty($pendingHeadings) ? $detectedHeading : (string) ($pendingHeadings[$detectedKey] ?? $detectedHeading),
                            ];
                        }
                    }
                }

                // Each heading is applied in turn; one whose text is only in the body (not a heading) changes nothing.
                $transformedContent = $content;
                $applied = [];
                foreach ($matches as $match) {
                    $next = $this->applyHeadingLevelToContent($transformedContent, $match['text'], $targetTag);
                    if ($next !== $transformedContent) {
                        $transformedContent = $next;
                        $applied[] = $match;
                    }
                }

                if ($applied === []) {
                    continue;
                }

                $beforeText = TextIntegrity::normalize($content);
                $afterText = TextIntegrity::normalize($transformedContent);
                if ($beforeText !== $afterText) {
                    $names = implode("', '", array_column($applied, 'text'));
                    throw new \RuntimeException("text integrity check failed for '{$names}' (body text changed)");
                }

                // Never change a block under the user's open editor (BF-7): reported with the failures.
                EditModeGuard::refuseOpenBlock($page, (int) $blockID);
                // P3.5-1: nor a block whose own Edit Block permission the user lacks: reported the same way.
                $permissionBlock = Block::getByID((int) $blockID, $page, (string) $areaHandle);
                if ($permissionBlock) {
                    ObjectPermissions::deny(ObjectPermissions::editBlock($permissionBlock));
                }

                $planned[] = [
                    'blockID' => (int) $blockID,
                    'areaHandle' => $areaHandle,
                    'dbTable' => $dbTable,
                    'headings' => $applied,
                    'transformedContent' => $transformedContent,
                    'beforeText' => $beforeText,
                ];
                foreach ($applied as $match) {
                    unset($pendingHeadings[$match['key']]);
                }
            } catch (\Throwable $e) {
                $failed[] = [
                    'blockID' => (int) $blockID,
                    'areaHandle' => $areaHandle,
                    'matchMode' => $matchMode,
                    'error' => $e->getMessage(),
                ];

                if (!$continueOnError) {
                    break;
                }
            }
        }

        return ['empty' => false, 'planned' => $planned, 'failed' => $failed, 'pendingHeadings' => $pendingHeadings];
    }
    /**
     * @param string $content
     * @param array<string, string> $pendingHeadings
     * @return array{key:string,text:string}|null
     */
    /**
     * Every pending heading whose text is in the block (P3.3-1: not only the first).
     *
     * @return array<string, array{key: string, text: string}>
     */
    private function findMatchingHeadingTextsInContent(string $content, array $pendingHeadings): array
    {
        $plain = $this->normalizeHeadingLookupText(TextIntegrity::normalize($content));
        $found = [];
        foreach ($pendingHeadings as $key => $text) {
            $key = (string) $key;
            if ($key !== '' && strpos($plain, $key) !== false) {
                $found[$key] = ['key' => $key, 'text' => $text];
            }
        }

        return $found;
    }

    /**
     * The text of every bold paragraph (<p><strong>…</strong></p> or <b>) in the block, in order (P3.3-1: not only the first).
     *
     * @return list<string>
     */
    private function extractBoldParagraphHeadingTexts(string $html): array
    {
        $pattern = '~<p\b[^>]*>\s*(?:<strong\b[^>]*>|<b\b[^>]*>)\s*(.*?)\s*(?:</strong>|</b>)\s*</p>~isu';
        if (!preg_match_all($pattern, $html, $matches)) {
            return [];
        }

        $texts = [];
        foreach ($matches[1] as $candidate) {
            $candidate = html_entity_decode(strip_tags((string) $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $candidate = trim(preg_replace('/\s+/u', ' ', $candidate) ?: '');
            if ($candidate !== '') {
                $texts[] = $candidate;
            }
        }

        return $texts;
    }

    private function normalizeHeadingLookupText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?: '';
        return trim($value);
    }

    private function applyHeadingLevelToContent(string $html, string $headingText, string $targetTag): string
    {
        $escaped = preg_quote($headingText, '~');

        // Existing heading tag with exact heading text.
        $headingPattern = '~<h([1-6])\b([^>]*)>\s*' . $escaped . '\s*</h\1>~iu';
        $updated = preg_replace_callback($headingPattern, function (array $matches) use ($targetTag, $headingText): string {
            $attrs = isset($matches[2]) ? (string) $matches[2] : '';
            return '<' . $targetTag . $attrs . '>' . $headingText . '</' . $targetTag . '>';
        }, $html, -1, $count); // every heading with this text in the block (P3.3-1)

        if (is_string($updated) && $count > 0) {
            return $updated;
        }

        // Bold paragraph heading: <p><strong>Title</strong></p> or <p><b>Title</b></p>
        $boldParagraphPattern = '~<p\b[^>]*>\s*(?:<strong\b[^>]*>|<b\b[^>]*>)\s*' . $escaped . '\s*(?:</strong>|</b>)\s*</p>~iu';
        $updated = preg_replace($boldParagraphPattern, '<' . $targetTag . '>' . $headingText . '</' . $targetTag . '>', $html, -1, $count);
        if (is_string($updated) && $count > 0) {
            return $updated;
        }

        return $html;
    }
}
