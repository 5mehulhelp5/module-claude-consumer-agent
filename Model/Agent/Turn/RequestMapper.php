<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn;

use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\ToolCallInterface;

/**
 * Translates the stored transcript, which keeps content blocks, into an AI Base request.
 *
 * The builder carries the system prompt and the tools; the conversation continues on the request
 * itself, because only ChatRequestInterface::withAssistantTurn() carries reasoning back.
 */
class RequestMapper
{
    private const ERROR_PREFIX = 'Error: ';

    public function __construct(
        private readonly \MageOS\AiBase\Api\ChatRequestBuilderInterfaceFactory $builderFactory,
        private readonly \MageOS\AiBase\Api\Data\ChatMessageInterfaceFactory $messageFactory,
        private readonly \MageOS\AiBase\Api\Data\ChatResponseInterfaceFactory $responseFactory,
        private readonly \MageOS\AiBase\Api\Data\ToolCallInterfaceFactory $toolCallFactory
    ) {
    }

    /**
     * @param \MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition[] $definitions
     */
    public function build(
        string $staticText,
        array $definitions,
        array $messages
    ): ChatRequestInterface {
        $builder = $this->builderFactory->create()->withSystemMessage($staticText);
        foreach ($definitions as $definition) {
            $builder = $builder->withToolDefinition($definition);
        }
        $request = $builder->build();
        $calls = [];
        foreach ($messages as $message) {
            $content = $this->blocks($message['content'] ?? null);
            if (($message['role'] ?? null) === 'assistant') {
                $request = $this->withAssistant($request, $content, $calls);
                continue;
            }
            $request = $this->withUser($request, $content, $calls);
        }
        return $request;
    }

    private function withAssistant(
        ChatRequestInterface $request,
        array $content,
        array &$calls
    ): ChatRequestInterface {
        $text = '';
        $toolCalls = [];
        $reasoning = [];
        foreach ($content as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if ($type === 'text') {
                $text .= (string)($block['text'] ?? '');
                continue;
            }
            if ($type === 'thinking' && (string)($block['signature'] ?? '') !== '') {
                $reasoning[] = new StoredReasoning((string)($block['thinking'] ?? ''), (string)$block['signature']);
                continue;
            }
            if ($type !== 'tool_use') {
                continue;
            }
            $id = (string)($block['id'] ?? '');
            $call = $this->toolCallFactory->create([
                'id' => $id,
                'name' => (string)($block['name'] ?? ''),
                'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
            ]);
            $calls[$id] = $call;
            $toolCalls[] = $call;
        }
        if ($text === '' && $toolCalls === [] && $reasoning === []) {
            return $request;
        }
        return $request->withAssistantTurn($this->responseFactory->create([
            'text' => $text,
            'toolCalls' => $toolCalls,
            'reasoning' => $reasoning,
        ]));
    }

    private function withUser(
        ChatRequestInterface $request,
        array $content,
        array $calls
    ): ChatRequestInterface {
        $texts = [];
        foreach ($content as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if ($type === 'tool_result') {
                $call = $calls[(string)($block['tool_use_id'] ?? '')] ?? null;
                if ($call instanceof ToolCallInterface) {
                    $request = $request->withToolResult($call, $this->resultText($block));
                }
                continue;
            }
            if ($type === 'text' && (string)($block['text'] ?? '') !== '') {
                $texts[] = (string)$block['text'];
            }
        }
        if ($texts === []) {
            return $request;
        }
        return $request->withMessage($this->messageFactory->create([
            'role' => MessageRole::User,
            'content' => implode("\n\n", $texts),
        ]));
    }

    private function resultText(array $block): string
    {
        $content = $block['content'] ?? '';
        $text = is_string($content)
            ? $content
            : (string)json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ($block['is_error'] ?? false) === true ? self::ERROR_PREFIX . $text : $text;
    }

    private function blocks(mixed $raw): array
    {
        if (is_string($raw)) {
            return [['type' => 'text', 'text' => $raw]];
        }
        return is_array($raw) ? $raw : [];
    }
}
