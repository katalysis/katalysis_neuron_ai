<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Error\ErrorList\ErrorList;

/**
 * Refusal messages the page and block write tools share (P3.3-2, B-ERR-4). Each says what is wrong, what to do next,
 * and that nothing was changed, as BF-7's BlockNotFound does: a vague failure ("Block 31610 not found") made the model
 * guess at locks and permissions (BF-7).
 */
final class WriteRefusal
{
    public static function pageNotFound(int $pageID, string $which = 'Page'): string
    {
        return "{$which} with ID {$pageID} not found: it may have been deleted, or the ID is wrong. "
            . 'Find the page with get_current_page_context or list_pages and use the ID they give. Nothing was changed.';
    }

    public static function unknownBlockType(string $handle): string
    {
        return "No block type '{$handle}' is installed on this site. Tell the user so; don't choose another type in its "
            . "place. This tool's description lists the common handles. Nothing was changed.";
    }

    /**
     * A block controller's validate() result as one message, or null when it passed.
     */
    public static function blockValidation(mixed $errors, string $handle): ?string
    {
        if (!$errors instanceof ErrorList || !$errors->has()) {
            return null;
        }
        $messages = [];
        foreach ($errors->getList() as $error) {
            $messages[] = trim((string) $error);
        }

        return "The '{$handle}' block's own check refused the data: " . implode(' ', array_filter($messages))
            . ' Fix those fields (get_block_data shows how an existing block of this type stores them) and send the call again. '
            . 'Nothing was changed.';
    }
}
