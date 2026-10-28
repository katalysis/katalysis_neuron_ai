<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\Utilities\WriteRefusal;
use PHPUnit\Framework\TestCase;

/**
 * P3.3-7: a block type's validate() may return false (BlockController::validate() allows a boolean for older
 * packages). That is a refusal without a reason, never a pass: a write must not skip validation silently.
 */
final class WriteRefusalTest extends TestCase
{
    public function testAValidateThatReturnsFalseRefusesTheWrite(): void
    {
        $refusal = (string) WriteRefusal::blockValidation(false, 'some_block');

        $this->assertStringContainsString("'some_block'", $refusal);
        $this->assertStringContainsString('refused the data', $refusal);
        $this->assertStringContainsString('Nothing was changed', $refusal);
    }

    public function testAValidateThatReturnsTrueOrNothingPasses(): void
    {
        $this->assertNull(WriteRefusal::blockValidation(true, 'some_block'));
        $this->assertNull(WriteRefusal::blockValidation(null, 'some_block'));
    }
}
