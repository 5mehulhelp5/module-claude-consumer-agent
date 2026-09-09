<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Data;

interface PolicyInterface
{
    public function getPolicyId(): string;

    public function getTitle(): string;

    public function getCategory(): ?string;

    public function getContent(): string;

    public function toArray(): array;
}
