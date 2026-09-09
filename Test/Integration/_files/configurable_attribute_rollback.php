<?php
declare(strict_types=1);

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$attributeRepository = $objectManager->get(ProductAttributeRepositoryInterface::class);

try {
    $attributeRepository->deleteById('aiagent_test_size');
} catch (NoSuchEntityException $exception) {
}
