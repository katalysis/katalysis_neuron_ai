<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Extension\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * The public tool builder (P2.1 spike, EXTENSIONS.md). No Concrete, no database.
 */
final class ToolDefinitionTest extends TestCase
{
    public function testItBuildsTheSchemaTheModelGets(): void
    {
        $tool = ToolDefinition::make('get_thing', 'Reads a thing.')
            ->input('thingID', ToolDefinition::INTEGER, 'The thing', required: true)
            ->input('withChildren', ToolDefinition::BOOLEAN)
            ->handler(static fn (array $in): array => $in)
            ->toNeuronTool();

        $this->assertSame('get_thing', $tool->getName());
        $this->assertSame('Reads a thing.', $tool->getDescription());
        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'thingID' => ['type' => 'integer', 'description' => 'The thing'],
                'withChildren' => ['type' => 'boolean'],
            ],
            'required' => ['thingID'],
        ], $tool->getInputSchema());
    }

    public function testTheHandlerGetsTheInputsAsOneArray(): void
    {
        $received = null;
        $tool = ToolDefinition::make('get_thing', 'Reads a thing.')
            ->input('thingID', ToolDefinition::INTEGER, required: true)
            ->input('withChildren', ToolDefinition::BOOLEAN)
            ->handler(static function (array $in) use (&$received): string {
                $received = $in;

                return 'done';
            })
            ->toNeuronTool();

        $tool->setInputs(['thingID' => '3']);
        $tool->execute();

        $this->assertSame(['thingID' => 3, 'withChildren' => null], $received);
        $this->assertSame('done', $tool->getResult());
    }

    public function testWritesIsRecorded(): void
    {
        $this->assertFalse(ToolDefinition::make('get_thing', 'Reads.')->isWrite());
        $this->assertTrue(ToolDefinition::make('update_thing', 'Writes.')->writes()->isWrite());
    }

    /**
     * @return array<string, array{\Closure(): mixed}>
     */
    public static function invalidDefinitions(): array
    {
        return [
            'name not snake_case' => [static fn () => ToolDefinition::make('GetThing', 'Reads.')],
            'empty description' => [static fn () => ToolDefinition::make('get_thing', ' ')],
            'unknown type' => [static fn () => ToolDefinition::make('get_thing', 'Reads.')->input('x', 'int')],
            'input declared twice' => [static fn () => ToolDefinition::make('get_thing', 'Reads.')->input('x', 'string')->input('x', 'integer')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDefinitions')]
    public function testAnInvalidDefinitionIsRefusedWhenItIsBuilt(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $build();
    }

    public function testADefinitionWithoutAHandlerCannotBecomeATool(): void
    {
        $this->expectException(\LogicException::class);
        ToolDefinition::make('get_thing', 'Reads.')->toNeuronTool();
    }

    public function testNoNeuronTypeAppearsInTheExtensionMethods(): void
    {
        $leaks = [];
        foreach ((new \ReflectionClass(ToolDefinition::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === 'toNeuronTool') {
                continue; // core only
            }
            $types = [$method->getReturnType()];
            foreach ($method->getParameters() as $parameter) {
                $types[] = $parameter->getType();
            }
            foreach ($types as $type) {
                if ($type !== null && str_contains((string) $type, 'NeuronAI')) {
                    $leaks[] = $method->getName() . ': ' . $type;
                }
            }
        }

        $this->assertSame([], $leaks);
    }
}
