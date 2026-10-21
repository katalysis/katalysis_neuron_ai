<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Katalysis\NeuronAi\Tools\Support\CallableTool;

/**
 * A Tool that refuses inputs it does not declare.
 *
 * Neuron v3 dropped an input the model invented (for example "parentPath" on update_page) without a word, and the
 * tool then reported success for a change it never made (P1.13). On Neuron 4 every CallableTool refuses such inputs
 * before its handler runs, with refuseUnknownInputsWith() for a more specific message (P2.3), so this class only keeps
 * the name update_page is built with. P3.3 can remove it.
 */
class StrictInputTool extends CallableTool
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
