<?php
declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Config;
use Magento\TestFramework\Helper\Bootstrap;

require __DIR__ . '/configurable_attribute.php';

$objectManager = Bootstrap::getObjectManager();
$productRepository = $objectManager->create(ProductRepositoryInterface::class);

$eavConfig = $objectManager->get(Config::class);
$attribute = $eavConfig->getAttribute(Product::ENTITY, 'aiagent_test_size');
$options = $attribute->getOptions();
array_shift($options);

$childSkus = ['aiagent-simple-s', 'aiagent-simple-l'];
$childInStock = [1, 0];
$attributeValues = [];
$associatedProductIds = [];

foreach ($options as $index => $option) {
    $child = $objectManager->create(Product::class);
    $child->setTypeId(Type::TYPE_SIMPLE)
        ->setAttributeSetId(4)
        ->setWebsiteIds([1])
        ->setName('AI Agent Configurable Child ' . $option->getLabel())
        ->setSku($childSkus[$index])
        ->setPrice(30 + $index)
        ->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE)
        ->setStatus(Status::STATUS_ENABLED)
        ->setData('aiagent_test_size', $option->getValue())
        ->setStockData([
            'use_config_manage_stock' => 1,
            'qty' => $childInStock[$index] ? 100 : 0,
            'is_qty_decimal' => 0,
            'is_in_stock' => $childInStock[$index],
        ]);
    $child = $productRepository->save($child);

    $attributeValues[] = [
        'label' => 'aiagent_test_size',
        'attribute_id' => $attribute->getId(),
        'value_index' => $option->getValue(),
    ];
    $associatedProductIds[] = $child->getId();
}

$configurableAttributesData = [
    [
        'attribute_id' => $attribute->getId(),
        'code' => $attribute->getAttributeCode(),
        'label' => $attribute->getStoreLabel(),
        'position' => '0',
        'values' => $attributeValues,
    ],
];

$optionsFactory = $objectManager->create(Factory::class);
$configurableOptions = $optionsFactory->create($configurableAttributesData);

$parent = $objectManager->create(Product::class);
$extensionAttributes = $parent->getExtensionAttributes();
$extensionAttributes->setConfigurableProductOptions($configurableOptions);
$extensionAttributes->setConfigurableProductLinks($associatedProductIds);

$parent->setTypeId(Configurable::TYPE_CODE)
    ->setAttributeSetId(4)
    ->setWebsiteIds([1])
    ->setName('AI Agent Configurable Product')
    ->setSku('aiagent-configurable')
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setExtensionAttributes($extensionAttributes)
    ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);

$productRepository->save($parent);
