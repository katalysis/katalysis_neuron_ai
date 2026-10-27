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

    public function testKeepTextRefusesAReorderThatScopedTextReports(): void
    {
        $after = '<p>Text under the first h3 heading.</p><h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p>'
            . '<h3>Seed Heading Three A</h3><h3>Seed Heading Three B</h3><p>Text under the second h3 heading.</p>';

        // Since P3.3-2 (BF-5 decision 7) keep_text refuses a reorder; scoped_text saves it and reports it.
        $this->assertFalse(TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'keep_text')['ok']);

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'scoped_text', ['content']);
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

    // ---- BF-7, Glyn's browser check 6: escaped JSON copied back into a write ------------------------------------

    /** The block as the hand edit in edit mode stored it: real CR LF line breaks between the elements. */
    private const STORED_CRLF = "<h2>Seed Heading Two Changed</h2>\r\n\r\n<p>This paragraph sits under the h2 heading.</p>\r\n\r\n"
        . "<h3>Seed Heading Three A</h3>\r\n\r\n<p>Text under the first h3 heading.</p>\r\n\r\n"
        . "<h3>Seed Heading Three B</h3>\r\n\r\n<p>Text under the second h3 heading.</p>\r\n";

    /** What the model wrote back (chat 3995): the JSON-encoded read copied as text, so "<\/h2>" and "\r\n" are literal. */
    private const CORRUPT = '<h2>Seed Heading Two Changed Again!<\/h2>\r\n\r\n<p>This paragraph sits under the h2 heading.<\/p>\r\n\r\n'
        . '<h3>Seed Heading Three A<\/h3>\r\n\r\n<p>Text under the first h3 heading.<\/p>\r\n\r\n'
        . '<h3>Seed Heading Three B<\/h3>\r\n\r\n<p>Text under the second h3 heading.<\/p>';

    public function testTheBrowserCheck6CorruptionIsRefused(): void
    {
        $result = TextIntegrity::evaluate(['content' => self::STORED_CRLF], ['content' => self::CORRUPT], 'scoped_text', ['content'], ['Seed Heading Two Changed']);

        $this->assertFalse($result['ok'], 'the corrupted value passed the text check');
        $this->assertStringContainsString("Field 'content' contains JSON escape sequences", $result['message']);
        $this->assertStringContainsString('<\/', $result['message']);
        $this->assertStringContainsString('\r\n', $result['message']);
        $this->assertStringContainsString('Nothing was changed', $result['message']);
    }

    public function testTheSameEditWrittenAsHtmlPasses(): void
    {
        $fixed = str_replace('Seed Heading Two Changed', 'Seed Heading Two Changed Again!', self::STORED_CRLF);

        $result = TextIntegrity::evaluate(['content' => self::STORED_CRLF], ['content' => $fixed], 'scoped_text', ['content'], ['Seed Heading Two Changed']);

        $this->assertTrue($result['ok'], $result['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escapeProvider(): array
    {
        return [
            'escaped slash' => ['<p>Text under the first h3 heading.<\/p>'],
            'CR LF' => ['<p>Text under the first h3 heading.</p>\r\n'],
            'LF' => ['<p>Text under the first h3 heading.</p>\n'],
            'escaped quote' => ['<p>Text under the first h3 heading. <a href=\"/x\">x</a></p>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('escapeProvider')]
    public function testEachEscapeSequenceIsRefusedInEveryMode(string $added): void
    {
        foreach (['keep_text', 'formatting_only', 'scoped_text'] as $mode) {
            $after = $mode === 'formatting_only' ? str_replace('<p>Text under the first h3 heading.</p>', $added, self::SEED) : self::SEED . $added;
            $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], $mode, $mode === 'scoped_text' ? ['content'] : null);

            $this->assertFalse($result['ok'], "{$mode} let an escape sequence through: {$added}");
            $this->assertStringContainsString('JSON escape sequences', $result['message']);
        }
    }

    public function testAnEscapeSequenceTheStoredTextAlreadyHasIsAllowed(): void
    {
        $before = '<p>Type C:\new\folder to open it.</p>';

        $result = TextIntegrity::evaluate(['content' => $before], ['content' => '<h2>Type C:\new\folder to open it.</h2>'], 'keep_text');

        $this->assertTrue($result['ok'], $result['message']);
    }

    public function testHtmlThatIsNoLongerWellFormedIsRefused(): void
    {
        $after = str_replace('</h2>', '', self::SEED);

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'keep_text');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("Field 'content': the new HTML is not well-formed", $result['message']);
        $this->assertStringContainsString('<h2>', $result['message']);
    }

    public function testAWellFormedFixOfCorruptedStoredHtmlIsAllowed(): void
    {
        // Rewriting the whole corrupted block: every stored piece is named as replaced.
        $result = TextIntegrity::evaluate(['content' => self::CORRUPT], ['content' => self::SEED], 'scoped_text', ['content'], [TextIntegrity::normalize(self::CORRUPT)]);

        $this->assertTrue($result['ok'], $result['message']);
    }

    public function testKeepTextRefusesARemovedElementButAllowsARenamedOrAddedOne(): void
    {
        $withLink = '<p>Read <a href="/x">the guide</a> first.</p>';

        $unlinked = TextIntegrity::evaluate(['content' => $withLink], ['content' => '<p>Read the guide first.</p>'], 'keep_text');
        $this->assertFalse($unlinked['ok'], 'keep_text dropped a link');
        $this->assertStringContainsString('tag structure changed', $unlinked['message']);
        $this->assertStringContainsString('<a>', $unlinked['message']);

        $merged = TextIntegrity::evaluate(['content' => '<p>A.</p><p>B.</p>'], ['content' => '<p>A. B.</p>'], 'keep_text');
        $this->assertFalse($merged['ok'], 'keep_text merged two paragraphs');

        $this->assertTrue(TextIntegrity::evaluate(['content' => $withLink], ['content' => '<h2>Read <a href="/x">the guide</a> first.</h2>'], 'keep_text')['ok']);
        $this->assertTrue(TextIntegrity::evaluate(['content' => $withLink], ['content' => $withLink . '<p>New.</p>'], 'keep_text')['ok']);
    }

    // ---- P3.3-2: literal \n and \" only refused where they look copied from an encoded result -------------------

    /**
     * New text an author can mean: a Windows path, a code example, quoted speech written with backslashes.
     *
     * @return array<string, array{string}>
     */
    public static function legitimateBackslashProvider(): array
    {
        return [
            'Windows path' => ['<p>Save it to C:\new\temp\notes.txt and open it.</p>'],
            'code example' => ['<pre><code>printf("Hello\n"); puts("tab\there");</code></pre>'],
            'escaped quote in text' => ['<p>In JSON a quote is written \" inside a string.</p>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legitimateBackslashProvider')]
    public function testABackslashSequenceInTextIsAllowed(string $added): void
    {
        $this->assertNull(TextIntegrity::checkNewValue(['content' => $added]), 'a new block refused it');

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => self::SEED . $added], 'keep_text');
        $this->assertTrue($result['ok'], $result['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function copiedEscapeProvider(): array
    {
        return [
            '\n after a tag' => ['<p>New text.</p>\n'],
            '\n before a tag' => ['<p>New text.\n</p>'],
            '\r\n between tags' => ['<p>One.</p>\r\n<p>Two.</p>'],
            '\" in an attribute' => ['<p><a href=\"/x\">link</a></p>'],
            '\n in text, with <\/ elsewhere' => ['<p>Line one\nline two.<\/p>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('copiedEscapeProvider')]
    public function testABackslashSequenceNextToMarkupIsRefused(string $added): void
    {
        $this->assertStringContainsString('JSON escape sequences', (string) TextIntegrity::checkNewValue(['content' => $added]));

        $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => self::SEED . $added], 'keep_text');
        $this->assertFalse($result['ok'], 'keep_text let it through');
        $this->assertStringContainsString('JSON escape sequences', $result['message']);
    }

    // ---- P3.3-2: strict order under keep_text (BF-5 decision 7) -----------------------------------------------

    public function testKeepTextRefusesAReorderAndScopedTextAllowsIt(): void
    {
        $after = '<h3>Seed Heading Three B</h3><p>Text under the second h3 heading.</p>'
            . '<h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p>'
            . '<h3>Seed Heading Three A</h3><p>Text under the first h3 heading.</p>';

        $kept = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'keep_text');
        $this->assertFalse($kept['ok'], 'keep_text let a reorder through');
        $this->assertStringContainsString('changes the order of the existing text', $kept['message']);
        $this->assertStringContainsString('Nothing was changed', $kept['message']);
        $this->assertStringContainsString('scoped_text', $kept['message']);

        $scoped = TextIntegrity::evaluate(['content' => self::SEED], ['content' => $after], 'scoped_text', ['content']);
        $this->assertTrue($scoped['ok'], $scoped['message']);
        $this->assertTrue($scoped['fields']['content']['orderChanged']);
    }

    public function testAShortPieceOfTextThatAlsoAppearsEarlierIsNotAReorder(): void
    {
        $before = '<h2>About us</h2><p>Contact</p><p>us</p>';

        $result = TextIntegrity::evaluate(['content' => $before], ['content' => '<h3>About us</h3><p>Contact</p><p>us</p>'], 'keep_text');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertFalse($result['fields']['content']['orderChanged']);
    }

    // ---- P3.3-2: no code in content (B-T1-10) -----------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function codeProvider(): array
    {
        return [
            'script element' => ['<p>Hi</p><script>alert(1)</script>', '<script>'],
            'event handler' => ['<p>Hi</p><img src="/a.png" alt="" onerror="alert(1)">', 'event-handler attribute (onerror)'],
            'javascript: URL' => ['<p><a href="javascript:alert(1)">Hi</a></p>', 'javascript: URL'],
            'javascript: URL, entity and tab' => ['<p><a href="jav&#x61;\tscript:alert(1)">Hi</a></p>', 'javascript: URL'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('codeProvider')]
    public function testCodeInContentIsRefusedInEveryMode(string $added, string $named): void
    {
        $added = str_replace('\t', "\t", $added);
        $this->assertStringContainsString($named, (string) TextIntegrity::checkNewValue(['content' => $added]));

        foreach (['keep_text', 'scoped_text'] as $mode) {
            $result = TextIntegrity::evaluate(['content' => self::SEED], ['content' => self::SEED . $added], $mode, $mode === 'scoped_text' ? ['content'] : null);
            $this->assertFalse($result['ok'], "{$mode} let code through");
            $this->assertStringContainsString($named, $result['message']);
            $this->assertStringContainsString('Nothing was changed', $result['message']);
        }
    }

    public function testCodeTheStoredValueAlreadyHasIsKeptAndProseAboutItIsAllowed(): void
    {
        $embed = '<div id="map"></div><script src="https://maps.example.test/embed.js"></script>';
        $this->assertTrue(TextIntegrity::evaluate(['content' => $embed], ['content' => '<p>Our map:</p>' . $embed], 'keep_text')['ok']);

        $this->assertNull(TextIntegrity::checkNewValue(['content' => '<p>Never paste javascript: links or onclick= handlers into a page.</p>']));
        $this->assertNull(TextIntegrity::checkNewValue(['content' => '<p>The <code>&lt;script&gt;</code> element runs code.</p>']));
    }

    public function testANewValueIsCheckedOnItsOwn(): void
    {
        $this->assertNull(TextIntegrity::checkNewValue(['content' => '<h1>A further New Page Test</h1>']));
        $this->assertStringContainsString('JSON escape sequences', (string) TextIntegrity::checkNewValue(['content' => '<h1>A<\/h1>']));
        $this->assertStringContainsString('not well-formed', (string) TextIntegrity::checkNewValue(['content' => '<h1>A<p>B</p>']));
    }
}
