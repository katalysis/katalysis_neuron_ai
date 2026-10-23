<?php

/**
 * Scenario runner (task P1.4). Dev tooling: it lives under tests/ and is not part of the shipped package.
 *
 * Run it from httpdocs/ with Concrete's own runner. Options for this script go after "--":
 *
 *   php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php
 *   php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php -- --scenario=05-h3-to-h2
 *   php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php -- --scenario=05,06 --repeat=3
 *
 *   --scenario=ID[,ID...]  run only these scenarios (an id, or its number: "05" selects "05-h3-to-h2")
 *   --repeat=N             run each scenario N times (default 1), each from a fresh reset
 *   --fake                 a free dry run: the model is Neuron's FakeAIProvider (DryRunAgent), so no provider is called
 *                          and no API key is needed. The checks mostly fail; what it shows is that the runner works
 *                          (P2.6). Its result files are not committed.
 *
 * For every run it resets the fixture (seed_test_content.php --reset), acts as the scenario's user, plays the
 * turns through the real agent in this process, checks the result and writes documentation/eval-results/<timestamp>.md
 * and .json. This is the only thing in the package that calls the AI provider, so it uses the dev site's API key
 * and costs money. DEV ONLY: it resets the test content and refuses to run unless the site is a local dev site.
 *
 * Concrete has already booted when c5:exec starts this script, so the guards below run as the first thing the script
 * does, not before Concrete reads the database.
 */

use Concrete\Core\Cache\Cache;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Config;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Eval\DatabaseGuard;
use Katalysis\NeuronAi\Tests\Eval\DryRunAgent;
use Katalysis\NeuronAi\Tests\Eval\Expectations;
use Katalysis\NeuronAi\Tests\Eval\Fixture;
use Katalysis\NeuronAi\Tests\Eval\ResultsWriter;
use Katalysis\NeuronAi\Tests\Eval\ScenarioLoader;
use Katalysis\NeuronAi\Tests\Eval\ScenarioRunner;
use Katalysis\NeuronAi\Tests\Eval\SnapshotBuilder;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\FixtureLock;
use Symfony\Component\Console\Output\OutputInterface;

return (function () use ($args, $output): int {
    $packageDir = dirname(__DIR__, 2);
    $siteDir = dirname($packageDir, 2);

    spl_autoload_register(static function (string $class) use ($packageDir): void {
        $prefix = 'Katalysis\\NeuronAi\\Tests\\';
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $packageDir . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });

    $fail = static function (string $message, int $code = 1) use ($output): int {
        $output->writeln('<error>' . $message . '</error>');

        return $code;
    };

    // ---- Guards: before anything else ------------------------------------------------------------------------
    $dbProblem = DatabaseGuard::findProblem($siteDir . '/application/config');
    if ($dbProblem !== null) {
        return $fail('Refusing to run: ' . $dbProblem);
    }

    $app = Application::getFacadeApplication();
    $canonical = (string) $app->make('site')->getSite()->getConfigRepository()->get('seo.canonical_url');
    if (!ConcreteBoot::isDevHost($canonical)) {
        return $fail('Refusing to run: the canonical URL "' . $canonical . '" is not a .test/.localhost/localhost dev host.');
    }

    // WP-2: the fixture lock shared with the seed and the integration tests, held until the run ends. The seed resets
    // this starts share it.
    try {
        $fixtureLock = FixtureLock::acquire('the scenario runner (run_scenarios.php' . ($args !== [] ? ' ' . implode(' ', $args) : '') . ')');
    } catch (RuntimeException $e) {
        return $fail($e->getMessage());
    }

    // ---- Options ---------------------------------------------------------------------------------------------
    $filter = null;
    $repeat = 1;
    $fake = false;
    foreach ($args as $arg) {
        if (preg_match('/^--scenario=(.+)$/', $arg, $m) === 1) {
            $filter = $m[1];
        } elseif (preg_match('/^--repeat=(\d+)$/', $arg, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 20) {
            $repeat = (int) $m[1];
        } elseif ($arg === '--fake') {
            $fake = true;
        } else {
            return $fail('Unknown or invalid option "' . $arg . '". Options (after "--"): --scenario=ID[,ID...]  --repeat=N (1 to 20)  --fake', 2);
        }
    }

    if (!class_exists(ConcreteCmsAgent::class)) {
        return $fail('The katalysis_neuron_ai package is not installed on this site.');
    }
    try {
        $all = ScenarioLoader::loadAll($packageDir . '/tests/Scenarios');
    } catch (InvalidArgumentException $e) {
        return $fail("Scenario files are not valid:\n" . $e->getMessage());
    }
    $scenarios = ScenarioLoader::filter($all, $filter);
    if ($scenarios === []) {
        return $fail('No scenario matches "' . $filter . '". Available: ' . implode(', ', array_keys($all)), 2);
    }
    if (!$fake && trim((string) Config::get('katalysis.ai.open_ai_key', '')) === '') {
        return $fail('No OpenAI key is configured (katalysis.ai.open_ai_key), so the agent cannot run. Set it in Dashboard > System > AI > Neuron AI Settings.');
    }

    // ---- Run -------------------------------------------------------------------------------------------------
    set_time_limit(0);
    Cache::disableAll(); // so snapshots and permission checks never read a stale page

    $runner = new ScenarioRunner(
        $app,
        new Fixture($siteDir, $packageDir . '/documentation/fixtures/seed_test_content.php'),
        new SnapshotBuilder($app->make(Connection::class), (int) Page::getHomePageID()),
        $fake
            ? static fn (): ConcreteCmsAgent => DryRunAgent::make()
            : static fn (): ConcreteCmsAgent => new ConcreteCmsAgent()
    );

    $leftover = $runner->deleteChats();
    if ($leftover > 0) {
        $output->writeln("Removed {$leftover} chat row(s) left behind by an earlier run.");
    }

    $startedAt = date('Y-m-d H:i:s');
    $timestamp = date('Ymd-His');
    $started = microtime(true);
    $results = ['meta' => [], 'scenarios' => []];
    $exitCode = 0;

    $output->writeln(sprintf(
        'Running %d scenario(s) x %d. %s',
        count($scenarios),
        $repeat,
        $fake ? 'Dry run: the model is a fake, no provider is called.' : 'This calls the AI provider.'
    ));

    try {
        foreach ($scenarios as $id => $scenario) {
            $results['scenarios'][$id] = [
                'id' => $id,
                'title' => $scenario['title'],
                'knownToFailToday' => $scenario['known_to_fail_today'] ?? null,
                'runs' => [],
            ];

            for ($n = 1; $n <= $repeat; $n++) {
                $output->write(sprintf('%s run %d/%d ... ', $id, $n, $repeat));
                $run = $runner->run($scenario, $n);
                $results['scenarios'][$id]['runs'][] = $run;
                $output->writeln(sprintf(
                    '%s (%s s, tokens in/out: %s)%s',
                    $run['outcome'],
                    $run['durationSec'],
                    ResultsWriter::usageLabel($run['usage']),
                    $run['error'] !== null ? ' ' . $run['error'] : ''
                ));

                if ($run['outcome'] === Expectations::ERROR) {
                    $exitCode = 1;
                }
            }
        }
    } catch (RuntimeException $e) {
        // A failed fixture reset: the state is unknown, so stop here and still write what was done.
        $output->writeln('<error>' . $e->getMessage() . '</error>');
        $exitCode = 1;
    } finally {
        $runner->endSession();
        $deleted = $runner->deleteChats();
        $left = $runner->countChats();
        $output->writeln("Deleted {$deleted} chat row(s) created by this run" . ($left > 0 ? "; WARNING: {$left} still remain." : '.'));
    }

    $installed = json_decode((string) @file_get_contents($packageDir . '/vendor/composer/installed.json'), true);
    $neuronVersion = 'unknown';
    foreach ($installed['packages'] ?? $installed ?? [] as $package) {
        if (($package['name'] ?? '') === 'neuron-core/neuron-ai') {
            $neuronVersion = (string) $package['version'];
        }
    }
    $git = static function (string $args) use ($packageDir): string {
        $out = [];
        exec('git -C ' . escapeshellarg($packageDir) . ' ' . $args . ' 2>/dev/null', $out);

        return trim(implode("\n", $out));
    };
    $commit = $git('rev-parse --short HEAD') ?: 'unknown';
    $dirty = $git('status --porcelain') !== '';

    // The agent doesn't set a temperature, so this normally reads "provider default". It is read from the
    // provider's request parameters, so a later change in the agent shows up here without editing the runner.
    $temperature = 'provider default (not set by the agent)';
    try {
        $providerMethod = new ReflectionMethod(ConcreteCmsAgent::class, 'provider');
        $providerMethod->setAccessible(true);
        $provider = $providerMethod->invoke(new ConcreteCmsAgent());
        $parametersProperty = new ReflectionProperty($provider, 'parameters');
        $parametersProperty->setAccessible(true);
        $parameters = $parametersProperty->getValue($provider);
        if (is_array($parameters) && isset($parameters['temperature'])) {
            $temperature = (string) $parameters['temperature'];
        }
    } catch (Throwable) {
        $temperature = 'not recorded';
    }

    $results['meta'] = [
        'startedAt' => $startedAt,
        'durationSec' => round(microtime(true) - $started, 1),
        'repeat' => $repeat,
        'filter' => $filter,
        'model' => $fake ? DryRunAgent::MODEL : 'openai ' . (string) Config::get('katalysis.ai.open_ai_model', 'gpt-4o'),
        'temperature' => $temperature,
        'commit' => $commit . ($dirty ? ' (uncommitted changes)' : ''),
        'neuronVersion' => $neuronVersion,
        'concreteVersion' => (string) Config::get('concrete.version'),
        'phpVersion' => PHP_VERSION,
    ];

    $results['scenarios'] = array_filter($results['scenarios'], static fn (array $s): bool => $s['runs'] !== []);
    if ($results['scenarios'] === []) {
        return $exitCode ?: 1;
    }

    $paths = ResultsWriter::write($packageDir . '/documentation/eval-results', $timestamp, $results);
    $output->writeln('');
    $output->writeln(ResultsWriter::toMarkdown($results), OutputInterface::OUTPUT_RAW);
    $output->writeln('Results written to:');
    $output->writeln('  ' . $paths['md']);
    $output->writeln('  ' . $paths['json']);

    return $exitCode;
})();
