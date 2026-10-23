<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tests\Support\FixtureDrift;
use PHPUnit\Framework\TestCase;

/**
 * WP-2: the comparison of two fixture fingerprints, without Concrete. FixtureDriftIntegrationTest checks the
 * fingerprint itself on the dev site.
 */
final class FixtureDriftTest extends TestCase
{
    public function testNothingChangedIsNoDifference(): void
    {
        $this->assertSame([], FixtureDrift::differences($this->seeded(), $this->seeded()));
    }

    /** BF-4's case: a page-properties save made an unapproved version 2 of /neuron-test/headings. */
    public function testANewVersionNamesThePageAndItsVersions(): void
    {
        $now = $this->seeded();
        $now['/neuron-test/headings'] = ['cID' => 12, 'versions' => 2, 'newest' => 2, 'newestApproved' => false, 'hash' => 'b'];

        $this->assertSame(
            ['/neuron-test/headings (page ID 12) has 2 version(s), newest 2 (not approved); the seed left 1, newest 1'],
            FixtureDrift::differences($this->seeded(), $now)
        );
    }

    public function testAChangeInPlaceNamesThePage(): void
    {
        $now = $this->seeded();
        $now['/neuron-test/columns']['hash'] = 'changed';

        $this->assertSame(
            ['/neuron-test/columns (page ID 13) was changed in place (a version, block, text or attribute row)'],
            FixtureDrift::differences($this->seeded(), $now)
        );
    }

    public function testAddedGoneAndReplacedPagesAreNamed(): void
    {
        $now = $this->seeded();
        unset($now['/neuron-test/columns']);
        $now['/neuron-test/headings']['cID'] = 99;
        $now['/neuron-test/extra'] = ['cID' => 50, 'versions' => 1, 'newest' => 1, 'newestApproved' => true, 'hash' => 'e'];

        $this->assertSame(
            [
                '/neuron-test/columns (page ID 13) is gone',
                '/neuron-test/extra (page ID 50) was added',
                '/neuron-test/headings is a different page (page ID 99, the seed made 12)',
            ],
            FixtureDrift::differences($this->seeded(), $now)
        );
    }

    public function testTheMessageStartsWithTheAgreedWordsAndSaysHowToReset(): void
    {
        $message = FixtureDrift::message(['/neuron-test/headings (page ID 12) was changed in place']);

        $this->assertStringStartsWith('fixture drift: run the seed reset.', $message);
        $this->assertStringContainsString("\n  /neuron-test/headings (page ID 12) was changed in place\n", $message);
        $this->assertStringContainsString('seed_test_content.php -- --reset', $message);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function seeded(): array
    {
        return [
            '/neuron-test' => ['cID' => 11, 'versions' => 1, 'newest' => 1, 'newestApproved' => true, 'hash' => 'r'],
            '/neuron-test/columns' => ['cID' => 13, 'versions' => 1, 'newest' => 1, 'newestApproved' => true, 'hash' => 'c'],
            '/neuron-test/headings' => ['cID' => 12, 'versions' => 1, 'newest' => 1, 'newestApproved' => true, 'hash' => 'a'],
        ];
    }
}
