<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Context\ContextBuilder;
use PHPUnit\Framework\TestCase;

/**
 * P3.1: the context's compact lines, without Concrete: the area and block tree (block types with short previews), the
 * user's permissions on the page, the tier. ContextBuilderTest builds them from a real page and the panel's payload.
 */
final class ContextBuilderFormatTest extends TestCase
{
    public function testAnAreaListsItsBlockTypesWithShortPreviews(): void
    {
        $this->assertSame(
            '- Main: content "Seed Heading Two This paragraph", image, content "Closing"',
            ContextBuilder::areaLine('Main', null, [
                ['type' => 'content', 'preview' => 'Seed Heading Two This paragraph'],
                ['type' => 'image', 'preview' => null],
                ['type' => 'content', 'preview' => 'Closing'],
            ], 0)
        );
    }

    public function testAGlobalAreaSaysItIsSharedAndALongAreaIsCut(): void
    {
        $blocks = array_fill(0, ContextBuilder::MAX_BLOCKS_PER_AREA + 2, ['type' => 'autonav', 'preview' => null]);

        $line = ContextBuilder::areaLine('Header Navigation', ['name' => 'Header Navigation', 'pagesShowingIt' => 20], $blocks, 1);

        $this->assertStringStartsWith('- Header Navigation (global area, shown on 20 pages): autonav', $line);
        $this->assertStringEndsWith(', +2 more, 1 you can\'t view', $line);
    }

    public function testAPreviewIsShortWithAMarkWhenCut(): void
    {
        $this->assertSame('Short text', ContextBuilder::preview('<p>Short   text</p>'));
        $long = ContextBuilder::preview('<p>' . str_repeat('word ', 40) . '</p>');
        $this->assertLessThanOrEqual(ContextBuilder::PREVIEW_LENGTH + 1, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
        $this->assertNull(ContextBuilder::preview('<p> </p>'));
    }

    public function testThePermissionsAreOneShortLine(): void
    {
        $this->assertSame(
            'Your permissions on this page: can edit contents, add sub-pages; cannot delete it, publish (approve versions)',
            ContextBuilder::permissionsLine(['edit contents' => true, 'add sub-pages' => true, 'delete it' => false, 'publish (approve versions)' => false])
        );
    }

    public function testTheTierIsNamed(): void
    {
        $this->assertSame('Assistant tier: 1 (Content)', ContextBuilder::tierLine(1));
        $this->assertSame('Assistant tier: 3 (Generate)', ContextBuilder::tierLine(3));
        $this->assertSame('Assistant tier: none', ContextBuilder::tierLine(0));
    }
}
