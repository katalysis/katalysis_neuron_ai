<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval;

use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Tools\ToolCall;

/**
 * Neuron's ToolNode, with one change (P3.6-2): a call rejected because its own checks would refuse it (CallCheck) gets
 * that refusal as its result, word for word, as if it had run. Neuron's rejection text ("TOOL NOT EXECUTED. The user
 * rejected this action… Do not attempt this tool again") would be untrue, and would stop the model from retrying with
 * the error fixed (scenario 29: a made-up page ID, then the right one). Every other rejection is Neuron's own.
 *
 * Overrides a protected Neuron method (ToolNode::stampRejectionResult()); ApprovalFlowTest fails if Neuron changes it.
 * Middleware registered for ToolNode still applies: Neuron matches node middleware with instanceof.
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
}
