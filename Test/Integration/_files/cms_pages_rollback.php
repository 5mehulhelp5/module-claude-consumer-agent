<?php
declare(strict_types=1);

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$pageRepository = $objectManager->get(PageRepositoryInterface::class);
$collection = $objectManager->create(CollectionFactory::class)->create();
$collection->addFieldToFilter('identifier', ['in' => ['aiagent-returns', 'aiagent-shipping']]);

foreach ($collection as $page) {
    try {
        $pageRepository->delete($page);
    } catch (LocalizedException $exception) {
    }
}
