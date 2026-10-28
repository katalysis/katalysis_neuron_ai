<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Katalysis\NeuronAi\ConcreteCmsAgent;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * P3.5-1: runs one of the agent's core tools by name, whichever toolkit it is in, so a test survives P3.3-4's split of
 * PagesToolkit. Disabled tools are included (DisabledTools filters the agent's list, not the toolkits): a disabled tool
 * is still checked, in case it is re-enabled.
 */
final class ToolByName
{
    /**
     * The tool's result, or what it threw, worded as the agent's error handler words it for the model.
     *
     * @param array<string, mixed> $inputs
     */
    public static function run(string $name, array $inputs): string
    {
        return ThrowawayPages::runTool(self::toolkitWith($name), $name, $inputs);
    }

    /**
     * @return list<string> every core tool's name
     */
    public static function names(): array
    {
        $names = [];
        foreach (self::toolkits() as $toolkit) {
            foreach ($toolkit->tools() as $tool) {
                $names[] = $tool->getName();
            }
        }

        return $names;
    }

    public static function tool(string $name): ToolInterface
    {
        foreach (self::toolkitWith($name)->tools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        throw new \RuntimeException("No core tool named {$name}.");
    }

    private static function toolkitWith(string $name): ToolkitInterface
    {
        foreach (self::toolkits() as $toolkit) {
            foreach ($toolkit->tools() as $tool) {
                if ($tool->getName() === $name) {
                    return $toolkit;
                }
            }
        }

        throw new \RuntimeException("No core tool named {$name}.");
    }

    /**
     * @return list<ToolkitInterface>
     */
    private static function toolkits(): array
    {
        return (new \ReflectionMethod(ConcreteCmsAgent::class, 'coreToolkits'))->invoke(new ConcreteCmsAgent());
    }
}
