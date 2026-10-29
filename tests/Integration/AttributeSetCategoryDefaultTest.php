<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BF-21 (found in P3.6-2): the attribute-set tools say `category` defaults to collection, but Neuron passes null for
 * an omitted input, so the closure's default never applied and add_attribute_to_set failed with "Invalid category ''".
 * Each tool is called without `category`, with handles that don't exist, so nothing is written: the refusal must be
 * about the handle, in the collection category, not about an empty category.
 */
final class AttributeSetCategoryDefaultTest extends ConcreteTestCase
{
    private const MISSING = 'neuron_bf21_no_such_handle';

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
     * @return array<string, array{string, array<string, string>}>
     */
    public static function writeTools(): array
    {
        return [
            'add_attribute_to_set' => ['add_attribute_to_set', ['attribute_handle' => self::MISSING, 'set_handle' => self::MISSING]],
            'remove_attribute_from_set' => ['remove_attribute_from_set', ['attribute_handle' => self::MISSING]],
            'delete_attribute_set' => ['delete_attribute_set', ['handle' => self::MISSING]],
        ];
    }

    /**
     * @param array<string, string> $inputs
     */
    #[DataProvider('writeTools')]
    public function testWithoutACategoryTheToolUsesCollection(string $tool, array $inputs): void
    {
        $output = ToolByName::run($tool, $inputs);

        $this->assertFalse(ToolKind::succeeded($output), 'the handle does not exist');
        $this->assertStringNotContainsString("category ''", $output);
        $this->assertStringContainsString(self::MISSING, $output, 'refused for the handle, after the category was found');
    }

    public function testListingSetsWithoutACategoryListsThePageSets(): void
    {
        $output = ToolByName::run('list_attribute_sets', []);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('"category":"collection"', $output);
    }
}
