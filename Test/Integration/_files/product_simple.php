<?php
declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

$product = $objectManager->create(Product::class);
$product->setTypeId(Type::TYPE_SIMPLE)
    ->setAttributeSetId(4)
    ->setWebsiteIds([1])
    ->setName('AI Agent Simple Product')
    ->setSku('aiagent-simple')
    ->setPrice(25)
    ->setWeight(1)
    ->setShortDescription('A plain simple product for the storefront backend fixtures.')
    ->setDescription('Full description of the plain simple product.')
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setStockData([
        'use_config_manage_stock' => 1,
        'qty' => 100,
        'is_qty_decimal' => 0,
        'is_in_stock' => 1,
    ]);

$productRepository = $objectManager->create(ProductRepositoryInterface::class);
$productRepository->save($product);
