<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\CheckResult;
use Katalysis\NeuronAi\Tests\Eval\Expectations;
use Katalysis\NeuronAi\Tests\Eval\ReplyChecks;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

/**
 * BF-13: the linked_files_exist reply check. Every link in a reply to a site file under /application/files/ names a
 * file on disk at the end of the turn. The disk is a list of paths here, so the test needs neither Concrete nor a
 * network. The replies are recorded ones: Glyn's chat 8172 after P3.3-6, and the photo URL a reset had replaced.
 */
final class LinkedFilesExistCheckTest extends TestCase
{
    private const SITE = 'katalysis-pro.test';

    /** Glyn's chat 8172 (27 October, after P3.3-6): the link was right, and the file was there. */
    private const GLYN_REPLY = 'The image on this page is accessible at the following URL: '
        . '[Neuron test person photo](http://katalysis-pro.test/application/files/3917/9311/0730/neuron-test-person.png).';

    /** The same photo before a reset re-imported it: this path returned 404 on 27 October. */
    private const STALE_REPLY = 'Here is the photo: http://katalysis-pro.test/application/files/9917/9309/6401/neuron-test-person.png.';

    private const ON_DISK = ['/application/files/3917/9311/0730/neuron-test-person.png'];

    private function check(string $reply): CheckResult
    {
        $snapshot = S::make([S::page(1, '/')]);
        $c = new CheckContext($snapshot, $snapshot, $snapshot, [], $reply, 'Give me a link to the image on this page.');

        return ReplyChecks::linkedFilesExistOn($c, self::SITE, static fn (string $path): bool => in_array($path, self::ON_DISK, true));
    }

    public function testALinkToAFileOnDiskPasses(): void
    {
        $this->assertTrue($this->check(self::GLYN_REPLY)->passed);
    }

    public function testALinkToAFileAResetReplacedFailsAndIsPrimary(): void
    {
        $result = $this->check(self::STALE_REPLY);

        $this->assertFalse($result->passed);
        $this->assertTrue($result->primary);
        $this->assertStringContainsString('9917/9309/6401/neuron-test-person.png', $result->detail);
        $this->assertStringNotContainsString('png.', $result->detail, 'trailing punctuation is not part of the URL');
    }

    public function testASiteRelativePathIsCheckedToo(): void
    {
        $this->assertFalse($this->check('See /application/files/1/2/3/missing.png for the file.')->passed);
        $this->assertTrue($this->check('See /application/files/3917/9311/0730/neuron-test-person.png for the file.')->passed);
    }

    public function testOtherHostsAndOtherPathsAreNotChecked(): void
    {
        $this->assertTrue($this->check('https://example.test/application/files/1/2/3/a.png and http://katalysis-pro.test/neuron-test/block-types')->passed);
        $this->assertTrue($this->check('No links here.')->passed);
    }

    public function testItRunsOnEveryExpectBlockWithoutBeingNamed(): void
    {
        $snapshot = S::make([S::page(1, '/')]);
        $names = array_map(
            static fn (CheckResult $r): string => $r->name,
            Expectations::evaluate([], new CheckContext($snapshot, $snapshot, $snapshot, [], 'No links here.', 'p'))
        );

        $this->assertContains('linked_files_exist', $names);
    }
}
