<?php
/**
 * Prints what the model would receive for one user message: the system prompt, the user message with its page tag, and
 * the tools offered (P2.4). Dev tooling: it lives under tests/ and is not part of the shipped package.
 *
 * Run it from the package folder with Concrete's own runner. Options go after "--":
 *
 *   php ../../concrete/bin/concrete c5:exec tests/Support/show_prompt.php
 *   php ../../concrete/bin/concrete c5:exec tests/Support/show_prompt.php -- --path=/neuron-test/columns --user=neuron_chat_editor
 *
 *   --path=PATH     the page the user is on (default /neuron-test/headings)
 *   --user=NAME     the user (default neuron_chat_editor)
 *   --message=TEXT  the user's message (default "Hello")
 *
 * It runs one real agent turn with a fake model (PromptPreview): no AI provider call, no API key read, nothing written
 * to the database. It refuses to run unless the site is a local dev site, and everything it prints goes through
 * SecretRedactor. External toolkits are included, as on the site.
 */

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Katalysis\NeuronAi\Tests\Support\PromptPreview;

return (function () use ($args, $output): int {
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

    $fail = static function (string $message, int $code = 1) use ($output): int {
        $output->writeln('<error>' . $message . '</error>');

        return $code;
    };

    // ---- Guard: before anything else -------------------------------------------------------------------------
    $app = Application::getFacadeApplication();
    $canonical = (string) $app->make('site')->getSite()->getConfigRepository()->get('seo.canonical_url');
    $refusal = PromptPreview::refusal($canonical);
    if ($refusal !== null) {
        return $fail($refusal);
    }

    $options = PromptPreview::options(array_values($args));
    if (is_string($options)) {
        return $fail($options, 2);
    }

    $page = Page::getByPath($options['path'], 'ACTIVE');
    if (!$page || $page->isError()) {
        return $fail('There is no page at "' . $options['path'] . '".', 2);
    }

    try {
        PromptPreview::actAs($options['user']);
        $output->write(PromptPreview::render(PromptPreview::turn($page, $options['message'])), false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
    } catch (\InvalidArgumentException $e) {
        return $fail($e->getMessage(), 2);
    } finally {
        Request::getInstance()->setCustomRequestUser(-1);
    }

    return 0;
})();
