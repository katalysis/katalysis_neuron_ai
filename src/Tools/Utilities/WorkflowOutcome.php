<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

/**
 * B-WRT-4 (P3.6-2, plans/P3.6-2.md §4): a change Concrete sends through a workflow is reported as "submitted for
 * approval", not as done. Concrete's workflow request (DeletePageRequest, MovePageRequest) runs the change at once when
 * no workflow applies, or when the user may approve it themselves; otherwise it waits for someone to approve it, and
 * trigger() returns nothing. The tool then checks whether the change happened, and says which.
 */
final class WorkflowOutcome
{
    public const SUBMITTED = 'submitted_for_approval';

    /**
     * The result fields for a change that went through a workflow request. $done says whether the change can be seen
     * now (the page is in the trash, or under its new parent).
     *
     * @param string $summary what was asked, in plain words ("delete About (/about)")
     * @return array{workflow: string, workflowSummary: string, note?: string}
     */
    public static function of(bool $done, string $summary): array
    {
        if ($done) {
            return ['workflow' => 'done', 'workflowSummary' => $summary];
        }

        return [
            'workflow' => self::SUBMITTED,
            'workflowSummary' => $summary,
            'note' => 'This change was submitted for approval through the site\'s workflow: it has not happened yet. '
                . 'Say it was submitted for approval, not that it was done. A person approves it in the CMS.',
        ];
    }
}
