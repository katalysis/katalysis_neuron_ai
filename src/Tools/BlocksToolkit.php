<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\File\File;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use Katalysis\NeuronAi\Access\PermissionRefused;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use Katalysis\NeuronAi\Tools\Support\MarkdownToolEnvelope;
use Katalysis\NeuronAi\Tools\Utilities\AbsoluteUrl;
use Katalysis\NeuronAi\Tools\Utilities\ListWindow;
use Katalysis\NeuronAi\Tools\Utilities\BlockNotFound;
use Katalysis\NeuronAi\Tools\Utilities\BlockSaveInputs;
use Katalysis\NeuronAi\Tools\Utilities\PagePathLabel;
use Katalysis\NeuronAi\Tools\Utilities\PageVersionRead;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * Concrete CMS Blocks toolkit for Neuron AI
 * 
 * Provides tools for searching and inspecting blocks across the site.
 * Complements PagesToolkit by enabling efficient block-centric queries.
 * 
 * ARCHITECTURAL NOTE:
 * Concrete CMS does not provide a high-level BlockList API (only BlockTypeList for types).
 * Individual block instances are stored in legacy database tables (Blocks, CollectionVersionBlocks)
 * without Doctrine entity representations. Following Concrete CMS patterns (e.g., PageList::filterByPath),
 * we use Doctrine QueryBuilder for database abstraction while leveraging Concrete's APIs
 * (BlockType::getByHandle, Block::getByID) where they exist.
 */
class BlocksToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "Use these tools to search for and inspect blocks across the site. " .
               "WORKFLOW: First use search_blocks_by_type to find blocks and get their blockID, pageID, and areaHandle. " .
               "For blocks INSIDE CONTAINERS: Look for area handles with ' : ' delimiter (e.g., '12704 : Column 1'). " .
               "Use list_container_blocks(containerBlockID) to see all nested blocks. " .
               "Then use get_block_controller_data with blockID, pageID, and the full areaHandle (including container ID) to see the actual content. " .
               "This is more efficient than querying each page individually when looking for specific block types.";
    }

    public function provide(): array
    {
        // P3.5-1: a login check, and {ok: false, error} for every refusal or error (MarkdownToolEnvelope).
        return array_map(static fn (CallableTool $tool): CallableTool => $tool->wrapHandler([MarkdownToolEnvelope::class, 'wrap']), [
            $this->makeSearchBlocksByTypeTool(),
            $this->makeListPageBlocksByTypeTool(),
            $this->makeGetBlockDataTool(),
            $this->makeGetBlockControllerDataTool(),
            $this->makeCountBlocksByTypeTool(),
            $this->makeListContainerBlocksTool(),
        ]);
    }

    /**
     * P3.5-1: may this user read a block placed on page $cID, in a version that is ($approved) or isn't the published
     * one? The page needs View; a version that isn't published needs View Versions too (as PageVersionRead).
     *
     * @param array<int, array{view: bool, versions: bool}> $cache per page, for one call
     */
    private static function mayRead(int $cID, bool $approved, array &$cache): bool
    {
        if (!isset($cache[$cID])) {
            $page = Page::getByID($cID);
            $recent = Page::getByID($cID, 'RECENT');
            $cache[$cID] = [
                'view' => $page && !$page->isError() && ObjectPermissions::viewPage($page) === null,
                'versions' => $recent && !$recent->isError() && ObjectPermissions::viewPageVersions($recent) === null,
            ];
        }

        return $cache[$cID]['view'] && ($approved || $cache[$cID]['versions']);
    }

    /**
     * P3.5-1: refuses a block the user may not read: one on a page they can't view, one Concrete's own block
     * permissions hide, or one that only unpublished versions hold when they can't view versions (an edit makes a new
     * block ID, so such an ID is draft content). A block placed nowhere is left to the tool's own "not found".
     *
     * @return bool whether the user may also read unpublished versions of the block's page
     *
     * @throws PermissionRefused
     */
    private static function denyUnlessReadable(int $bID): bool
    {
        $db = Application::getFacadeApplication()->make('database')->connection();
        $rows = $db->fetchAllAssociative(
            'SELECT cvb.cID, MAX(cv.cvIsApproved) AS approved, MIN(cvb.arHandle) AS arHandle FROM CollectionVersionBlocks cvb'
            . ' JOIN CollectionVersions cv ON cv.cID = cvb.cID AND cv.cvID = cvb.cvID WHERE cvb.bID = ? GROUP BY cvb.cID',
            [$bID]
        );
        if ($rows === []) {
            return true;
        }
        $cache = [];
        foreach ($rows as $row) {
            $cID = (int) $row['cID'];
            if (!self::mayRead($cID, (bool) $row['approved'], $cache)) {
                continue;
            }
            $page = Page::getByID($cID, (bool) $row['approved'] ? 'ACTIVE' : 'RECENT');
            $block = Block::getByID($bID, $page, (string) $row['arHandle']);
            if ($block) {
                ObjectPermissions::deny(ObjectPermissions::viewBlock($block));
            }

            return $cache[$cID]['versions'];
        }

        $first = $rows[0];
        $cID = (int) $first['cID'];
        ObjectPermissions::deny(ObjectPermissions::viewPage(Page::getByID($cID)));
        ObjectPermissions::deny(ObjectPermissions::viewPageVersions(Page::getByID($cID, 'RECENT')));

        throw new PermissionRefused("Permission denied: you can't read block {$bID}.");
    }

    protected function makeListPageBlocksByTypeTool(): Tool
    {
        $tool = new CallableTool(
            'list_page_blocks_by_type',
            'List block instances of a specific block type on a single page (including nested/container sub-areas).'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'Page ID to inspect',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'blockTypeHandle',
            PropertyType::STRING,
            'Block type handle to filter by (for example: "content", "multi")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'version',
            PropertyType::STRING,
            PageVersionRead::DESCRIPTION,
            false
        ));

        $tool->setHandler(function (
            ?int $pageID = null,
            ?string $blockTypeHandle = null,
            ?string $version = null
        ): string {
            if (!$pageID || !$blockTypeHandle) {
                return "❌ Error: pageID and blockTypeHandle are required";
            }

            $app = Application::getFacadeApplication();
            $db = $app->make('database')->connection();

            try {
                // BF-10: one version of the page, the newest by default, as editors see it.
                [$page, $versionInfo] = PageVersionRead::load((int) $pageID, $version);

                $resolution = $this->resolveBlockTypeHandle((string) $blockTypeHandle, $db);
                $bt = $resolution['blockType'];
                if (!$bt) {
                    $message = "❌ Error: Block type '{$blockTypeHandle}' not found.";
                    if (!empty($resolution['suggestions'])) {
                        $message .= "\n\nDid you mean one of these handles?\n";
                        foreach ($resolution['suggestions'] as $suggestion) {
                            $message .= "- `{$suggestion}`\n";
                        }
                    }
                    return $message;
                }

                $qb = $db->createQueryBuilder();
                $qb->select(
                        'b.bID',
                        'cvb.cID',
                        'cvb.cvID',
                        'cvb.arHandle',
                        'cv.cvIsApproved',
                        'cv.cvHandle as pageHandle',
                        'pp.cPath as pagePath',
                        'b.bDateModified'
                    )
                    ->from('Blocks', 'b')
                    ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
                    ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
                    ->leftJoin('cvb', 'PagePaths', 'pp', 'cvb.cID = pp.cID AND pp.ppIsCanonical = 1')
                    ->where('cvb.cID = :cID')
                    ->andWhere('cvb.cvID = :cvID')
                    ->andWhere('b.btID = :btID')
                    ->setParameter('cID', $pageID)
                    ->setParameter('cvID', (int) $page->getVersionID())
                    ->setParameter('btID', $bt->getBlockTypeID())
                    ->orderBy('b.bID', 'ASC');

                $rows = $qb->execute()->fetchAllAssociative();
                if (empty($rows)) {
                    // P3.3-6 (scenario 32): asked for "form" on a page with an Express form block, the model concluded
                    // there was no form. Say which block types the page does have.
                    $present = $db->fetchAllAssociative(
                        'SELECT bt.btHandle, bt.btName, COUNT(*) AS n FROM CollectionVersionBlocks cvb JOIN Blocks b ON b.bID = cvb.bID'
                        . ' JOIN BlockTypes bt ON bt.btID = b.btID WHERE cvb.cID = ? AND cvb.cvID = ? GROUP BY bt.btHandle, bt.btName ORDER BY bt.btHandle',
                        [(int) $pageID, (int) $page->getVersionID()]
                    );
                    $types = array_map(static fn (array $r): string => "`{$r['btHandle']}` ({$r['btName']}, {$r['n']})", $present);

                    return "No '{$bt->getBlockTypeHandle()}' blocks found on page {$pageID}. {$versionInfo['summary']}"
                        . ($types === [] ? '' : "\nBlock types on this page: " . implode(', ', $types) . '. Use one of these handles if it is the block the user means.');
                }

                $byBlock = [];
                foreach ($rows as $row) {
                    $byBlock[(int) $row['bID']] = $row;
                }

                $pagePath = $page->getCollectionPath();
                $output = "📄 **Page {$pageID} ({$pagePath})**\n";
                $output .= "**Version:** {$versionInfo['summary']}\n";
                $output .= "🔍 Found **" . count($byBlock) . "** distinct `{$bt->getBlockTypeHandle()}` block(s).\n\n";
                if (($resolution['resolvedFrom'] ?? null) && $resolution['resolvedFrom'] !== $bt->getBlockTypeHandle()) {
                    $output .= "ℹ️ Resolved requested handle `{$resolution['resolvedFrom']}` to installed handle `{$bt->getBlockTypeHandle()}`.\n\n";
                }

                $output .= "| Block ID | Area Handle | Version | Modified |\n";
                $output .= "|----------|-------------|---------|----------|\n";

                foreach ($byBlock as $row) {
                    $status = ((int) ($row['cvIsApproved'] ?? 0) === 1) ? '✅' : '📝';
                    $modified = isset($row['bDateModified']) ? date('Y-m-d', strtotime((string) $row['bDateModified'])) : 'N/A';

                    $output .= sprintf(
                        "| %d | `%s` | %s v%d | %s |\n",
                        (int) $row['bID'],
                        (string) ($row['arHandle'] ?? ''),
                        $status,
                        (int) ($row['cvID'] ?? 0),
                        $modified
                    );
                }

                $output .= "\n💡 Use `get_block_controller_data(blockID, pageID, areaHandle)` with the exact Area Handle for edits/checks.\n";

                return $output;
            } catch (\Throwable $e) {
                return "❌ Error listing blocks on page: " . $e->getMessage();
            }
        });

        return $tool;
    }

    protected function makeSearchBlocksByTypeTool(): Tool
    {
        $tool = new CallableTool(
            'search_blocks_by_type',
            'Search for all instances of a specific block type across the site (including blocks nested inside container blocks), '
            . 'on the pages you can view. The result gives the total and whether there are more; use offset for the next blocks.'
        );

        $tool->addProperty(new ToolProperty(
            'blockTypeHandle',
            PropertyType::STRING,
            'Block type handle to search for (e.g., "contact", "katalysis_people", "content")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of results to return (1-500, default 100)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'offset',
            PropertyType::INTEGER,
            ListWindow::OFFSET_DESCRIPTION,
            false
        ));

        $tool->addProperty(new ToolProperty(
            'version',
            PropertyType::STRING,
            PageVersionRead::SITE_DESCRIPTION,
            false
        ));

        $tool->setHandler(function (
            ?string $blockTypeHandle = null,
            ?int $limit = 100,
            ?string $version = null,
            ?int $offset = null
        ): string {
            if (!$blockTypeHandle) {
                return "❌ Error: blockTypeHandle is required";
            }
            try {
                $published = PageVersionRead::normalize($version) === PageVersionRead::PUBLISHED;
            } catch (\RuntimeException $e) {
                return '❌ Error: ' . $e->getMessage();
            }

            $app = Application::getFacadeApplication();
            $db = $app->make('database')->connection();

            // Resolve block type handle with normalized variants and suggestions.
            $resolution = $this->resolveBlockTypeHandle((string) $blockTypeHandle, $db);
            $bt = $resolution['blockType'];
            if (!$bt) {
                $message = "❌ Error: Block type '{$blockTypeHandle}' not found.";
                if (!empty($resolution['suggestions'])) {
                    $message .= "\n\nDid you mean one of these handles?\n";
                    foreach ($resolution['suggestions'] as $suggestion) {
                        $message .= "- `{$suggestion}`\n";
                    }
                }
                return $message;
            }

            try {
                // Use Doctrine QueryBuilder (Concrete CMS's preferred database abstraction)
                $qb = $db->createQueryBuilder();
                
                $qb->select(
                        'b.bID',
                        'b.btID',
                        'cvb.cID',
                        'cvb.cvID',
                        'cvb.arHandle',
                        'cv.cvName as pageName',
                        'cv.cvHandle as pageHandle',
                        'pp.cPath as pagePath',
                        'cv.cvIsApproved',
                        'b.bDateModified',
                        '(SELECT MAX(cvn.cvID) FROM CollectionVersions cvn WHERE cvn.cID = cvb.cID) AS newestCvID'
                    )
                    ->from('Blocks', 'b')
                    ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
                    ->innerJoin('cvb', 'Pages', 'p', 'cvb.cID = p.cID')
                    ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
                    ->leftJoin('p', 'PagePaths', 'pp', 'p.cID = pp.cID AND pp.ppIsCanonical = 1')
                    ->where('b.btID = :btID')
                    ->setParameter('btID', $bt->getBlockTypeID())
                    ->orderBy('b.bDateModified', 'DESC')
                    ->addOrderBy('b.bID', 'DESC');

                // BF-10: one version per page, the newest by default (what editors see and the write tools change).
                // P3.5-1: the published one too, for the pages where this user can't view unpublished versions.
                $qb->andWhere($published
                    ? 'cv.cvIsApproved = 1'
                    : '(cv.cvIsApproved = 1 OR cvb.cvID = (SELECT MAX(cvn.cvID) FROM CollectionVersions cvn WHERE cvn.cID = cvb.cID))');

                // BF-6: only blocks on pages the user can view are listed or counted, so every match is read and
                // checked page by page (no SQL limit); the window is sliced from what's left. P3.5-1: a page whose
                // unpublished versions this user can't view shows its published version (BF-6's leak).
                $cache = [];
                $fellBack = false;
                $results = array_values(array_filter($qb->execute()->fetchAll(), static function (array $row) use (&$cache, &$fellBack, $published): bool {
                    $cID = (int) $row['cID'];
                    if (!self::mayRead($cID, true, $cache)) {
                        return false;
                    }
                    if ($published) {
                        return true;
                    }
                    if ($cache[$cID]['versions']) {
                        return (int) $row['cvID'] === (int) $row['newestCvID'];
                    }
                    $fellBack = $fellBack || (int) $row['cvID'] !== (int) $row['newestCvID'];

                    return (bool) $row['cvIsApproved'];
                }));

                if (empty($results)) {
                    return "No instances of '{$bt->getBlockTypeHandle()}' found in the " . ($published ? 'published' : 'newest') . ' version of any page you can view.';
                }

                $window = ListWindow::of($limit, $offset, 100, 500);
                $total = count($results);
                $results = $window->slice($results);
                $note = $window->note($total, count($results), 'search_blocks_by_type');
                $output = "🔍 **Found {$total} instance(s) of '{$bt->getBlockTypeHandle()}' block** in the "
                    . ($published ? 'published version' : 'newest version') . " of each page you can view.\n\n";
                if ($fellBack) {
                    $output .= "ℹ️ Some pages have a newer, unpublished version you don't have permission to view (Concrete's \"View Versions\"): their published version is shown.\n\n";
                }
                if ($note !== null) {
                    $output .= "ℹ️ {$note}\n\n";
                }
                if (empty($results)) {
                    return $output;
                }
                if (($resolution['resolvedFrom'] ?? null) && $resolution['resolvedFrom'] !== $bt->getBlockTypeHandle()) {
                    $output .= "ℹ️ Resolved requested handle `{$resolution['resolvedFrom']}` to installed handle `{$bt->getBlockTypeHandle()}`.\n\n";
                }
                $output .= "| Block ID | Page | Area | Version | Modified |\n";
                $output .= "|----------|------|------|---------|----------|\n";

                foreach ($results as $row) {
                    $pageInfo = self::pageLink((int) $row['cID'], $row['pagePath']);
                    $status = $row['cvIsApproved'] ? '✅' : '📝';
                    $modified = date('Y-m-d', strtotime($row['bDateModified']));
                    
                    // Detect if block is in a container (area handle contains ' : ' delimiter)
                    $areaDisplay = $row['arHandle'];
                    if (strpos($areaDisplay, ' : ') !== false) {
                        // Format: "12704 : Column 1" → "Column 1 (in container 12704)"
                        list($containerID, $subArea) = explode(' : ', $areaDisplay, 2);
                        $areaDisplay = "{$subArea} 📦#{$containerID}";
                    }
                    
                    $output .= sprintf(
                        "| %d | %s | %s | %s v%d | %s |\n",
                        $row['bID'],
                        $pageInfo,
                        $areaDisplay,
                        $status,
                        $row['cvID'],
                        $modified
                    );
                }

                $output .= "\n💡 **Next steps:**\n";
                $output .= "- Use `get_block_data(blockID)` to inspect field values\n";
                $output .= "- ✅ published version, 📝 newer unpublished version (what editors see and the write tools change)\n";

                return $output;
            } catch (\Exception $e) {
                return "❌ Error searching blocks: " . $e->getMessage();
            }
        });

        return $tool;
    }

    protected function makeGetBlockDataTool(): Tool
    {
        $tool = new CallableTool(
            'get_block_data',
            'Get detailed field data and configuration from a specific block'
        );

        $tool->addProperty(new ToolProperty(
            'blockID',
            PropertyType::INTEGER,
            'Block ID to retrieve data from',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'Page ID containing the block (optional, improves lookup)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'versionID',
            PropertyType::INTEGER,
            'Optional page version ID (cvID). By default the block is looked up in the page\'s newest version, and the result says whether it is in it.',
            false
        ));

        $tool->setHandler(function (
            ?int $blockID = null,
            ?int $pageID = null,
            ?int $versionID = null
        ): string {
            if (!$blockID) {
                return "❌ Error: blockID is required";
            }
            // P3.5-1: before anything is read (thrown past the try below, to the envelope).
            self::denyUnlessReadable((int) $blockID);

            try {
                // Get the block instance
                $c = null;
                if ($pageID) {
                    $page = Page::getByID($pageID, $versionID ? (string)$versionID : 'RECENT');
                    if ($page && !$page->isError()) {
                        $c = $page;
                    }
                }

                // Block::getByID() with a page and no area matches only an empty area handle, so a lookup with pageID
                // always failed before BF-10: the area comes from the block's placement. An ID the newest version no
                // longer holds is still read, from its newest placement, and the version note below says so.
                $block = $c ? $this->blockOnPage((int) $blockID, $c, !$versionID) : Block::getByID($blockID);
                
                if (!$block) {
                    return "❌ Error: Block {$blockID} not found";
                }

                $bt = $block->getBlockTypeObject();
                if (!$bt) {
                    return "❌ Error: Could not retrieve block type for block {$blockID}";
                }

                $controller = $block->getController();
                
                $output = "📦 **Block #{$blockID} Details:**\n\n";
                $output .= "**Type:** {$bt->getBlockTypeName()} (`{$bt->getBlockTypeHandle()}`)\n";
                
                if ($block->getBlockCollectionID()) {
                    $page = Page::getByID($block->getBlockCollectionID());
                    if ($page && !$page->isError()) {
                        $pagePath = $page->getCollectionPath();
                        $output .= "**Page:** [{$pagePath}]({$pagePath})\n";
                    }
                }
                
                if ($block->getAreaHandle()) {
                    $output .= "**Area:** {$block->getAreaHandle()}\n";
                }

                // BF-10: whether this ID is in the newest version, the one the write tools change.
                $versionNote = BlockNotFound::readNote((int) ($pageID ?: $block->getBlockCollectionID()), (int) $blockID);
                if ($versionNote !== null) {
                    $output .= "**Version:** {$versionNote}\n";
                }

                $output .= "\n**Field Data:**\n\n";

                // Get the database table for this block type (Concrete CMS API)
                $db = $controller->getBlockTypeDatabaseTable();
                
                if ($db && $db !== false) {
                    $app = Application::getFacadeApplication();
                    $conn = $app->make('database')->connection();
                    
                    // Use QueryBuilder for database access
                    // Note: Block type tables have dynamic schemas, so we need to query directly
                    $qb = $conn->createQueryBuilder();
                    $qb->select('*')
                        ->from($db)
                        ->where('bID = :bID')
                        ->setParameter('bID', $blockID)
                        ->setMaxResults(1);
                    
                    $data = $qb->execute()->fetchAssociative();
                    
                    if ($data) {
                        // Remove bID from display (not useful)
                        unset($data['bID']);
                        
                        if (empty($data)) {
                            $output .= "_No additional field data stored_\n";
                        } else {
                            foreach ($data as $field => $value) {
                                // Handle null values
                                if ($value === null) {
                                    $displayValue = "(null)";
                                }
                                // Handle non-string values
                                elseif (!is_string($value)) {
                                    $displayValue = var_export($value, true);
                                }
                                // Preserve full string values to avoid accidental truncation in write workflows.
                                elseif (is_numeric($value) && strlen($value) < 20) {
                                    $displayValue = $value;
                                } else {
                                    $displayValue = (string)$value;
                                }
                                
                                // Check if value might be a file ID
                                if (preg_match('/^(fID|fileID)$/i', $field) && is_numeric($value) && $value > 0) {
                                    $displayValue .= " (File ID)";
                                }
                                
                                // Check if value might be a page ID
                                if (preg_match('/(cID|pageID|linkPage)/i', $field) && is_numeric($value) && $value > 0) {
                                    $displayValue .= " (Page ID)";
                                }
                                
                                // Check if value might be a user ID
                                if (preg_match('/(uID|userID|contactee)/i', $field) && is_numeric($value) && $value > 0) {
                                    $displayValue .= " (User ID)";
                                }
                                
                                $output .= "- **{$field}:** {$displayValue}\n";
                            }
                        }
                    } else {
                        $output .= "_No data found in block table_\n";
                    }
                } else {
                    $output .= "_This block type doesn't store additional field data_\n";
                }

                // P3.3-6: rows the block keeps in other tables (an accordion's entries), and how to send them back.
                if ($db) {
                    foreach (BlockSaveInputs::childRows((string) $db, (int) $blockID) as $childTable => $rows) {
                        $output .= "\n**{$childTable}** (" . count($rows) . " row(s)):\n";
                        $output .= json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                    }
                }
                if ($bt->getBlockTypeHandle() === 'accordion') {
                    $output .= "\nTo change the entries, send value.accordionBlockData: a JSON array of {title, description}, every entry in order "
                        . "(entries left out are removed). Settings changes may leave it out. Current value:\n"
                        . BlockSaveInputs::accordionJson((int) $blockID) . "\n";
                }

                $output .= "\n💡 **Tips:**\n";
                $output .= "- File IDs can be resolved using file management tools\n";
                $output .= "- Page IDs can be looked up with `get_page(pageID)`\n";

                return $output;
            } catch (\Exception $e) {
                return "❌ Error getting block data: " . $e->getMessage();
            }
        });

        return $tool;
    }

    protected function makeGetBlockControllerDataTool(): Tool
    {
        $tool = new CallableTool(
            'get_block_controller_data',
            'Get runtime controller data from a block, including resolved relationships (users, files, pages). Better for understanding block content than raw database fields.'
        );

        $tool->addProperty(new ToolProperty(
            'blockID',
            PropertyType::INTEGER,
            'Block ID to inspect',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'Page ID containing the block (optional; if omitted or stale, the tool auto-discovers candidate page placements)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandle',
            PropertyType::STRING,
            'Area handle where the block is located (optional; if omitted or stale, the tool auto-discovers valid block context)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'versionID',
            PropertyType::INTEGER,
            'Optional page version ID hint (cvID) to prioritize during block lookup',
            false
        ));

        $tool->setHandler(function (
            ?int $blockID = null,
            ?int $pageID = null,
            ?string $areaHandle = null,
            ?int $versionID = null
        ): string {
            if (!$blockID) {
                return "❌ Error: blockID is required";
            }
            // P3.5-1: before anything is read.
            self::denyUnlessReadable((int) $blockID);

            try {
                $placements = $this->getBlockPlacements($blockID, $pageID);
                if (empty($placements) && $pageID) {
                    // If the provided pageID is stale/incorrect, broaden to all placements for this block.
                    $placements = $this->getBlockPlacements($blockID, null);
                }

                $candidatePageIDs = [];
                if ($pageID && $pageID > 0) {
                    $candidatePageIDs[] = (int) $pageID;
                }

                foreach ($placements as $placement) {
                    $candidate = (int) ($placement['cID'] ?? 0);
                    if ($candidate > 0 && !in_array($candidate, $candidatePageIDs, true)) {
                        $candidatePageIDs[] = $candidate;
                    }
                }

                if (empty($candidatePageIDs)) {
                    return "❌ Error: Block {$blockID} has no discoverable placements in CollectionVersionBlocks.";
                }

                $context = null;
                $attemptedPageIDs = [];

                foreach ($candidatePageIDs as $candidatePageID) {
                    $attemptedPageIDs[] = $candidatePageID;
                    $candidateContext = $this->resolveBlockLookupContext($blockID, $candidatePageID, $areaHandle, $versionID);
                    if (($candidateContext['block'] ?? null) instanceof Block) {
                        $context = $candidateContext;
                        break;
                    }
                }

                if (!is_array($context)) {
                    $output = "❌ Error: Unable to resolve block {$blockID} in runtime context.";
                    if (!empty($attemptedPageIDs)) {
                        $output .= " Tried page ID(s): " . implode(', ', $attemptedPageIDs) . ".";
                    }

                    if (!empty($placements)) {
                        $output .= "\n\nKnown placements:\n";
                        foreach ($placements as $placement) {
                            $placementPage = (int) ($placement['cID'] ?? 0);
                            $placementCv = (int) ($placement['cvID'] ?? 0);
                            $placementArea = (string) ($placement['arHandle'] ?? '');
                            $placementPath = PagePathLabel::describe($placementPage, $placement['pagePath'] ?? null);
                            $placementStatus = ((int) ($placement['cvIsApproved'] ?? 0) === 1) ? 'approved' : 'draft/pending';
                            $output .= "- page {$placementPage} ({$placementPath}), area '{$placementArea}', version v{$placementCv} ({$placementStatus})\n";
                        }
                    }

                    return $output;
                }

                $block = $context['block'];

                if (!$block instanceof Block) {
                    return "❌ Error: Block {$blockID} could not be resolved. " .
                        "Try search_blocks_by_type first to refresh identifiers and area handles.";
                }

                /** @var Page $page */
                $page = $context['page'];
                $resolvedAreaHandle = (string) ($context['areaHandle'] ?? $areaHandle ?? $block->getAreaHandle() ?? '');
                $resolvedVersionID = (int) ($context['cvID'] ?? 0);
                $lookupWarnings = $context['warnings'] ?? [];

                if ($pageID && $pageID > 0 && $page->getCollectionID() !== $pageID) {
                    $lookupWarnings[] = "Requested pageID {$pageID} did not match resolved placement. Using pageID {$page->getCollectionID()} instead.";
                }

                $bt = $block->getBlockTypeObject();
                if (!$bt) {
                    return "❌ Error: Could not retrieve block type";
                }

                // Get the controller and run its view() method to populate data
                $controller = $block->getController();
                $controllerWarnings = [];

                // Simulate what happens when the block is rendered, but keep going on runtime failures.
                try {
                    $controller->on_start();
                } catch (\Throwable $throwable) {
                    $controllerWarnings[] = "on_start(): " . $throwable->getMessage();
                }

                try {
                    $controller->runAction('view');
                } catch (\Throwable $throwable) {
                    $controllerWarnings[] = "view(): " . $throwable->getMessage();
                }

                $output = "📦 **Block #{$blockID} Controller Data:**\n\n";
                $output .= "**Type:** {$bt->getBlockTypeName()} (`{$bt->getBlockTypeHandle()}`)\n";
                $output .= "**Page:** [{$page->getCollectionPath()}]({$page->getCollectionPath()})\n";
                $output .= "**Area:** " . ($resolvedAreaHandle !== '' ? $resolvedAreaHandle : '(unknown)') . "\n";
                // BF-10: whether this ID is in the newest version, the one the write tools change.
                $versionNote = BlockNotFound::readNote((int) $page->getCollectionID(), (int) $blockID);
                if ($versionNote !== null) {
                    $output .= "**Version:** {$versionNote}\n";
                } elseif ($resolvedVersionID > 0) {
                    $output .= "**Version:** v{$resolvedVersionID}\n";
                }

                if (!empty($lookupWarnings) || !empty($controllerWarnings)) {
                    $output .= "\n**Warnings:**\n";
                    foreach (array_merge($lookupWarnings, $controllerWarnings) as $warning) {
                        $output .= "- {$warning}\n";
                    }
                }

                $output .= "\n";

                // Long text is printed in full once, under Raw Block Table Data; the other sections refer to it (BF-5).
                $rawBlockData = $this->getRawBlockRecord($controller, $blockID);
                $rawLongText = [];
                foreach ($rawBlockData as $field => $value) {
                    if (is_string($value) && strlen($value) > 100 && !isset($rawLongText[$value])) {
                        $rawLongText[$value] = (string) $field;
                    }
                }

                // Get data that's been set for the view (using getSets())
                $viewData = $controller->getSets();
                
                if (!empty($viewData)) {
                    $output .= "**View Data (what the block displays):**\n\n";
                    // P3.3-1: Concrete's Html\Image gives a relative src beside an absolute srcset; make them absolute.
                    $siteBase = AbsoluteUrl::siteBase();

                    foreach ($viewData as $key => $value) {
                        if (is_string($value)) {
                            $value = AbsoluteUrl::inHtml($value, $siteBase);
                        }
                        $output .= $this->formatControllerValue($key, $value, $rawLongText);
                    }
                } else {
                    $output .= "_No view data set_\n\n";
                }

                // Fallback to raw block table data for blocks that fail to populate runtime sets.
                if (!empty($rawBlockData)) {
                    $output .= "**Raw Block Table Data:**\n\n";
                    foreach ($rawBlockData as $field => $value) {
                        $output .= $this->formatControllerValue((string) $field, $value);
                    }
                    $output .= "\n";

                    if ($bt->getBlockTypeHandle() === 'multi') {
                        $output .= $this->formatMultiLinkSummary($rawBlockData);
                    }
                }

                // Also check controller properties directly
                $output .= "**Controller Properties:**\n\n";
                $reflection = new \ReflectionObject($controller);
                $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
                $arrayProperties = [];
                
                foreach ($properties as $property) {
                    $propName = $property->getName();
                    // Skip Concrete CMS internal properties
                    if (in_array($propName, ['helpers', 'btTable', 'btFieldsRequired', 'app'])) {
                        continue;
                    }
                    
                    $property->setAccessible(true);
                    $value = $property->getValue($controller);
                    
                    if (is_array($value)) {
                        $arrayProperties[] = $propName;
                    } elseif ($value !== null) {
                        $output .= $this->formatControllerValue($propName, $value, $rawLongText);
                    }
                }
                if ($arrayProperties !== []) {
                    $output .= '- Not shown (arrays): ' . implode(', ', $arrayProperties) . "\n";
                }

                return $output;
            } catch (\Throwable $e) {
                return "❌ Error getting controller data: " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * A markdown link to the page's real path, or "page ID n (no path)" with no link: never a guessed path (P3.3-1).
     */
    private static function pageLink(int $cID, ?string $storedPath): string
    {
        $path = PagePathLabel::of($cID, $storedPath);

        return $path !== null ? "[{$path}]({$path})" : PagePathLabel::describe($cID, null);
    }

    /**
     * The block in the area it has in $page's version; with $anyVersion, else from its newest placement on the page.
     */
    private function blockOnPage(int $blockID, Page $page, bool $anyVersion): ?Block
    {
        $db = Application::getFacadeApplication()->make('database')->connection();
        $cID = (int) $page->getCollectionID();
        $rows = $db->fetchAllAssociative(
            'SELECT cvID, arHandle FROM CollectionVersionBlocks WHERE cID = ? AND bID = ? ORDER BY cvID DESC',
            [$cID, $blockID]
        );
        foreach ($rows as $row) {
            if ((int) $row['cvID'] === (int) $page->getVersionID()) {
                return Block::getByID($blockID, $page, (string) $row['arHandle']) ?: null;
            }
        }
        if (!$anyVersion || $rows === []) {
            return null;
        }
        $older = Page::getByID($cID, (string) $rows[0]['cvID']);

        return ($older && !$older->isError()) ? (Block::getByID($blockID, $older, (string) $rows[0]['arHandle']) ?: null) : null;
    }

    /**
     * Return known block placements from CollectionVersionBlocks, optionally scoped to a page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getBlockPlacements(int $blockID, ?int $pageID = null): array
    {
        try {
            $app = Application::getFacadeApplication();
            $db = $app->make('database')->connection();

            $qb = $db->createQueryBuilder();
            $qb->select(
                    'cvb.cID',
                    'cvb.cvID',
                    'cvb.arHandle',
                    'cv.cvIsApproved',
                    'cv.cvHandle as pageHandle',
                    'pp.cPath as pagePath'
                )
                ->from('CollectionVersionBlocks', 'cvb')
                ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
                ->leftJoin('cvb', 'PagePaths', 'pp', 'cvb.cID = pp.cID AND pp.ppIsCanonical = 1')
                ->where('cvb.bID = :bID')
                ->setParameter('bID', $blockID)
                // BF-10: the newest version first.
                ->orderBy('cvb.cvID', 'DESC');

            if ($pageID && $pageID > 0) {
                $qb->andWhere('cvb.cID = :cID')
                    ->setParameter('cID', $pageID);
            }

            $rows = $qb->execute()->fetchAllAssociative();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $throwable) {
            return [];
        }
    }

    /**
     * Format a controller value for human-readable output. Strings are never shortened (BF-5): a long one that is
     * already printed under Raw Block Table Data ($rawLongText, text => field) is referred to instead of repeated.
     *
     * @param array<string, string> $rawLongText
     */
    private function formatControllerValue(string $key, $value, array $rawLongText = []): string
    {
        $output = "";
        
        // Handle UserInfo objects (common in Contact blocks)
        if ($value instanceof \Concrete\Core\User\UserInfo) {
            $output .= "- **{$key}:** User #{$value->getUserID()}\n";
            $output .= "  - Name: {$value->getUserName()}\n";
            $output .= "  - Display Name: {$value->getUserDisplayName()}\n";
            $output .= "  - Email: {$value->getUserEmail()}\n";
            
            // Get user attributes if they exist
            $attrs = ['position', 'telephone', 'mobile', 'bio'];
            foreach ($attrs as $attr) {
                $attrValue = $value->getAttribute($attr);
                if ($attrValue) {
                    $output .= "  - " . ucfirst($attr) . ": {$attrValue}\n";
                }
            }
        }
        // Handle File objects
        elseif ($value instanceof \Concrete\Core\Entity\File\File || $value instanceof \Concrete\Core\File\File) {
            $output .= "- **{$key}:** File #{$value->getFileID()}\n";
            $output .= "  - Filename: {$value->getFilename()}\n";
            $output .= "  - URL: {$value->getURL()}\n";
        }
        // Handle Page objects
        elseif ($value instanceof \Concrete\Core\Page\Page) {
            $output .= "- **{$key}:** Page #{$value->getCollectionID()}\n";
            $output .= "  - Path: {$value->getCollectionPath()}\n";
            $output .= "  - Name: {$value->getCollectionName()}\n";
        }
        // Handle arrays
        elseif (is_array($value)) {
            $output .= "- **{$key}:** Array with " . count($value) . " items (not shown; get_block_data returns the block's own table, and rows in other tables are not returned by any tool)\n";
        }
        // Handle objects (generic)
        elseif (is_object($value)) {
            $className = get_class($value);
            $output .= "- **{$key}:** {$className} object (not shown)\n";
        }
        // Handle primitives
        elseif (is_string($value) && isset($rawLongText[$value])) {
            $output .= "- **{$key}:** (same as raw field `{$rawLongText[$value]}`, shown in full under Raw Block Table Data)\n";
        } else {
            $output .= "- **{$key}:** {$value}\n";
        }
        
        return $output;
    }

    /**
     * Resolve a block instance in the most reliable context available.
     *
     * @return array{block:?Block,page:?Page,areaHandle:?string,cvID:?int,warnings:array<int,string>}
     */
    private function resolveBlockLookupContext(int $blockID, int $pageID, ?string $requestedAreaHandle = null, ?int $requestedVersionID = null): array
    {
        $warnings = [];
        $app = Application::getFacadeApplication();
        $db = $app->make('database')->connection();

        $requestedArea = is_string($requestedAreaHandle) ? trim($requestedAreaHandle) : '';
        $candidates = [];

        if ($requestedVersionID && $requestedVersionID > 0) {
            $candidates[] = [
                'cvID' => (int) $requestedVersionID,
                'areaHandle' => $requestedArea,
                'source' => 'requested versionID',
            ];
        }

        if ($requestedArea !== '') {
            $candidates[] = [
                'cvID' => null,
                'areaHandle' => $requestedArea,
                'source' => 'requested areaHandle',
            ];
        }

        $qb = $db->createQueryBuilder();
        $qb->select('cvID', 'arHandle')
            ->from('CollectionVersionBlocks')
            ->where('bID = :bID')
            ->andWhere('cID = :cID')
            ->setParameter('bID', $blockID)
            ->setParameter('cID', $pageID)
            ->orderBy('cvID', 'DESC');

        $placementRows = $qb->execute()->fetchAllAssociative();
        foreach ($placementRows as $row) {
            $rowArea = trim((string) ($row['arHandle'] ?? ''));
            if ($requestedArea !== '' && $rowArea !== '' && strcasecmp($rowArea, $requestedArea) !== 0) {
                continue;
            }

            $candidates[] = [
                'cvID' => isset($row['cvID']) ? (int) $row['cvID'] : null,
                'areaHandle' => $rowArea,
                'source' => 'CollectionVersionBlocks',
            ];
        }

        // If the requested area wasn't found in placement rows, broaden lookup.
        if ($requestedArea !== '' && !empty($placementRows)) {
            $areaMatched = false;
            foreach ($placementRows as $row) {
                if (strcasecmp(trim((string) ($row['arHandle'] ?? '')), $requestedArea) === 0) {
                    $areaMatched = true;
                    break;
                }
            }

            if (!$areaMatched) {
                $warnings[] = "Requested areaHandle '{$requestedArea}' not found for this block. Auto-discovering a valid placement.";
                foreach ($placementRows as $row) {
                    $candidates[] = [
                        'cvID' => isset($row['cvID']) ? (int) $row['cvID'] : null,
                        'areaHandle' => trim((string) ($row['arHandle'] ?? '')),
                        'source' => 'fallback placement',
                    ];
                }
            }
        }

        $dedupedCandidates = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = (string) ($candidate['cvID'] ?? 'ACTIVE') . '|' . (string) ($candidate['areaHandle'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $dedupedCandidates[] = $candidate;
        }

        foreach ($dedupedCandidates as $candidate) {
            $cvID = $candidate['cvID'] ?? null;
            $area = trim((string) ($candidate['areaHandle'] ?? ''));

            $page = $cvID ? Page::getByID($pageID, (string) $cvID) : Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                continue;
            }

            $block = $area !== ''
                ? Block::getByID($blockID, $page, $area)
                : Block::getByID($blockID, $page);

            if ($block instanceof Block) {
                return [
                    'block' => $block,
                    'page' => $page,
                    'areaHandle' => $area !== '' ? $area : $block->getAreaHandle(),
                    'cvID' => $cvID,
                    'warnings' => $warnings,
                ];
            }
        }

        // Last resort: try recent version without area restriction.
        $recentPage = Page::getByID($pageID, 'RECENT');
        if ($recentPage && !$recentPage->isError()) {
            $fallbackBlock = Block::getByID($blockID, $recentPage);
            if ($fallbackBlock instanceof Block) {
                $warnings[] = 'Resolved block using RECENT page version without areaHandle filter.';
                return [
                    'block' => $fallbackBlock,
                    'page' => $recentPage,
                    'areaHandle' => $fallbackBlock->getAreaHandle(),
                    'cvID' => null,
                    'warnings' => $warnings,
                ];
            }
        }

        return [
            'block' => null,
            'page' => null,
            'areaHandle' => null,
            'cvID' => null,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRawBlockRecord($controller, int $blockID): array
    {
        try {
            $table = $controller->getBlockTypeDatabaseTable();
            if (!$table || $table === false) {
                return [];
            }

            $app = Application::getFacadeApplication();
            $db = $app->make('database')->connection();

            $qb = $db->createQueryBuilder();
            $qb->select('*')
                ->from($table)
                ->where('bID = :bID')
                ->setParameter('bID', $blockID)
                ->setMaxResults(1);

            $row = $qb->execute()->fetchAssociative();
            if (!is_array($row)) {
                return [];
            }

            unset($row['bID']);
            return $row;
        } catch (\Throwable $throwable) {
            return [];
        }
    }

    /**
     * Build a concise, normalized summary for Multi Link blocks.
     *
     * @param array<string, mixed> $rawBlockData
     */
    private function formatMultiLinkSummary(array $rawBlockData): string
    {
        $linkType = trim((string) ($rawBlockData['link'] ?? ''));
        $title = trim((string) ($rawBlockData['link_Title'] ?? ''));
        $resolvedUrl = '';

        if ($linkType === 'url') {
            $resolvedUrl = trim((string) ($rawBlockData['link_URL'] ?? ''));
        } elseif ($linkType === 'relative_url') {
            $resolvedUrl = trim((string) ($rawBlockData['link_Relative_URL'] ?? ''));
        } elseif ($linkType === 'page') {
            $pageID = (int) ($rawBlockData['link_Page'] ?? 0);
            if ($pageID > 0) {
                $page = Page::getByID($pageID);
                if ($page && !$page->isError() && !$page->isInTrash()) {
                    $resolvedUrl = (string) $page->getCollectionLink();
                    if ($title === '') {
                        $title = (string) $page->getCollectionName();
                    }
                }
            }
        } elseif ($linkType === 'file') {
            $fileID = (int) ($rawBlockData['link_File'] ?? 0);
            if ($fileID > 0) {
                $file = File::getByID($fileID);
                if (is_object($file)) {
                    $resolvedUrl = (string) $file->getDownloadURL();
                    if ($title === '') {
                        $title = (string) $file->getTitle();
                    }
                }
            }
        } elseif ($linkType === 'image') {
            $imageID = (int) ($rawBlockData['link_Image'] ?? 0);
            if ($imageID > 0) {
                $image = File::getByID($imageID);
                if (is_object($image)) {
                    $resolvedUrl = (string) $image->getURL();
                    if ($title === '') {
                        $title = (string) $image->getTitle();
                    }
                }
            }
        }

        $output = "**Normalized Multi Link Summary:**\n\n";
        $output .= "- **linkType:** " . ($linkType !== '' ? $linkType : '(none)') . "\n";
        $output .= "- **title:** " . ($title !== '' ? $title : '(empty)') . "\n";
        $output .= "- **resolvedUrl:** " . ($resolvedUrl !== '' ? $resolvedUrl : '(empty)') . "\n";

        return $output . "\n";
    }

    protected function makeCountBlocksByTypeTool(): Tool
    {
        $tool = new CallableTool(
            'count_blocks_by_type',
            'Get a quick count of how many instances exist for each block type (or a specific type)'
        );

        $tool->addProperty(new ToolProperty(
            'blockTypeHandle',
            PropertyType::STRING,
            'Optional: Specific block type handle to count (omit to see counts for all types)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'activeOnly',
            PropertyType::BOOLEAN,
            'Count only blocks on active/published pages (default: true)',
            false
        ));

        $tool->setHandler(function (
            ?string $blockTypeHandle = null,
            ?bool $activeOnly = true
        ): string {
            $app = Application::getFacadeApplication();
            $db = $app->make('database')->connection();

            try {
                if ($blockTypeHandle) {
                    // Resolve block type handle with normalized variants and suggestions.
                    $resolution = $this->resolveBlockTypeHandle((string) $blockTypeHandle, $db);
                    $bt = $resolution['blockType'];
                    if (!$bt) {
                        $message = "❌ Error: Block type '{$blockTypeHandle}' not found.";
                        if (!empty($resolution['suggestions'])) {
                            $message .= "\n\nDid you mean one of these handles?\n";
                            foreach ($resolution['suggestions'] as $suggestion) {
                                $message .= "- `{$suggestion}`\n";
                            }
                        }
                        return $message;
                    }

                    // Use Doctrine QueryBuilder
                    $qb = $db->createQueryBuilder();
                    $qb->select('b.bID', 'cvb.cID', 'cv.cvIsApproved')
                        ->from('Blocks', 'b')
                        ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
                        ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
                        ->where('b.btID = :btID')
                        ->setParameter('btID', $bt->getBlockTypeID());

                    if ($activeOnly) {
                        $qb->andWhere('cv.cvIsApproved = 1');
                    }

                    // P3.5-1: only blocks this user could read (search_blocks_by_type's rule), checked page by page,
                    // which is slower on a large site (P3.3-5).
                    $cache = [];
                    $readable = [];
                    foreach ($qb->execute()->fetchAllAssociative() as $row) {
                        if (self::mayRead((int) $row['cID'], (bool) $row['cvIsApproved'], $cache)) {
                            $readable[(int) $row['bID']] = true;
                        }
                    }
                    $result = count($readable);
                    
                    $status = $activeOnly ? "active" : "total";
                    $resolvedHandle = $bt->getBlockTypeHandle();
                    $prefix = ($resolution['resolvedFrom'] ?? null) && $resolution['resolvedFrom'] !== $resolvedHandle
                        ? "ℹ️ Resolved `{$resolution['resolvedFrom']}` to `{$resolvedHandle}`.\n"
                        : '';
                    return $prefix . "📊 **{$resolvedHandle}:** {$result} {$status} instance(s)";
                } else {
                    // Count all block types using Doctrine QueryBuilder
                    $qb = $db->createQueryBuilder();
                    $qb->select('bt.btHandle', 'bt.btName', 'b.bID', 'cvb.cID', 'cv.cvIsApproved')
                        ->from('BlockTypes', 'bt')
                        ->innerJoin('bt', 'Blocks', 'b', 'bt.btID = b.btID')
                        ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
                        ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID');

                    if ($activeOnly) {
                        $qb->where('cv.cvIsApproved = 1');
                    }

                    // P3.5-1: only blocks this user could read, as for one type.
                    $cache = [];
                    $byType = [];
                    foreach ($qb->execute()->fetchAllAssociative() as $row) {
                        if (!self::mayRead((int) $row['cID'], (bool) $row['cvIsApproved'], $cache)) {
                            continue;
                        }
                        $byType[$row['btHandle']]['btHandle'] = $row['btHandle'];
                        $byType[$row['btHandle']]['btName'] = $row['btName'];
                        $byType[$row['btHandle']]['ids'][(int) $row['bID']] = true;
                    }
                    $results = array_map(static fn (array $type): array => [
                        'btHandle' => $type['btHandle'],
                        'btName' => $type['btName'],
                        'count' => count($type['ids']),
                    ], array_values($byType));
                    usort($results, static fn (array $a, array $b): int => [$b['count'], $a['btName']] <=> [$a['count'], $b['btName']]);

                    if (empty($results)) {
                        return "No blocks found.";
                    }

                    $status = $activeOnly ? "Active" : "Total";
                    $output = "📊 **Block Usage Summary ({$status} Instances):**\n\n";
                    $output .= "| Block Type | Handle | Count |\n";
                    $output .= "|------------|--------|-------|\n";

                    foreach ($results as $row) {
                        $output .= sprintf(
                            "| %s | `%s` | %d |\n",
                            $row['btName'],
                            $row['btHandle'],
                            $row['count']
                        );
                    }

                    $output .= "\n💡 Use `search_blocks_by_type(blockTypeHandle)` to see where specific blocks are used\n";

                    return $output;
                }
            } catch (\Exception $e) {
                return "❌ Error counting blocks: " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Resolve a user-provided block type identifier to an installed block type.
     *
     * Supports exact handles first, then common normalizations (spaces/dashes/underscores),
     * and finally returns suggestions for close handles.
     *
     * @param string $input
     * @param mixed $db Doctrine DB connection
     * @return array{blockType: ?BlockType, resolvedFrom: ?string, suggestions: array<int, string>}
     */
    private function resolveBlockTypeHandle(string $input, $db): array
    {
        $requested = trim($input);
        if ($requested === '') {
            return ['blockType' => null, 'resolvedFrom' => null, 'suggestions' => []];
        }

        $variants = [];
        $lower = strtolower($requested);
        $variants[] = $requested;
        $variants[] = str_replace(' ', '_', $lower);
        $variants[] = str_replace('-', '_', $lower);
        $variants[] = str_replace(' ', '-', $lower);
        $variants[] = str_replace('_', '-', $lower);
        $variants[] = str_replace([' ', '-', '_'], '', $lower);

        $variants = array_values(array_unique(array_filter($variants, fn($v) => is_string($v) && $v !== '')));

        foreach ($variants as $variant) {
            $bt = BlockType::getByHandle($variant);
            if ($bt) {
                return [
                    'blockType' => $bt,
                    'resolvedFrom' => $requested,
                    'suggestions' => [],
                ];
            }
        }

        // Match by exact installed block name as a fallback for custom blocks whose
        // human-facing name is what the user knows, not the internal handle.
        try {
            $qb = $db->createQueryBuilder();
            $qb->select('btID', 'btHandle', 'btName')
                ->from('BlockTypes')
                ->where('LOWER(btName) = :exactName')
                ->setParameter('exactName', $lower)
                ->setMaxResults(1);

            $row = $qb->execute()->fetchAssociative();
            if (!empty($row['btHandle'])) {
                $bt = BlockType::getByHandle((string) $row['btHandle']);
                if ($bt) {
                    return [
                        'blockType' => $bt,
                        'resolvedFrom' => $requested,
                        'suggestions' => [],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Ignore exact-name fallback failures and continue to suggestions.
        }

        $suggestions = [];
        try {
            $needle = strtolower(str_replace([' ', '-'], '_', $requested));
            $tokens = preg_split('/[^a-z0-9]+/i', $needle) ?: [];
            $tokens = array_values(array_filter($tokens, fn($t) => $t !== '' && strlen($t) >= 3));

            $qb = $db->createQueryBuilder();
            $qb->select('btHandle', 'btName')
                ->from('BlockTypes')
                ->where('LOWER(btHandle) LIKE :needle OR LOWER(btName) LIKE :needle')
                ->setParameter('needle', '%' . $needle . '%')
                ->setMaxResults(10);

            $rows = $qb->execute()->fetchAllAssociative();
            foreach ($rows as $row) {
                if (!empty($row['btHandle'])) {
                    $suggestions[] = (string) $row['btHandle'] . ' (' . (string) ($row['btName'] ?? $row['btHandle']) . ')';
                }
            }

            if (empty($suggestions) && !empty($tokens)) {
                $qb = $db->createQueryBuilder();
                $orX = $qb->expr()->orX();
                foreach ($tokens as $i => $token) {
                    $param = 'token' . $i;
                    $orX->add('LOWER(btHandle) LIKE :' . $param . ' OR LOWER(btName) LIKE :' . $param);
                    $qb->setParameter($param, '%' . strtolower($token) . '%');
                }

                if (count($orX->getParts()) > 0) {
                    $qb->select('btHandle')
                        ->from('BlockTypes')
                        ->where($orX)
                        ->setMaxResults(10);

                    $rows = $qb->execute()->fetchAllAssociative();
                    foreach ($rows as $row) {
                        if (!empty($row['btHandle'])) {
                            $suggestions[] = (string) $row['btHandle'];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore suggestion lookup failures and return a simple not-found response.
        }

        $suggestions = array_values(array_unique($suggestions));

        return [
            'blockType' => null,
            'resolvedFrom' => $requested,
            'suggestions' => $suggestions,
        ];
    }

    protected function makeListContainerBlocksTool(): Tool
    {
        $tool = new CallableTool(
            'list_container_blocks',
            'List all blocks nested inside a specific container block (by container block ID)'
        );

        $tool->addProperty(new ToolProperty(
            'containerBlockID',
            PropertyType::INTEGER,
            'The block ID of the container block (e.g., 12704)',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'version',
            PropertyType::STRING,
            PageVersionRead::DESCRIPTION,
            false
        ));

        $tool->setHandler(function (?int $containerBlockID = null, ?string $version = null): string {
            if (!$containerBlockID) {
                return "❌ Error: containerBlockID is required";
            }
            try {
                $published = PageVersionRead::normalize($version) === PageVersionRead::PUBLISHED;
            } catch (\RuntimeException $e) {
                return '❌ Error: ' . $e->getMessage();
            }
            // P3.5-1: the container must be readable, and a user who can't view versions reads the published one.
            if (!self::denyUnlessReadable((int) $containerBlockID)) {
                $published = true;
            }

            $app = Application::getFacadeApplication();
            $db = $app->make('database')->connection();

            try {
                $containerInstanceID = (int) $db->fetchOne(
                    'SELECT containerInstanceID FROM btCoreContainer WHERE bID = ? LIMIT 1',
                    [$containerBlockID]
                );

                if ($containerInstanceID <= 0) {
                    return "❌ Block {$containerBlockID} is not a container block (no btCoreContainer mapping found).";
                }

                // Use Doctrine QueryBuilder to find all blocks in sub-areas of this container
                $qb = $db->createQueryBuilder();
                
                // Sub-areas for container instances are namespaced by parent area and containerInstanceID,
                // for example: "Middle : 13587 : Column 1"
                $likePattern = '% : ' . $containerInstanceID . ' : %';
                
                $qb->select(
                        'b.bID',
                        'bt.btHandle',
                        'bt.btName',
                        'cvb.arHandle',
                        'cvb.cID',
                        'cvb.cvID',
                        'cv.cvName as pageName',
                        'pp.cPath as pagePath',
                        'cv.cvIsApproved',
                        'b.bDateModified'
                    )
                    ->from('Blocks', 'b')
                    ->innerJoin('b', 'BlockTypes', 'bt', 'b.btID = bt.btID')
                    ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
                    ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
                    ->innerJoin('cvb', 'Pages', 'p', 'cvb.cID = p.cID')
                    ->leftJoin('p', 'PagePaths', 'pp', 'p.cID = pp.cID AND pp.ppIsCanonical = 1')
                    ->where($qb->expr()->like('cvb.arHandle', ':pattern'))
                    ->setParameter('pattern', $likePattern)
                    ->orderBy('cvb.arHandle', 'ASC')
                    ->addOrderBy('b.bDateModified', 'DESC');

                // BF-10: the newest version of the page by default (what editors see and the write tools change). A container
                // copied into a draft keeps its instance, so its sub-area handles are the same in every version.
                $qb->andWhere($published
                    ? 'cv.cvIsApproved = 1'
                    : 'cvb.cvID = (SELECT MAX(cvn.cvID) FROM CollectionVersions cvn WHERE cvn.cID = cvb.cID)');

                $results = $qb->execute()->fetchAllAssociative();

                if (empty($results)) {
                    return "📦 **Container Block #{$containerBlockID}:** No blocks found inside this container in the "
                        . ($published ? 'published' : 'newest') . " version of its page.\n\n" .
                           "💡 This could mean:\n" .
                           "- The container is empty\n" .
                           "- The block ID is not a container" . ($published ? "\n- The blocks are only in a newer, unpublished version (read without version \"published\")" : '');
                }

                $count = count($results);
                $output = "📦 **Container Block #{$containerBlockID} (instance #{$containerInstanceID}):** Found {$count} nested block(s)\n\n";
                $output .= 'Version: the ' . ($published ? 'published version' : 'newest version (what editors see and the write tools change)')
                    . " of the page.\n\n";
                $output .= "| Block ID | Type | Sub-Area | Full Area Handle | Page | Version |\n";
                $output .= "|----------|------|----------|------------------|------|----------|\n";

                foreach ($results as $row) {
                    $pageInfo = self::pageLink((int) $row['cID'], $row['pagePath']);
                    $status = $row['cvIsApproved'] ? '✅' : '📝';
                    
                    // Extract trailing display area name from "Parent : <instanceID> : <subArea>" format.
                    $subArea = $row['arHandle'];
                    $parts = explode(' : ', (string) $subArea);
                    if (count($parts) >= 3) {
                        $subArea = (string) end($parts);
                    } elseif (strpos((string) $subArea, ' : ') !== false) {
                        list(, $subArea) = explode(' : ', (string) $subArea, 2);
                    }
                    
                    $output .= sprintf(
                        "| %d | %s | %s | `%s` | %s | %s v%d |\n",
                        $row['bID'],
                        $row['btName'],
                        $subArea,
                        (string) $row['arHandle'],
                        $pageInfo,
                        $status,
                        $row['cvID']
                    );
                }

                $output .= "\n💡 **Next steps:**\n";
                $output .= "- Use `get_block_data(blockID)` to inspect raw field values\n";
                $output .= "- Use `get_block_controller_data(blockID, pageID, areaHandle)` for resolved content\n";
                $output .= "  - Prefer the **Full Area Handle** column when calling `get_block_controller_data`\n";

                return $output;
            } catch (\Exception $e) {
                return "❌ Error listing container blocks: " . $e->getMessage();
            }
        });

        return $tool;
    }
}
