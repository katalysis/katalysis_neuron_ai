<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\File\File;
use Concrete\Core\Url\UrlImmutable;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\KatalysisPro;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\PeopleToolkit;

/**
 * BF-12: a person's image comes with an absolute URL, built by Concrete from the site's canonical URL, so the model
 * never has to add a host to a path (B-TRU-9). Found in BF-11's check 4: katalysis_pro's list_people returned only
 * imagePath, and the reply gave https://your-domain/application/files/….
 *
 * The test gives Test Person Beta the seed's file as an image and Test Person Gamma none, and puts both back. With no
 * canonical URL and no request host (only on the CLI), the URL is null and the result says why. That case is made by
 * rebinding url/canonical for the test (restored after it), so the site's configuration is never changed. No AI
 * provider call.
 */
final class PersonImageUrlTest extends ConcreteTestCase
{
    /** @var array<int, int|null> sID => the image it had */
    private array $images = [];

    private ?\Closure $canonicalBinding = null;

    protected function setUp(): void
    {
        if (!KatalysisPro::isInstalled()) {
            $this->markTestSkipped('katalysis_pro is not installed.');
        }
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        foreach ($this->images as $sID => $image) {
            $this->db()->executeStatement('UPDATE KatalysisPeople SET image = ? WHERE sID = ?', [$image, $sID]);
        }
        $this->images = [];
        if ($this->canonicalBinding !== null) {
            $this->app()->bind('url/canonical', $this->canonicalBinding);
            $this->canonicalBinding = null;
        }
        $this->app()->make('cache/request')->flush();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function personID(string $name): int
    {
        $sID = (int) $this->db()->fetchOne('SELECT sID FROM KatalysisPeople WHERE name = ? AND email LIKE ? ORDER BY sID LIMIT 1', [$name, '%@neuron-test.invalid']);
        if ($sID === 0) {
            $this->markTestSkipped("{$name} is not on this site: run the seed.");
        }

        return $sID;
    }

    private function setImage(int $sID, ?int $fID): void
    {
        if (!array_key_exists($sID, $this->images)) {
            $image = $this->db()->fetchOne('SELECT image FROM KatalysisPeople WHERE sID = ?', [$sID]);
            $this->images[$sID] = $image === null ? null : (int) $image;
        }
        $this->db()->executeStatement('UPDATE KatalysisPeople SET image = ? WHERE sID = ?', [$fID, $sID]);
    }

    /**
     * The seed's file: any file works, as getURL() doesn't depend on the type.
     */
    private function fixtureFileID(): int
    {
        $fID = (int) $this->db()->fetchOne("SELECT MIN(fID) FROM FileVersions WHERE fvTitle = 'Neuron Test File'");
        if ($fID === 0) {
            $this->markTestSkipped('The seed\'s "Neuron Test File" is not on this site: run the seed.');
        }

        return $fID;
    }

    /**
     * Beta with the file, Gamma without an image.
     *
     * @return array{int, int, int} Beta's sID, Gamma's sID and the file ID
     */
    private function arrange(): array
    {
        $fID = $this->fixtureFileID();
        $beta = $this->personID('Test Person Beta');
        $gamma = $this->personID('Test Person Gamma');
        $this->setImage($beta, $fID);
        $this->setImage($gamma, null);

        return [$beta, $gamma, $fID];
    }

    private function expectedUrl(int $fID): string
    {
        $url = (string) File::getByID($fID)->getApprovedVersion()->getURL();
        $canonical = rtrim((string) $this->app()->make('site')->getSite()->getSiteCanonicalURL(), '/');
        $this->assertNotSame('', $canonical, 'the dev site has a canonical URL (the test boot refuses one without)');
        $this->assertStringStartsWith($canonical . '/', $url, 'Concrete builds the URL from the canonical URL');

        return $url;
    }

    /**
     * No canonical URL and no request host, as on the CLI of a site with no canonical URL set.
     */
    private function withoutCanonicalUrl(): void
    {
        $this->canonicalBinding = static fn ($app) => $app->make('Concrete\Core\Url\Resolver\CanonicalUrlResolver')->resolve([]);
        $this->app()->bind('url/canonical', static fn () => UrlImmutable::createFromUrl(''));
    }

    /**
     * @param array<string, mixed> $inputs
     *
     * @return array<string, mixed>
     */
    private function runKatalysisPro(string $tool, array $inputs): array
    {
        $class = KatalysisPro::TOOLKIT;
        foreach ((new $class())->definitions() as $definition) {
            if ($definition->name() === $tool) {
                $neuronTool = $definition->toNeuronTool();
                $neuronTool->setInputs($inputs)->execute();

                return $this->json((string) $neuronTool->getResult());
            }
        }
        $this->fail("katalysis_pro has no {$tool}");
    }

    /**
     * @param array<string, mixed> $inputs
     *
     * @return array<string, mixed>
     */
    private function runCore(string $tool, array $inputs): array
    {
        return $this->json(ThrowawayPages::runTool(new PeopleToolkit(), $tool, $inputs));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $output): array
    {
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, $output);

        return isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function row(array $result, int $sID): array
    {
        foreach ((array) ($result['people'] ?? []) as $row) {
            if ((int) ($row['sID'] ?? 0) === $sID) {
                return $row;
            }
        }
        $this->fail("person {$sID} is not in the result: " . json_encode($result));
    }

    // ---- katalysis_pro ----------------------------------------------------------------------------------------

    public function testKatalysisProListPeopleGivesAnAbsoluteImageUrl(): void
    {
        [$beta, $gamma, $fID] = $this->arrange();

        $result = $this->runKatalysisPro('list_people', ['search' => 'Test Person', 'limit' => 100]);

        $this->assertSame($this->expectedUrl($fID), $this->row($result, $beta)['imageUrl'] ?? null);
        $this->assertSame(File::getRelativePathFromID($fID), $this->row($result, $beta)['imagePath'] ?? null, 'imagePath stays (add_person and update_person take it)');
        $this->assertArrayHasKey('imageUrl', $this->row($result, $gamma));
        $this->assertNull($this->row($result, $gamma)['imageUrl']);
        $this->assertArrayNotHasKey('imageUrlNote', $result, 'every image has a URL, so there is no note');
    }

    public function testKatalysisProGetPersonGivesAnAbsoluteImageUrl(): void
    {
        [$beta, , $fID] = $this->arrange();

        $result = $this->runKatalysisPro('get_person', ['personID' => $beta]);

        $this->assertSame($this->expectedUrl($fID), $result['person']['imageUrl'] ?? null);
    }

    public function testKatalysisProWithoutACanonicalUrlGivesNoUrlAndSaysWhy(): void
    {
        [$beta] = $this->arrange();
        $this->withoutCanonicalUrl();

        $list = $this->runKatalysisPro('list_people', ['search' => 'Test Person', 'limit' => 100]);
        $one = $this->runKatalysisPro('get_person', ['personID' => $beta]);

        $this->assertNull($this->row($list, $beta)['imageUrl']);
        $this->assertNotNull($this->row($list, $beta)['imagePath']);
        foreach ([$list, $one] as $result) {
            $this->assertStringContainsString('no canonical URL', (string) ($result['imageUrlNote'] ?? ''), json_encode($result));
            $this->assertStringContainsString("don't add a host", (string) $result['imageUrlNote']);
        }
    }

    // ---- this package's PeopleToolkit (used when katalysis_pro's list_people isn't offered) --------------------

    public function testCoreListPeopleGivesAnAbsoluteImageUrl(): void
    {
        [$beta, $gamma, $fID] = $this->arrange();
        $expected = $this->expectedUrl($fID);

        $rows = [];
        for ($offset = 0, $page = null; $page === null || !empty($page['more']); $offset += 100) {
            $page = $this->runCore('list_people', ['limit' => 100, 'offset' => $offset]);
            array_push($rows, ...(array) ($page['people'] ?? []));
        }
        $all = ['people' => $rows];

        $this->assertSame($expected, $this->row($all, $beta)['imageUrl'] ?? null);
        $this->assertArrayHasKey('imageUrl', $this->row($all, $gamma));
        $this->assertNull($this->row($all, $gamma)['imageUrl']);
    }

    public function testCoreGetPersonDetailsGivesAnAbsoluteImageUrl(): void
    {
        [$beta, , $fID] = $this->arrange();

        $result = $this->runCore('get_person_details', ['person_id' => $beta]);

        $this->assertSame($this->expectedUrl($fID), $result['person']['imageUrl'] ?? null);
    }

    public function testCoreGetPeopleImageFileIdsGivesAnAbsoluteImageUrl(): void
    {
        [$beta, , $fID] = $this->arrange();

        $result = $this->runCore('get_people_image_file_ids', []);

        $this->assertSame($this->expectedUrl($fID), $this->row($result, $beta)['imageUrl'] ?? null);
    }

    public function testCoreWithoutACanonicalUrlGivesNoUrlAndSaysWhy(): void
    {
        [$beta] = $this->arrange();
        $this->withoutCanonicalUrl();

        $result = $this->runCore('get_person_details', ['person_id' => $beta]);

        $this->assertNull($result['person']['imageUrl']);
        $this->assertStringContainsString('no canonical URL', (string) ($result['imageUrlNote'] ?? ''), json_encode($result));
    }
}
