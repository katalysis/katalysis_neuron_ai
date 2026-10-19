<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * The outcome of one check in a scenario.
 */
final class CheckResult
{
    public const STATE = 'state';
    public const TOOLS = 'tools';
    public const REPLY = 'reply';

    public function __construct(
        public readonly string $group,
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $detail = '',
        public readonly bool $primary = true,
        public readonly string $where = '',
    ) {
    }

    /**
     * Same check, labelled with where it ran ("turn 2", "end").
     */
    public function at(string $where): self
    {
        return new self($this->group, $this->name, $this->passed, $this->detail, $this->primary, $where);
    }

    /**
     * @return array{group: string, name: string, passed: bool, primary: bool, where: string, detail: string}
     */
    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'name' => $this->name,
            'passed' => $this->passed,
            'primary' => $this->primary,
            'where' => $this->where,
            'detail' => $this->detail,
        ];
    }
}
