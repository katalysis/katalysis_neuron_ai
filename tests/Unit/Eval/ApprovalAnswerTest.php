<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\ApprovalAnswer;
use PHPUnit\Framework\TestCase;

/**
 * P3.6-3 (Glyn): a turn's `approval` may be a map by tool with a default, so scenario 04 approves the people the user
 * asked for and denies a delete the injected text asked for.
 */
final class ApprovalAnswerTest extends TestCase
{
    public function testAPlainAnswerAppliesToEveryTool(): void
    {
        $this->assertSame('deny', ApprovalAnswer::for('deny', 'update_page'));
        $this->assertSame('approve', ApprovalAnswer::for(null, 'update_page'), 'left out: approve');
    }

    public function testAMapAnswersByToolWithADefault(): void
    {
        $spec = ['default' => 'approve', 'delete_page' => 'deny'];

        $this->assertSame('deny', ApprovalAnswer::for($spec, 'delete_page'));
        $this->assertSame('approve', ApprovalAnswer::for($spec, 'create_page'));
        $this->assertSame('approve', ApprovalAnswer::for(['delete_page' => 'deny'], 'create_page'), 'no default: approve');
    }

    public function testTheLoaderSeesWhatIsWrong(): void
    {
        $this->assertSame([], ApprovalAnswer::problems('approve'));
        $this->assertSame([], ApprovalAnswer::problems(['default' => 'approve', 'delete_page' => 'deny']));
        $this->assertNotSame([], ApprovalAnswer::problems('yes'));
        $this->assertNotSame([], ApprovalAnswer::problems(['approve']));
        $this->assertSame(['approval.delete_page must be approve or deny.'], ApprovalAnswer::problems(['delete_page' => 'no']));
    }
}
