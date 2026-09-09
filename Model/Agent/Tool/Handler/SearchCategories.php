<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Handler;

use MageOS\ClaudeConsumerAgent\Api\Data\CategoryMatchInterface;
use MageOS\ClaudeConsumerAgent\Api\Tool\HandlerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Agent\ToolOutcome;

final class SearchCategories implements HandlerInterface
{
    private const DEFAULT_LIMIT = 8;
    private const MAX_LIMIT = 20;

    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Serializer $serializer
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $keywords = (string)($input['keywords'] ?? '');
        $limit = max(1, min((int)($input['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $matches = $this->backend->searchCategories($context, $keywords, $limit);
        $records = array_map(
            static fn (CategoryMatchInterface $match): array => $match->toArray(),
            $matches
        );
        $text = $this->serializer->categorySearchText($keywords, $records, $config->maxFencedChars);
        return ToolOutcome::ok($text);
    }
}
