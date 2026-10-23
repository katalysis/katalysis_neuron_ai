<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\FixtureDrift;
use Katalysis\NeuronAi\Tests\Support\FixtureLock;
use Katalysis\NeuronAi\Tests\Support\SeedProcess;

/**
 * WP-2 on the dev site: this PHPUnit run holds the shared fixture lock (ConcreteBoot), so a seed or a scenario runner
 * started outside it is refused; and a change to a fixture page is reported as drift, naming the page.
 *
 * The refused processes are started without this run's token, as a separate run would be. Both are harmless if the
 * refusal failed: the seed with no options only creates what is missing, and the runner gets an unknown option, which
 * it rejects (exit 2) before it resets anything.
 */
final class FixtureLockAndDriftTest extends ConcreteTestCase
{
    private const PAGE = '/neuron-test/headings';

    public function testThisRunHoldsTheLockAndSaysSo(): void
    {
        $held = json_decode((string) file_get_contents(FixtureLock::path()), true);

        $this->assertSame('the PHPUnit integration tests', $held['holder'] ?? null);
        $this->assertSame(getmypid(), $held['pid'] ?? null);
        $this->assertSame($held['token'] ?? null, getenv(FixtureLock::ENV), 'Child processes inherit the token.');
    }

    public function testASeedStartedOutsideThisRunIsRefused(): void
    {
        $result = SeedProcess::forThisPackage()->run([], $this->environmentWithoutTheLock());

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('Refusing to start the seed (seed_test_content.php):', $result['output']);
        $this->assertStringContainsString('in use by the PHPUnit integration tests (process ' . getmypid() . ', since ', $result['output']);
    }

    public function testAScenarioRunStartedOutsideThisRunIsRefused(): void
    {
        $siteDir = dirname(__DIR__, 4);
        $process = proc_open(
            [PHP_BINARY, 'concrete/bin/concrete', 'c5:exec', dirname(__DIR__) . '/Eval/run_scenarios.php', '--', '--wp2-lock-probe'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $siteDir,
            $this->environmentWithoutTheLock()
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Refusing to start the scenario runner (run_scenarios.php --wp2-lock-probe):', $output);
        $this->assertStringContainsString('in use by the PHPUnit integration tests', $output);
    }

    public function testTheFixtureIsAsTheSeedLeftIt(): void
    {
        $this->assertNull(FixtureDrift::problem($this->db()));
    }

    /** BF-4's case: a new version of a fixture page. */
    public function testANewVersionOfAFixturePageIsDriftNamingThePage(): void
    {
        $page = Page::getByPath(self::PAGE);
        $this->assertFalse($page->isError(), 'The dev site has no ' . self::PAGE . ' fixture.');
        $draft = $page->getVersionToModify();

        try {
            $problem = (string) FixtureDrift::problem($this->db());
        } finally {
            $draft->getVersionObject()->discard();
        }

        $this->assertStringStartsWith('fixture drift: run the seed reset.', $problem);
        $this->assertMatchesRegularExpression('#' . preg_quote(self::PAGE, '#') . ' \(page ID \d+\) has \d+ version\(s\), newest \d+ \(not approved\)#', $problem);
        $this->assertNull(FixtureDrift::problem($this->db()), 'Discarding the draft puts the page back.');
    }

    public function testAChangeInPlaceIsDriftNamingThePage(): void
    {
        $db = $this->db();
        $cID = (int) $db->fetchOne('SELECT cID FROM PagePaths WHERE cPath = ? AND ppIsCanonical = 1', [self::PAGE]);
        $cvID = (int) $db->fetchOne('SELECT MAX(cvID) FROM CollectionVersions WHERE cID = ?', [$cID]);
        $description = $db->fetchOne('SELECT cvDescription FROM CollectionVersions WHERE cID = ? AND cvID = ?', [$cID, $cvID]);

        $db->executeStatement('UPDATE CollectionVersions SET cvDescription = ? WHERE cID = ? AND cvID = ?', ['changed by WP-2', $cID, $cvID]);
        try {
            $problem = (string) FixtureDrift::problem($db);
        } finally {
            $db->executeStatement('UPDATE CollectionVersions SET cvDescription = ? WHERE cID = ? AND cvID = ?', [$description, $cID, $cvID]);
        }

        $this->assertStringContainsString(self::PAGE . " (page ID {$cID}) was changed in place", $problem);
        $this->assertNull(FixtureDrift::problem($db));
    }

    /**
     * @return array<string, string>
     */
    private function environmentWithoutTheLock(): array
    {
        $env = getenv();
        unset($env[FixtureLock::ENV]);

        return $env;
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
