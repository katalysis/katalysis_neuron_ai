<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * P3.6-2 (plans/P3.6-2.md §6): scenario 09's wrong-page write made a second "Move Target", which Concrete quietly
 * placed at /neuron-test/move-target-1. create_page now refuses a page whose path already exists under the parent,
 * and points to the page that is there. Needs the seeded dev site. No AI provider call.
 */
final class CreatePageDuplicateTest extends ConcreteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        // A page the old behaviour made: removed, so the leak check stays clean.
        foreach (['/neuron-test/move-target-1'] as $path) {
            $page = Page::getByPath($path);
            if ($page && !$page->isError()) {
                $cID = (int) $page->getCollectionID();
                $page->delete();
                $this->app()->make(Connection::class)->executeStatement('DELETE FROM PageSummaryTemplates WHERE cID = ?', [$cID]);
            }
        }
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    public function testAPageWhosePathExistsIsRefusedAndNothingIsMade(): void
    {
        $parent = Page::getByPath('/neuron-test');
        $children = count($parent->getCollectionChildrenArray());

        $output = ToolByName::run('create_page', ['parentID' => (int) $parent->getCollectionID(), 'name' => 'Move Target']);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('/neuron-test/move-target', $output, 'it names the page that is there');
        $this->assertStringContainsString('nothing was created', strtolower($output));
        $this->assertSame($children, count(Page::getByPath('/neuron-test')->getCollectionChildrenArray()));
        $missing = Page::getByPath('/neuron-test/move-target-1');
        $this->assertTrue(!$missing || $missing->isError(), 'no -1 page');
    }

    public function testTheCheckIgnoresCase(): void
    {
        $parent = Page::getByPath('/neuron-test');

        $output = ToolByName::run('create_page', ['parentID' => (int) $parent->getCollectionID(), 'name' => 'move target']);

        $this->assertFalse(ToolKind::succeeded($output), $output);
    }
}
