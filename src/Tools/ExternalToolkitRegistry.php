<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Support\Facade\Log;
use Concrete\Core\User\User;
use Katalysis\NeuronAi\Extension\ToolDefinition;
use Katalysis\NeuronAi\Security\SecretRedactor;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

class ExternalToolkitRegistry
{
    /**
     * Toolkit factories, and ToolDefinition providers as {definitions: factory, guidelines, label}, in the order they
     * were registered.
     *
     * @var array<string, callable(): array|array{definitions: callable(): array, guidelines: ?string, label: string}>
     */
    private static array $factories = [];

    public static function registerToolkitFactory(string $key, callable $factory): void
    {
        if (!isset(self::$factories[$key])) {
            self::$factories[$key] = $factory;
        }
    }

    /**
     * How another package hands its tools to the assistant without any Neuron class (P2.8). Call it from the package's
     * on_start(), only when this method exists:
     *
     *     if (method_exists(ExternalToolkitRegistry::class, 'registerToolDefinitions')) {
     *         ExternalToolkitRegistry::registerToolDefinitions('my_package', fn (): array => [ToolDefinition::make(...), ...]);
     *     }
     *
     * The factory runs once per agent and returns a list of ToolDefinitions. Each tool then goes through the same
     * layers as a core tool: a login check (as core guarded()), the {ok, ...} envelope, the name-clash rule, the
     * disabled-tools list and the result scanner. A factory that throws, or returns anything but ToolDefinitions, is
     * skipped and logged, and the rest of the assistant keeps working (P2.2).
     *
     * @param callable(): array $factory returns list<ToolDefinition>
     * @param string|null $guidelines added to the prompt after the tool names, as a toolkit's guidelines are
     * @param string|null $label heads the guidelines in the prompt (as a toolkit's class name did); defaults to $key
     */
    public static function registerToolDefinitions(string $key, callable $factory, ?string $guidelines = null, ?string $label = null): void
    {
        if (!isset(self::$factories[$key])) {
            self::$factories[$key] = ['definitions' => $factory, 'guidelines' => $guidelines, 'label' => $label ?? $key];
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
     * @param callable(): bool|null $isRegistered the login check for ToolDefinition tools; defaults to the request's user
     */
    public static function build(?callable $logger = null, ?callable $isRegistered = null): ExternalToolkitBuild
    {
        $logger ??= static function (string $level, string $message): void {
            Log::$level($message);
        };
        $isRegistered ??= static fn (): bool => (bool) (new User())->isRegistered();

        $toolkits = [];
        $skipped = [];

        foreach (self::$factories as $key => $factory) {
            if (is_array($factory) && isset($factory['definitions'])) {
                try {
                    $toolkits[] = self::buildDefinitions($factory, $isRegistered);
                } catch (\Throwable $e) {
                    $skipped[] = self::skip($logger, (string) $key, $factory['label'], $e);
                }

                continue;
            }

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
     * One provider's ToolDefinitions as a toolkit headed by its label, each tool behind the login check.
     *
     * @param array{definitions: callable(): array, guidelines: ?string, label: string} $provider
     * @param callable(): bool $isRegistered
     */
    private static function buildDefinitions(array $provider, callable $isRegistered): FilteredToolkit
    {
        $definitions = ($provider['definitions'])();
        if (!is_array($definitions)) {
            throw new \UnexpectedValueException('the factory did not return a list of ToolDefinitions');
        }

        $tools = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof ToolDefinition) {
                throw new \UnexpectedValueException(sprintf(
                    'the factory returned %s, not a %s',
                    get_debug_type($definition),
                    ToolDefinition::class
                ));
            }

            $tools[] = $definition->toNeuronTool()->wrapHandler(
                static fn (\Closure $handler): \Closure => static function (mixed ...$in) use ($handler, $isRegistered): mixed {
                    if (!$isRegistered()) {
                        throw new \RuntimeException('Authentication required: Please log in to use this tool.');
                    }

                    return $handler(...$in);
                }
            );
        }

        return new FilteredToolkit($tools, $provider['guidelines'], null, $provider['label']);
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
