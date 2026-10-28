<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Page\Page;
use Concrete\Core\User\User;
use Katalysis\NeuronAi\Tools\Utilities\DraftCleanup;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;

/**
 * P3.3-4: the login wrapper and the draft every page write edits, shared by PagesToolkit, ContentToolkit and
 * BatchEditToolkit (moved from PagesToolkit unchanged).
 */
trait PageToolGuards
{
    protected function guarded(callable $callable): callable
    {
        return function (...$args) use ($callable) {
            $user = new User();
            if (!$user->isRegistered()) {
                throw new \RuntimeException('Authentication required: Please log in to use this tool.');
            }

            // A call that fails leaves no draft or page it made behind (BF-3).
            return DraftCleanup::run(static fn () => $callable(...$args));
        };
    }

    /**
     * The version of the page to edit (see PageDraft::of()).
     */
    protected function getDraftOf(Page $page): Page
    {
        return PageDraft::of($page);
    }
}
