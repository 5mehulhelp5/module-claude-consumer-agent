<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Enrich;

use MageOS\ClaudeConsumerAgent\Model\Agent\Exception\PresentationRefused;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\EnrichmentContext;

final class Products
{
    private const MAX_ITEMS = 12;

    private const NOTE_TITLE_MAX_CHARS = 80;

    private const MAX_EXPANDED_FAMILIES = 2;

    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Sanitizer $sanitizer
    ) {
    }

    public function __invoke(array $input, EnrichmentContext $ctx): array
    {
        $items = [];
        $dropped = [];
        $expandedFamilies = 0;
        foreach ((is_array($input['picks'] ?? null) ? $input['picks'] : []) as $pick) {
            $productId = (string)($pick['product_id'] ?? '');
            $product = $ctx->state->seen($productId);
            if ($product === null) {
                $dropped[] = $productId;
                continue;
            }
            $reason = $pick['reason'] ?? null;
            $options = is_array($product['options'] ?? null) ? $product['options'] : [];
            $variantOf = $product['variant_of'] ?? null;
            if ($options !== [] && ($variantOf === null || $variantOf === '')) {
                $cachedVariants = $this->cachedVariants($productId, $ctx);
                if ($cachedVariants !== null) {
                    foreach ($this->buildVariantItems($cachedVariants, $product, $reason) as $variantItem) {
                        $items[] = $variantItem;
                    }
                    continue;
                }
                if ($expandedFamilies < self::MAX_EXPANDED_FAMILIES) {
                    $variantItems = $this->expandFamily($productId, $product, $reason, $ctx);
                    if ($variantItems !== null) {
                        $expandedFamilies++;
                        foreach ($variantItems as $variantItem) {
                            $items[] = $variantItem;
                        }
                        continue;
                    }
                } else {
                    $ctx->notes[] = 'Variant expansion is capped at ' . self::MAX_EXPANDED_FAMILIES
                        . ' families per set; ' . $this->sanitizer->text(
                            (string)($product['title'] ?? ''),
                            self::NOTE_TITLE_MAX_CHARS
                        ) . ' is shown without its variants.';
                }
            }
            $items[] = [
                'product' => $product,
                'reason' => $reason,
                'option_values' => is_array($product['option_values'] ?? null) ? $product['option_values'] : [],
                'variant_of' => $variantOf,
            ];
        }
        if ($items === []) {
            throw new PresentationRefused(
                "None of those product_ids came from this session's catalog results. "
                . 'Search first and pick from the results.',
                'provenance'
            );
        }
        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, 0, self::MAX_ITEMS);
        }
        if ($dropped !== []) {
            $ctx->notes[] = 'Skipped unknown product_ids not seen in this session: '
                . implode(', ', $dropped) . '.';
        }
        $payload = [
            'layout' => (string)($input['layout'] ?? 'carousel'),
            'items' => $items,
        ];
        if (isset($input['title'])) {
            $payload = ['title' => $input['title']] + $payload;
        }
        return $payload;
    }

    private function expandFamily(string $productId, array $product, mixed $reason, EnrichmentContext $ctx): ?array
    {
        try {
            $details = $ctx->backend->getProductDetails($ctx->context, $productId);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'product details lookup failed during variant expansion',
                ['product_id' => $productId, 'exception' => $exception]
            );
            return null;
        }
        if ($details === null) {
            return null;
        }
        $variants = $details->getVariants();
        if ($variants === []) {
            return null;
        }
        $variantRecords = [];
        foreach ($variants as $variant) {
            $variantRecords[] = $variant->toArray();
        }
        $ctx->state->rememberProducts($variantRecords);
        $items = $this->buildVariantItems($variantRecords, $product, $reason);
        $title = $this->sanitizer->text((string)($product['title'] ?? ''), self::NOTE_TITLE_MAX_CHARS);
        $ctx->notes[] = 'Expanded ' . $title . ' into ' . count($items) . ' variants.';
        return $items;
    }

    private function cachedVariants(string $productId, EnrichmentContext $ctx): ?array
    {
        $variants = [];
        foreach ($ctx->state->seenProducts as $record) {
            if (($record['variant_of'] ?? null) === $productId) {
                $variants[] = $record;
            }
        }
        return $variants !== [] ? $variants : null;
    }

    private function buildVariantItems(array $variantRecords, array $product, mixed $reason): array
    {
        $inStock = [];
        $outOfStock = [];
        foreach ($variantRecords as $record) {
            if ($record['in_stock'] ?? true) {
                $inStock[] = $record;
            } else {
                $outOfStock[] = $record;
            }
        }
        $hasReason = is_string($reason) && $reason !== '';
        $items = [];
        foreach (array_merge($inStock, $outOfStock) as $record) {
            $optionValues = is_array($record['option_values'] ?? null) ? $record['option_values'] : [];
            $record['title'] = $product['title'] ?? $record['title'];
            $items[] = [
                'product' => $record,
                'reason' => $hasReason ? $reason : null,
                'option_values' => $optionValues,
                'variant_of' => $record['variant_of'] ?? null,
            ];
        }
        return $items;
    }
}
