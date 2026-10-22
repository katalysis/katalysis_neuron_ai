<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\Utilities\TextIntegrity;
use PHPUnit\Framework\TestCase;

/**
 * BF-5: the text check shared by the block write tools (CLAUDE.md rule 7, B-T1-2, B-TRU-7). No Concrete.
 *
 * SEED is block 1 of the seeded Headings page, the block scenario 05 lost text from (plans/BF-5.md §2).
 */
final class TextIntegrityTest extends TestCase
{
    private const SEED = '<h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p>'
        . '<h3>Seed Heading Three A</h3><p>Text under the first h3 heading.</p>'
        . '<h3>Seed Heading Three B</h3><p>Text under the second h3 heading.</p>';

    public function testTagsCountAsSpacesSoJoinedParagraphsAreAChange(): void
    {
        $this->assertSame('A B', TextIntegrity::normalize('<p>A</p><p>B</p>'));
        $this->assertNotSame(TextIntegrity::normalize('<p>A</p><p>B</p>'), TextIntegrity::normalize('<p>AB</p>'));
        $this->assertSame('Fish & chips', TextIntegrity::normalize("  <b>Fish</b> &amp;\n chips "));
    }

    public function testKeepTextIsTheDefaultModeAndFreeformIsRefused(): void
    {
        $this->assertSame('keep_text', TextIntegrity::mode(null));
        $this->assertSame('keep_text', TextIntegrity::mode(''));
        $this->assertSame('scoped_text', TextIntegrity::mode(' Scoped_Text '));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('keep_text, formatting_only, scoped_text');
        TextIntegrity::mode('freeform');
    }

    public function testKeepTextAllowsAHeadingLevelChange(): void
    {
        $after = str_replace(['<h3>', '</h3>'], ['<h2>', '</h2>'], self::SEED);

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'keep_text');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertTrue($result['fields']['content']['unchanged']);
        $this->assertFalse($result['fields']['content']['orderChanged']);
    }

    public function testKeepTextAllowsAddedText(): void
    {
        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => self::SEED . '<p>New paragraph.</p>'], 'keep_text');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertFalse($result['fields']['content']['unchanged']);
    }

    public function testKeepTextRefusesARemovedParagraph(): void
    {
        $after = str_replace('<p>Text under the first h3 heading.</p>', '', self::SEED);

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'keep_text');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("'content'", $result['message']);
        $this->assertStringContainsString('Text under the first h3 heading.', $result['message']);
        $this->assertStringContainsString('Nothing was changed', $result['message']);
        $this->assertStringContainsString('scoped_text', $result['message'], 'The refusal says how to make an intended text change');
    }

    /** Scenario 05 runs 2 and 5 (P2.9): the paragraph replaced by invented text. */
    public function testKeepTextRefusesAReplacedParagraph(): void
    {
        $after = str_replace('Text under the second h3 heading.', 'Body text continues under the updated heading level.', self::SEED);

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'keep_text');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Text under the second h3 heading.', $result['message']);
    }

    public function testKeepTextReportsAReorder(): void
    {
        $after = '<p>Text under the first h3 heading.</p><h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p>'
            . '<h3>Seed Heading Three A</h3><h3>Seed Heading Three B</h3><p>Text under the second h3 heading.</p>';

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'keep_text');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertTrue($result['fields']['content']['orderChanged']);
    }

    public function testFormattingOnlyRefusesAnyTextChange(): void
    {
        $this->assertTrue(TextIntegrity::evaluate(['content' => '<p>A</p>'], ['content' => '<h2>A</h2>'], 'formatting_only')['ok']);

        $result = TextIntegrity::evaluate(['content' => '<p>A</p>'], ['content' => '<p>A</p><p>B</p>'], 'formatting_only');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('formatting_only', $result['message']);
    }

    public function testScopedTextNeedsTextFields(): void
    {
        $result = TextIntegrity::evaluate(['content' => '<p>A</p>'], ['content' => '<p>B</p>'], 'scoped_text', null, ['A']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('textFields', $result['message']);
    }

    /** Glyn, on approving BF-5: a rewrite works when the model names the text it replaces. */
    public function testScopedTextRewritesAParagraphWhenTheReplacedTextIsNamed(): void
    {
        $after = str_replace('Text under the first h3 heading.', 'A rewritten paragraph.', self::SEED);

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'scoped_text', ['content'], ['Text under the first h3 heading.']);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(['Text under the first h3 heading.'], $result['fields']['content']['removedText']);
    }

    public function testScopedTextRefusesTheSameRewriteWithoutNamingTheText(): void
    {
        $after = str_replace('Text under the first h3 heading.', 'A rewritten paragraph.', self::SEED);

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'scoped_text', ['content']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Text under the first h3 heading.', $result['message']);
        $this->assertStringContainsString('replacedText', $result['message']);
    }

    public function testScopedTextRefusesALossTheNamedTextDoesNotCover(): void
    {
        // Names one paragraph but drops two.
        $after = str_replace(['Text under the first h3 heading.', 'Text under the second h3 heading.'], ['New.', ''], self::SEED);

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'scoped_text', ['content'], ['Text under the first h3 heading.']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Text under the second h3 heading.', $result['message']);
    }

    public function testScopedTextRefusesNamedTextThatIsNotInTheBlock(): void
    {
        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => self::SEED], 'scoped_text', ['content'], ['Words that were never there.']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Words that were never there.', $result['message']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    public function testScopedTextKeepsFieldsItDoesNotListIdentical(): void
    {
        $before = ['title' => 'Title', 'paragraph' => '<p>Body</p>'];

        $result = TextIntegrity::evaluate($before, ['title' => 'Title and more', 'paragraph' => '<p>New body</p>'], 'scoped_text', ['paragraph'], ['Body']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("'title'", $result['message']);
    }

    /** Scenario 05 run 1 (P2.9) and the BF-5 "before" run 4: the cut read written back. */
    public function testTextBuiltFromAShortenedReadIsRefusedInEveryMode(): void
    {
        $shortened = '<h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p><h2>Seed Heading Three A...</h2>';

        foreach (['keep_text', 'formatting_only'] as $mode) {
            $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $shortened], $mode);
            $this->assertFalse($result['ok'], $mode);
            $this->assertStringContainsString('shortened read', $result['message'], $mode);
            $this->assertStringContainsString('get_block_data', $result['message'], $mode);
        }

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $shortened], 'scoped_text', ['content'], [
            'Text under the first h3 heading.', 'Seed Heading Three B', 'Text under the second h3 heading.',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('shortened read', $result['message']);
    }

    public function testAnEllipsisThatIsReallyInTheTextIsAllowed(): void
    {
        $before = '<p>Wait for it... and then it happened.</p>';

        $this->assertTrue(TextIntegrity::evaluate(['content' => $before], ['content' => '<h2>Wait for it... and then it happened.</h2>'], 'keep_text')['ok']);
        $this->assertTrue(TextIntegrity::evaluate(['content' => $before], ['content' => $before . '<p>More to come...</p>'], 'keep_text')['ok']);
    }

    public function testOmittedFieldsAreKeptAndAnExplicitEmptyValueStillClears(): void
    {
        $before = ['bID' => 7, 'title' => 'Title', 'paragraph' => '<p>Body</p>', 'icon' => '', 'internalLinkCID' => 0, 'fID' => '0', 'titleFormat' => null];

        $merged = TextIntegrity::keepOmittedFields($before, ['paragraph' => '<p>Body, longer</p>']);
        $this->assertSame(['paragraph' => '<p>Body, longer</p>', 'title' => 'Title'], $merged['value']);
        $this->assertSame(['title'], $merged['keptFields']);

        $cleared = TextIntegrity::keepOmittedFields($before, ['title' => '', 'paragraph' => '<p>Body</p>']);
        $this->assertSame('', $cleared['value']['title']);
        $this->assertSame([], $cleared['keptFields']);
    }
}
