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
 * Why a wrapper and not a filter on the agent's flattened tool list: the
 * prompt's guideline text, including the list of each toolkit's tool names, is
 * built from each toolkit's own tools() (ConcreteCmsAgent::resolveTools(), as
 * Neuron 3's HandleTools::bootstrapTools did). A filter applied afterwards
 * would leave dropped and disabled tool names in the prompt. The wrapper keeps
 * that list correct.
 *
 * Neuron 3 titled a toolkit's guidelines with the toolkit's class short name,
 * so a filtered toolkit's title is "FilteredToolkit" (guidelinesHeading(); P2.4
 * keeps that rendering, P3.7 decides the heading). To keep the original name
 * visible to the model, the guidelines start with a line naming the original
 * toolkit.
 *
 * built() is for a toolkit whose tools were built once and are offered as they
 * are, with nothing removed (the result scanner, ExternalToolkitRegistry): its
 * guidelines are kept exactly, with no name line, so the prompt doesn't change.
 */
final class FilteredToolkit extends AbstractToolkit
{
    /**
     * @param ToolInterface[] $tools
     * @param string|null $builtFrom for built(): the original toolkit's name, kept for a later from() but not shown
     */
    public function __construct(
        private readonly array $tools,
        private readonly ?string $guidelines = null,
        private readonly ?string $originalName = null,
        private readonly ?string $builtFrom = null
    ) {
    }

    /**
     * Offers the given, already-built tools with the original's guidelines unchanged.
     *
     * @param ToolInterface[] $tools
     */
    public static function built(ToolkitInterface $original, array $tools): self
    {
        if ($original instanceof self) {
            return new self(array_values($tools), $original->guidelines, $original->originalName, $original->builtFrom);
        }

        return new self(array_values($tools), $original->guidelines(), null, self::nameOf($original));
    }

    /**
     * @param ToolInterface[] $tools
     */
    public static function from(ToolkitInterface $original, array $tools): self
    {
        // A built() toolkit stands in for its original: filter it as if it were the original.
        if ($original instanceof self && $original->originalName === null && $original->builtFrom !== null) {
            return new self(array_values($tools), $original->guidelines, $original->builtFrom);
        }

        return new self(array_values($tools), $original->guidelines(), self::nameOf($original));
    }

    private static function nameOf(ToolkitInterface $toolkit): ?string
    {
        $shortName = (new \ReflectionClass($toolkit))->getShortName();

        // An anonymous class has no usable name ("class@anonymous" plus a path).
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $shortName) === 1 ? $shortName : null;
    }

    /**
     * The name the prompt heads this toolkit's guidelines with (ConcreteCmsAgent::resolveTools(), P2.4), as on
     * Neuron 3: a built() toolkit stands in for its original, so it takes the original's name; a filtered one was
     * offered as a FilteredToolkit, so it is headed "FilteredToolkit".
     */
    public function guidelinesHeading(): string
    {
        if ($this->originalName === null && $this->builtFrom !== null) {
            return $this->builtFrom;
        }

        return 'FilteredToolkit';
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
