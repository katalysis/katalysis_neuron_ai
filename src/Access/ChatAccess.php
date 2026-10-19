<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Access;

use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;
use Concrete\Core\Validation\CSRF\Token;

/**
 * The one access check for the assistant (BEHAVIOUR_SPEC B-ACC-1). The chat panel,
 * every chat endpoint and the diagnostics endpoint all use it, so the panel being
 * hidden is never the only thing standing between a user and the endpoints.
 *
 * P3.2 replaces the settings-page rule with tier groups.
 */
final class ChatAccess
{
    public const SETTINGS_PAGE_PATH = '/dashboard/system/ai/neuron_ai';

    /** Action name for the CSRF token the panel sends with its POST requests. */
    public const TOKEN_ACTION = 'katalysis_neuron_ai_chat';

    /** Request header the panel sends the token in. */
    public const TOKEN_HEADER = 'X-Neuron-Token';

    /**
     * A registered user who can view the Neuron AI settings page. If that page
     * doesn't exist, only the super user.
     */
    public static function canUse(): bool
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return false;
        }

        $settingsPage = Page::getByPath(self::SETTINGS_PAGE_PATH);
        if ($settingsPage && !$settingsPage->isError()) {
            return (bool) (new Checker($settingsPage))->canViewPage();
        }

        return $user->isSuperUser();
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
            return 'Access denied.';
        }

        if ($isStateChanging && !self::isValidToken($token)) {
            return 'Invalid or missing security token. Please reload the page and try again.';
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
