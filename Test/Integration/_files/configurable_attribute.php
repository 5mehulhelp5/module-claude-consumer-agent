<?php
declare(strict_types=1);

use Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

$attributeRepository = $objectManager->get(ProductAttributeRepositoryInterface::class);
$attributeFactory = $objectManager->get(ProductAttributeInterfaceFactory::class);

try {
    $attributeRepository->get('aiagent_test_size');
} catch (NoSuchEntityException $exception) {
    $eavConfig = $objectManager->get(Config::class);
    $installer = $objectManager->get(EavSetup::class);
    $attributeSetId = $installer->getAttributeSetId(Product::ENTITY, 'Default');
    $groupId = $installer->getDefaultAttributeGroupId(Product::ENTITY, $attributeSetId);

    $attributeModel = $attributeFactory->create();
    $attributeModel->setData([
        'attribute_code' => 'aiagent_test_size',
        'entity_type_id' => $installer->getEntityTypeId(Product::ENTITY),
        'is_global' => 1,
        'is_user_defined' => 1,
        'frontend_input' => 'select',
        'is_unique' => 0,
        'is_required' => 0,
        'is_searchable' => 0,
        'is_visible_in_advanced_search' => 0,
        'is_comparable' => 0,
        'is_filterable' => 0,
        'is_filterable_in_search' => 0,
        'is_used_for_promo_rules' => 0,
        'is_html_allowed_on_front' => 1,
        'is_visible_on_front' => 0,
        'used_in_product_listing' => 0,
        'used_for_sort_by' => 0,
        'frontend_label' => ['Test Size'],
        'backend_type' => 'int',
        'option' => [
            'value' => ['option_0' => ['Small'], 'option_1' => ['Large']],
            'order' => ['option_0' => 1, 'option_1' => 2],
        ],
    ]);

    $attribute = $attributeRepository->save($attributeModel);
    $installer->addAttributeToGroup(Product::ENTITY, $attributeSetId, $groupId, $attribute->getId());
    $eavConfig->clear();
}
