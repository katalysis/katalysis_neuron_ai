<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Access;

use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Validation\CSRF\Token;

/**
 * The one access check for the assistant (BEHAVIOUR_SPEC B-ACC-1). The chat panel,
 * every chat endpoint and the diagnostics endpoint all use it, so the panel being
 * hidden is never the only thing standing between a user and the endpoints.
 *
 * Access is Concrete's own task permission "Use Neuron AI assistant" (see NeuronPermissions),
 * set under Dashboard > System & Settings > Permissions & Access > Tasks. P3.2 adds tier keys.
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
     * A registered user who has the "Use Neuron AI assistant" task permission (the super user always
     * does). Registered users only, deliberately: guests never get the assistant, even if an admin
     * grants the permission to the Guest group.
     */
    public static function canUse(): bool
    {
        return NeuronPermissions::userHas(NeuronPermissions::USE_HANDLE);
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
