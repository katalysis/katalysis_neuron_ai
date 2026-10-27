<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\File\File;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\KatalysisPro;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\PeopleToolkit;

/**
 * BF-13: every file URL a tool gives the model points at a file that is there. Each URL is under the site's canonical
 * URL, and the file it names exists on disk under the web root. Checked on disk, with no request (no network in
 * tests). Found after P3.3-6: Glyn's link to the seed photo was right but showed nothing (a 1×1 pixel; the size is
 * SeedResetTest's check), and every reset re-imports the photo with a new path, so a URL can outlive its file.
 *
 * Uses the seed's photo ("Neuron Test Person Photo") on throwaway pages, and Test Person Alpha, who has it as her
 * image. No AI provider call.
 */
final class FileUrlTargetsTest extends ConcreteTestCase
{
    private const PHOTO = 'Neuron Test Person Photo';

    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
        $this->app()->make('cache/request')->flush();
    }

    public function testGetPageContentImageUrlsAreOnDisk(): void
    {
        $fID = $this->photoID();
        $page = $this->pages->create('File URL image block', ['<p><concrete-picture fid="' . $fID . '" alt="Photo" /></p>']);
        $page->addBlock(BlockType::getByHandle('image'), 'Main', ['fID' => $fID, 'altText' => 'Photo', 'title' => '', 'imageLink__which' => 'none']);
        CacheLocal::flush();

        $read = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => (int) $page->getCollectionID()]));

        $images = array_values(array_filter($read['blocks'], static fn (array $b): bool => isset($b['image'])));
        $this->assertCount(1, $images);
        $this->assertOnDisk((string) $images[0]['image']['url']);
        $srcs = $this->srcs((string) $read['html']);
        $this->assertNotSame([], $srcs, 'get_page_content gave no image src');
        foreach ($srcs as $src) {
            $this->assertOnDisk($src);
        }
    }

    public function testGetBlockControllerDataImageSrcIsOnDisk(): void
    {
        $fID = $this->photoID();
        $page = $this->pages->create('File URL controller data', ['<p><concrete-picture fid="' . $fID . '" alt="Photo" /></p>']);
        $bID = $this->pages->blockIDs((int) $page->getCollectionID())[0];

        $output = ThrowawayPages::runTool(new BlocksToolkit(), 'get_block_controller_data', ['blockID' => $bID, 'pageID' => (int) $page->getCollectionID()]);

        $srcs = $this->srcs($output);
        $this->assertNotSame([], $srcs, 'get_block_controller_data gave no image src: ' . $output);
        foreach ($srcs as $src) {
            $this->assertOnDisk($src);
        }
    }

    public function testGetPageFileAttributeUrlIsOnDisk(): void
    {
        $page = $this->pages->create('File URL thumbnail', ['<p>Has a thumbnail.</p>']);
        $page->setAttribute('thumbnail', File::getByID($this->photoID()));
        CacheLocal::flush();

        $read = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page', ['pageID' => (int) $page->getCollectionID()]));

        $this->assertOnDisk((string) ($read['attributes']['thumbnail']['url'] ?? ''));
    }

    public function testPeopleToolImageUrlsAreOnDisk(): void
    {
        if (!KatalysisPro::isInstalled()) {
            $this->markTestSkipped('katalysis_pro is not installed.');
        }
        $alpha = (int) $this->db()->fetchOne("SELECT sID FROM KatalysisPeople WHERE name = 'Test Person Alpha' AND email LIKE ? ORDER BY sID LIMIT 1", ['%@neuron-test.invalid']);
        $this->assertGreaterThan(0, $alpha, 'Test Person Alpha is not on this site: run the seed.');

        $core = $this->json(ThrowawayPages::runTool(new PeopleToolkit(), 'get_person_details', ['person_id' => $alpha]));
        $this->assertOnDisk((string) ($core['person']['imageUrl'] ?? ''));

        $class = KatalysisPro::TOOLKIT;
        foreach ((new $class())->definitions() as $definition) {
            if ($definition->name() === 'get_person') {
                $tool = $definition->toNeuronTool();
                $tool->setInputs(['personID' => $alpha])->execute();
                $pro = $this->json((string) $tool->getResult());
                $this->assertOnDisk((string) ($pro['person']['imageUrl'] ?? ''));

                return;
            }
        }
        $this->fail('katalysis_pro has no get_person');
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    /**
     * The URL is under the canonical URL, and the file its path names exists under the web root.
     */
    private function assertOnDisk(string $url): void
    {
        $canonical = rtrim((string) $this->app()->make('site')->getSite()->getSiteCanonicalURL(), '/');
        $this->assertNotSame('', $canonical, 'the dev site has a canonical URL');
        $this->assertStringStartsWith($canonical . '/', $url, "Not under the canonical URL: {$url}");

        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $this->assertStringStartsWith('/application/files/', $path, "Not a file manager URL: {$url}");
        $this->assertFileExists(rtrim(DIR_BASE, '/') . $path, "No file on disk for {$url}");
    }

    /**
     * @return list<string> the src URLs of the img tags
     */
    private function srcs(string $html): array
    {
        preg_match_all('~<img[^>]*\ssrc="([^"]+)"~i', $html, $m);

        return array_values(array_unique($m[1]));
    }

    private function photoID(): int
    {
        $fID = (int) $this->db()->fetchOne('SELECT MIN(fID) FROM FileVersions WHERE fvTitle = ? AND fvIsApproved = 1', [self::PHOTO]);
        $this->assertGreaterThan(0, $fID, 'The seed photo is missing: run the seed.');

        return $fID;
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

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
