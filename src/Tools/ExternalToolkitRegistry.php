<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Support\Facade\Log;

class ExternalToolkitRegistry
{
    /**
     * @var array<string, callable(): array>
     */
    private static array $factories = [];

    public static function registerToolkitFactory(string $key, callable $factory): void
    {
        if (!isset(self::$factories[$key])) {
            self::$factories[$key] = $factory;
        }
    }

    public static function getExternalToolkits(): array
    {
        $toolkits = [];

        foreach (self::$factories as $key => $factory) {
            try {
                $resolved = $factory();

                if (!is_array($resolved)) {
                    continue;
                }

                foreach ($resolved as $toolkit) {
                    if (!is_object($toolkit)) {
                        continue;
                    }

                    // P2.1 spike only: a toolkit whose tools can't be built (katalysis_pro's still builds v3 Tool
                    // objects) is skipped and logged, so one package can't break every chat turn.
                    if ($toolkit instanceof \NeuronAI\Tools\Toolkits\ToolkitInterface) {
                        try {
                            $toolkit->tools();
                        } catch (\Throwable $e) {
                            Log::warning(sprintf(
                                'Katalysis Neuron AI: external toolkit %s from provider "%s" skipped; its tools could not be built: %s',
                                $toolkit::class,
                                $key,
                                $e->getMessage()
                            ));

                            continue;
                        }
                    }

                    $toolkits[] = $toolkit;
                }
            } catch (\Throwable $e) {
                Log::warning(sprintf(
                    'Katalysis Neuron AI: external toolkit provider "%s" failed: %s',
                    $key,
                    $e->getMessage()
                ));
            }
        }

        return $toolkits;
    }
}
