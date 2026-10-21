<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Security;

use Katalysis\NeuronAi\Tools\FilteredToolkit;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use Katalysis\NeuronAi\Tools\ToolKind;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Workflow\Events\Event;

/**
 * The one hook that covers every tool (P1.11, plans/P1.11.md 2.2). It wraps each tool's callback so that its return
 * value is scanned for text addressed to the AI assistant. On a hit it adds `suspectedInstructions` to the top level of
 * the result, writes a log line, and records the hit for the notice (InjectionNotice). It never blocks a read and never
 * changes the content.
 *
 * It works on whatever tools the agent ends up with, so tools from other packages (ExternalToolkitRegistry) are
 * covered without a list. Two paths (Neuron 4, plans/P2.2.md 2.5):
 * - our CallableTool: the handler is wrapped (wrapTool()), so the tool's own result is scanned where it is made;
 * - everything else, and anything the wrapper did not see: resultMiddleware() on Neuron's ToolNode scans each tool
 *   result before it goes to the model or into the history. It skips only results the wrapper actually inspected
 *   (counted per tool name), never a tool it merely believes is wrapped, so a wrap that silently stops working is
 *   still covered. In streaming mode the raw result chunks reach the stream before the middleware runs; the panel
 *   doesn't stream.
 *
 * One instance belongs to one agent. reset() starts a turn.
 */
final class ToolResultScanner
{
    private bool $flagged = false;
    private bool $writeAfterFlag = false;

    /** @var array<string, true> the InjectionNotice SOURCE_ values of the tools whose results were flagged this turn */
    private array $sources = [];
    /** @var \WeakMap<ToolInterface, true> */
    private \WeakMap $wrapped;
    /** @var array<string, int> tool name => results the wrapper inspected that the middleware has not seen yet */
    private array $inspectedByWrapper = [];

    /**
     * @param \Closure(string, string): void|null $logger called as ($level, $message); defaults to the Concrete log
     */
    public function __construct(
        private readonly InstructionDetector $detector,
        private readonly ?\Closure $logger = null,
    ) {
        $this->wrapped = new \WeakMap();
    }

    /**
     * Forget the previous turn's hits.
     */
    public function reset(): void
    {
        $this->flagged = false;
        $this->writeAfterFlag = false;
        $this->sources = [];
        $this->inspectedByWrapper = [];
    }

    /**
     * The kind of notice this turn needs (an InjectionNotice constant), or null.
     */
    public function turnNoticeKind(): ?string
    {
        if (!$this->flagged) {
            return null;
        }

        return $this->writeAfterFlag ? InjectionNotice::SUSPECTED_THEN_WRITES : InjectionNotice::SUSPECTED;
    }

    /**
     * Where the flagged text was read: the one InjectionNotice SOURCE_ value when every flagged result came from the
     * same kind of tool, SOURCE_OTHER when they came from more than one kind, and null when nothing was flagged.
     */
    public function turnNoticeSource(): ?string
    {
        if (!$this->flagged) {
            return null;
        }

        return count($this->sources) === 1 ? (string) array_key_first($this->sources) : InjectionNotice::SOURCE_OTHER;
    }

    public function isWrapped(ToolInterface $tool): bool
    {
        return isset($this->wrapped[$tool]);
    }

    /**
     * Attaches the scanner to every tool in a list of tools and toolkits.
     *
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    public function wrap(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($item instanceof ToolInterface) {
                $result[] = $this->wrapTool($item);
            } elseif ($item instanceof ToolkitInterface) {
                $result[] = $this->wrapToolkit($item);
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Builds the toolkit's tools once, wraps them, and returns a toolkit that offers that fixed list with the
     * original guidelines unchanged. Not the toolkit's with() hook: Neuron 4 keeps one with() callback per tool
     * class, so any other with() on the same class would silently replace the scanner's.
     */
    public function wrapToolkit(ToolkitInterface $toolkit): ToolkitInterface
    {
        $tools = [];
        foreach ($toolkit->tools() as $tool) {
            $tools[] = $tool instanceof ToolInterface ? $this->wrapTool($tool) : $tool;
        }

        return FilteredToolkit::built($toolkit, $tools);
    }

    /**
     * Wraps our CallableTool's handler. Any other tool is returned as it is, and isWrapped() says false for it: the
     * middleware covers it.
     */
    public function wrapTool(ToolInterface $tool): ToolInterface
    {
        if (!$tool instanceof CallableTool || isset($this->wrapped[$tool])) {
            return $tool;
        }

        $name = $tool->getName();
        $tool->wrapHandler(fn (\Closure $original): \Closure => function (mixed ...$args) use ($original, $name): mixed {
            $result = $this->inspect($name, $original(...$args));
            $this->inspectedByWrapper[$name] = ($this->inspectedByWrapper[$name] ?? 0) + 1;

            return $result;
        });

        $this->wrapped[$tool] = true;

        return $tool;
    }

    /**
     * The middleware for Neuron's ToolNode (register it with addMiddleware(ToolNode::class, ...)). After the node has
     * run its tool calls, and before the results go to the model or into the history, it scans every result the
     * wrapper did not inspect.
     */
    public function resultMiddleware(): AgentMiddleware
    {
        return new class ($this) extends AgentMiddleware {
            public function __construct(private readonly ToolResultScanner $scanner)
            {
            }

            protected function afterAgentNode(AgentNodeInterface $node, Event $result, AgentState $state, AgentResources $resources): void
            {
                // ToolNode leaves exactly this node's tool call and result messages on the outgoing request.
                foreach ($state->request->messages as $message) {
                    if (!$message instanceof ToolResultMessage) {
                        continue;
                    }
                    foreach ($message->getToolCalls() as $call) {
                        if ($call->hasResult()) {
                            $call->setResult($this->scanner->scanUnseenResult($call->getName(), $call->getResult()));
                        }
                    }
                }
            }
        };
    }

    /**
     * @internal For resultMiddleware(). Returns the result scanned, or as it is when the wrapper already scanned it.
     */
    public function scanUnseenResult(string $toolName, string|ToolOutput $result): string|ToolOutput
    {
        if (($this->inspectedByWrapper[$toolName] ?? 0) > 0) {
            $this->inspectedByWrapper[$toolName]--;

            return $result;
        }

        $scanned = $this->inspect($toolName, $result);

        return $scanned instanceof ToolOutput ? $scanned : (is_array($scanned) ? (string) json_encode($scanned) : (string) $scanned);
    }

    /**
     * Scans one tool result and returns it, with `suspectedInstructions` added on a hit.
     */
    private function inspect(string $toolName, mixed $result): mixed
    {
        $flaggedBefore = $this->flagged;
        $hits = [];
        $returned = $result;

        if ($result instanceof ToolOutput) {
            // A multimodal result: its text is scanned, and the hits go in an extra text block.
            $hits = $this->detector->scan($result->getText());
            if ($hits !== []) {
                $returned = new ToolOutput([
                    ...$result->getBlocks(),
                    new TextContent('suspectedInstructions: '
                        . json_encode($hits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)),
                ], $result->isError());
            }
        } elseif (is_array($result)) {
            $hits = $this->detector->scan($result);
            if ($hits !== []) {
                $returned = $result;
                $returned['suspectedInstructions'] = $hits;
            }
        } elseif (is_string($result)) {
            $decoded = json_decode($result, true);
            if (is_array($decoded)) {
                $hits = $this->detector->scan($decoded);
                if ($hits !== []) {
                    $decoded['suspectedInstructions'] = $hits;
                    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    $returned = $encoded === false ? $result : $encoded;
                }
            } else {
                $hits = $this->detector->scan($result);
                if ($hits !== []) {
                    $returned = $result . "\n\nsuspectedInstructions: "
                        . json_encode($hits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                }
            }
        }

        if ($hits !== []) {
            $this->flagged = true;
            $this->sources[InjectionNotice::sourceForTool($toolName)] = true;
            $this->logHits($toolName, $hits);
        }

        // A write that succeeded after flagged content was read, in the same turn (not by the same call).
        if ($flaggedBefore && ToolKind::isWrite($toolName)) {
            $asText = is_string($returned) || $returned instanceof ToolOutput ? (string) $returned : (string) json_encode($returned);
            if (ToolKind::succeeded($asText)) {
                $this->writeAfterFlag = true;
            }
        }

        return $returned;
    }

    /**
     * @param list<array<string, string>> $hits
     */
    private function logHits(string $toolName, array $hits): void
    {
        $kinds = implode(', ', array_unique(array_column($hits, 'kind')));
        $first = $hits[0];
        $message = SecretRedactor::redact(sprintf(
            'Katalysis Neuron AI: text addressed to the AI assistant found in the result of "%s" (%s), field "%s"%s: %s',
            $toolName,
            $kinds,
            $first['field'],
            isset($first['source']) ? ' of ' . $first['source'] : '',
            $first['excerpt']
        ));

        if ($this->logger !== null) {
            ($this->logger)('notice', $message);

            return;
        }
        \Concrete\Core\Support\Facade\Log::notice($message);
    }
}
