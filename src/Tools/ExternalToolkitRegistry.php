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
                    if (is_object($toolkit)) {
                        $toolkits[] = $toolkit;
                    }
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
