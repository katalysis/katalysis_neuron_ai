<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\GlobalAreaFixture;
use Katalysis\NeuronAi\Tests\Support\PageGrantFixture;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BF-23 (Glyn, after P3.6a): every read tool that lists or returns a page's blocks shows the blocks of the page's
 * global areas, marked as shared (`globalArea` in a JSON result, "a global area" in a text one), to a user who may view
 * them, and not to one who may not. In P3.6a three of the five such tools missed them, and scenarios 39 and 40 failed
 * on two. A throwaway global area shown on a throwaway page; neuron_content_editor may view the page, and the "denied"
 * cases take View away from that user on the stack.
 */
final class GlobalAreaReadCoverageTest extends ConcreteTestCase
{
    private const TEXT = 'Shared global coverage text';

    /** Read tools that take a page but don't list or return its blocks, and why. */
    private const NOT_BLOCK_READS = [
        'get_page_children' => 'lists child pages',
        'list_page_versions' => 'lists versions, not their blocks',
    ];

    private GlobalAreaFixture $fixture;
    private PageGrantFixture $grants;

    protected function setUp(): void
    {
        ThrowawayPages::actAs('superuser');
        $this->fixture = new GlobalAreaFixture($this->app()->make(Connection::class));
        $this->grants = new PageGrantFixture($this->app());
    }

    protected function tearDown(): void
    {
        ThrowawayPages::actAs('superuser');
        $this->fixture->restore();
        $this->grants->restore();
        Request::getInstance()->setCustomRequestUser(-1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readToolProvider(): array
    {
        return array_combine(self::READS, array_map(static fn (string $t): array => [$t], self::READS));
    }

    /** Every read tool that lists or returns a page's blocks. */
    private const READS = [
        'get_page',
        'get_page_content',
        'get_page_blocks',
        'list_page_blocks_by_type',
        'get_block_data',
        'get_block_controller_data',
        'get_version_content',
    ];

    #[DataProvider('readToolProvider')]
    public function testTheToolShowsTheGlobalAreasBlocksMarkedShared(string $tool): void
    {
        [$inputs] = $this->setUpPage($tool);
        ThrowawayPages::actAs('neuron_content_editor');

        $output = ToolByName::run($tool, $inputs);

        $this->assertStringContainsString(self::TEXT, $output, "{$tool} doesn't show the global area's block: {$output}");
        $this->assertMatchesRegularExpression('/"globalArea"|\ba global area\b/', $output, "{$tool} doesn't mark it as a global area: {$output}");
    }

    #[DataProvider('readToolProvider')]
    public function testTheToolHidesThemFromAUserWhoMayNotViewTheStack(string $tool): void
    {
        [$inputs, $stackID] = $this->setUpPage($tool);
        $this->grants->takeAway($stackID, 'view_page', ['neuron_content_editor']);
        ThrowawayPages::actAs('neuron_content_editor');

        $output = ToolByName::run($tool, $inputs);

        $this->assertStringNotContainsString(self::TEXT, $output, "{$tool} showed a global area the user may not view: {$output}");
    }

    public function testEveryReadToolThatTakesAPageIsCovered(): void
    {
        $found = [];
        foreach (ToolByName::names() as $name) {
            if (ToolKind::isWrite($name)) {
                continue;
            }
            $properties = array_map(static fn ($p): string => $p->getName(), ToolByName::tool($name)->getProperties());
            if (array_intersect($properties, ['pageID', 'pagePath']) !== []) {
                $found[] = $name;
            }
        }
        sort($found);
        $covered = array_merge(self::READS, array_keys(self::NOT_BLOCK_READS));
        sort($covered);

        $this->assertSame($found, $covered, 'A read tool takes a page but is in neither READS nor NOT_BLOCK_READS: add it to '
            . 'READS if it lists or returns the page\'s blocks (and make it show the global areas\'), or say why not.');
    }

    /**
     * A global area holding one Content block, on a page under /neuron-test, and the tool's inputs.
     *
     * @return array{array<string, mixed>, int}
     */
    private function setUpPage(string $tool): array
    {
        [$handle, $stackID, $bID] = $this->fixture->globalArea('<p>' . self::TEXT . '</p>');
        $page = $this->fixture->page([$handle]);
        $pageID = (int) $page->getCollectionID();

        $inputs = match ($tool) {
            'list_page_blocks_by_type' => ['pageID' => $pageID, 'blockTypeHandle' => 'content'],
            'get_block_data', 'get_block_controller_data' => ['blockID' => $bID, 'pageID' => $pageID],
            'get_version_content' => ['pageID' => $pageID, 'versionID' => (int) $page->getVersionID()],
            default => ['pageID' => $pageID],
        };

        return [$inputs, $stackID];
    }
}
