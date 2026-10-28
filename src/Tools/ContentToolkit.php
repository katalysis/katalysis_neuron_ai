<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Area\Area;
use Concrete\Core\Area\Exception\AreaNotFoundException;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\Command\DeleteBlockCommand;
use Concrete\Core\Block\Exception\BlockNotFoundException;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use Katalysis\NeuronAi\Tools\Utilities\BlockFields;
use Katalysis\NeuronAi\Tools\Utilities\BlockImportSave;
use Katalysis\NeuronAi\Tools\Utilities\BlockNotFound;
use Katalysis\NeuronAi\Tools\Utilities\DraftState;
use Katalysis\NeuronAi\Tools\Utilities\EditModeGuard;
use Katalysis\NeuronAi\Tools\Utilities\DryRunReached;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;
use Katalysis\NeuronAi\Tools\Utilities\PageVersionRead;
use Katalysis\NeuronAi\Tools\Utilities\SystemPageGuard;
use Katalysis\NeuronAi\Tools\Utilities\TextIntegrity;
use Katalysis\NeuronAi\Tools\Utilities\WriteRefusal;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * The blocks and areas of a page: read a page's blocks, and add, update, delete and move a block (P3.3-4: split out of
 * PagesToolkit, unchanged). No guidelines of its own, so the prompt adds no text for it.
 */
class ContentToolkit extends AbstractToolkit
{
    use PageToolGuards;
    use BlockWriteSupport;
    use BlockSummaries;

    public function provide(): array
    {
        return [
            $this->makeGetPageBlocksTool(),
            $this->makeAddBlockToPageAreaTool(),
            $this->makeUpdateBlockInPageAreaTool(),
            $this->makeDeleteBlockFromPageAreaTool(),
            $this->makeMoveBlockBetweenAreasTool(),
        ];
    }

    protected function makeGetPageBlocksTool(): Tool
    {
        $tool = new CallableTool(
            'get_page_blocks',
            'Get detailed inventory of all blocks on a page grouped by area. Use this for debugging or when you need block IDs for updates.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to inspect',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'version',
            PropertyType::STRING,
            PageVersionRead::DESCRIPTION,
            false
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandle',
            PropertyType::STRING,
            'Optional: Only return blocks from this specific area',
            false
        ));

        $tool->setHandler($this->guarded(function (?int $pageID = null, ?string $version = null, ?string $areaHandle = null): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }

            [$page, $versionInfo] = PageVersionRead::load($pageID, $version);

            $areas = [];
            $totalBlocks = 0;
            $errors = [];

            try {
                $areaHandles = $areaHandle ? [$areaHandle] : Area::getHandleList();
                
                foreach ($areaHandles as $handle) {
                    try {
                        $area = Area::get($page, $handle);
                        if (!$area) {
                            if ($areaHandle === $handle) {
                                throw new \RuntimeException("Area '{$handle}' does not exist on this page");
                            }
                            continue;
                        }
                        
                        $blocks = $area->getAreaBlocksArray($page);
                        $blockData = [];
                        
                        foreach ($blocks as $block) {
                            try {
                                $controller = $block->getController();
                                $blockType = $block->getBlockTypeHandle();
                                $blockTypeName = $block->getBlockTypeName();
                                
                                // Try to extract a preview of content
                                $preview = ['contentPreview' => null];
                                try {
                                    if (method_exists($controller, 'getContent')) {
                                        $preview = $this->blockPreviewFields((string) $controller->getContent());
                                    } elseif (method_exists($controller, 'getSearchableContent')) {
                                        $preview = $this->blockPreviewFields((string) $controller->getSearchableContent());
                                    }
                                } catch (\Exception $e) {
                                    $preview = ['contentPreview' => "[Error extracting content: " . $e->getMessage() . "]"];
                                }
                                
                                $blockData[] = [
                                    'blockID' => $block->getBlockID(),
                                    'blockType' => $blockType,
                                    'blockTypeName' => $blockTypeName,
                                    'blockName' => $block->getBlockName(),
                                    'displayOrder' => $block->getBlockDisplayOrder(),
                                ] + $preview;
                                $totalBlocks++;
                            } catch (\Exception $e) {
                                $errors[] = "Error reading block in area '{$handle}': " . $e->getMessage();
                            }
                        }
                        
                        if (!empty($blockData)) {
                            $areas[] = [
                                'areaHandle' => $handle,
                                'blockCount' => count($blockData),
                                'blocks' => $blockData,
                            ];
                        }
                    } catch (\Exception $e) {
                        $errors[] = "Error reading area '{$handle}': " . $e->getMessage();
                    }
                }
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to enumerate areas: " . $e->getMessage());
            }

            $result = [
                'page' => [
                    'id' => $page->getCollectionID(),
                    'path' => $page->getCollectionPath(),
                    'name' => $page->getCollectionName(),
                    'versionID' => $page->getVersionID(),
                    'isActive' => $page->isActive(),
                ],
                'version' => $versionInfo,
                'totalBlocks' => $totalBlocks,
                'areaCount' => count($areas),
                'areas' => $areas,
                'note' => 'contentPreview values are shortened previews for finding blocks; a block marked contentPreviewShortened has more text. Do not use contentPreview as source content for updates: get_block_data returns a block\'s full stored fields, and get_page_content the full text of the page.',
            ] + $this->subAreaSummary($page);

            if (!empty($errors)) {
                $result['errors'] = $errors;
                $result['warning'] = 'Some errors occurred during block inspection. See errors array.';
            }

            return $result;
        }));

        return $tool;
    }

    protected function makeAddBlockToPageAreaTool(): Tool
    {
        $tool = new CallableTool(
            'add_block_to_page_area',
            'Add a new block to a specific page area. '
            . 'blockType must be the handle of the block type the user asked for. Never substitute a different type '
            . '(for example, do not use "content" when the user asked for an HTML block). '
            . 'Common handles: '
            . 'content = rich text (formatted text, headings, paragraphs); '
            . 'html = raw HTML, for markup that must be kept exactly as written; '
            . 'image = a single image; '
            . 'file = a link to a downloadable file; '
            . 'page_list = a list of pages; '
            . 'autonav = a navigation menu; '
            . 'youtube or video = an embedded video; '
            . 'google_map = a map; '
            . 'form = a Concrete form; '
            . 'accordion, faq, feature = structured content blocks. '
            . 'If the requested type does not exist or you are unsure of its handle, say so instead of choosing another.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page that contains the target area',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandle',
            PropertyType::STRING,
            'Handle of the area (for example: "Main")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'blockType',
            PropertyType::STRING,
            'Handle of the block type the user asked for, used exactly as requested '
            . '("content" = rich text, "html" = raw HTML, "image", "file", "page_list", ...). Do not pick a different type.',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'value',
            PropertyType::OBJECT,
            BlockImportSave::VALUE_DESCRIPTION,
            true
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?string $areaHandle = null,
            ?string $blockType = null,
            ?array $value = []
        ): array {
            if (!$pageID || !$areaHandle || !$blockType) {
                throw new \RuntimeException('pageID, areaHandle, and blockType are required');
            }

            $page = Page::getByID($pageID);
            if (!$page || $page->isError()) {
                throw new \RuntimeException(WriteRefusal::pageNotFound((int) $pageID));
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            SystemPageGuard::refuseGlobalArea($page, $areaHandle);
            // P3.5-1 decision 3: no block is added without edit mode's own permission.
            ObjectPermissions::deny(ObjectPermissions::editPageContents($page));

            $bt = BlockType::getByHandle($blockType);
            if (!$bt) {
                throw new \RuntimeException(WriteRefusal::unknownBlockType((string) $blockType));
            }

            // P3.3-7: the Level 0 checks (the block's fields, references, required columns; BF-17), then the value as
            // the import-mode save takes it.
            $fields = BlockFields::ofType($bt);
            $prepared = BlockImportSave::prepareNew($fields, (array) ($value ?? []));
            // No escaped or broken HTML, and no code, in a new block either (BF-7, browser check 6; P3.3-2, B-T1-10).
            $valueProblem = TextIntegrity::checkNewValue($prepared['check']);
            if ($valueProblem !== null) {
                throw new \RuntimeException($valueProblem);
            }

            // The block type's own check, as Concrete's add-block dialog runs it (AddBlockToPageCommand doesn't; P3.3-2),
            // on the column-shaped value the import-mode save gets (P3.3-7).
            $validation = WriteRefusal::blockValidation($bt->getController()->validate($prepared['value']), (string) $blockType);
            if ($validation !== null) {
                throw new \RuntimeException($validation);
            }

            // A refused add leaves no Areas row (P3.3-2; BF-3 plan §9): an area made here is removed again.
            [$area, $areaIsNew] = $this->areaForWrite($page, $areaHandle);
            $refusal = ObjectPermissions::addBlockToArea($area, $bt);
            if ($refusal !== null) {
                $this->removeNewArea($area, $areaIsNew);
                ObjectPermissions::deny($refusal);
            }

            // Added to the draft, so a second write in this request adds to the same draft (see PageDraft::of()), in
            // import mode, as AddBlockToPageCommand adds it but with the save mode it can't pass (P3.3-7). An approval
            // check's dry run stops here (P3.6-2): an area made for the check goes.
            try {
                $draft = $this->getDraftOf($page);
            } catch (DryRunReached $e) {
                $this->removeNewArea($area, $areaIsNew);
                throw $e;
            }
            $block = BlockImportSave::add($draft, $area, $bt, $fields, $prepared);

            return $this->serializeBlock($block, true) + DraftState::of($draft);
        }));

        return $tool;
    }

    protected function makeUpdateBlockInPageAreaTool(): Tool
    {
        $tool = new CallableTool(
            'update_block_in_page_area',
            'Update an existing block in a specific page area, in a draft. Existing text is kept unless changeMode says otherwise, and fields left out of value keep their stored values. Read the full block with get_block_data first.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page that contains the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandle',
            PropertyType::STRING,
            'Handle of the area containing the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'blockID',
            PropertyType::INTEGER,
            'ID of the block to update',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'value',
            PropertyType::OBJECT,
            BlockImportSave::VALUE_DESCRIPTION . ' Fields left out keep their stored values.',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'changeMode',
            PropertyType::STRING,
            'Text safety mode: "keep_text" (default: existing text must stay; text may be added), "formatting_only" (text must remain identical), or "scoped_text" (text may change only in textFields, and stored text may go only if it is listed in replacedText)',
            false
        ));

        $tool->addProperty(new ArrayProperty(
            'textFields',
            'With changeMode "scoped_text": the fields whose text may change, e.g. ["content"]',
            false,
            new ToolProperty('field', PropertyType::STRING, 'A field name of the block', true)
        ));

        $tool->addProperty(new ArrayProperty(
            'replacedText',
            'With changeMode "scoped_text": the stored text the change replaces or removes, word for word, one string per paragraph or heading',
            false,
            new ToolProperty('text', PropertyType::STRING, 'Stored text, as get_block_data returns it', true)
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?string $areaHandle = null,
            ?int $blockID = null,
            ?array $value = [],
            ?string $changeMode = null,
            ?array $textFields = null,
            ?array $replacedText = null
        ): array {
            if (!$pageID || !$areaHandle || !$blockID) {
                throw new \RuntimeException('pageID, areaHandle, and blockID are required');
            }

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException(WriteRefusal::pageNotFound((int) $pageID));
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            SystemPageGuard::refuseGlobalArea($page, $areaHandle);
            EditModeGuard::refuseOpenBlock($page, $blockID);
            // P3.6-2: a block an earlier call in this request copied into the draft is changed in its copy.
            $blockID = PageDraft::followCopies($page, $areaHandle, (int) $blockID);
            // P3.5-1 decision 3: edit mode's own permission first, then the block's.
            ObjectPermissions::deny(ObjectPermissions::editPageContents($page));

            try {
                [$area, $block] = $this->getBlockToWorkWith($page, $areaHandle, $blockID);
            } catch (AreaNotFoundException|BlockNotFoundException $e) {
                // Look again on the newest version, as a plain lookup. This must not make a draft:
                // a block that isn't there is an error, and an error must leave no draft behind.
                $fallbackArea = $page->getArea($areaHandle);
                if (!$fallbackArea) {
                    $fallbackArea = new Area($areaHandle);
                    $fallbackArea->c = $page;
                }
                $fallbackBlock = Block::getByID($blockID, $page, $fallbackArea);
                if (!$fallbackBlock) {
                    // Where the block is instead, and the ID to use (BF-7, browser check 6).
                    throw new \RuntimeException(BlockNotFound::explain($page, $areaHandle, $blockID));
                }
                $area = $fallbackArea;
                $block = $fallbackBlock;
            }

            ObjectPermissions::deny(ObjectPermissions::editBlock($block));

            $body = (array) ($value ?? []);

            // The text check, on the block as it is stored now, before any draft exists (BF-5): a refusal changes nothing.
            $mode = TextIntegrity::mode($changeMode);
            $controller = $block->getController();
            $dbTable = $controller ? $controller->getBlockTypeDatabaseTable() : null;
            $beforeRecord = $this->getBlockTableRecord($dbTable, (int) $block->getBlockID());
            $handle = (string) $block->getBlockTypeHandle();
            // P3.3-7: the Level 0 checks (BF-17's fields, references, child rows), and the full stored row with the
            // change, as the import-mode save takes it.
            $fields = BlockFields::ofController($handle, $controller);
            $prepared = BlockImportSave::prepareUpdate($fields, (int) $block->getBlockID(), $beforeRecord, $body);
            $body = $prepared['value'];
            $textPolicy = TextIntegrity::evaluate($prepared['checkBefore'], $prepared['checkAfter'], $mode, BlockImportSave::textFields($fields, $textFields, array_keys($prepared['checkBefore'] + $prepared['checkAfter'])), $replacedText);
            if (!$textPolicy['ok']) {
                throw new \RuntimeException($textPolicy['message']);
            }

            // The block type's own check, on the column-shaped value the import-mode save gets (P3.3-7). validate()
            // returning false refuses too.
            $validation = WriteRefusal::blockValidation($controller->validate($body), $handle);
            if ($validation !== null) {
                throw new \RuntimeException($validation);
            }

            // Make the draft only now that the checks have passed. If it exists already this
            // returns it, so getBlockToEdit doesn't make a second one.
            $pageToModify = $this->getDraftOf($page);
            $blockToEdit = $this->getBlockToEdit($pageToModify, $area, $areaHandle, $blockID);

            // Saved once (P3.3-2), in import mode, as UpdatePageBlockCommand saves it but with the save mode it can't
            // pass (P3.3-7).
            $updatedBlock = BlockImportSave::update($pageToModify, $blockToEdit, $fields, $prepared);

            $afterRecord = $this->getBlockTableRecord($dbTable, (int) $updatedBlock->getBlockID());

            return $this->serializeBlock($updatedBlock, true) + $this->previousBlockID((int) $blockID, (int) $updatedBlock->getBlockID()) + [
                'textCheck' => [
                    'mode' => $mode,
                    'fields' => $textPolicy['fields'],
                    'keptFields' => $prepared['keptFields'],
                    'checksumsBefore' => TextIntegrity::checksums($beforeRecord, $textPolicy['watchedFields']),
                    'checksumsAfter' => TextIntegrity::checksums($afterRecord, $textPolicy['watchedFields']),
                ],
            ] + DraftState::of($pageToModify);
        }));

        return $tool;
    }

    protected function makeDeleteBlockFromPageAreaTool(): Tool
    {
        $tool = new CallableTool(
            'delete_block_from_page_area',
            'Delete a block from a specific page area'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page that contains the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandle',
            PropertyType::STRING,
            'Handle of the area containing the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'blockID',
            PropertyType::INTEGER,
            'ID of the block to delete',
            true
        ));

        $tool->setHandler($this->guarded(function (?int $pageID = null, ?string $areaHandle = null, ?int $blockID = null): array {
            if (!$pageID || !$areaHandle || !$blockID) {
                throw new \RuntimeException('pageID, areaHandle, and blockID are required');
            }

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException(WriteRefusal::pageNotFound((int) $pageID));
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            SystemPageGuard::refuseGlobalArea($page, $areaHandle);
            EditModeGuard::refuseOpenBlock($page, $blockID);
            // P3.6-2: a block an earlier call in this request copied into the draft is removed in its copy.
            $blockID = PageDraft::followCopies($page, $areaHandle, (int) $blockID);

            // P3.5-1 decision 3: edit mode's own permission first, then the block's.
            ObjectPermissions::deny(ObjectPermissions::editPageContents($page));
            try {
                [$area, $block] = $this->getBlockToWorkWith($page, $areaHandle, $blockID);
            } catch (AreaNotFoundException|BlockNotFoundException $e) {
                throw new \RuntimeException(BlockNotFound::explain($page, $areaHandle, $blockID));
            }

            ObjectPermissions::deny(ObjectPermissions::deleteBlock($block));

            // Delete in a draft, never in the version the page may be showing. The block stays
            // in the live version, and discarding the draft restores the page.
            $pageToModify = $this->getDraftOf($page);

            $command = new DeleteBlockCommand(
                $block->getBlockID(),
                $pageToModify->getCollectionID(),
                $pageToModify->getVersionID(),
                $area->getAreaHandle()
            );

            // Report only what happened (BF-2): the command does nothing, silently, when the draft has no such block.
            $placements = fn (): int => (int) Application::getFacadeApplication()->make('database')->connection()->fetchOne(
                'SELECT COUNT(*) FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? AND bID = ?',
                [$pageToModify->getCollectionID(), $pageToModify->getVersionID(), $block->getBlockID()]
            );
            $before = $placements();
            Application::getFacadeApplication()->executeCommand($command);
            if ($before === 0 || $placements() > 0) {
                throw new \RuntimeException("Block {$blockID} was not deleted from page {$pageID}: it is not in the draft being edited.");
            }

            return [
                'pageID' => $pageID,
                'areaHandle' => $areaHandle,
                'blockID' => $blockID,
                'deleted' => true,
            ] + DraftState::of($pageToModify);
        }));

        return $tool;
    }

    protected function makeMoveBlockBetweenAreasTool(): Tool
    {
        $tool = new CallableTool(
            'move_block_between_areas',
            'Move an existing block to a different area on the same page'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page containing the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'fromAreaHandle',
            PropertyType::STRING,
            'Current area handle for the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'toAreaHandle',
            PropertyType::STRING,
            'Destination area handle for the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'blockID',
            PropertyType::INTEGER,
            'ID of the block to move',
            true
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?string $fromAreaHandle = null,
            ?string $toAreaHandle = null,
            ?int $blockID = null
        ): array {
            if (!$pageID || !$fromAreaHandle || !$toAreaHandle || !$blockID) {
                throw new \RuntimeException('pageID, fromAreaHandle, toAreaHandle, and blockID are required');
            }

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException(WriteRefusal::pageNotFound((int) $pageID));
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            SystemPageGuard::refuseGlobalArea($page, $fromAreaHandle);
            SystemPageGuard::refuseGlobalArea($page, $toAreaHandle);
            EditModeGuard::refuseOpenBlock($page, $blockID);
            // P3.6-2: a block an earlier call in this request copied into the draft is moved as its copy.
            $blockID = PageDraft::followCopies($page, $fromAreaHandle, (int) $blockID);
            // P3.5-1 decision 3: edit mode's own permission first, then the block's and the destination area's.
            ObjectPermissions::deny(ObjectPermissions::editPageContents($page));

            try {
                [, $block] = $this->getBlockToWorkWith($page, $fromAreaHandle, $blockID);
            } catch (AreaNotFoundException|BlockNotFoundException $e) {
                throw new \RuntimeException(BlockNotFound::explain($page, $fromAreaHandle, $blockID));
            }

            ObjectPermissions::deny(ObjectPermissions::editBlock($block));

            // Check the destination before making a draft, so a refused move leaves no draft behind.
            // (Areas belong to the page, not to a version.) An area made for the check is removed again (P3.3-2).
            $destinationBlockType = $block->getBlockTypeObject();
            [$destination, $destinationIsNew] = $this->areaForWrite($page, $toAreaHandle);
            $refusal = $destinationBlockType ? ObjectPermissions::addBlockToArea($destination, $destinationBlockType) : null;
            if ($refusal !== null) {
                $this->removeNewArea($destination, $destinationIsNew);
                ObjectPermissions::deny($refusal);
            }

            // An approval check's dry run stops here (P3.6-2): an area made for the check goes.
            try {
                $pageToModify = $this->getDraftOf($page);
            } catch (DryRunReached $e) {
                $this->removeNewArea($destination, $destinationIsNew);
                throw $e;
            }
            $destinationArea = Area::getOrCreate($pageToModify, $toAreaHandle);

            $editableBlock = $this->getBlockToEdit($pageToModify, Area::getOrCreate($pageToModify, $fromAreaHandle), $fromAreaHandle, $blockID);
            if (!$editableBlock) {
                throw new \RuntimeException("Block {$blockID} could not be prepared for moving");
            }

            $editableBlock->move($pageToModify, $destinationArea);
            $pageToModify->rescanDisplayOrder($fromAreaHandle);
            $pageToModify->rescanDisplayOrder($toAreaHandle);

            $movedBlock = Block::getByID($editableBlock->getBlockID(), $pageToModify, $destinationArea);

            return $this->serializeBlock($movedBlock ?: $editableBlock, true)
                + $this->previousBlockID((int) $blockID, (int) $editableBlock->getBlockID())
                + DraftState::of($pageToModify);
        }));

        return $tool;
    }

    /**
     * The area to check a write against, and whether it was made here (P3.3-2). Area::getOrCreate() writes an Areas row,
     * so a refused call removes the row it made with removeNewArea().
     *
     * @return array{0: Area, 1: bool}
     */
    private function areaForWrite(Page $page, string $areaHandle): array
    {
        $existing = Area::get($page, $areaHandle);
        if ($existing instanceof Area) {
            return [$existing, false];
        }

        /** @var mixed $pageForArea */
        $pageForArea = $page;

        return [Area::getOrCreate($pageForArea, $areaHandle), true];
    }

    private function removeNewArea(Area $area, bool $isNew): void
    {
        if ($isNew && (int) $area->getAreaID() > 0) {
            Application::getFacadeApplication()->make('database')->connection()
                ->executeStatement('DELETE FROM Areas WHERE arID = ?', [(int) $area->getAreaID()]);
            Application::getFacadeApplication()->make('cache/request')->flush();
        }
    }

    /**
     * After a copy into the draft the block has a new ID (BF-7). The result names the one it replaced, so later calls
     * in the same turn use the new one.
     *
     * @return array{previousBlockID?: int, blockIDNote?: string}
     */
    private function previousBlockID(int $previous, int $current): array
    {
        if ($previous === $current || $current <= 0) {
            return [];
        }

        return [
            'previousBlockID' => $previous,
            'blockIDNote' => "The block was copied into the draft and now has ID {$current} (it was {$previous}). Use {$current} for further changes to it.",
        ];
    }

    /**
     * Concrete's block lookup, refusing a global area first (BF-2): for a global area it reaches into the stack.
     */
    public function getBlockToWorkWith(Page $page, string $areaHandle, $blockID): array
    {
        SystemPageGuard::refuseGlobalArea($page, $areaHandle);

        return $this->coreGetBlockToWorkWith($page, $areaHandle, $blockID);
    }
}
