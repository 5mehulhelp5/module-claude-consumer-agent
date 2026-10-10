<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Console\Command;

use Magento\Framework\Console\Cli;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiShoppingAssistant\Model\Client\ConfiguredClientResolver;
use MageOS\AiShoppingAssistant\Model\Config\Source\ThinkingEffort;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not final: Magento generates an interceptor for console commands.
 */
class SpikeStream extends Command
{
    private const OPTION_STORE = 'store';
    private const OPTION_MESSAGE = 'message';
    private const OPTION_SERVICE = 'service';

    public function __construct(
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \Magento\Framework\App\State $appState,
        private readonly \Magento\Store\Model\App\Emulation $appEmulation,
        private readonly \MageOS\AiShoppingAssistant\Api\Client\ClientResolverInterface $clientResolver,
        private readonly \MageOS\AiBase\Api\AiClientFactoryInterface $clientFactory,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\StaticSystem $staticSystem,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\DynamicContext $dynamicContext,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Registry $toolRegistry,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Turn\RequestMapper $requestMapper
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('aiagent:spike:stream')
            ->setDescription('Stream one assistant request through the configured AI service and time every chunk')
            ->addOption(self::OPTION_STORE, null, InputOption::VALUE_OPTIONAL, 'Store code')
            ->addOption(self::OPTION_MESSAGE, null, InputOption::VALUE_OPTIONAL, 'Customer message', 'What is your return policy?')
            ->addOption(self::OPTION_SERVICE, null, InputOption::VALUE_OPTIONAL, 'AI Base service row id; default is the configured one');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
        }
        $storeId = (int)$this->storeManager->getStore($input->getOption(self::OPTION_STORE))->getId();
        $this->appEmulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND);
        try {
            return $this->stream($input, $output, $storeId);
        } finally {
            $this->appEmulation->stopEnvironmentEmulation();
        }
    }

    private function stream(InputInterface $input, OutputInterface $output, int $storeId): int
    {
        $client = $this->client($storeId, (string)($input->getOption(self::OPTION_SERVICE) ?? ''));
        $config = $this->storeConfig->agent($storeId);
        $request = $this->requestMapper->build(
            $this->staticSystem->text($storeId),
            $this->toolRegistry->definitions($storeId),
            [['role' => 'user', 'content' => [
                [
                    'type' => 'text',
                    'text' => $this->dynamicContext->build(
                        null,
                        null,
                        new PageContext(),
                        (new \DateTimeImmutable())->format(DATE_ATOM)
                    ),
                ],
                ['type' => 'text', 'text' => (string)$input->getOption(self::OPTION_MESSAGE)],
            ]]]
        );
        $options = [
            'max_tokens' => $config->maxTokens,
            'tool_choice' => 'auto',
            'reasoning_effort' => $config->thinkingEffort === ThinkingEffort::OFF ? 'none' : $config->thinkingEffort,
        ];
        if ($config->modelId !== '') {
            $options[AiClientInterface::OPTION_MODEL] = $config->modelId;
        }
        $output->writeln(sprintf(
            'service=%s model=%s',
            $client->getServiceCode(),
            $options[AiClientInterface::OPTION_MODEL] ?? $client->getModel()
        ));

        $start = microtime(true);
        $last = $start;
        $longestGap = 0.0;
        $stream = $client->streamChat($request, $options);
        foreach ($stream as $chunk) {
            $now = microtime(true);
            $longestGap = max($longestGap, $now - $last);
            $last = $now;
            $detail = $chunk->getType() === StreamChunkType::Text ? json_encode($chunk->getText()) : '';
            if ($chunk->getToolCall() !== null) {
                $detail = $chunk->getToolCall()->getName();
            }
            $output->writeln(sprintf('%7d ms  %-16s %s', (int)round(($now - $start) * 1000), $chunk->getType()->value, $detail));
        }
        $turn = $stream->getReturn();
        $usage = $turn->getUsage();
        $output->writeln(sprintf(
            'finish=%s raw=%s reasoning_blocks=%d longest_gap_ms=%d',
            $turn->getFinishReason()?->value ?? 'none',
            $turn->getRawFinishReason() ?? 'none',
            count($turn->getReasoning()),
            (int)round($longestGap * 1000)
        ));
        $output->writeln(sprintf(
            'prompt=%d cache_read=%d cache_write=%d completion=%d',
            $usage?->getPromptTokens() ?? 0,
            $usage?->getCacheReadTokens() ?? 0,
            $usage?->getCacheWriteTokens() ?? 0,
            $usage?->getCompletionTokens() ?? 0
        ));
        return Cli::RETURN_SUCCESS;
    }

    private function client(int $storeId, string $serviceId): AiClientInterface
    {
        if ($serviceId === '') {
            return $this->clientResolver->forStore($storeId);
        }
        return $this->clientFactory->createById($serviceId, ConfiguredClientResolver::CONSUMER);
    }
}
