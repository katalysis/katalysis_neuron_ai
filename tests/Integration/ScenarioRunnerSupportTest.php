<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Eval\ScenarioLoader;
use Katalysis\NeuronAi\Tests\Eval\SnapshotBuilder;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;

/**
 * Parts of the scenario runner (P1.4) that need Concrete. Read-only: no test here writes to the
 * dev site, calls the AI provider or uses an API key.
 */
final class ScenarioRunnerSupportTest extends ConcreteTestCase
{
    public function testEveryScenarioFileLoadsAndMatchesTheVocabulary(): void
    {
        $scenarios = ScenarioLoader::loadAll(dirname(__DIR__) . '/Scenarios');

        $this->assertCount(20, $scenarios);
        foreach ($scenarios as $id => $scenario) {
            $this->assertSame($id, $scenario['id']);
            $this->assertNotEmpty($scenario['turns']);
        }
        $this->assertArrayHasKey('09-move-page-with-descendants', $scenarios);
        $this->assertSame('superuser', $scenarios['09-move-page-with-descendants']['user']);

        // Scenario 19 moves the user to another page between turns (per-turn current_page).
        $turns = $scenarios['19-page-context-navigation']['turns'];
        $this->assertArrayNotHasKey('current_page', $turns[0]);
        $this->assertSame('/neuron-test/columns', $turns[1]['current_page']);
        $this->assertSame('/neuron-test/columns', $turns[2]['current_page']);

        // Scenario 20 reads on one page, then edits "this page" after moving to another.
        $scenario20 = $scenarios['20-navigate-then-edit'];
        $this->assertSame('/neuron-test/headings', $scenario20['current_page']);
        $this->assertArrayNotHasKey('current_page', $scenario20['turns'][0]);
        $this->assertSame('/neuron-test/columns', $scenario20['turns'][1]['current_page']);
    }

    private function builder(): SnapshotBuilder
    {
        return new SnapshotBuilder($this->app()->make(Connection::class), (int) Page::getHomePageID());
    }

    public function testASnapshotIsStableAndReadOnly(): void
    {
        $first = $this->builder()->take();
        $second = $this->builder()->take();

        $this->assertTrue($second->identicalTo($first), 'two snapshots with nothing between them must be identical');
        $this->assertSame([], $second->changedPageIDs($first));
        $this->assertNotNull($first->pageByPath('/'), 'the home page is recorded as /');
        $this->assertGreaterThan(1, count($first->pages()));
    }

    public function testFixturePagesGetBlocksAttributesAndRecordedChildren(): void
    {
        $snapshot = $this->builder()->take();
        $root = $snapshot->pageByPath('/neuron-test');
        if ($root === null) {
            $this->markTestSkipped('The dev site has no /neuron-test fixture. Run documentation/fixtures/seed_test_content.php first.');
        }

        $this->assertNotEmpty($root['children']);
        $headings = $snapshot->pageByPath('/neuron-test/headings');
        $this->assertNotNull($headings);
        $this->assertSame(3, count(array_filter($headings['latestBlocks'], static fn (array $b): bool => $b['type'] === 'content')));
        $this->assertStringContainsString('<h', implode('', array_column(array_column($headings['latestBlocks'], 'data'), 'content')));
        $this->assertArrayHasKey('approvedBlocks', $headings);

        $columns = $snapshot->pageByPath('/neuron-test/columns');
        $this->assertNotNull($columns);
        $areas = array_unique(array_column($columns['latestBlocks'], 'area'));
        $this->assertNotEmpty(array_filter($areas, static fn (string $a): bool => str_ends_with($a, ' : Column 1')), 'container sub-areas are named "... : Column 1"');
    }

    public function testPagesOutsideTheFixtureAreRecordedWithoutBlockDetail(): void
    {
        $home = $this->builder()->take()->pageByPath('/');

        $this->assertNotNull($home);
        $this->assertArrayNotHasKey('latestBlocks', $home);
    }

    /**
     * Guards the one-condition change to ConcreteCmsAgent::getCurrentContext() made for P1.4: under the CLI the
     * agent builds page context only when the caller passes a runtime page context. Outside the CLI the
     * condition is always true, so the browser path is unchanged.
     */
    public function testAgentBuildsPageContextUnderTheCliOnlyWhenGivenARuntimeContext(): void
    {
        $homeID = (int) Page::getHomePageID();
        $context = new \ReflectionMethod(ConcreteCmsAgent::class, 'getCurrentContext');
        $context->setAccessible(true);

        $this->assertSame('cli', PHP_SAPI);

        $without = new ConcreteCmsAgent();
        $this->assertStringNotContainsString('Current Page', $context->invoke($without));

        $with = new ConcreteCmsAgent();
        $with->setRuntimePageContext(['id' => $homeID]);
        $this->assertStringContainsString('Current Page ID: ' . $homeID, $context->invoke($with));
    }
}
