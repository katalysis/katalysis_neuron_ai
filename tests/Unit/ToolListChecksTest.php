<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tests\Support\ToolListChecks;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
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
            new CallableTool('list_people', 'core'),
            new CallableTool('get_page', 'unique'),
            new CallableTool('list_people', 'external'),
        ];

        $this->assertSame(['list_people'], ToolListChecks::duplicateNames($tools));
    }

    public function testADuplicateIsReportedOnceEvenWhenRepeated(): void
    {
        $tools = [new CallableTool('a', 'x'), new CallableTool('a', 'y'), new CallableTool('a', 'z'), new CallableTool('b', 'x'), new CallableTool('b', 'y')];

        $this->assertSame(['a', 'b'], ToolListChecks::duplicateNames($tools));
    }

    public function testUniqueNamesReportNothing(): void
    {
        $this->assertSame([], ToolListChecks::duplicateNames([new CallableTool('a', 'x'), new CallableTool('b', 'x')]));
        $this->assertSame([], ToolListChecks::duplicateNames([]));
    }

    public function testArrayPropertyWithoutItemsIsReported(): void
    {
        $tools = [
            new Tool('bad_tool', 'd', [
                new ToolProperty('name', PropertyType::STRING, 'd'),
                new ToolProperty('options', PropertyType::ARRAY, 'bare array, no items'),
            ]),
        ];

        $this->assertSame(['bad_tool.options'], ToolListChecks::arraysWithoutItems($tools));
    }

    public function testArrayPropertiesThatDeclareItemsAreNotReported(): void
    {
        $tools = [
            new Tool('good_tool', 'd', [
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
            new Tool('z_tool', 'd', [new ToolProperty('b', PropertyType::ARRAY, 'd')]),
            new Tool('a_tool', 'd', [new ToolProperty('c', PropertyType::ARRAY, 'd'), new ToolProperty('a', PropertyType::ARRAY, 'd')]),
        ];

        $this->assertSame(['a_tool.a', 'a_tool.c', 'z_tool.b'], ToolListChecks::arraysWithoutItems($tools));
    }
}
