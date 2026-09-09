<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Cart;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DataObject;

interface BuyRequestBuilderInterface
{
    public function build(ProductInterface $product, int $qty, array $selections): DataObject;
}
