<?php
declare(strict_types=1);

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

$quote = $objectManager->create(Quote::class);
$quote->setStoreId(1)
    ->setIsActive(true)
    ->setIsMultiShipping(false)
    ->setCustomerIsGuest(true)
    ->setReservedOrderId('aiagent_guest_quote')
    ->collectTotals()
    ->save();

$quoteIdMask = $objectManager->create(QuoteIdMaskFactory::class)->create();
$quoteIdMask->setQuoteId($quote->getId());
$quoteIdMask->setDataChanges(true);
$quoteIdMask->save();
