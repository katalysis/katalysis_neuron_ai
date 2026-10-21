<?php
/**
 * Writes tests/Support/prompt-baseline-v3.json: the system prompt and the tagged user message the model received on
 * Neuron 3, for a fixed page, user and message (P2.4, plan section 3.3).
 *
 * Run once, on `development` with Neuron 3, from the package folder:
 *
 *   php ../../concrete/bin/concrete c5:exec tests/Support/capture_prompt_baseline.php
 *
 * It builds the agent's prompt and reads it (Neuron 3's start event, as its ChatNode hands it to the provider). It
 * calls no tool and no AI provider, logs no one in (the request's user is set in memory) and writes nothing but the
 * JSON file. PromptBaselineTest compares a real v4 turn with the file; the file is never edited to make that test pass.
 *
 * Two prompts are captured: with the external toolkits the site registers (katalysis_pro), compared at P2.8, and with
 * the core toolkits only, compared by P2.4.
 */

require_once __DIR__ . '/PromptBaseline.php';

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\PromptBaseline;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;

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

$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$user = $app->make(UserInfoRepository::class)->getByUserName(PromptBaseline::USER_NAME);
$page = Page::getByPath(PromptBaseline::PAGE_PATH);
if ($user === null || !$page || $page->isError()) {
    fwrite(STDERR, "The seeded user or page is missing. Run documentation/fixtures/seed_test_content.php first.\n");

    return 1;
}
Request::getInstance()->setCustomRequestUser($user);
$context = PromptBaseline::context($page);

$capture = static function () use ($context, $page, $user): array {
    $agent = new ConcreteCmsAgent();
    $agent->setRuntimePageContext($context);
    $system = $agent->getStartEvent()->instructions;
    $tools = array_map(static fn ($tool): string => $tool->getName(), $agent->bootstrapTools());

    return PromptBaseline::part(
        $system,
        $agent->tagMessage(PromptBaseline::MESSAGE),
        $tools,
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
