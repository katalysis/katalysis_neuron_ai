<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Security;

use Katalysis\NeuronAi\Tools\Support\CallableTool;
use Katalysis\NeuronAi\Tools\ToolKind;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * The one hook that covers every tool (P1.11, plans/P1.11.md 2.2). It wraps each tool's callback so that its return
 * value is scanned for text addressed to the AI assistant. On a hit it adds `suspectedInstructions` to the top level of
 * the result, writes a log line, and records the hit for the notice (InjectionNotice). It never blocks a read and never
 * changes the content.
 *
 * It works on whatever tools the agent ends up with, so tools from other packages (ExternalToolkitRegistry) are
 * covered without a list. A toolkit's tools are built again on every tools() call, so the wrapper is attached with
 * the toolkit's own with() hook, once for every tool class the toolkit provides, and not by replacing the toolkit.
 *
 * Neuron v3 specific: Tool::$callback is protected and has no getter, so it is read and replaced through a bound
 * closure. tests/Unit/ToolCallbackWrapSpikeTest pins what this depends on. P2.3 moves the wrapper into CallableTool.
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

    public function wrapToolkit(ToolkitInterface $toolkit): ToolkitInterface
    {
        // Every class of tool it provides gets the hook. A tool of a class it doesn't provide yet can't appear later.
        $classes = [];
        foreach ($toolkit->tools() as $tool) {
            if ($tool instanceof ToolInterface) {
                $classes[$tool::class] = true;
            }
        }

        foreach (array_keys($classes) as $class) {
            $toolkit->with($class, function (ToolInterface $tool): ToolInterface {
                return $this->wrapTool($tool);
            });
        }

        return $toolkit;
    }

    public function wrapTool(ToolInterface $tool): ToolInterface
    {
        // P2.1 spike: on Neuron 4 only our CallableTool can be wrapped; it exposes its handler, so no reflection on
        // Neuron internals. Any other tool is left unwrapped and isWrapped() says so (see resultMiddleware()).
        if (!$tool instanceof CallableTool || isset($this->wrapped[$tool])) {
            return $tool;
        }

        $name = $tool->getName();
        $tool->wrapHandler(fn (\Closure $original): \Closure => function (...$args) use ($original, $name) {
            return $this->inspect($name, $original(...$args));
        });

        $this->wrapped[$tool] = true;

        return $tool;
    }

    /**
     * P2.1 spike: covers the tools wrapTool() can't wrap (any Neuron 4 tool that isn't our CallableTool, such as a
     * third-party toolkit's own Tool subclass). Registered on ToolNode, it runs after the node's tool calls settle
     * and before their results go to the model or into the history. A streamed turn has already sent the raw result
     * chunks to the browser by then.
     */
    public function resultMiddleware(): AgentMiddleware
    {
        $scanner = $this;

        return new class ($scanner) extends AgentMiddleware {
            public function __construct(private readonly ToolResultScanner $scanner)
            {
            }

            protected function afterAgentNode(AgentNodeInterface $node, Event $result, AgentState $state, AgentResources $resources): void
            {
                foreach ($state->request->messages as $message) {
                    if (!$message instanceof ToolResultMessage) {
                        continue;
                    }
                    foreach ($message->getToolCalls() as $call) {
                        $tool = $resources->tools->find($call->getName());
                        if ($tool === null || $this->scanner->isWrapped($tool) || !$call->hasResult()) {
                            continue;
                        }
                        $value = $call->getResult();
                        if (is_string($value)) {
                            $call->setResult($this->scanner->inspectResult($call->getName(), $value));
                        }
                    }
                }
            }
        };
    }

    /**
     * @internal For resultMiddleware().
     */
    public function inspectResult(string $toolName, string $result): mixed
    {
        return $this->inspect($toolName, $result);
    }

    /**
     * Scans one tool result and returns it, with `suspectedInstructions` added on a hit.
     */
    private function inspect(string $toolName, mixed $result): mixed
    {
        $flaggedBefore = $this->flagged;
        $hits = [];
        $returned = $result;

        if (is_array($result)) {
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
            $asText = is_string($returned) ? $returned : (string) json_encode($returned);
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
