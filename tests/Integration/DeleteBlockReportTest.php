<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\GlobalAreaFixture;
use Katalysis\NeuronAi\Tools\ContentToolkit;

/**
 * BF-2: delete_block_from_page_area reports only what happened. Before BF-2 it answered `deleted: true` whatever the
 * delete command did; on a global area the command found nothing and the block stayed (plans/BF-2.md 2.2).
 *
 * The global area is refused now, so the case is made directly: the toolkit's draft seam (getDraftOf()) is pointed at
 * another page's draft, so the delete command finds no such block there. The tool must report an error.
 */
final class DeleteBlockReportTest extends ConcreteTestCase
{
    private GlobalAreaFixture $fixture;

    protected function setUp(): void
    {
        $this->actAs('superuser');
        $this->fixture = new GlobalAreaFixture($this->app()->make(Connection::class));
    }

    protected function tearDown(): void
    {
        $this->actAs('superuser');
        $this->fixture->restore();
        Request::getInstance()->setCustomRequestUser(-1);
    }

    public function testADeleteThatRemovedNothingIsAnError(): void
    {
        $page = $this->fixture->page();
        $blockID = (int) $page->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => '<p>Keep me</p>'])->getBlockID();
        $elsewhere = $this->fixture->page();

        $toolkit = new class ($elsewhere) extends ContentToolkit {
            public function __construct(private readonly Page $elsewhere)
            {
            }

            protected function getDraftOf(Page $page): Page
            {
                return parent::getDraftOf($this->elsewhere);
            }
        };

        $output = $this->runDelete($toolkit, ['pageID' => (int) $page->getCollectionID(), 'areaHandle' => 'Main', 'blockID' => $blockID]);

        $this->assertStringNotContainsString('"deleted":true', $output);
        $this->assertStringContainsString("Block {$blockID} was not deleted", $output);
    }

    public function testARealDeleteStillReportsDeleted(): void
    {
        $page = $this->fixture->page();
        $blockID = (int) $page->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => '<p>Delete me</p>'])->getBlockID();

        $output = $this->runDelete(new ContentToolkit(), ['pageID' => (int) $page->getCollectionID(), 'areaHandle' => 'Main', 'blockID' => $blockID]);

        $this->assertStringContainsString('"deleted":true', $output);
    }

    private function runDelete(ContentToolkit $toolkit, array $inputs): string
    {
        foreach ($toolkit->tools() as $tool) {
            if ($tool->getName() === 'delete_block_from_page_area') {
                $tool = clone $tool;
                try {
                    $tool->setInputs($inputs)->execute();

                    return (string) $tool->getResult();
                } catch (\Throwable $e) {
                    return 'Error executing tool: ' . $e->getMessage();
                }
            }
        }
        $this->fail('No delete_block_from_page_area tool');
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        Request::getInstance()->setCustomRequestUser($info);
        $this->app()->make('cache/request')->flush();
    }
}
