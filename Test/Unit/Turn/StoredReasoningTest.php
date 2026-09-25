<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Turn;

use MageOS\AiBase\Api\Data\ReasoningInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\StoredReasoning;
use PHPUnit\Framework\TestCase;

final class StoredReasoningTest extends TestCase
{
    public function testItCarriesTextAndSignatureUnchanged(): void
    {
        $reasoning = new StoredReasoning('weighing options', 'sig_abc');

        $this->assertInstanceOf(ReasoningInterface::class, $reasoning);
        $this->assertSame('weighing options', $reasoning->getText());
        $this->assertSame('sig_abc', $reasoning->getSignature());
    }
}
