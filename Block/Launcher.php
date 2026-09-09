<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Block;

use Magento\Framework\View\Element\Template;
use MageOS\ClaudeConsumerAgent\ViewModel\Assistant;

class Launcher extends Template
{
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\ClaudeConsumerAgent\ViewModel\Assistant $assistant,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();
        $config = $this->storeConfig->agent($storeId);
        if (!$config->enabled || !$config->launcherEnabled) {
            return '';
        }
        return parent::_toHtml();
    }

    public function getAssistant(): Assistant
    {
        return $this->assistant;
    }
}
