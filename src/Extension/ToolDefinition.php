<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Extension;

use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * Our own builder for a tool, with no Neuron types in its public methods (EXTENSIONS.md; from the P2.1 spike,
 * brought in by P2.3). Another package hands its ToolDefinitions to the core with
 * ExternalToolkitRegistry::registerToolDefinitions() (P2.8).
 *
 *     ToolDefinition::make('get_page_content', 'Read the text of a page.')
 *         ->input('pageID', ToolDefinition::INTEGER, 'The page ID', required: true)
 *         ->handler(fn (array $in): array => ...);
 *
 * The handler gets the declared inputs as one array, cast to their types, with null for an input the model left out.
 * Its result reaches the model in the {ok, ...} envelope: an array with an "ok" key as it is, anything else as
 * {ok: true, data: <result>}. An exception is left to the agent's tool error handler, which redacts and logs it.
 * Only the core calls toNeuronTool(); an extension never sees a Neuron class.
 *
 * Not yet here: group(), tier(), permission() and the handler's context argument (EXTENSIONS.md), which need
 * the tier and permission design of Phase 3. writes() is recorded but does not yet ask for approval (P3.6).
 */
final class ToolDefinition
{
    public const STRING = 'string';
    public const INTEGER = 'integer';
    public const NUMBER = 'number';
    public const BOOLEAN = 'boolean';
    public const ARRAY = 'array';
    public const OBJECT = 'object';

    private const NEURON_TYPES = [
        self::STRING => PropertyType::STRING,
        self::INTEGER => PropertyType::INTEGER,
        self::NUMBER => PropertyType::NUMBER,
        self::BOOLEAN => PropertyType::BOOLEAN,
        self::ARRAY => PropertyType::ARRAY,
        self::OBJECT => PropertyType::OBJECT,
    ];

    /** @var list<array{name: string, type: string, description: ?string, required: bool}> */
    private array $inputs = [];

    /** @var (\Closure(array<string, mixed>): mixed)|null */
    private ?\Closure $handler = null;

    private bool $writes = false;

    private function __construct(private readonly string $name, private readonly string $description)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException("Tool name \"{$name}\" must be snake_case: a-z, 0-9 and _, starting with a letter.");
        }
        if (trim($description) === '') {
            throw new \InvalidArgumentException("Tool \"{$name}\" needs a description.");
        }
    }

    public static function make(string $name, string $description): self
    {
        return new self($name, $description);
    }

    public function input(string $name, string $type, ?string $description = null, bool $required = false): self
    {
        if (!isset(self::NEURON_TYPES[$type])) {
            throw new \InvalidArgumentException("Input \"{$name}\" of tool \"{$this->name}\" has unknown type \"{$type}\".");
        }
        foreach ($this->inputs as $input) {
            if ($input['name'] === $name) {
                throw new \InvalidArgumentException("Tool \"{$this->name}\" declares input \"{$name}\" twice.");
            }
        }

        $this->inputs[] = ['name' => $name, 'type' => $type, 'description' => $description, 'required' => $required];

        return $this;
    }

    /**
     * @param callable(array<string, mixed>): mixed $handler
     */
    public function handler(callable $handler): self
    {
        $this->handler = \Closure::fromCallable($handler);

        return $this;
    }

    public function writes(): self
    {
        $this->writes = true;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isWrite(): bool
    {
        return $this->writes;
    }

    /**
     * @internal For the core only.
     */
    public function toNeuronTool(): CallableTool
    {
        if ($this->handler === null) {
            throw new \LogicException("Tool \"{$this->name}\" has no handler.");
        }

        $handler = $this->handler;
        $tool = new CallableTool($this->name, $this->description, static fn (mixed ...$in): array => self::envelope($handler($in)));
        foreach ($this->inputs as $input) {
            $tool->addProperty(new ToolProperty(
                $input['name'],
                self::NEURON_TYPES[$input['type']],
                $input['description'],
                $input['required']
            ));
        }

        return $tool;
    }

    /**
     * @return array<string, mixed>
     */
    private static function envelope(mixed $result): array
    {
        if (is_array($result) && array_key_exists('ok', $result)) {
            return $result;
        }

        return ['ok' => true, 'data' => $result];
    }
}
