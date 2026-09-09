<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Backend;

use Magento\Sales\Model\Order;

interface OrderStatusMapperInterface
{
    public function map(Order $order): string;
}
