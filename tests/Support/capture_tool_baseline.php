<?php
/**
 * Writes tests/Support/tool-baseline-v3.json: what the model is told about every core tool (P2.3, plan section 3).
 *
 * Run once, on `development` with Neuron 3, before the CallableTool conversion, from the package folder:
 *
 *   php ../../concrete/bin/concrete c5:exec tests/Support/capture_tool_baseline.php
 *
 * It builds the tools and reads them. It calls no tool, no AI provider and writes nothing but the JSON file.
 * ToolBaselineTest compares the converted tools with the file; the file is never edited to make that test pass.
 */

require_once __DIR__ . '/ToolBaseline.php';

use Katalysis\NeuronAi\Tests\Support\ToolBaseline;
use Katalysis\NeuronAi\Tools\DisabledTools;
use Katalysis\NeuronAi\Tools\ToolNameResolver;

$packageDir = dirname(__DIR__, 2);
$commit = trim((string) shell_exec('git -C ' . escapeshellarg($packageDir) . ' rev-parse --short HEAD'));
$branch = trim((string) shell_exec('git -C ' . escapeshellarg($packageDir) . ' rev-parse --abbrev-ref HEAD'));

// The bundled Neuron's version, from the package's own vendor/ (Concrete has a Composer of its own).
$installed = json_decode((string) file_get_contents($packageDir . '/vendor/composer/installed.json'), true);
$neuron = 'unknown';
foreach ($installed['packages'] ?? $installed ?? [] as $package) {
    if (($package['name'] ?? '') === 'neuron-core/neuron-ai') {
        $neuron = $package['version'];
    }
}

$toolkits = ToolBaseline::coreToolkits();
// What the agent offers from the core toolkits: AttributesToolkit (the last) is not registered.
$registered = array_slice(ToolBaseline::coreToolkits(), 0, -1);
$offered = DisabledTools::apply(ToolNameResolver::resolve($registered, [], static function (): void {}), static function (): void {});

$baseline = [
    'captured' => [
        'branch' => $branch,
        'commit' => $commit,
        'neuron' => $neuron,
        'date' => gmdate('Y-m-d\TH:i\Z'),
    ],
    'definitions' => ToolBaseline::definitions($toolkits),
    'offered' => ToolBaseline::offered($offered),
];

file_put_contents(ToolBaseline::FILE, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$count = array_sum(array_map(static fn (array $t): int => count($t['tools']), $baseline['definitions']));
$offeredCount = array_sum(array_map(static fn (array $t): int => count($t['tools']), $baseline['offered']));
echo "Wrote {$count} tool definitions ({$offeredCount} offered) from {$branch} {$commit}, Neuron {$baseline['captured']['neuron']}\n";

return 0;
