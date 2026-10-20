<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

/**
 * P1.11 spike (plans/P1.11.md, 2.2): can a tool's callback be wrapped after the tool is built, without breaking the
 * named arguments Neuron passes?
 *
 * Neuron v3 (Tool::execute) calls `call_user_func($this->callback, ...$parameters)` with $parameters keyed by property
 * name, and `Tool::$callback` is protected with no getter. The scanner reads it through a bound closure and puts a
 * wrapper in its place. This test pins the parts that wrapper depends on, so a Neuron upgrade that changes them fails
 * here (P2.3 moves the wrapper into CallableTool). No Concrete, no database, no provider.
 */
final class ToolCallbackWrapSpikeTest extends TestCase
{
    private function tool(): Tool
    {
        return (new Tool('echo_args', 'Returns what it was called with'))
            ->addProperty(new ToolProperty('pageID', PropertyType::INTEGER, 'ID', true))
            ->addProperty(new ToolProperty('version', PropertyType::STRING, 'Version', false))
            ->setCallable(static fn (int $pageID, ?string $version = null): array => ['pageID' => $pageID, 'version' => $version]);
    }

    /**
     * Replaces the callback with a wrapper that calls the original with the same arguments and changes the result.
     */
    private function wrap(Tool $tool): void
    {
        $read = \Closure::bind(fn (): mixed => $this->callback, $tool, Tool::class);
        $original = $read();
        $this->assertIsCallable($original);

        \Closure::bind(function () use ($original): void {
            $this->callback = static function (...$args) use ($original) {
                $result = $original(...$args);
                $result['wrapped'] = true;

                return $result;
            };
        }, $tool, Tool::class)();
    }

    public function testNamedArgumentsReachTheOriginalCallbackThroughTheWrapper(): void
    {
        $tool = $this->tool();
        $this->wrap($tool);

        $tool->setInputs(['pageID' => 7, 'version' => 'RECENT']);
        $tool->execute();

        $this->assertSame(
            ['pageID' => 7, 'version' => 'RECENT', 'wrapped' => true],
            json_decode($tool->getResult(), true)
        );
    }

    public function testAnOmittedOptionalArgumentArrivesAsNullLikeBefore(): void
    {
        $tool = $this->tool();
        $this->wrap($tool);

        $tool->setInputs(['pageID' => 7]);
        $tool->execute();

        $this->assertSame(
            ['pageID' => 7, 'version' => null, 'wrapped' => true],
            json_decode($tool->getResult(), true)
        );
    }

    public function testAnExceptionFromTheOriginalStillPropagates(): void
    {
        $tool = (new Tool('boom', 'Throws'))->setCallable(static function (): never {
            throw new \RuntimeException('original failure');
        });
        $this->wrap($tool);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('original failure');
        $tool->execute();
    }
}
