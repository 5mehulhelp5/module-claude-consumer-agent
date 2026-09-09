<?php
declare(strict_types=1);

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$registry = $objectManager->get(Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

$orderRepository = $objectManager->get(OrderRepositoryInterface::class);
$orderFactory = $objectManager->get(OrderInterfaceFactory::class);
foreach (['900000001', '900000002'] as $incrementId) {
    $order = $orderFactory->create()->loadByIncrementId($incrementId);
    if ($order->getId()) {
        $orderRepository->delete($order);
    }
}

$customerRepository = $objectManager->get(CustomerRepositoryInterface::class);
try {
    $customer = $customerRepository->get('aiagent-customer@example.test');
    $customerRepository->delete($customer);
} catch (NoSuchEntityException $exception) {
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);

require __DIR__ . '/product_simple_rollback.php';
