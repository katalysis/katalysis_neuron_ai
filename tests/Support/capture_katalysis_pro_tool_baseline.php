<?php
/**
 * Writes tests/Support/katalysis-pro-tool-baseline-v3.json: what the model is told about each of katalysis_pro's
 * tools on Neuron 3 (P2.8, plan section 4.4), in ToolBaseline::tool()'s shape.
 *
 * Run once, with this package on `development` (Neuron 3) and katalysis_pro on its pre-P2.8 toolkit, from the package
 * folder:
 *
 *   php ../../concrete/bin/concrete c5:exec tests/Support/capture_katalysis_pro_tool_baseline.php
 *
 * Self-contained (ToolBaseline.php is not on `development`). It builds the tools and reads them. It calls no tool,
 * no AI provider and writes nothing but the JSON file. KatalysisProToolsTest compares the converted tools with the
 * file; the file is never edited to make that test pass.
 */

use NeuronAI\Providers\OpenAI\ToolMapper;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;

$packageDir = dirname(__DIR__, 2);
$proDir = dirname($packageDir) . '/katalysis_pro';
$git = static fn (string $dir, string $args): string => trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' ' . $args));

$installed = json_decode((string) file_get_contents($packageDir . '/vendor/composer/installed.json'), true);
$neuron = 'unknown';
foreach ($installed['packages'] ?? $installed ?? [] as $package) {
    if (($package['name'] ?? '') === 'neuron-core/neuron-ai') {
        $neuron = $package['version'];
    }
}

$class = 'Concrete\\Package\\KatalysisPro\\Src\\KatalysisPro\\Ai\\KatalysisProEntitiesToolkit';
if (!class_exists($class)) {
    fwrite(STDERR, "katalysis_pro's toolkit class is not loaded.\n");

    return 1;
}
$toolkit = new $class();

$tool = static fn (ToolInterface $tool): array => [
    'name' => $tool->getName(),
    'description' => $tool->getDescription(),
    'properties' => array_map(static fn (ToolPropertyInterface $p): array => [
        'name' => $p->getName(),
        'type' => $p->getType()->value,
        'description' => $p->getDescription(),
        'required' => $p->isRequired(),
    ], array_values($tool->getProperties())),
    'payload' => json_decode((string) json_encode((new ToolMapper())->map([$tool])[0]), true),
];

$baseline = [
    'captured' => [
        'branch' => $git($packageDir, 'rev-parse --abbrev-ref HEAD'),
        'commit' => $git($packageDir, 'rev-parse --short HEAD'),
        'neuron' => $neuron,
        'katalysis_pro' => $git($proDir, 'rev-parse --abbrev-ref HEAD') . ' ' . $git($proDir, 'rev-parse --short HEAD'),
        'date' => gmdate('Y-m-d\TH:i\Z'),
    ],
    'guidelines' => $toolkit->guidelines(),
    'tools' => array_map($tool, array_values($toolkit->tools())),
];

$file = __DIR__ . '/katalysis-pro-tool-baseline-v3.json';
file_put_contents($file, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo sprintf("Wrote %s: %d tools, Neuron %s, katalysis_pro %s\n", basename($file), count($baseline['tools']), $neuron, $baseline['captured']['katalysis_pro']);

return 0;
