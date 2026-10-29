<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolRegistry;

/**
 * Neuron's ToolNode, with two changes:
 *
 * - **P3.6-2:** a call rejected because its own checks would refuse it (CallCheck) gets that refusal as its result,
 *   word for word, as if it had run. Neuron's rejection text ("TOOL NOT EXECUTED. The user rejected this action… Do not
 *   attempt this tool again") would be untrue, and would stop the model from retrying with the error fixed (scenario
 *   29: a made-up page ID, then the right one). Every other rejection is Neuron's own.
 * - **P3.9-3, the loop guard (LoopGuard):** before the approval step, a call the guard refuses is settled at once with
 *   the guard's reason: it runs not, and gets no card. After the step, the user's Denies, and the calls that failed
 *   (refused by their own checks, or run and failed), are remembered in the run's state for the rest of the turn.
 *
 * Overrides protected Neuron methods (stampRejectionResult(), resolveToolApprovals(), filterToolsRequiringApproval(),
 * executeSingleTool()); ApprovalFlowTest and LoopGuardTest fail if Neuron changes them. Middleware registered for
 * ToolNode still applies: Neuron matches node middleware with instanceof.
 */
final class ApprovalToolNode extends ToolNode
{
    protected function stampRejectionResult(ToolCall $call): void
    {
        $reason = (string) $call->getRejectReason();
        if (str_starts_with($reason, ApprovalService::REFUSED_PREFIX)) {
            $call->setResult(substr($reason, strlen(ApprovalService::REFUSED_PREFIX)));

            return;
        }

        parent::stampRejectionResult($call);
    }

    protected function resolveToolApprovals(ToolCallMessage $message, AgentState $state, AgentResources $resources): bool
    {
        $denied = (array) $state->get(LoopGuard::DENIED_KEY, []);
        $failed = (array) $state->get(LoopGuard::FAILED_KEY, []);
        foreach ($message->getToolCalls() as $call) {
            if ($call->getApprovalState() !== null) {
                continue;
            }
            $refusal = LoopGuard::refusal($call->getName(), (array) $call->getInputs(), $denied, $failed);
            if ($refusal !== null) {
                // Settled now: executeSingleTool() skips a rejected call, and the approval step leaves it out.
                $call->setApprovalState(ApprovalState::Rejected, $refusal);
                $call->setResult($refusal);
            }
        }

        $gated = parent::resolveToolApprovals($message, $state, $resources);

        foreach ($message->getToolCalls() as $call) {
            if ($call->getApprovalState() !== ApprovalState::Rejected) {
                continue;
            }
            $reason = (string) $call->getRejectReason();
            $denied = LoopGuard::afterDecision($call->getName(), $reason, $denied);
            if (str_starts_with($reason, ApprovalService::REFUSED_PREFIX)) {
                // Refused by its own checks: a failure, as if it had run.
                $failed = LoopGuard::afterResult($call->getName(), (array) $call->getInputs(), 'Error executing tool: refused', $failed);
            }
        }
        $state->set(LoopGuard::DENIED_KEY, $denied);
        $state->set(LoopGuard::FAILED_KEY, $failed);

        return $gated;
    }

    /** A call the loop guard settled (rejected before the approval step) is never put to the user. */
    protected function filterToolsRequiringApproval(array $calls, ToolRegistry $tools): array
    {
        return parent::filterToolsRequiringApproval(
            array_filter($calls, static fn (ToolCall $call): bool => $call->getApprovalState() !== ApprovalState::Rejected),
            $tools
        );
    }

    protected function executeSingleTool(ToolCall $call, int $index, AgentState $state, ToolRegistry $tools): void
    {
        if ($call->getApprovalState() === ApprovalState::Rejected) {
            // Not run. A call the loop guard refused is still reported, so the turn's Changed line says it wasn't done.
            if (str_starts_with((string) $call->getRejectReason(), 'TOOL NOT EXECUTED. ')) {
                $this->emit(new ToolCalled($call));
            }

            return;
        }

        parent::executeSingleTool($call, $index, $state, $tools);

        if ($call->hasResult()) {
            $state->set(LoopGuard::FAILED_KEY, LoopGuard::afterResult($call->getName(), (array) $call->getInputs(), $call->getResult(), (array) $state->get(LoopGuard::FAILED_KEY, [])));
        }
    }
}
