<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

/**
 * CallableTool on Neuron 4 (P2.1 spike). Each test runs the tool the way Neuron's ToolNode does: clone the registered
 * tool, setInputs(), execute(), getResult(). No Concrete, no database.
 */
final class CallableToolTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $calls = [];

    private function tool(): CallableTool
    {
        $tool = new CallableTool('update_thing', 'Updates a thing.', function (...$args): array {
            $this->calls[] = $args;

            return ['ok' => true, 'data' => $args];
        });
        $tool->addProperty(new ToolProperty('thingID', PropertyType::INTEGER, 'The thing', true));
        $tool->addProperty(new ToolProperty('title', PropertyType::STRING, 'New title', false));

        return $tool;
    }

    private function runTool(CallableTool $registered, array $inputs): string|ToolOutput
    {
        $tool = clone $registered;
        $tool->setInputs($inputs);
        $tool->execute();

        return $tool->getResult();
    }

    public function testDeclaredInputsArePassedByNameWithNullForAMissingOne(): void
    {
        $this->runTool($this->tool(), ['thingID' => 7]);

        $this->assertSame([['thingID' => 7, 'title' => null]], $this->calls);
    }

    public function testInputsAreCastToTheirDeclaredType(): void
    {
        $this->runTool($this->tool(), ['thingID' => '7', 'title' => 'New']);

        $this->assertSame(7, $this->calls[0]['thingID']);
    }

    public function testAnArrayResultIsEncodedOnce(): void
    {
        $result = $this->runTool($this->tool(), ['thingID' => 7]);

        $this->assertSame('{"ok":true,"data":{"thingID":7,"title":null}}', $result);
    }

    public function testAStringResultIsKeptAsItIs(): void
    {
        $tool = new CallableTool('get_text', 'Returns text.', static fn (): string => '{"ok":true}');

        $this->assertSame('{"ok":true}', $this->runTool($tool, []));
    }

    public function testAnUnknownInputIsRefusedBeforeTheHandlerRuns(): void
    {
        try {
            $this->runTool($this->tool(), ['thingID' => 7, 'parentPath' => '/x']);
            $this->fail('An unknown input must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('update_thing does not accept: parentPath. Nothing was changed. It accepts only: thingID, title.', $e->getMessage());
        }

        $this->assertSame([], $this->calls, 'The handler must not run.');
    }

    public function testAnUnknownInputCanGetAMoreSpecificMessage(): void
    {
        $tool = $this->tool()->refuseUnknownInputsWith(static fn (array $unknown): string => 'Use move_page for ' . implode(', ', $unknown) . '.');

        $this->expectExceptionMessage('Use move_page for parentPath.');
        $this->runTool($tool, ['thingID' => 7, 'parentPath' => '/x']);
    }

    public function testAMissingRequiredInputIsAToolErrorAndTheHandlerDoesNotRun(): void
    {
        $result = $this->runTool($this->tool(), ['title' => 'New']);

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('Parameter "thingID" is required.', $result->getText());
        $this->assertSame([], $this->calls);
    }

    public function testAWrongTypeIsAToolErrorAndTheHandlerDoesNotRun(): void
    {
        $result = $this->runTool($this->tool(), ['thingID' => 'seven']);

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('Parameter "thingID" must be of type integer, string given.', $result->getText());
        $this->assertSame([], $this->calls);
    }

    public function testAWrappedHandlerIsWhatAClonedToolRuns(): void
    {
        $tool = $this->tool()->wrapHandler(static fn (\Closure $inner): \Closure => static fn (...$args): array => ['wrapped' => $inner(...$args)]);

        $result = $this->runTool($tool, ['thingID' => 7]);

        $this->assertSame('{"wrapped":{"ok":true,"data":{"thingID":7,"title":null}}}', $result);
    }

    public function testAToolWithoutAHandlerFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->runTool(new CallableTool('no_handler', 'Nothing.'), []);
    }
}
