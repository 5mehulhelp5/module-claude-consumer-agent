<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Data;

interface ProductDetailsInterface extends ProductInterface
{
    public function getLongDescription(): ?string;

    public function getSpecs(): array;

    /**
     * @return \MageOS\ClaudeConsumerAgent\Api\Data\ProductInterface[]
     */
    public function getVariants(): array;

    public function getNote(): ?string;

    public function withNote(?string $note): self;
}
