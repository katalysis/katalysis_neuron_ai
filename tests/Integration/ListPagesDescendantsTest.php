<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * P3.6-2 (plans/P3.6-2.md §6): scenario 09's wrong-page write. `list_pages` with includeDescendants=true passed
 * "/parent/%" to Concrete's filterByPath(), which adds "/%" itself, so only grandchildren came back. Asked for
 * everything under /neuron-test, the model saw Move Me Child only, decided Move Target didn't exist, created a second
 * one and moved the child. Read-only; no AI provider call. Needs the seeded dev site.
 */
final class ListPagesDescendantsTest extends ConcreteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    /** @return list<string> */
    private function paths(array $inputs): array
    {
        $output = ToolByName::run('list_pages', $inputs + ['limit' => 100]);
        $this->assertTrue(ToolKind::succeeded($output), $output);

        return array_column(json_decode($output, true)['pages'], 'path');
    }

    public function testDescendantsIncludeTheDirectChildrenAndTheirChildren(): void
    {
        $paths = $this->paths(['parentPath' => '/neuron-test', 'includeDescendants' => true]);

        $this->assertContains('/neuron-test/move-me', $paths, 'a direct child');
        $this->assertContains('/neuron-test/move-target', $paths, 'a direct child');
        $this->assertContains('/neuron-test/move-me/move-me-child', $paths, 'a grandchild');
        $this->assertNotContains('/neuron-test', $paths, 'not the parent itself');
    }

    public function testDescendantsByParentIDMatchByPath(): void
    {
        $byPath = $this->paths(['parentPath' => '/neuron-test', 'includeDescendants' => true]);
        $parentID = (int) \Concrete\Core\Page\Page::getByPath('/neuron-test')->getCollectionID();
        $byID = $this->paths(['parentID' => $parentID, 'includeDescendants' => true]);
        sort($byPath);
        sort($byID);

        $this->assertSame($byPath, $byID);
    }

    public function testDescendantsOfTheHomePageIncludeItsChildren(): void
    {
        $this->assertContains('/neuron-test', $this->paths(['parentPath' => '/', 'includeDescendants' => true]));
    }
}
