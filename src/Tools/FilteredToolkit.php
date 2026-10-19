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
 * Neuron titles a toolkit's guidelines in the system prompt with the toolkit's
 * class short name (HandleTools::bootstrapTools). So that the prompt still
 * says "PagesToolkit" and not "FilteredToolkit", from() returns an instance of
 * a trivial subclass that has the original's short name, in the namespace
 * Katalysis\NeuronAi\Tools\FilteredAs. These subclasses are generated at
 * runtime, once per short name; the name is checked to be a plain identifier
 * first, and anything else falls back to FilteredToolkit itself.
 */
class FilteredToolkit extends AbstractToolkit
{
    private const GENERATED_NAMESPACE = 'Katalysis\\NeuronAi\\Tools\\FilteredAs';

    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(private readonly array $tools, private readonly ?string $guidelines = null)
    {
    }

    /**
     * @param ToolInterface[] $tools
     */
    public static function from(ToolkitInterface $original, array $tools): self
    {
        $class = self::classNamed((new \ReflectionClass($original))->getShortName());

        return new $class(array_values($tools), $original->guidelines());
    }

    public function guidelines(): ?string
    {
        return $this->guidelines;
    }

    public function provide(): array
    {
        return $this->tools;
    }

    /**
     * @return class-string<self>
     */
    private static function classNamed(string $shortName): string
    {
        // Anonymous classes ("class@anonymous\0/path...") and anything else that isn't a
        // plain identifier keep the default name.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $shortName) !== 1) {
            return self::class;
        }

        $fqcn = self::GENERATED_NAMESPACE . '\\' . $shortName;
        if (class_exists($fqcn, false)) {
            return $fqcn;
        }

        try {
            // $shortName passed the identifier check above, so nothing else can reach the code.
            eval(sprintf('namespace %s; final class %s extends \\%s {}', self::GENERATED_NAMESPACE, $shortName, self::class));
        } catch (\Throwable) {
            return self::class;
        }

        return class_exists($fqcn, false) ? $fqcn : self::class;
    }
}
