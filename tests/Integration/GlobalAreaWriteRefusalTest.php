<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\GlobalAreaFixture;
use Katalysis\NeuronAi\Tools\AttributeSetsToolkit;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\FileAttributesToolkit;
use Katalysis\NeuronAi\Tools\FilesToolkit;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\PeopleToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;
use Katalysis\NeuronAi\Tools\TopicsToolkit;
use Katalysis\NeuronAi\Tools\UserAttributesToolkit;
use Katalysis\NeuronAi\Tools\UsersToolkit;
use Katalysis\NeuronAi\Tools\VersionsToolkit;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BF-2, B-WRT-5 (b): a write tool must not change a global area through an ordinary page that shows it. Concrete's
 * block paths redirect a global area to its stack and put the change in an unapproved stack version tied to the
 * page's draft, so approving that page draft would publish the change on every page that shows the area
 * (plans/BF-2.md 2.2). Until stack editing is enabled (P3.6a) every such call is refused before anything is made.
 *
 * A throwaway global area and pages from GlobalAreaFixture, as the superuser (whose permissions allow everything).
 */
final class GlobalAreaWriteRefusalTest extends ConcreteTestCase
{
    private const REFUSAL = "can't edit stacks or global areas yet";

    /** Inputs that name an area, or carry entries that do. */
    private const AREA_INPUTS = ['areaHandle', 'fromAreaHandle', 'toAreaHandle', 'blocks'];

    /** Area inputs that only filter what a tool reads from the page's own blocks (see the headings test). */
    private const AREA_FILTERS = ['areaHandleContains'];

    private GlobalAreaFixture $fixture;

    protected function setUp(): void
    {
        $this->actAs('superuser');
        $this->fixture = new GlobalAreaFixture($this->db());
    }

    protected function tearDown(): void
    {
        $this->actAs('superuser');
        $this->fixture->restore();
        Request::getInstance()->setCustomRequestUser(-1);
    }

    /**
     * @return array<string, array{string, string}> case => [tool, area input]
     */
    public static function blockToolProvider(): array
    {
        return [
            'update_block_in_page_area' => ['update_block_in_page_area', 'areaHandle'],
            'delete_block_from_page_area' => ['delete_block_from_page_area', 'areaHandle'],
            'add_block_to_page_area' => ['add_block_to_page_area', 'areaHandle'],
            'move_block_between_areas (from the global area)' => ['move_block_between_areas', 'fromAreaHandle'],
            'move_block_between_areas (into the global area)' => ['move_block_between_areas', 'toAreaHandle'],
            'batch_update_page_content' => ['batch_update_page_content', 'blocks'],
        ];
    }

    #[DataProvider('blockToolProvider')]
    public function testTheToolRefusesAGlobalAreaAndMakesNothing(string $tool, string $areaInput): void
    {
        [$handle, $stackID, $stackBlockID] = $this->fixture->globalArea();
        $page = $this->fixture->page([$handle]);
        $pageBlockID = (int) $page->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => '<p>Page block</p>'])->getBlockID();
        $pageID = (int) $page->getCollectionID();
        $stackBefore = $this->fixture->versions($stackID);
        $pageBefore = $this->fixture->versions($pageID);

        $inputs = match ($areaInput) {
            'areaHandle' => match ($tool) {
                'add_block_to_page_area' => ['pageID' => $pageID, 'areaHandle' => $handle, 'blockType' => 'content', 'value' => ['content' => '<p>Added by the tool</p>']],
                'update_block_in_page_area' => ['pageID' => $pageID, 'areaHandle' => $handle, 'blockID' => $stackBlockID, 'value' => ['content' => '<p>Changed by the tool</p>']],
                default => ['pageID' => $pageID, 'areaHandle' => $handle, 'blockID' => $stackBlockID],
            },
            'fromAreaHandle' => ['pageID' => $pageID, 'fromAreaHandle' => $handle, 'toAreaHandle' => 'Main', 'blockID' => $stackBlockID],
            'toAreaHandle' => ['pageID' => $pageID, 'fromAreaHandle' => 'Main', 'toAreaHandle' => $handle, 'blockID' => $pageBlockID],
            'blocks' => ['pageID' => $pageID, 'blocks' => [['areaHandle' => $handle, 'blockID' => $stackBlockID, 'value' => ['content' => '<p>Batch</p>']]]],
        };

        // P3.3-4: the batch tool is in BatchEditToolkit, the block tools in ContentToolkit.
        $toolkit = $tool === 'batch_update_page_content' ? new BatchEditToolkit() : new ContentToolkit();
        $output = $this->runTool($toolkit, $tool, $inputs);

        $this->assertStringContainsString(self::REFUSAL, $output, "{$tool} did not refuse a global area. It returned: {$output}");
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame($stackBefore, $this->fixture->versions($stackID), "{$tool} changed the global area's stack.");
        $this->assertSame($pageBefore, $this->fixture->versions($pageID), "{$tool} left a draft (or another change) on the page.");
    }

    public function testHeadingUpdatesNeverReachAGlobalArea(): void
    {
        [$handle, $stackID] = $this->fixture->globalArea('<h3>Global Heading</h3><p>Shared text.</p>');
        $page = $this->fixture->page([$handle]);
        $stackBefore = $this->fixture->versions($stackID);

        $this->runTool(new BatchEditToolkit(), 'batch_update_content_headings', [
            'pageID' => (int) $page->getCollectionID(),
            'headingTexts' => ['Global Heading'],
            'targetTag' => 'h2',
        ]);

        $this->assertSame($stackBefore, $this->fixture->versions($stackID), 'batch_update_content_headings changed a global area.');
    }

    public function testTheBlockLookupsTheToolsShareRefuseAGlobalArea(): void
    {
        [$handle, , $stackBlockID] = $this->fixture->globalArea();
        $page = $this->fixture->page([$handle]);
        // P3.3-4: both lookups are ContentToolkit's (getBlockToEdit through BlockWriteSupport, which BatchEditToolkit
        // shares).
        $toolkit = new ContentToolkit();

        foreach (['getBlockToWorkWith', 'getBlockToEdit'] as $method) {
            try {
                if ($method === 'getBlockToWorkWith') {
                    $toolkit->getBlockToWorkWith($page, $handle, $stackBlockID);
                } else {
                    $toolkit->getBlockToEdit($page, Area::get($page, $handle), $handle, $stackBlockID);
                }
                $this->fail("{$method} did not refuse a global area.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString(self::REFUSAL, $e->getMessage(), $method);
            }
        }
    }

    public function testEveryWriteToolWithAnAreaInputIsCovered(): void
    {
        $covered = [];
        foreach (self::blockToolProvider() as [$tool, $input]) {
            $covered[$tool][] = $input;
        }

        $found = [];
        foreach ($this->allToolkits() as $toolkit) {
            foreach ($toolkit->tools() as $tool) {
                if (!$tool instanceof ToolInterface || !ToolKind::isWrite($tool->getName())) {
                    continue;
                }
                foreach ($tool->getProperties() as $property) {
                    $name = $property->getName();
                    if (in_array($name, self::AREA_FILTERS, true)) {
                        continue;
                    }
                    if (in_array($name, self::AREA_INPUTS, true) || preg_match('/area/i', $name) === 1) {
                        $found[$tool->getName()][] = $name;
                    }
                }
            }
        }

        ksort($covered);
        ksort($found);
        foreach ($covered as &$inputs) {
            $inputs = array_values(array_unique($inputs));
        }
        $this->assertSame($found, $covered, 'A write tool takes an area but is not in blockToolProvider(), so nothing checks that it refuses a '
            . 'global area. Add a case (and make it call SystemPageGuard::refuseGlobalArea()), or list a read-only filter in AREA_FILTERS.');
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function runTool(ToolkitInterface $toolkit, string $name, array $inputs): string
    {
        foreach ($toolkit->tools() as $tool) {
            if ($tool->getName() !== $name) {
                continue;
            }
            $tool = clone $tool;
            try {
                $tool->setInputs($inputs)->execute();

                return (string) $tool->getResult();
            } catch (\Throwable $e) {
                // What the agent's tool error handler gives the model (ConcreteCmsAgent).
                return 'Error executing tool: ' . $e->getMessage();
            }
        }
        $this->fail("No tool named {$name} in " . $toolkit::class);
    }

    /**
     * @return list<ToolkitInterface>
     */
    private function allToolkits(): array
    {
        return [
            new PagesToolkit(), new ContentToolkit(), new BatchEditToolkit(), new BlocksToolkit(), new FilesToolkit(), new UsersToolkit(), new PageAttributesToolkit(),
            new UserAttributesToolkit(), new FileAttributesToolkit(), new AttributeSetsToolkit(), new TopicsToolkit(),
            new PeopleToolkit(), new VersionsToolkit(),
        ];
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
        $this->app()->make('cache/request')->flush();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
