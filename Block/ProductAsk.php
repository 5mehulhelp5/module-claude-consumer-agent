<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Block;

use Magento\Framework\View\Element\Template;

class ProductAsk extends Template
{
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        private readonly \Hyva\Theme\ViewModel\CurrentProduct $currentProduct,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();
        if (!$this->storeConfig->isEnabled($storeId) || !$this->currentProduct->exists()) {
            return '';
        }
        return parent::_toHtml();
    }

    public function getProductId(): string
    {
        return (string)$this->currentProduct->get()->getId();
    }
}
