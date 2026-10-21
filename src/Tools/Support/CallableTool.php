<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Support;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolPropertyInterface;

/**
 * A Neuron 4 Tool that runs a closure, for tools built at runtime (P2.1 spike, plan 4.2 option A).
 *
 * Neuron 4's Tool is abstract and runs __invoke(), reflecting on its parameter names. Our tools are closures, so this
 * class gives Neuron a variadic __invoke() and no signature: Neuron's execute() then casts the inputs, settles invalid
 * ones as ToolOutput::error() without calling the closure, and passes every declared property by name, null when the
 * model left it out. That is the calling convention our v3 closures were written for.
 *
 * Every tool refuses inputs it does not declare (StrictInputTool's rule, now for all tools). The check runs before
 * the closure, so nothing is written.
 *
 * wrapHandler() lets the core wrap the closure (the result scanner, P1.11) without reflection on Neuron internals.
 *
 * @internal Not part of the extension API. Extensions use Katalysis\NeuronAi\Extension\ToolDefinition.
 */
class CallableTool extends Tool
{
    /** @var \Closure(mixed ...): mixed */
    private \Closure $handler;

    /** @var (\Closure(list<string>): ?string)|null */
    private ?\Closure $unknownInputMessage = null;

    public function __construct(string $name, ?string $description = null, ?callable $handler = null)
    {
        $this->name = $name;
        $this->description = $description;
        $this->handler = $handler !== null
            ? \Closure::fromCallable($handler)
            : static fn (): never => throw new \LogicException("Tool \"{$name}\" has no handler.");
    }

    public function setHandler(callable $handler): static
    {
        $this->handler = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * Replaces the handler with $wrap($handler). Used by the core to scan results.
     *
     * @param callable(\Closure): callable $wrap
     */
    public function wrapHandler(callable $wrap): static
    {
        $this->handler = \Closure::fromCallable($wrap($this->handler));

        return $this;
    }

    /**
     * @param callable(list<string>): ?string $messageFor gets the unknown input names and may return a more specific message
     */
    public function refuseUnknownInputsWith(callable $messageFor): static
    {
        $this->unknownInputMessage = \Closure::fromCallable($messageFor);

        return $this;
    }

    public function execute(): void
    {
        $declared = array_map(static fn (ToolPropertyInterface $property): string => $property->getName(), $this->getProperties());
        $unknown = [];
        foreach (array_keys($this->getInputs()) as $name) {
            if (!in_array((string) $name, $declared, true)) {
                $unknown[] = (string) $name;
            }
        }

        if ($unknown !== []) {
            $message = $this->unknownInputMessage !== null ? ($this->unknownInputMessage)($unknown) : null;

            // Thrown, not returned, so the agent's tool error handler words it as in v3 ("Error executing tool: ...").
            throw new \RuntimeException($message ?? self::unknownInputsMessage($this->getName(), $unknown, $declared));
        }

        parent::execute();
    }

    public function __invoke(mixed ...$inputs): mixed
    {
        return ($this->handler)(...$inputs);
    }

    /**
     * No signature: Neuron then passes every declared property, null when it is missing.
     *
     * @return array<string, \ReflectionParameter>
     */
    protected function invokeParameters(): array
    {
        return [];
    }

    /**
     * @param list<string> $unknown
     * @param list<string> $declared
     */
    public static function unknownInputsMessage(string $tool, array $unknown, array $declared): string
    {
        return sprintf(
            '%s does not accept: %s. Nothing was changed. It accepts only: %s.',
            $tool,
            implode(', ', $unknown),
            implode(', ', $declared)
        );
    }
}
