<?php
declare(strict_types=1);

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$cartRepository = $objectManager->get(CartRepositoryInterface::class);
$collection = $objectManager->create(CollectionFactory::class)->create();
$collection->addFieldToFilter('reserved_order_id', 'aiagent_guest_quote');

foreach ($collection as $quote) {
    $cartRepository->delete($quote);
}
