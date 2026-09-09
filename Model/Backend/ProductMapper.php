<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Backend;

use Magento\Catalog\Api\Data\ProductInterface as MagentoProductInterface;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Data\Product;

final class ProductMapper
{
    public function __construct(
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \Magento\Catalog\Helper\Image $imageHelper,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Backend\Salability $salability,
        private readonly \MageOS\ClaudeConsumerAgent\Api\Backend\ProductOptionsProviderInterface $optionsProvider
    ) {
    }

    public function toProduct(MagentoProductInterface $p, SessionContext $ctx): Product
    {
        $summary = $this->optionsProvider->summarize($p);

        $data = [
            'product_id' => (string)$p->getId(),
            'title' => (string)$p->getName(),
            'brand' => $this->brand($p),
            'price' => $this->finalPrice($p),
            'currency' => $this->currency($ctx),
            'rating' => null,
            'review_count' => null,
            'image_url' => $this->imageHelper->init($p, 'category_page_grid')->getUrl(),
            'url' => $p->getProductUrl(),
            'category' => null,
            'labels' => [],
            'attributes' => $this->optionAttributes($summary['options']),
            'in_stock' => $this->salability->isSalable($p, $ctx),
            'short_description' => $this->cleanText((string)$p->getData('short_description')),
            'options' => $p->getTypeId() === Configurable::TYPE_CODE ? $this->configurableOptions($p) : [],
            'option_values' => [],
            'variant_of' => null,
            'has_required_custom_options' => $summary['required'],
            'custom_options' => $summary['custom_options'],
        ];

        return Product::fromArray($data);
    }

    private function optionAttributes(array $optionCounts): array
    {
        $attributes = [];
        foreach ($optionCounts as $title => $count) {
            $attributes[$title] = $count . ' values';
        }
        return $attributes;
    }

    private function finalPrice(MagentoProductInterface $p): float
    {
        $amount = $p->getPriceInfo()->getPrice(FinalPrice::PRICE_CODE)->getAmount()->getValue();
        return $amount !== null ? (float)$amount : 0.0;
    }

    private function currency(SessionContext $ctx): string
    {
        return $this->storeManager->getStore($ctx->storeId)->getCurrentCurrencyCode();
    }

    private function brand(MagentoProductInterface $p): ?string
    {
        try {
            $text = $p->getAttributeText('manufacturer');
        } catch (\Throwable $exception) {
            return null;
        }

        if (is_array($text)) {
            $text = reset($text);
        }

        if ($text === false || $text === null) {
            return null;
        }

        $value = trim((string)$text);
        return $value !== '' ? $value : null;
    }

    private function configurableOptions(MagentoProductInterface $p): array
    {
        $typeInstance = $p->getTypeInstance();
        if (!$typeInstance instanceof Configurable) {
            return [];
        }

        $result = [];
        foreach ($typeInstance->getConfigurableAttributesAsArray($p) as $attribute) {
            $label = (string)($attribute['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $values = [];
            foreach ((array)($attribute['options'] ?? []) as $option) {
                $optionLabel = $option['label'] ?? null;
                if ($optionLabel !== null && (string)$optionLabel !== '') {
                    $values[] = (string)$optionLabel;
                }
            }
            $result[$label] = $values;
        }
        return $result;
    }

    private function cleanText(string $text): ?string
    {
        $stripped = strip_tags($text);
        $collapsed = preg_replace('/\s+/', ' ', $stripped) ?? '';
        $trimmed = trim($collapsed);
        return $trimmed !== '' ? $trimmed : null;
    }
}
