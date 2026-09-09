<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Data;

interface CartInterface
{
    /**
     * @return \MageOS\ClaudeConsumerAgent\Api\Data\CartItemInterface[]
     */
    public function getItems(): array;

    public function getCurrency(): string;

    public function getItemCount(): int;

    public function getSubtotal(): float;

    public function find(string $productId): ?CartItemInterface;

    public function isEmpty(): bool;

    public function toArray(): array;
}
