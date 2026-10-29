<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Context;

use Concrete\Core\Area\Area;
use Concrete\Core\Attribute\Key\CollectionKey;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Katalysis\NeuronAi\Access\CoreAliases;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use Katalysis\NeuronAi\Access\NeuronPermissions;
use Katalysis\NeuronAi\Extension\Tier;
use Katalysis\NeuronAi\Tools\Utilities\HtmlToText;
use Katalysis\NeuronAi\Tools\Utilities\PageVersionRead;
use Katalysis\NeuronAi\Tools\Utilities\SharedArea;

/**
 * The "CURRENT CONTEXT" section of the system message (P3.1; moved out of ConcreteCmsAgent). From the page context the
 * chat endpoint resolved from the panel's payload (PageContextResolver, BF-1):
 *
 * - the page: ID, name, path, type, template, description, and its set attributes (at most 20);
 * - its areas and blocks, compactly: each area's block types in order, with a short text preview (BF-10: the old
 *   snapshot never had one), global areas marked shared (P3.6a), blocks the user may not view counted, not shown;
 * - the user's permissions on the page, in one line (B-CTX-3);
 * - the user, the assistant tier (B-CTX-3) and the site's language (B-LNG-2).
 *
 * It stays small, because it is sent with every request of a turn: no full text (get_page_content gives it), no block
 * IDs (the read tools give them), at most MAX_AREAS areas and MAX_BLOCKS_PER_AREA blocks each.
 */
final class ContextBuilder
{
    public const PREVIEW_LENGTH = 40;
    public const MAX_BLOCKS_PER_AREA = 6;
    public const MAX_AREAS = 12;
    private const MAX_ATTRIBUTES = 20;

    private const TIER_NAMES = [1 => 'Content', 2 => 'Configure', 3 => 'Generate'];

    /**
     * @param array<string, mixed>|null $runtimePageContext the page context the chat endpoint resolved, or null
     */
    public function __construct(private readonly ?array $runtimePageContext)
    {
    }

    public function build(): string
    {
        $lines = [];

        // Under the CLI there is no current page, so build page context only when the caller passed one (the scenario
        // runner does). Outside the CLI this is always true.
        if (php_sapi_name() !== 'cli' || $this->runtimePageContext !== null) {
            try {
                $page = $this->resolvePage();
                if ($page && !$page->isError()) {
                    // BF-10: the newest version, as editors see it and as the read tools show it.
                    $lines = array_merge($lines, $this->pageLines(PageVersionRead::newestIfAllowed($page)));
                } elseif ($this->runtimePageContext) {
                    $lines = array_merge($lines, $this->runtimeFallbackLines());
                }
            } catch (\Throwable) {
                // Without page context, the rest still goes.
            }
        }

        try {
            $user = new \Concrete\Core\User\User();
            if ($user->isRegistered()) {
                $lines[] = 'User: ' . $user->getUserName();
                $lines[] = 'User ID: ' . $user->getUserID();
            }
            $lines[] = self::tierLine(NeuronPermissions::tier());
            $locale = $this->siteLocale();
            if ($locale !== null) {
                $lines[] = 'Site language: ' . $locale;
            }
        } catch (\Throwable) {
            // Bootstrap not complete.
        }

        return implode("\n", $lines);
    }

    /**
     * One area: its block types in order, with a short preview where a block has text.
     *
     * @param array{name: string, pagesShowingIt: int}|null $global
     * @param list<array{type: string, preview: ?string}> $blocks
     * @param int $hidden blocks the user may not view
     */
    public static function areaLine(string $handle, ?array $global, array $blocks, int $hidden): string
    {
        $items = [];
        foreach (array_slice($blocks, 0, self::MAX_BLOCKS_PER_AREA) as $block) {
            $items[] = $block['type'] . ($block['preview'] !== null && $block['preview'] !== '' ? ' "' . $block['preview'] . '"' : '');
        }
        $more = count($blocks) - self::MAX_BLOCKS_PER_AREA;
        if ($more > 0) {
            $items[] = '+' . $more . ' more';
        }
        if ($hidden > 0) {
            $items[] = $hidden . ' you can\'t view';
        }
        $label = $global !== null ? $handle . ' (global area, shown on ' . $global['pagesShowingIt'] . ' ' . ($global['pagesShowingIt'] === 1 ? 'page' : 'pages') . ')' : $handle;

        return '- ' . $label . ': ' . implode(', ', $items);
    }

    /** A block's text, as a short preview, or null when it has none. */
    public static function preview(string $html): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', HtmlToText::convert($html)));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::PREVIEW_LENGTH ? rtrim(mb_substr($text, 0, self::PREVIEW_LENGTH)) . '…' : $text;
    }

    /**
     * When the user can't do something, the line ends with who can grant it, as the tools' refusals do
     * (ObjectPermissions), so a refusal made from the context alone says it too (P3.7-2, after 11 and 33 in P3.2-2).
     *
     * @param array<string, bool> $permissions what the user may do on the page, by phrase
     */
    public static function permissionsLine(array $permissions): string
    {
        $can = array_keys(array_filter($permissions));
        $cannot = array_keys(array_filter($permissions, static fn (bool $v): bool => !$v));
        $parts = [];
        if ($can !== []) {
            $parts[] = 'can ' . implode(', ', $can);
        }
        if ($cannot !== []) {
            $parts[] = 'cannot ' . implode(', ', $cannot);
        }

        return 'Your permissions on this page: ' . implode('; ', $parts) . ($cannot !== [] ? '. ' . ObjectPermissions::GRANT_PAGE : '');
    }

    public static function tierLine(int $tier): string
    {
        return isset(self::TIER_NAMES[$tier]) ? 'Assistant tier: ' . $tier . ' (' . self::TIER_NAMES[$tier] . ')' : 'Assistant tier: none';
    }

    private function resolvePage(): ?Page
    {
        $runtimePath = $this->runtimePageContext['path'] ?? null;
        $runtimeId = $this->runtimePageContext['id'] ?? null;
        $hasRuntimeHint = is_numeric($runtimeId) || (is_string($runtimePath) && $runtimePath !== '');

        if (is_numeric($runtimeId)) {
            $page = Page::getByID((int) $runtimeId, 'ACTIVE');
            if ($page && !$page->isError()) {
                return $page;
            }
        }

        if (is_string($runtimePath) && $runtimePath !== '') {
            $page = Page::getByPath($runtimePath, 'ACTIVE');
            if ($page && !$page->isError()) {
                return $page;
            }
        }

        if ($hasRuntimeHint) {
            return null;
        }

        return Page::getCurrentPage();
    }

    /** @return list<string> */
    private function pageLines(Page $page): array
    {
        $lines = [
            'Current Page ID: ' . $page->getCollectionID(),
            'Current Page Name: ' . $page->getCollectionName(),
            'Current Page Path: ' . $page->getCollectionPath(),
            'Current Page Type: ' . ($page->getPageTypeHandle() ?: 'N/A'),
            'Current Page Template: ' . ($page->getPageTemplateHandle() ?: 'N/A'),
        ];

        $description = trim((string) $page->getCollectionDescription());
        if ($description !== '') {
            $lines[] = 'Current Page Description: ' . $description;
        }

        $attributeLines = $this->attributeLines($page);
        if ($attributeLines !== []) {
            $lines[] = 'Current Page Attributes:';
            foreach ($attributeLines as $attributeLine) {
                $lines[] = '- ' . $attributeLine;
            }
        }

        $areaLines = $this->areaLines($page);
        if ($areaLines !== []) {
            // BF-5: shortened previews, so the heading says so and names the tool for the full text.
            $lines[] = 'Current Page Areas and Blocks (block types in order, with shortened previews; get_page_content returns the full text):';
            array_push($lines, ...$areaLines);
        }

        $lines[] = self::permissionsLine($this->permissions($page));

        return $lines;
    }

    /** @return list<string> */
    private function runtimeFallbackLines(): array
    {
        $lines = [];
        $runtimeId = $this->runtimePageContext['id'] ?? null;
        if (is_numeric($runtimeId)) {
            $lines[] = 'Current Page ID: ' . (int) $runtimeId;
        }
        foreach (['title' => 'Current Page Name', 'path' => 'Current Page Path', 'url' => 'Current Page URL'] as $key => $label) {
            $value = trim((string) ($this->runtimePageContext[$key] ?? ''));
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function attributeLines(Page $page): array
    {
        $lines = [];
        try {
            foreach (CollectionKey::getList() as $key) {
                $handle = (string) $key->getAttributeKeyHandle();
                if ($handle === '') {
                    continue;
                }
                $value = self::normalize($page->getAttribute($handle));
                if ($value === null || $value === '') {
                    continue;
                }
                $lines[] = $handle . ': ' . $value;
                if (count($lines) >= self::MAX_ATTRIBUTES) {
                    break;
                }
            }
        } catch (\Throwable) {
            // Without attributes.
        }

        return $lines;
    }

    /**
     * The page's areas, its global areas included (they are on their stacks, P3.6a/BF-23).
     *
     * @return list<string>
     */
    private function areaLines(Page $page): array
    {
        $lines = [];
        try {
            // BF-19: Concrete's View Block check needs the aliases loaded.
            CoreAliases::load();
            $handles = array_values(array_unique(array_merge(Area::getHandleList(), SharedArea::globalAreaHandles($page))));
            foreach ($handles as $handle) {
                $shared = SharedArea::readableBlocks($page, (string) $handle);
                $hidden = 0;
                if ($shared !== null) {
                    $visible = $shared['blocks'];
                } else {
                    $visible = [];
                    foreach ($page->getBlocks((string) $handle) as $block) {
                        if ((new Checker($block))->canViewBlock()) {
                            $visible[] = $block;
                        } else {
                            $hidden++;
                        }
                    }
                }
                if ($visible === [] && $hidden === 0) {
                    continue;
                }
                $blocks = array_map(static fn ($block): array => ['type' => (string) $block->getBlockTypeHandle(), 'preview' => self::blockPreview($block)], $visible);
                $lines[] = self::areaLine((string) $handle, $shared['globalArea'] ?? null, $blocks, $hidden);
                if (count($lines) >= self::MAX_AREAS) {
                    break;
                }
            }
        } catch (\Throwable) {
            // Without the tree.
        }

        return $lines;
    }

    /** BF-10: the old snapshot asked the block for an instance it doesn't have, so it never had a preview. */
    private static function blockPreview($block): ?string
    {
        try {
            $controller = $block->getController();
            if ($controller === null) {
                return null;
            }
            if (method_exists($controller, 'getContent')) {
                return self::preview((string) $controller->getContent());
            }
            if (method_exists($controller, 'getSearchableContent')) {
                return self::preview((string) $controller->getSearchableContent());
            }
        } catch (\Throwable) {
            // No preview.
        }

        return null;
    }

    /** @return array<string, bool> */
    private function permissions(Page $page): array
    {
        CoreAliases::load();
        $checker = new Checker($page);

        return [
            'edit contents' => (bool) $checker->canEditPageContents(),
            'edit properties' => (bool) $checker->canEditPageProperties(),
            'add sub-pages' => (bool) $checker->canAddSubpage(),
            'move it' => (bool) $checker->canMoveOrCopyPage(),
            'delete it' => (bool) $checker->canDeletePage(),
            'publish (approve versions)' => (bool) $checker->canApprovePageVersions(),
        ];
    }

    private function siteLocale(): ?string
    {
        $site = Application::getFacadeApplication()->make('site')->getSite();
        $locale = $site ? $site->getDefaultLocale() : null;

        return $locale ? (string) $locale->getLocale() : null;
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            $string = trim((string) $value);

            return $string !== '' ? $string : null;
        }

        return null;
    }
}
