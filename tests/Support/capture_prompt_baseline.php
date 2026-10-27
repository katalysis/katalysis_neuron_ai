<?php
/**
 * Writes tests/Support/prompt-baseline.json: the system prompt and the tagged user message the model receives, for a
 * fixed page, user and message. First written on Neuron 3 for P2.4 (prompt-baseline-v3.json); replaced on Neuron 4 by
 * P3.7-1, the first deliberate prompt change, so later prompt changes stay deliberate.
 *
 * Run from the package folder, on purpose, when a task changes the prompt (the new file goes in its own commit, with
 * the reason):
 *
 *   php ../../concrete/bin/concrete c5:exec tests/Support/capture_prompt_baseline.php
 *
 * It runs the same turn as PromptBaselineTest (PromptPreview: a real turn with Neuron's FakeAIProvider and an
 * in-memory history). It calls no tool and no AI provider, logs no one in (the request's user is set in memory) and
 * writes nothing but the JSON file. The file is never edited by hand to make that test pass.
 *
 * Two prompts are captured: with the external toolkits the site registers (katalysis_pro), and with the core toolkits
 * only.
 */

use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Support\PromptBaseline;
use Katalysis\NeuronAi\Tests\Support\PromptPreview;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;

$packageDir = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($packageDir): void {
    $prefix = 'Katalysis\\NeuronAi\\Tests\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = $packageDir . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$refusal = PromptPreview::refusal((string) $app->make('site')->getSite()->getConfigRepository()->get('seo.canonical_url'));
if ($refusal !== null) {
    fwrite(STDERR, $refusal . "\n");

    return 1;
}

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

$page = Page::getByPath(PromptBaseline::PAGE_PATH);
if (!$page || $page->isError()) {
    fwrite(STDERR, "The seeded page is missing. Run documentation/fixtures/seed_test_content.php first.\n");

    return 1;
}
try {
    $user = PromptPreview::actAs(PromptBaseline::USER_NAME);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . " Run documentation/fixtures/seed_test_content.php first.\n");

    return 1;
}

$capture = static function () use ($page, $user): array {
    $turn = PromptPreview::turn($page, PromptBaseline::MESSAGE);

    return PromptBaseline::part(
        $turn['system'],
        (string) $turn['messages'][0]->getContent(),
        $turn['tools'],
        (int) $page->getCollectionID(),
        (int) $user->getUserID()
    );
};

$withExternal = $capture();
(new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories'))->setValue(null, []);
$core = $capture();

$baseline = [
    'captured' => [
        'branch' => $branch,
        'commit' => $commit,
        'neuron' => $neuron,
        'date' => gmdate('Y-m-d\TH:i\Z'),
    ],
    'inputs' => [
        'page' => PromptBaseline::PAGE_PATH,
        'user' => PromptBaseline::USER_NAME,
        'message' => PromptBaseline::MESSAGE,
    ],
    'core' => $core,
    'withExternal' => $withExternal,
];

file_put_contents(PromptBaseline::FILE, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo sprintf(
    "Wrote the prompt baseline from %s %s, Neuron %s: core %d lines, %d tools; with external toolkits %d lines, %d tools\n",
    $branch,
    $commit,
    $neuron,
    count($core['system']),
    count($core['tools']),
    count($withExternal['system']),
    count($withExternal['tools'])
);

return 0;
