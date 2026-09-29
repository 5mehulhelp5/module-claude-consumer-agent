<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn;

use MageOS\AiBase\Api\Data\ReasoningInterface;

/**
 * AI Base declares no preference for ReasoningInterface, so stored reasoning is rebuilt here.
 */
class StoredReasoning implements ReasoningInterface
{
    public function __construct(
        private readonly string $text,
        private readonly ?string $signature
    ) {
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }
}
