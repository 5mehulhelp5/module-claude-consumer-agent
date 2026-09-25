<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Turn;

use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\RequestMapper;
use MageOS\AiShoppingAssistant\Test\Unit\Support\AiBaseDoubles;
use PHPUnit\Framework\TestCase;

class RequestMapperTest extends TestCase
{
    use AiBaseDoubles;

    public function testTheStaticPromptComesFirstAndTheSessionContextSecond(): void
    {
        $request = $this->mapper()->build('STATIC', 'DYNAMIC', [], [$this->user('Hi')]);

        $messages = $request->getMessages();
        $this->assertSame(MessageRole::System, $messages[0]->getRole());
        $this->assertSame('STATIC', $messages[0]->getContent());
        $this->assertSame(MessageRole::System, $messages[1]->getRole());
        $this->assertSame('DYNAMIC', $messages[1]->getContent());
        $this->assertSame(MessageRole::User, $messages[2]->getRole());
    }

    public function testAnEmptySessionContextAddsNoSecondSystemMessage(): void
    {
        $request = $this->mapper()->build('STATIC', '', [], [$this->user('Hi')]);

        $this->assertSame(MessageRole::User, $request->getMessages()[1]->getRole());
    }

    public function testToolDefinitionsAreOfferedAsTheyAre(): void
    {
        $definition = new Definition('search_products', 'Search', ['type' => 'object'], 'core', null, [], false, false, 10);

        $request = $this->mapper()->build('S', '', [$definition], [$this->user('Hi')]);

        $this->assertSame([$definition], $request->getTools());
    }

    public function testTheTextBlocksOfOneUserMessageBecomeOneUserMessage(): void
    {
        $message = ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => '[Page: the customer is now on a product page.]'],
            ['type' => 'text', 'text' => 'Does it run small?'],
        ]];

        $request = $this->mapper()->build('S', '', [], [$message]);

        $this->assertSame(
            "[Page: the customer is now on a product page.]\n\nDoes it run small?",
            $request->getMessages()[1]->getContent()
        );
    }

    public function testStringContentIsReadAsOneTextBlock(): void
    {
        $request = $this->mapper()->build('S', '', [], [['role' => 'user', 'content' => 'Hello']]);

        $this->assertSame('Hello', $request->getMessages()[1]->getContent());
    }

    public function testAnAssistantTurnKeepsSignedReasoningTextAndToolCalls(): void
    {
        $request = $this->mapper()->build('S', '', [], [
            $this->user('Find a jacket'),
            ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => 'weighing', 'signature' => 'sig_1'],
                ['type' => 'thinking', 'thinking' => 'unsigned', 'signature' => ''],
                ['type' => 'text', 'text' => 'Searching.'],
                ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'search_products', 'input' => ['query' => 'jacket']],
            ]],
        ]);

        $assistant = $request->getMessages()[2];
        $this->assertSame(MessageRole::Assistant, $assistant->getRole());
        $this->assertSame('Searching.', $assistant->getContent());
        $this->assertCount(1, $assistant->getReasoning());
        $this->assertSame('sig_1', $assistant->getReasoning()[0]->getSignature());
        $this->assertSame('tu1', $assistant->getToolCalls()[0]->getId());
        $this->assertSame(['query' => 'jacket'], $assistant->getToolCalls()[0]->getArguments());
    }

    public function testToolResultsAnswerTheirCallsAndErrorsArePrefixed(): void
    {
        $request = $this->mapper()->build('S', '', [], [
            $this->user('Two things'),
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'a', 'input' => []],
                ['type' => 'tool_use', 'id' => 'tu2', 'name' => 'b', 'input' => []],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'tu1', 'content' => 'ok', 'is_error' => false],
                ['type' => 'tool_result', 'tool_use_id' => 'tu2', 'content' => 'boom', 'is_error' => true],
                ['type' => 'tool_result', 'tool_use_id' => 'unknown', 'content' => 'x', 'is_error' => false],
            ]],
        ]);

        $messages = $request->getMessages();
        $this->assertCount(5, $messages);
        $this->assertSame(MessageRole::Tool, $messages[3]->getRole());
        $this->assertSame('tu1', $messages[3]->getToolCallId());
        $this->assertSame('ok', $messages[3]->getContent());
        $this->assertSame('tu2', $messages[4]->getToolCallId());
        $this->assertSame('Error: boom', $messages[4]->getContent());
    }

    public function testAnAssistantTurnWithNothingToReplayIsSkipped(): void
    {
        $request = $this->mapper()->build('S', '', [], [
            $this->user('Hi'),
            ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => 'x', 'signature' => '']]],
            $this->user('Still there?'),
        ]);

        $this->assertCount(3, $request->getMessages());
    }

    private function mapper(): RequestMapper
    {
        return new RequestMapper(
            $this->chatRequestBuilderFactory(),
            $this->chatMessageFactory(),
            $this->chatResponseFactory(),
            $this->toolCallFactory()
        );
    }

    private function user(string $text): array
    {
        return ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
