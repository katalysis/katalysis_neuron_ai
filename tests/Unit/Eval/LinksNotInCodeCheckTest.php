<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\CheckResult;
use Katalysis\NeuronAi\Tests\Eval\ReplyChecks;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

/**
 * P3.7-2 (B-REP-1 (b), found in P2.6's browser checks): one way to show links. A URL or a site path is never in code
 * formatting, where the panel shows it as text instead of a link. Secondary: the link is still there.
 */
final class LinksNotInCodeCheckTest extends TestCase
{
    private function check(string $reply): CheckResult
    {
        $snapshot = S::make([S::page(1, '/')]);

        return ReplyChecks::linksNotInCode(new CheckContext($snapshot, $snapshot, $snapshot, [], $reply, 'Where is it?'));
    }

    public function testAMarkdownLinkOrAPlainUrlPasses(): void
    {
        $this->assertTrue($this->check('See [Headings](http://katalysis-pro.test/neuron-test/headings).')->passed);
        $this->assertTrue($this->check('It is at http://katalysis-pro.test/neuron-test/headings.')->passed);
        $this->assertTrue($this->check('I changed the `<h3>` headings to `<h2>`.')->passed, 'code that is not a link');
    }

    public function testAUrlOrPathInCodeFormattingFailsAsSecondary(): void
    {
        $result = $this->check('The page is at `http://katalysis-pro.test/neuron-test/headings`.');

        $this->assertFalse($result->passed);
        $this->assertFalse($result->primary);
        $this->assertFalse($this->check('It is at `/neuron-test/headings`.')->passed);
    }
}
