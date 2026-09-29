<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Presentation;

use MageOS\AiShoppingAssistant\Model\Agent\Presentation\EnrichmentContext;

interface PresentationExtensionInterface
{
    public function getToolName(): string;

    public function getComponent(): string;

    public function getDescription(): string;

    public function getInputSchema(): array;

    /**
     * Runs on the live turn and again when the conversation is restored, so it must only read data.
     */
    public function enrich(array $payload, EnrichmentContext $ctx): array;

    public function getTemplate(): string;
}
