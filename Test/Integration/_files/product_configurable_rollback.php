<?php
declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$registry = $objectManager->get(Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

$productRepository = $objectManager->create(ProductRepositoryInterface::class);
foreach (['aiagent-configurable', 'aiagent-simple-s', 'aiagent-simple-l'] as $sku) {
    try {
        $productRepository->deleteById($sku);
    } catch (NoSuchEntityException $exception) {
    }
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);

require __DIR__ . '/configurable_attribute_rollback.php';
