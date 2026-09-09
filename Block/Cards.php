<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Block;

use Magento\Framework\View\Element\Template;

class Cards extends Template
{
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();
        if (!$this->storeConfig->isEnabled($storeId)) {
            return '';
        }
        return parent::_toHtml();
    }
}
