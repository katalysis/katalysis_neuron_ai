<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

class SchemaGenerated
{
    public function __construct(public string $class, public array $schema)
    {
    }
}
