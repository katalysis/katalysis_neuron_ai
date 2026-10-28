<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\SeedProcess;

/**
 * BF-19 (P3.S2's regression in scenario 06): Concrete 9.5.5's BlockAssignment tests `instanceof Page` against the
 * global `Page` alias, which Concrete registers lazily through the autoloader. `instanceof` never autoloads, so in a
 * PHP process where nothing has referenced `\Page` yet, every block in an area that inherits the page's permissions
 * failed View Block and Edit Block, though the page's own checks passed. It depended on what ran earlier in the
 * process (a page read loads the alias), so scenario 06 failed after 04 and 23 passed later in the same run.
 *
 * The check runs in its own process (tests/Support/fresh_process_block_check.php through c5:exec), because this PHPUnit
 * process has usually loaded the alias already. Reads only.
 */
final class CoreAliasesTest extends ConcreteTestCase
{
    public function testBlockChecksInAPageInheritedAreaPassInAFreshProcess(): void
    {
        $result = $this->freshCheck('neuron_content_editor', '/neuron-test/edit-mode');

        $this->assertFalse($result['aliasLoadedBefore'], 'Precondition: nothing has loaded the Page alias yet in the fresh process');
        $this->assertFalse($result['areaOverrides'], 'Precondition: the area inherits the page\'s permissions');
        $this->assertNull($result['viewPage'], 'The fixture user may view the page');
        $this->assertNull($result['viewBlock'], 'View Block was refused on a page-inherited area: ' . $result['viewBlock']);
        $this->assertNull($result['editBlock'], 'Edit Block was refused on a page-inherited area: ' . $result['editBlock']);
    }

    /**
     * @return array{aliasLoadedBefore: bool, areaOverrides: bool, viewPage: ?string, viewBlock: ?string, editBlock: ?string}
     */
    private function freshCheck(string $user, string $path): array
    {
        $packageDir = dirname(__DIR__, 2);
        // c5:exec exits with the script's require() result (1), so the JSON line is what tells success.
        $run = (new SeedProcess(dirname($packageDir, 2), $packageDir . '/tests/Support/fresh_process_block_check.php'))->run([$user, $path]);
        $lines = array_values(array_filter(explode("\n", $run['output']), static fn (string $l): bool => str_starts_with(trim($l), '{')));
        $data = json_decode((string) end($lines), true);
        $this->assertIsArray($data, "No JSON line in:\n" . $run['output']);

        return $data;
    }
}
