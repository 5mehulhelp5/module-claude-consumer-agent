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
try {
    $productRepository->deleteById('aiagent-custom-option');
} catch (NoSuchEntityException $exception) {
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);
