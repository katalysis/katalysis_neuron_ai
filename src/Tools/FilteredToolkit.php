<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * A toolkit that offers a fixed, already-filtered list of tools, keeping the
 * original toolkit's guidelines.
 *
 * Neuron's exclude() works by tool class, and all our tools are plain Tool
 * objects, so a single tool can't be dropped by name that way. Used by
 * ToolNameResolver and DisabledTools.
 *
 * Why a wrapper and not a filter on the agent's flattened tool list: Neuron
 * (HandleTools::bootstrapTools) builds the guideline text, including the list
 * of each toolkit's tool names, from the original toolkit's own tools(). A
 * filter applied afterwards would leave dropped and disabled tool names in the
 * prompt. The wrapper keeps that list correct.
 *
 * Neuron titles a toolkit's guidelines with the toolkit's class short name,
 * which can't be changed, so the title is "FilteredToolkit". To keep the
 * original name visible to the model, the guidelines start with a line naming
 * the original toolkit.
 */
final class FilteredToolkit extends AbstractToolkit
{
    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(
        private readonly array $tools,
        private readonly ?string $guidelines = null,
        private readonly ?string $originalName = null
    ) {
    }

    /**
     * @param ToolInterface[] $tools
     */
    public static function from(ToolkitInterface $original, array $tools): self
    {
        $shortName = (new \ReflectionClass($original))->getShortName();

        // An anonymous class has no usable name ("class@anonymous" plus a path).
        $name = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $shortName) === 1 ? $shortName : null;

        return new self(array_values($tools), $original->guidelines(), $name);
    }

    public function guidelines(): ?string
    {
        // Neuron adds the guidelines (and the title) only when there is text, so keep null/empty as it is.
        if ($this->guidelines === null || $this->guidelines === '' || $this->originalName === null) {
            return $this->guidelines;
        }

        return 'Toolkit: ' . $this->originalName . PHP_EOL . $this->guidelines;
    }

    public function provide(): array
    {
        return $this->tools;
    }
}
