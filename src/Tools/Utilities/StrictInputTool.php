<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Katalysis\NeuronAi\Tools\Support\CallableTool;
use Katalysis\NeuronAi\Tools\Support\LegacyTool;

/**
 * A Tool that refuses inputs it does not declare.
 *
 * P2.1 spike: on Neuron 4 every CallableTool refuses unknown inputs, so this class only keeps the name the toolkits
 * use. P2.3 removes it.
 */
class StrictInputTool extends LegacyTool
{
    /**
     * @param list<string> $unknown
     * @param list<string> $declared
     */
    public static function defaultMessage(string $tool, array $unknown, array $declared): string
    {
        return CallableTool::unknownInputsMessage($tool, $unknown, $declared);
    }
}
