<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use NeuronAI\Tools\Tool;

/**
 * A Tool that refuses inputs it does not declare.
 *
 * Neuron's Tool::execute() builds the callback's arguments from the declared properties only, so an input the
 * model invents (for example "parentPath" on update_page) is dropped without a word, and the tool then reports
 * success for a change it never made. This class turns that into an error the model can read and pass on.
 * The check runs before the callback, so nothing is written.
 *
 * Neuron v3 specific; P2.3 moves it into CallableTool.
 */
class StrictInputTool extends Tool
{
    /** @var (callable(list<string>): ?string)|null */
    private $messageFor = null;

    /**
     * @param callable(list<string>): ?string $messageFor gets the unknown input names and may return a more specific message
     */
    public function refuseUnknownInputsWith(callable $messageFor): self
    {
        $this->messageFor = $messageFor;

        return $this;
    }

    public function execute(): void
    {
        $declared = array_map(static fn ($property): string => $property->getName(), $this->getProperties());
        $unknown = [];
        foreach (array_keys($this->getInputs()) as $name) {
            if (!in_array((string) $name, $declared, true)) {
                $unknown[] = (string) $name;
            }
        }

        if ($unknown !== []) {
            $message = $this->messageFor !== null ? ($this->messageFor)($unknown) : null;

            throw new \RuntimeException($message ?? self::defaultMessage($this->getName(), $unknown, $declared));
        }

        parent::execute();
    }

    /**
     * @param list<string> $unknown
     * @param list<string> $declared
     */
    public static function defaultMessage(string $tool, array $unknown, array $declared): string
    {
        return sprintf(
            "%s does not accept: %s. Nothing was changed. It accepts only: %s.",
            $tool,
            implode(', ', $unknown),
            implode(', ', $declared)
        );
    }
}
