<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Core\Support\Facade\Application;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * Throwaway pages under /neuron-test with Content blocks and one approved version, and Concrete's edit-mode checkout
 * of them (BF-7). restore() checks the pages in and deletes them, so the leak check finds nothing.
 */
final class ThrowawayPages
{
    /** @var int[] */
    private array $ids = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * A page with one Content block per entry of $contents in Main, made as the superuser. Leaves the request acting
     * as the superuser.
     *
     * @param list<string> $contents
     */
    public function create(string $label, array $contents = ['<p>Block one original text.</p>', '<p>Block two original text.</p>']): Page
    {
        self::actAs('superuser');
        $parent = Page::getByPath('/neuron-test');
        if (!$parent || $parent->isError()) {
            throw new \RuntimeException('/neuron-test does not exist. Run the seed script first.');
        }
        $template = PageTemplate::getByHandle('full');
        $unique = bin2hex(random_bytes(3));
        $page = $parent->add(PageType::getByHandle('page'), [
            'cName' => $label . ' ' . $unique,
            'cHandle' => 'bf7-' . strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $label)) . '-' . $unique,
            'pTemplateID' => $template->getPageTemplateID(),
        ], $template);
        $this->ids[] = (int) $page->getCollectionID();
        $content = BlockType::getByHandle('content');
        foreach ($contents as $html) {
            $page->addBlock($content, 'Main', ['content' => $html]);
        }
        CacheLocal::flush();

        return Page::getByID($page->getCollectionID());
    }

    /**
     * The block IDs in Main of the page's newest version, in display order.
     *
     * @return list<int>
     */
    public function blockIDs(int $cID): array
    {
        CacheLocal::flush();
        $cvID = (int) Page::getByID($cID, 'RECENT')->getVersionID();

        return array_map('intval', $this->db->fetchFirstColumn(
            'SELECT bID FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? AND arHandle = ? ORDER BY cbDisplayOrder',
            [$cID, $cvID, 'Main']
        ));
    }

    /**
     * What the toolbar's Edit button writes (User::loadCollectionEdit()), without its session steps. $secondsAgo sets
     * the last-edit time, which edit mode's heartbeat keeps fresh; null leaves it empty.
     */
    public function checkOut(int $cID, int $uID, ?int $secondsAgo = 0): void
    {
        $now = date('Y-m-d H:i:s');
        $lastEdit = $secondsAgo === null ? null : date('Y-m-d H:i:s', time() - $secondsAgo);
        $this->db->executeStatement(
            'UPDATE Pages SET cIsCheckedOut = 1, cCheckedOutUID = ?, cCheckedOutDatetime = ?, cCheckedOutDatetimeLastEdit = ? WHERE cID = ?',
            [$uID, $now, $lastEdit, $cID]
        );
        CacheLocal::flush();
    }

    /**
     * @return array<string, mixed> the page's checkout columns
     */
    public function checkoutRow(int $cID): array
    {
        return $this->db->fetchAssociative(
            'SELECT cIsCheckedOut, cCheckedOutUID, cCheckedOutDatetime, cCheckedOutDatetimeLastEdit FROM Pages WHERE cID = ?',
            [$cID]
        ) ?: [];
    }

    /**
     * Versions and blocks: what any page write changes.
     *
     * @return array<string, mixed>
     */
    public function state(int $cID): array
    {
        return [
            'versions' => $this->db->fetchAllAssociative('SELECT cvID, cvName, cvIsApproved FROM CollectionVersions WHERE cID = ? ORDER BY cvID', [$cID]),
            'blocks' => $this->db->fetchAllAssociative('SELECT cvID, bID, arHandle FROM CollectionVersionBlocks WHERE cID = ? ORDER BY cvID, bID', [$cID]),
            'page' => $this->db->fetchAssociative('SELECT cParentID, cIsActive FROM Pages WHERE cID = ?', [$cID]),
            'attributes' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM CollectionAttributeValues WHERE cID = ?', [$cID]),
        ];
    }

    /**
     * Adds a page made some other way (for example by create_page) to the pages restore() deletes.
     */
    public function adopt(int $cID): void
    {
        $this->ids[] = $cID;
    }

    public function restore(): void
    {
        self::actAs('superuser');
        foreach (array_reverse($this->ids) as $cID) {
            $this->db->executeStatement(
                'UPDATE Pages SET cIsCheckedOut = 0, cCheckedOutUID = NULL, cCheckedOutDatetime = NULL, cCheckedOutDatetimeLastEdit = NULL WHERE cID = ?',
                [$cID]
            );
            CacheLocal::flush();
            $page = Page::getByID($cID);
            if ($page && !$page->isError()) {
                $page->delete();
            }
            // Approving a page adds a PageSummaryTemplates row, and Page::delete() does not remove it.
            $this->db->executeStatement('DELETE FROM PageSummaryTemplates WHERE cID = ?', [$cID]);
        }
        $this->ids = [];
        Request::getInstance()->setCustomRequestUser(-1);
    }

    public static function actAs(string $who): int
    {
        $repository = Application::getFacadeApplication()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        if ($info === null) {
            throw new \RuntimeException("The user \"{$who}\" does not exist. Run the seed script first.");
        }
        Request::getInstance()->setCustomRequestUser($info);
        Application::getFacadeApplication()->make('cache/request')->flush();
        CacheLocal::flush();

        return (int) $info->getUserID();
    }

    /**
     * Runs a tool as the agent would. Returns its result, or what it threw, worded as the agent's error handler words
     * it for the model.
     */
    public static function runTool(ToolkitInterface $toolkit, string $name, array $inputs): string
    {
        CacheLocal::flush();
        foreach ($toolkit->tools() as $tool) {
            if ($tool->getName() !== $name) {
                continue;
            }
            $tool = clone $tool;
            try {
                $tool->setInputs($inputs)->execute();
                $result = $tool->getResult();

                return is_string($result) ? $result : $result->getText();
            } catch (\Throwable $e) {
                return 'Error executing tool: ' . $e->getMessage();
            }
        }

        throw new \RuntimeException("No tool named {$name} in " . $toolkit::class);
    }
}
