<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Access;

use Concrete\Core\Page\Page;
use Concrete\Core\Page\View\PageView;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Validation\CSRF\Token;

/**
 * The one access check for the assistant (BEHAVIOUR_SPEC B-ACC-1). The chat panel,
 * every chat endpoint and the diagnostics endpoint all use it, so the panel being
 * hidden is never the only thing standing between a user and the endpoints.
 *
 * Access is Concrete's own task permission "Use Neuron AI assistant" (see NeuronPermissions),
 * set under Dashboard > System & Settings > Permissions & Access > Tasks, or a higher tier's key (P3.2-1). The panel
 * is sent only where Concrete shows the user the toolbar, not everywhere (Glyn, on P3.2-1).
 */
final class ChatAccess
{
    /** Action name for the CSRF token the panel sends with its POST requests. */
    public const TOKEN_ACTION = 'katalysis_neuron_ai_chat';

    /** Request header the panel sends the token in. */
    public const TOKEN_HEADER = 'X-Neuron-Token';

    public const ACCESS_DENIED_ERROR = 'Access denied.';
    public const TOKEN_ERROR = 'Invalid or missing security token. Please reload the page and try again.';

    /** The `code` field of a 403 response caused by a missing, invalid or expired token; the panel keys its message on it. */
    public const TOKEN_ERROR_CODE = 'invalid_token';

    /**
     * A registered user with a tier: the "Use Neuron AI assistant" task permission or a higher tier's key (P3.2-1;
     * tiers are cumulative), and the super user always. Registered users only, deliberately: guests never get the
     * assistant, even if an admin grants the permission to the Guest group.
     */
    public static function canUse(): bool
    {
        return NeuronPermissions::tier() >= 1;
    }

    /**
     * Whether the panel's markup, CSS and JS go into this view: the user may use the assistant AND Concrete shows
     * them the toolbar on this page. The panel opens only from a button in that toolbar, so without the toolbar it
     * could not be opened. The toolbar test is Concrete's own (PageView::startRender() and page_controls_header.php
     * both ask the page's permissions for canViewToolbar()), so it covers dashboard pages as well. Views that are
     * not pages (dialogs, tools, errors) never get the panel.
     *
     * Only the panel's output depends on this. The chat endpoints use canUse() alone (refusalReason()).
     *
     * @param mixed $view the view named by the on_before_render event
     */
    public static function canShowPanelOn(mixed $view): bool
    {
        if (!$view instanceof PageView || !self::canUse()) {
            return false;
        }

        $page = $view->getPageObject();
        if (!$page instanceof Page || $page->isError()) {
            return false;
        }

        // Concrete's answer can be an int (1) rather than true, so compare as a boolean.
        return (bool) (new Checker($page))->canViewToolbar();
    }

    /**
     * Checks a request before any work is done.
     *
     * @param string|null $token the value of the token header, if any
     * @return string|null null when the request may go ahead, otherwise the error text to return with HTTP 403
     */
    public static function refusalReason(bool $isStateChanging, ?string $token): ?string
    {
        if (!self::canUse()) {
            return self::ACCESS_DENIED_ERROR;
        }

        if ($isStateChanging && !self::isValidToken($token)) {
            return self::TOKEN_ERROR;
        }

        return null;
    }

    public static function generateToken(): string
    {
        return self::tokenService()->generate(self::TOKEN_ACTION);
    }

    private static function isValidToken(?string $token): bool
    {
        if ($token === null || trim($token) === '') {
            return false;
        }

        return self::tokenService()->validate(self::TOKEN_ACTION, trim($token)) === true;
    }

    private static function tokenService(): Token
    {
        return Application::getFacadeApplication()->make('token');
    }
}
