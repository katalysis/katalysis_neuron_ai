<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tests\Support\ToolListChecks;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\LegacyTool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

/**
 * Proves that the checks used by the registry test can fail, using synthetic
 * tools. No Concrete, no database.
 */
final class ToolListChecksTest extends TestCase
{
    public function testDuplicateNamesAreReported(): void
    {
        $tools = [
            new LegacyTool('list_people', 'core'),
            new LegacyTool('get_page', 'unique'),
            new LegacyTool('list_people', 'external'),
        ];

        $this->assertSame(['list_people'], ToolListChecks::duplicateNames($tools));
    }

    public function testADuplicateIsReportedOnceEvenWhenRepeated(): void
    {
        $tools = [new LegacyTool('a', 'x'), new LegacyTool('a', 'y'), new LegacyTool('a', 'z'), new LegacyTool('b', 'x'), new LegacyTool('b', 'y')];

        $this->assertSame(['a', 'b'], ToolListChecks::duplicateNames($tools));
    }

    public function testUniqueNamesReportNothing(): void
    {
        $this->assertSame([], ToolListChecks::duplicateNames([new LegacyTool('a', 'x'), new LegacyTool('b', 'x')]));
        $this->assertSame([], ToolListChecks::duplicateNames([]));
    }

    public function testArrayPropertyWithoutItemsIsReported(): void
    {
        $tools = [
            new LegacyTool('bad_tool', 'd', [
                new ToolProperty('name', PropertyType::STRING, 'd'),
                new ToolProperty('options', PropertyType::ARRAY, 'bare array, no items'),
            ]),
        ];

        $this->assertSame(['bad_tool.options'], ToolListChecks::arraysWithoutItems($tools));
    }

    public function testArrayPropertiesThatDeclareItemsAreNotReported(): void
    {
        $tools = [
            new LegacyTool('good_tool', 'd', [
                // ArrayProperty adds an item type (string by default).
                new ArrayProperty('plain', 'd'),
                new ArrayProperty('typed', 'd', false, new ToolProperty('item', PropertyType::INTEGER, 'd')),
                new ToolProperty('name', PropertyType::STRING, 'd'),
            ]),
        ];

        $this->assertSame([], ToolListChecks::arraysWithoutItems($tools));
    }

    public function testArraysWithoutItemsAreSortedAcrossTools(): void
    {
        $tools = [
            new LegacyTool('z_tool', 'd', [new ToolProperty('b', PropertyType::ARRAY, 'd')]),
            new LegacyTool('a_tool', 'd', [new ToolProperty('c', PropertyType::ARRAY, 'd'), new ToolProperty('a', PropertyType::ARRAY, 'd')]),
        ];

        $this->assertSame(['a_tool.a', 'a_tool.c', 'z_tool.b'], ToolListChecks::arraysWithoutItems($tools));
    }
}
