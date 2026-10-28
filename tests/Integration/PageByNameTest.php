<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Attribute\Key\CollectionKey;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\GlobalAreaFixture;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
use Katalysis\NeuronAi\Tools\Utilities\PageByName;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BF-2: finding a page by name for set_page_attribute_value (plans/BF-2.md 3.3). Before BF-2 the case-insensitive
 * and partial steps passed an array to the query builder's where() and always failed with a SQL error, and the first
 * step was a substring match, so an exact name could be refused as ambiguous.
 *
 * Throwaway pages under /neuron-test with unique names (GlobalAreaFixture), as neuron_editor.
 */
final class PageByNameTest extends ConcreteTestCase
{
    private GlobalAreaFixture $fixture;
    private string $unique;

    protected function setUp(): void
    {
        $this->actAs('superuser');
        $this->fixture = new GlobalAreaFixture($this->app()->make(Connection::class));
        $this->unique = bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        $this->actAs('superuser');
        $this->fixture->restore();
        Request::getInstance()->setCustomRequestUser(-1);
    }

    private function named(string $name): int
    {
        $this->actAs('superuser');
        $page = $this->fixture->page([], $name);
        // GlobalAreaFixture adds a random suffix; give the page exactly this name.
        $page->update(['cName' => $name]);

        return (int) $page->getCollectionID();
    }

    public function testAnExactNameIsFound(): void
    {
        $id = $this->named("BF2 Exact {$this->unique}");
        $this->actAs('neuron_editor');

        $this->assertSame($id, (int) PageByName::find("BF2 Exact {$this->unique}")->getCollectionID());
    }

    public function testCaseIsIgnored(): void
    {
        $id = $this->named("BF2 Mixed Case {$this->unique}");
        $this->actAs('neuron_editor');

        $this->assertSame($id, (int) PageByName::find(strtolower("BF2 Mixed Case {$this->unique}"))->getCollectionID());
    }

    public function testPartOfANameIsFound(): void
    {
        $id = $this->named("BF2 Partial {$this->unique} Page");
        $this->actAs('neuron_editor');

        $this->assertSame($id, (int) PageByName::find("partial {$this->unique}")->getCollectionID());
    }

    public function testAnExactNameWinsOverNamesThatContainIt(): void
    {
        $exact = $this->named("BF2 Winner {$this->unique}");
        $this->named("BF2 Winner {$this->unique} Two");
        $this->actAs('neuron_editor');

        $this->assertSame($exact, (int) PageByName::find("BF2 Winner {$this->unique}")->getCollectionID());
    }

    public function testSeveralMatchesAreRefusedAsAmbiguous(): void
    {
        $this->named("BF2 Twin {$this->unique} A");
        $this->named("BF2 Twin {$this->unique} B");
        $this->actAs('neuron_editor');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Multiple pages found');
        PageByName::find("BF2 Twin {$this->unique}");
    }

    public function testNoMatchIsNotFoundNotASqlError(): void
    {
        $this->actAs('neuron_editor');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No page found matching name 'BF2 Nothing {$this->unique}'");
        PageByName::find("BF2 Nothing {$this->unique}");
    }

    public function testASystemPageIsNeverFound(): void
    {
        $this->actAs('superuser');
        $name = (string) Page::getByPath('/dashboard/system')->getCollectionName();

        try {
            $page = PageByName::find($name);
            $this->assertFalse((bool) $page->isSystemPage(), "found the system page {$name}");
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/No page found|Multiple pages found/', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{class-string<ToolkitInterface>}>
     */
    public static function toolkitProvider(): array
    {
        return ['PageAttributesToolkit' => [PageAttributesToolkit::class]];
    }

    /**
     * @param class-string<ToolkitInterface> $toolkit
     */
    #[DataProvider('toolkitProvider')]
    public function testSetPageAttributeValueFindsALowerCaseNameAndWritesADraft(string $toolkit): void
    {
        $id = $this->named("BF2 Attribute {$this->unique}");
        $this->actAs('neuron_editor');

        $output = '';
        foreach ((new $toolkit())->tools() as $tool) {
            if ($tool->getName() === 'set_page_attribute_value') {
                $tool = clone $tool;
                try {
                    $tool->setInputs(['pageName' => strtolower("BF2 Attribute {$this->unique}"), 'handle' => 'meta_title', 'value' => 'BF2 title'])->execute();
                    $output = (string) $tool->getResult();
                } catch (\Throwable $e) {
                    $output = 'Error executing tool: ' . $e->getMessage();
                }
            }
        }

        $this->assertStringContainsString('"success":true', $output, $output);
        $key = CollectionKey::getByHandle('meta_title');
        $this->assertSame('BF2 title', (string) Page::getByID($id, 'RECENT')->getAttribute($key));
        $this->assertNotSame('BF2 title', (string) Page::getByID($id, 'ACTIVE')->getAttribute($key), 'the live version must not change');
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
        $this->app()->make('cache/request')->flush();
    }
}
