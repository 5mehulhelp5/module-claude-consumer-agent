<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

class StoreFacts extends AbstractFieldArray
{
    private ?SourceColumn $sourceRenderer = null;

    protected function _prepareToRender(): void
    {
        $this->addColumn('topic', ['label' => __('Topic')]);
        $this->addColumn('keywords', ['label' => __('Keywords')]);
        $this->addColumn('source', ['label' => __('Source'), 'renderer' => $this->getSourceRenderer()]);
        $this->addColumn('value', ['label' => __('Answer or identifier')]);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Fact');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $optionExtraAttr = [];
        $hash = $this->getSourceRenderer()->calcOptionHash((string)$row->getData('source'));
        $optionExtraAttr['option_' . $hash] = 'selected="selected"';
        $row->setData('option_extra_attrs', $optionExtraAttr);
    }

    private function getSourceRenderer(): SourceColumn
    {
        if ($this->sourceRenderer === null) {
            $this->sourceRenderer = $this->getLayout()->createBlock(
                SourceColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->sourceRenderer;
    }
}
