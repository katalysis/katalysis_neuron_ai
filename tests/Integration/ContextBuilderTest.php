<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Context\ContextBuilder;
use Katalysis\NeuronAi\PageContextResolver;
use Katalysis\NeuronAi\Tests\Eval\PanelPath;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;

/**
 * P3.1: the context the model gets, built from the panel's payload as the chat endpoint receives it (BF-1): the page,
 * its areas and blocks with short previews (BF-10: the old snapshot never had a preview), the user's permissions on
 * the page, the tier and the site's language. Read only; the seed's fixture pages.
 */
final class ContextBuilderTest extends ConcreteTestCase
{
    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
    }

    private static function contextFromThePanel(string $path, bool $editMode = false): string
    {
        $page = Page::getByPath($path);
        $payload = PanelPath::pageContext($page, $editMode);

        return (new ContextBuilder((new PageContextResolver())->fromBrowser($payload)))->build();
    }

    public function testTheContextFromThePanelsPayloadHasThePageItsBlocksAndWhatTheUserMayDo(): void
    {
        ThrowawayPages::actAs('neuron_content_editor');

        $context = self::contextFromThePanel('/neuron-test/headings');

        $this->assertStringContainsString('Current Page Path: /neuron-test/headings', $context);
        $this->assertStringContainsString('- Main: content "Seed Heading Two', $context, 'block types with previews (BF-10)');
        $this->assertStringContainsString('Your permissions on this page: can ', $context);
        $this->assertMatchesRegularExpression('/can [^;]*edit contents/', $context);
        // The seed gives the editors Edit Contents and Approve under /neuron-test, but not Delete.
        $this->assertMatchesRegularExpression('/cannot [^\n]*delete it/', $context);
        $this->assertStringContainsString('Assistant tier: 1 (Content)', $context);
        $this->assertMatchesRegularExpression('/Site language: [a-z]{2}_[A-Z]{2}/', $context);
        $this->assertStringContainsString('User: neuron_content_editor', $context);
    }

    public function testOnALockedPageTheUserCannotEditContents(): void
    {
        ThrowawayPages::actAs('neuron_content_editor');

        $this->assertMatchesRegularExpression('/cannot [^\n]*edit contents/', self::contextFromThePanel('/neuron-test/locked'));
    }

    public function testWhatTheUserCannotDoSaysWhoCanGrantIt(): void
    {
        // P3.7-2 (P3.2-2's finding in 11 and 33): a refusal from the context alone still says who can grant it.
        ThrowawayPages::actAs('neuron_content_editor');

        $this->assertMatchesRegularExpression('/cannot [^\n]*edit contents[^\n]*\. A site administrator can grant it in the page\'s Permissions/', self::contextFromThePanel('/neuron-test/locked'));
    }

    public function testInEditModeThePageIsFoundByItsId(): void
    {
        ThrowawayPages::actAs('neuron_content_editor');

        $context = self::contextFromThePanel('/neuron-test/edit-mode', true);

        $this->assertStringContainsString('Current Page Path: /neuron-test/edit-mode', $context);
        $this->assertStringContainsString('content "Edit Mode Heading Two', $context);
    }

    public function testAGlobalAreaIsMarkedShared(): void
    {
        ThrowawayPages::actAs('neuron_content_editor');

        $context = self::contextFromThePanel('/neuron-test/shared-a');

        $this->assertStringContainsString('- Neuron Test Banner (global area, shown on ', $context);
        $this->assertStringContainsString('content "Spring opening hours', $context);
        $this->assertStringNotContainsString('Spring opening hours: Monday to Friday, 9am to 5pm.', $context, 'a preview, not the full text');
    }
}
