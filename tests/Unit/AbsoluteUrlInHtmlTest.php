<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\Utilities\AbsoluteUrl;
use PHPUnit\Framework\TestCase;

/**
 * P3.3-1 (BF-12, B-TRU-9): site-relative src, href and srcset values in tool HTML are made absolute against the site's
 * canonical URL, so the model never has to add a host. Concrete's Html\Image gives a relative src beside an absolute
 * srcset.
 */
final class AbsoluteUrlInHtmlTest extends TestCase
{
    private const BASE = 'http://katalysis-pro.test/';

    public function testRelativeSrcHrefAndSrcsetBecomeAbsolute(): void
    {
        $html = '<a href="/neuron-test">x</a><img src="/application/files/1/a.png" srcset="/application/files/1/a.png 1x, /application/files/1/b.png 2x">';

        $this->assertSame(
            '<a href="http://katalysis-pro.test/neuron-test">x</a><img src="http://katalysis-pro.test/application/files/1/a.png" '
            . 'srcset="http://katalysis-pro.test/application/files/1/a.png 1x, http://katalysis-pro.test/application/files/1/b.png 2x">',
            AbsoluteUrl::inHtml($html, self::BASE)
        );
    }

    public function testSingleQuotedAttributesAndABaseWithoutASlash(): void
    {
        $this->assertSame("<img src='http://h.test/a.png'>", AbsoluteUrl::inHtml("<img src='/a.png'>", 'http://h.test'));
    }

    public function testEverythingElseIsLeftAlone(): void
    {
        $html = '<a href="https://other.test/x">a</a><a href="//cdn.test/x">b</a><a href="mailto:a@b.invalid">c</a>'
            . '<a href="#top">d</a><a href="page.html">e</a><img src="data:image/png;base64,AAAA"><p>src="/not-an-attribute"</p>';

        $this->assertSame($html, AbsoluteUrl::inHtml($html, self::BASE));
    }

    public function testNoBaseLeavesTheHtmlUnchanged(): void
    {
        $html = '<img src="/application/files/1/a.png">';

        $this->assertSame($html, AbsoluteUrl::inHtml($html, null));
        $this->assertSame($html, AbsoluteUrl::inHtml($html, '/'));
    }

    public function testHasRelativeUrlsFindsThemOnlyInAttributes(): void
    {
        $this->assertTrue(AbsoluteUrl::hasRelativeUrls('<img src="/a.png">'));
        $this->assertFalse(AbsoluteUrl::hasRelativeUrls('<img src="https://h.test/a.png"><p>src="/a.png" in text</p>'));
    }
}
