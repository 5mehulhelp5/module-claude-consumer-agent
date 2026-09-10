<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Backend;

interface SkuMatcherInterface
{
    /**
     * Returns the first candidate that names an existing product, spelled as the catalog stores it.
     *
     * @param string[] $skus
     */
    public function firstExisting(array $skus, int $storeId): ?string;
}
