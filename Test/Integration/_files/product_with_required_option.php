<?php
declare(strict_types=1);

use Magento\Catalog\Api\Data\ProductCustomOptionInterfaceFactory;
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
    ->setName('AI Agent Custom Option Product')
    ->setSku('aiagent-custom-option')
    ->setPrice(40)
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setStockData([
        'use_config_manage_stock' => 1,
        'qty' => 50,
        'is_qty_decimal' => 0,
        'is_in_stock' => 1,
    ])
    ->setCanSaveCustomOptions(true)
    ->setHasOptions(true);

$customOptionFactory = $objectManager->create(ProductCustomOptionInterfaceFactory::class);
$option = $customOptionFactory->create([
    'data' => [
        'title' => 'Engraving',
        'type' => 'drop_down',
        'is_require' => 1,
        'sort_order' => 0,
        'values' => [
            [
                'title' => 'None',
                'price' => 0,
                'price_type' => 'fixed',
                'sku' => 'aiagent-engrave-none',
            ],
            [
                'title' => 'Custom text',
                'price' => 5,
                'price_type' => 'fixed',
                'sku' => 'aiagent-engrave-text',
            ],
        ],
    ],
]);
$option->setProductSku($product->getSku());
$product->setOptions([$option]);

$productRepository = $objectManager->create(ProductRepositoryInterface::class);
$productRepository->save($product);
