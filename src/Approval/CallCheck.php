<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval;

use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;
use Katalysis\NeuronAi\Tools\Utilities\DryRunReached;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * P3.6-2 (Glyn, after scenarios 36 and 29: four approvals for calls that then failed): would this call be refused?
 * Asked before the user is, so a call that can't succeed isn't put to them. It is rejected at once with the tool's own
 * error (ApprovalService, ApprovalToolNode), and the real checks still run at Approve.
 *
 * - Draft-writing tools run their own checks in a dry run that stops where the draft would be made (PageDraft::dryRun()):
 *   every check they make before writing, in the same order, with nothing written (BF-3 put the checks first).
 *   create_page makes no draft; its dry run stops just before it adds the page (PageDraft::stopIfDryRun()).
 * - Other tools: their preview's reads say when the call can't succeed (ChangePreview).
 *
 * The dry run uses a fresh instance of the tool, not the agent's, so the result scanner and the turn's record don't see it.
 */
final class CallCheck
{
    /** The tools the dry run covers (the draft-writing tools, and create_page), and the toolkit each comes from. */
    public const DRY_RUN = [
        'create_page' => PagesToolkit::class,
        'update_page' => PagesToolkit::class,
        'set_page_attribute_value' => PageAttributesToolkit::class,
        'add_block_to_page_area' => ContentToolkit::class,
        'update_block_in_page_area' => ContentToolkit::class,
        'delete_block_from_page_area' => ContentToolkit::class,
        'move_block_between_areas' => ContentToolkit::class,
        'batch_update_page_content' => BatchEditToolkit::class,
        'batch_update_content_headings' => BatchEditToolkit::class,
    ];

    /**
     * The tool's own refusal of this call, as the model would get it, or null when the call passes its checks (it would
     * reach its draft, or it isn't a dry-run tool).
     *
     * @param array<string, mixed> $inputs
     */
    public static function dryRun(string $toolName, array $inputs): ?string
    {
        $toolkit = self::DRY_RUN[$toolName] ?? null;
        if ($toolkit === null) {
            return null;
        }
        $tool = self::tool(new $toolkit(), $toolName);
        if ($tool === null) {
            return null;
        }

        try {
            $tool = clone $tool;
            $tool->setInputs($inputs);
            PageDraft::dryRun(static fn () => $tool->execute());
            $result = $tool->getResult();
        } catch (DryRunReached) {
            return null;
        } catch (\Throwable $e) {
            return ConcreteCmsAgent::toolErrorText($e);
        }

        // It ended without reaching a draft: refused if it says it failed ("no headings found").
        $text = is_string($result) ? $result : (string) json_encode($result);

        return ToolKind::succeeded($result) ? null : $text;
    }

    private static function tool(ToolkitInterface $toolkit, string $name): ?ToolInterface
    {
        foreach ($toolkit->tools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
