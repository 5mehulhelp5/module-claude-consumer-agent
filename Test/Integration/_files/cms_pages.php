<?php
declare(strict_types=1);

use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$pageFactory = $objectManager->get(PageInterfaceFactory::class);
$pageRepository = $objectManager->get(PageRepositoryInterface::class);

$pages = [
    [
        'identifier' => 'aiagent-returns',
        'title' => 'Return Policy',
        'content' => '<h2>Returns</h2><p>You can return items within 30 days of delivery for a full refund.</p>'
            . '<h3>Exchanges</h3><p>Exchanges are processed once the returned item is received at the warehouse.</p>',
        'is_active' => 1,
        'stores' => [0],
    ],
    [
        'identifier' => 'aiagent-shipping',
        'title' => 'Shipping Policy',
        'content' => '<h2>Delivery times</h2><p>Standard delivery arrives within 3 to 5 business days.</p>'
            . '<h3>Shipping costs</h3><p>Shipping is free on orders over 50 dollars.</p>',
        'is_active' => 1,
        'stores' => [0],
    ],
];

foreach ($pages as $data) {
    $page = $pageFactory->create();
    $page->setIdentifier($data['identifier'])
        ->setTitle($data['title'])
        ->setContent($data['content'])
        ->setIsActive($data['is_active'])
        ->setStores($data['stores'])
        ->setPageLayout('1column');
    $pageRepository->save($page);
}
