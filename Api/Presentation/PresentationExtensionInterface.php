<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Presentation;

use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\EnrichmentContext;

interface PresentationExtensionInterface
{
    public function getToolName(): string;

    public function getComponent(): string;

    public function getDescription(): string;

    public function getInputSchema(): array;

    public function enrich(array $payload, EnrichmentContext $ctx): array;

    public function getTemplate(): string;
}
