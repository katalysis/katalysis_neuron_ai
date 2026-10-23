<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

/**
 * CallableTool on Neuron 4 (plans/P2.2.md 2.2; replaces ToolCallbackWrapSpikeTest, which pinned v3's callback
 * rebinding). Each test runs the tool the way Neuron's ToolNode does: clone the registered tool, setInputs(),
 * execute(), getResult(). No Concrete, no database.
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

    /**
     * P2.3 plan §12.2: the refusal names the unknown inputs and lists the allowed ones; a tool with none says so.
     */
    public function testTheRefusalNamesEveryUnknownInputAndSaysWhenTheToolTakesNone(): void
    {
        try {
            $this->runTool($this->tool(), ['thingID' => 7, 'parentPath' => '/x', 'colour' => 'red']);
            $this->fail('Unknown inputs must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('update_thing does not accept: parentPath, colour. Nothing was changed. It accepts only: thingID, title.', $e->getMessage());
        }

        $ran = false;
        $none = new CallableTool('get_text', 'Returns text.', static function () use (&$ran): string {
            $ran = true;

            return '{"ok":true}';
        });
        try {
            $this->runTool($none, ['pageID' => 3]);
            $this->fail('An input to a tool that takes none must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('get_text does not accept: pageID. Nothing was changed. It takes no inputs.', $e->getMessage());
        }
        $this->assertFalse($ran);
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

    public function testAnExceptionFromAWrappedHandlerStillPropagates(): void
    {
        $tool = (new CallableTool('boom', 'Throws.', static function (): never {
            throw new \RuntimeException('original failure');
        }))->wrapHandler(static fn (\Closure $inner): \Closure => static fn (...$args): mixed => $inner(...$args));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('original failure');
        $this->runTool($tool, []);
    }

    public function testAToolWithoutAHandlerFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->runTool(new CallableTool('no_handler', 'Nothing.'), []);
    }

    /**
     * BF-7, browser check 6: a read result's HTML reached the model as "<\/h2>\r\n\r\n<p>", and the model copied it
     * back as text. Results are encoded without escaped slashes, and line breaks inside HTML are removed (between
     * elements) or become a space (inside text), so there is no escape sequence in HTML to copy. <pre> keeps its lines.
     */
    public function testAnArrayResultHasNoEscapedSlashesAndItsHtmlNoLineBreaks(): void
    {
        $tool = new CallableTool('get_thing', 'Reads a thing.', static fn (): array => [
            'html' => "<h2>Seed Heading Two</h2>\r\n\r\n<p>This paragraph\r\nsits under the h2 heading.</p>\r\n",
            'nested' => ['content' => "<ul>\n  <li>One</li>\n</ul>"],
            'pre' => "<pre>line one\r\nline two</pre>",
            'text' => "Line one\r\nLine two",
            'path' => '/neuron-test/headings',
        ]);

        $result = (string) $this->runTool($tool, []);

        $this->assertStringNotContainsString('<\/', $result);
        $this->assertStringNotContainsString('\/', $result, 'no escaped slash anywhere, paths included');
        $this->assertStringNotContainsString('\r', $result);
        $decoded = json_decode($result, true);
        $this->assertSame('<h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p>', $decoded['html']);
        $this->assertSame('<ul><li>One</li></ul>', $decoded['nested']['content']);
        $this->assertSame("<pre>line one\nline two</pre>", $decoded['pre']);
        $this->assertSame("Line one\nLine two", $decoded['text']);
        $this->assertSame('/neuron-test/headings', $decoded['path']);
    }
}
