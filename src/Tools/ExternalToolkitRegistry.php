<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Support\Facade\Log;
use Katalysis\NeuronAi\Security\SecretRedactor;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

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

    /**
     * Runs every registered factory and builds each toolkit's tools once (P2.2, P2.1 findings 5.4).
     *
     * A factory that throws, or a toolkit whose tools() throws, is skipped and logged, so one package can't break
     * every chat turn. Each toolkit that builds is returned as a FilteredToolkit::built() holding the tools it built,
     * so nothing calls the original's tools() again (an MCP toolkit would reconnect). Call it once per agent.
     *
     * @param callable(string, string): void|null $logger called as ($level, $message); defaults to the Concrete log
     */
    public static function build(?callable $logger = null): ExternalToolkitBuild
    {
        $logger ??= static function (string $level, string $message): void {
            Log::$level($message);
        };

        $toolkits = [];
        $skipped = [];

        foreach (self::$factories as $key => $factory) {
            try {
                $resolved = $factory();
            } catch (\Throwable $e) {
                $skipped[] = self::skip($logger, (string) $key, null, $e);

                continue;
            }

            if (!is_array($resolved)) {
                continue;
            }

            foreach ($resolved as $toolkit) {
                if (!is_object($toolkit)) {
                    continue;
                }
                if (!$toolkit instanceof ToolkitInterface) {
                    $toolkits[] = $toolkit;

                    continue;
                }

                try {
                    $toolkits[] = FilteredToolkit::built($toolkit, $toolkit->tools());
                } catch (\Throwable $e) {
                    $skipped[] = self::skip($logger, (string) $key, $toolkit::class, $e);
                }
            }
        }

        return new ExternalToolkitBuild($toolkits, $skipped);
    }

    public static function getExternalToolkits(): array
    {
        return self::build()->toolkits();
    }

    /**
     * @param callable(string, string): void $logger
     * @return array{provider: string, toolkit: ?string, reason: string}
     */
    private static function skip(callable $logger, string $key, ?string $toolkitClass, \Throwable $e): array
    {
        $reason = SecretRedactor::redact($e->getMessage());

        $logger('warning', $toolkitClass === null
            ? sprintf('Katalysis Neuron AI: external toolkit provider "%s" failed: %s', $key, $reason)
            : sprintf(
                'Katalysis Neuron AI: external toolkit %s from provider "%s" skipped; its tools could not be built: %s',
                $toolkitClass,
                $key,
                $reason
            ));

        return ['provider' => $key, 'toolkit' => $toolkitClass, 'reason' => $reason];
    }
}
