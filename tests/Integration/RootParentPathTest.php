<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * BF-18: `parentPath` "/" is the home page in `list_pages` and `get_page_children`, as PageContextResolver resolves it
 * (BF-1). Concrete stores the home page with an empty path, so Page::getByPath('/') found nothing and both tools said
 * "Parent page not found at path: /" (scenario 15, 2 of 5 runs in P3.S1). Read-only; no AI provider call.
 */
final class RootParentPathTest extends ConcreteTestCase
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

    /**
     * @return array<string, mixed>
     */
    private function call(string $tool, array $inputs): array
    {
        $output = ThrowawayPages::runTool(new PagesToolkit(), $tool, $inputs);
        $this->assertTrue(ToolKind::succeeded($output), "{$tool}: {$output}");
        $data = json_decode($output, true);
        $this->assertIsArray($data, $output);

        return $data;
    }

    public function testListPagesWithParentPathSlashListsTheHomePagesChildren(): void
    {
        // Scenario 15's call.
        $result = $this->call('list_pages', ['parentPath' => '/', 'includeDescendants' => false, 'limit' => 100]);

        $this->assertContains('/neuron-test', array_column($result['pages'], 'path'), 'a child of the home page');
    }

    public function testGetPageChildrenWithParentPathSlashGivesTheHomePagesChildren(): void
    {
        $result = $this->call('get_page_children', ['parentPath' => '/']);

        $this->assertContains('/neuron-test', array_column($result['children'], 'path'), 'a child of the home page');
    }
}
