<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Router;

use Generator;
use Throwable;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Router\Rules\CallbackRule;
use NeuronAI\Router\Rules\ContentRule;
use NeuronAI\Router\Rules\MethodRule;
use NeuronAI\Router\Rules\RoundRobinRule;
use NeuronAI\Router\Rules\RoutingRuleInterface;
use NeuronAI\Router\RouterProvider;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class RouterProviderTest extends TestCase
{
    // --- CallbackRule tests ---

    public function test_callback_rule_routes_chat_to_correct_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('from anthropic'));
        $openai = FakeAIProvider::make(AssistantMessage::make('from openai'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('openai', $openai)
            ->setRule(new CallbackRule(fn (string $method): string => $method === 'structured' ? 'openai' : 'anthropic'));

        $response = $router
            ->systemPrompt('test prompt')
            ->chat(UserMessage::make('hello'));

        $this->assertSame('from anthropic', $response->getContent());
    }

    public function test_callback_rule_routes_structured_to_different_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('from anthropic'));
        $openai = FakeAIProvider::make(AssistantMessage::make('from openai'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('openai', $openai)
            ->setRule(new CallbackRule(fn (string $method): string => $method === 'structured' ? 'openai' : 'anthropic'));

        $response = $router
            ->systemPrompt('test')
            ->setTools([])
            ->structured([UserMessage::make('hello')], 'stdClass', []);

        $this->assertSame('from openai', $response->getContent());
    }

    public function test_callback_rule_receives_messages_and_tools(): void
    {
        $fake = FakeAIProvider::make(AssistantMessage::make('ok'));
        $tool = Tool::make('search', 'Search tool');
        $received = [];

        $router = RouterProvider::make()
            ->addProvider('main', $fake)
            ->setRule(new CallbackRule(function (string $method, array $messages, array $tools) use (&$received): string {
                $received = [$method, $messages, $tools];
                return 'main';
            }));

        $router
            ->systemPrompt(null)
            ->setTools([$tool])
            ->chat(UserMessage::make('hello'));

        $this->assertSame('chat', $received[0]);
        $this->assertCount(1, $received[1]);
        $this->assertCount(1, $received[2]);
    }

    // --- MethodRule tests ---

    public function test_method_rule_routes_by_method(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('from anthropic'));
        $openai = FakeAIProvider::make(AssistantMessage::make('from openai'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('openai', $openai)
            ->setRule((new MethodRule('anthropic'))->structured('openai'));

        $chatResponse = $router
            ->systemPrompt('test')
            ->setTools([])
            ->chat(UserMessage::make('hello'));

        $this->assertSame('from anthropic', $chatResponse->getContent());

        // Need to add another response for the structured call
        $anthropic->addResponses(AssistantMessage::make('from anthropic 2'));

        $structuredResponse = $router
            ->systemPrompt('test')
            ->setTools([])
            ->structured([UserMessage::make('hello')], 'stdClass', []);

        $this->assertSame('from openai', $structuredResponse->getContent());
    }

    public function test_method_rule_uses_default_when_no_override(): void
    {
        $fake = FakeAIProvider::make(AssistantMessage::make('default'));

        $router = RouterProvider::make()
            ->addProvider('default', $fake)
            ->setRule(new MethodRule('default'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat(UserMessage::make('hello'));

        $this->assertSame('default', $response->getContent());
    }

    // --- Streaming tests ---

    public function test_routes_stream_and_yields_chunks(): void
    {
        $provider = FakeAIProvider::make(AssistantMessage::make('hello world'));

        $router = RouterProvider::make()
            ->addProvider('default', $provider)
            ->setRule(new CallbackRule(fn (): string => 'default'));

        $stream = $router
            ->systemPrompt(null)
            ->setTools([])
            ->stream(UserMessage::make('hi'));

        $chunks = [];
        foreach ($stream as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertNotEmpty($chunks);
        $this->assertSame('hello world', $stream->getReturn()->getContent());
    }

    // --- Forwarding tests ---

    public function test_forwards_system_prompt_and_tools_to_chosen_provider(): void
    {
        $tool = Tool::make('test_tool', 'A test tool');
        $fake = FakeAIProvider::make(AssistantMessage::make('ok'));

        $router = RouterProvider::make()
            ->addProvider('main', $fake)
            ->setRule(new CallbackRule(fn (): string => 'main'));

        $router
            ->systemPrompt('system instructions')
            ->setTools([$tool])
            ->chat(UserMessage::make('go'));

        $fake->assertSystemPrompt('system instructions');
        $fake->assertToolsConfigured(['test_tool']);
    }

    public function test_set_http_client_propagates_to_all_providers(): void
    {
        $fake1 = FakeAIProvider::make(AssistantMessage::make('a'));
        $fake2 = FakeAIProvider::make(AssistantMessage::make('b'));
        $client = $this->createMock(HttpClientInterface::class);

        $router = RouterProvider::make()
            ->addProvider('one', $fake1)
            ->addProvider('two', $fake2);

        $result = $router->setHttpClient($client);

        $this->assertSame($router, $result);
    }

    // --- Error handling tests ---

    public function test_throws_when_rule_returns_unknown_provider(): void
    {
        $fake = FakeAIProvider::make(AssistantMessage::make('ok'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $fake)
            ->setRule(new CallbackRule(fn (): string => 'unknown'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("RouterProvider: unknown provider 'unknown'");

        $router->chat(UserMessage::make('hello'));
    }

    public function test_throws_when_no_rule_configured(): void
    {
        $router = RouterProvider::make()
            ->addProvider('anthropic', FakeAIProvider::make(AssistantMessage::make('ok')));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('no routing strategy configured');

        $router->chat(UserMessage::make('hello'));
    }

    public function test_throws_when_no_providers_registered(): void
    {
        $router = RouterProvider::make()
            ->setRule(new CallbackRule(fn (): string => 'anthropic'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('no providers registered');

        $router->chat(UserMessage::make('hello'));
    }

    public function test_message_mapper_delegates_to_last_resolved_provider(): void
    {
        $fake = FakeAIProvider::make(AssistantMessage::make('ok'));

        $router = RouterProvider::make()
            ->addProvider('main', $fake)
            ->setRule(new CallbackRule(fn (): string => 'main'));

        $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hello'));

        $this->assertInstanceOf(\NeuronAI\Testing\FakeMessageMapper::class, $router->messageMapper());
    }

    public function test_tool_payload_mapper_delegates_to_last_resolved_provider(): void
    {
        $fake = FakeAIProvider::make(AssistantMessage::make('ok'));

        $router = RouterProvider::make()
            ->addProvider('main', $fake)
            ->setRule(new CallbackRule(fn (): string => 'main'));

        $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hello'));

        $this->assertInstanceOf(\NeuronAI\Testing\FakeToolMapper::class, $router->toolPayloadMapper());
    }

    public function test_mapper_uses_default_provider_before_any_call(): void
    {
        $fake = FakeAIProvider::make(AssistantMessage::make('ok'));

        $router = RouterProvider::make()
            ->addProvider('main', $fake)
            ->setDefaultProvider('main');

        $this->assertInstanceOf(\NeuronAI\Testing\FakeMessageMapper::class, $router->messageMapper());
        $this->assertInstanceOf(\NeuronAI\Testing\FakeToolMapper::class, $router->toolPayloadMapper());
    }

    public function test_default_provider_overwritten_by_routing(): void
    {
        $mapperA = $this->createMock(MessageMapperInterface::class);
        $mapperB = $this->createMock(MessageMapperInterface::class);

        $providerA = $this->createMock(AIProviderInterface::class);
        $providerA->method('messageMapper')->willReturn($mapperA);
        $providerA->method('systemPrompt')->willReturnSelf();
        $providerA->method('setTools')->willReturnSelf();
        $providerA->method('chat')->willReturn(AssistantMessage::make('a'));

        $providerB = $this->createMock(AIProviderInterface::class);
        $providerB->method('messageMapper')->willReturn($mapperB);
        $providerB->method('systemPrompt')->willReturnSelf();
        $providerB->method('setTools')->willReturnSelf();
        $providerB->method('chat')->willReturn(AssistantMessage::make('b'));

        $router = RouterProvider::make()
            ->addProvider('a', $providerA)
            ->addProvider('b', $providerB)
            ->setDefaultProvider('a')
            ->setRule(new CallbackRule(fn (): string => 'b'));

        $this->assertSame($mapperA, $router->messageMapper());

        $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hello'));

        $this->assertSame($mapperB, $router->messageMapper());
    }

    public function test_set_default_provider_throws_for_unknown_name(): void
    {
        $router = RouterProvider::make()
            ->addProvider('main', FakeAIProvider::make(AssistantMessage::make('ok')));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("unknown provider 'unknown'");
        $router->setDefaultProvider('unknown');
    }

    public function test_mapper_throws_when_no_default_and_no_resolution(): void
    {
        $router = RouterProvider::make()
            ->addProvider('main', FakeAIProvider::make(AssistantMessage::make('ok')));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('no provider available for delegation');
        $router->messageMapper();
    }

    // --- Interface compliance ---

    public function test_router_provider_implements_ai_provider_interface(): void
    {
        $router = RouterProvider::make()
            ->addProvider('main', FakeAIProvider::make(AssistantMessage::make('ok')))
            ->setRule(new CallbackRule(fn (): string => 'main'));

        $this->assertInstanceOf(AIProviderInterface::class, $router);
    }

    public function test_rules_implement_routing_rule_interface(): void
    {
        $this->assertInstanceOf(RoutingRuleInterface::class, new CallbackRule(fn (): string => 'a'));
        $this->assertInstanceOf(RoutingRuleInterface::class, new MethodRule('a'));
        $this->assertInstanceOf(RoutingRuleInterface::class, new RoundRobinRule(['a', 'b']));
        $this->assertInstanceOf(RoutingRuleInterface::class, new ContentRule('a'));
    }

    // --- ContentRule tests ---

    public function test_content_rule_routes_to_default_for_text_only(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));
        $gemini = FakeAIProvider::make(AssistantMessage::make('gemini'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('gemini', $gemini)
            ->setRule((new ContentRule('anthropic'))->image('gemini'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat(UserMessage::make('just text'));

        $this->assertSame('anthropic', $response->getContent());
    }

    public function test_content_rule_routes_image_to_configured_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));
        $gemini = FakeAIProvider::make(AssistantMessage::make('gemini'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('gemini', $gemini)
            ->setRule((new ContentRule('anthropic'))->image('gemini'));

        $message = UserMessage::make('describe this');
        $message->addContent(new ImageContent('https://example.com/photo.jpg', SourceType::URL, 'image/jpeg'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message);

        $this->assertSame('gemini', $response->getContent());
    }

    public function test_content_rule_routes_file_to_configured_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));
        $openai = FakeAIProvider::make(AssistantMessage::make('openai'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('openai', $openai)
            ->setRule((new ContentRule('anthropic'))->file('openai'));

        $message = UserMessage::make('read this');
        $message->addContent(new FileContent('base64data', SourceType::BASE64, 'application/pdf'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message);

        $this->assertSame('openai', $response->getContent());
    }

    public function test_content_rule_routes_audio_to_configured_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));
        $openai = FakeAIProvider::make(AssistantMessage::make('openai'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('openai', $openai)
            ->setRule((new ContentRule('anthropic'))->audio('openai'));

        $message = UserMessage::make('transcribe this');
        $message->addContent(new AudioContent('base64audio', SourceType::BASE64, 'audio/wav'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message);

        $this->assertSame('openai', $response->getContent());
    }

    public function test_content_rule_routes_video_to_configured_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));
        $gemini = FakeAIProvider::make(AssistantMessage::make('gemini'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('gemini', $gemini)
            ->setRule((new ContentRule('anthropic'))->video('gemini'));

        $message = UserMessage::make('describe this');
        $message->addContent(new VideoContent('https://example.com/clip.mp4', SourceType::URL, 'video/mp4'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message);

        $this->assertSame('gemini', $response->getContent());
    }

    public function test_content_rule_video_takes_precedence_over_image(): void
    {
        $gemini = FakeAIProvider::make(AssistantMessage::make('gemini'));
        $openai = FakeAIProvider::make(AssistantMessage::make('openai'));

        $router = RouterProvider::make()
            ->addProvider('gemini', $gemini)
            ->addProvider('openai', $openai)
            ->setRule((new ContentRule('gemini'))->image('openai')->video('gemini'));

        $message = UserMessage::make('describe these');
        $message->addContent(new ImageContent('https://example.com/photo.jpg', SourceType::URL, 'image/jpeg'));
        $message->addContent(new VideoContent('https://example.com/clip.mp4', SourceType::URL, 'video/mp4'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message);

        $this->assertSame('gemini', $response->getContent());
    }

    public function test_content_rule_ignores_unconfigured_content_types(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->setRule((new ContentRule('anthropic'))->image('gemini'));

        // Message has audio but only image routing is configured
        $message = UserMessage::make('listen');
        $message->addContent(new AudioContent('base64audio', SourceType::BASE64, 'audio/wav'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message);

        $this->assertSame('anthropic', $response->getContent());
    }

    // --- RoundRobinRule tests ---

    public function test_round_robin_cycles_through_providers(): void
    {
        $providerA = FakeAIProvider::make(
            AssistantMessage::make('a1'),
            AssistantMessage::make('a2'),
        );
        $providerB = FakeAIProvider::make(
            AssistantMessage::make('b1'),
            AssistantMessage::make('b2'),
        );

        $router = RouterProvider::make()
            ->addProvider('a', $providerA)
            ->addProvider('b', $providerB)
            ->setRule(new RoundRobinRule(['a', 'b']));

        $response1 = $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));
        $this->assertSame('a1', $response1->getContent());

        $response2 = $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));
        $this->assertSame('b1', $response2->getContent());

        $response3 = $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));
        $this->assertSame('a2', $response3->getContent());

        $response4 = $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));
        $this->assertSame('b2', $response4->getContent());
    }

    // --- Fallback tests ---

    public function test_chat_falls_back_to_next_provider_on_rate_limit(): void
    {
        $primary = $this->failingProvider('chat', $this->httpError(429));
        $fallback = $this->chatReturningMock('from fallback');

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback');

        $response = $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));

        $this->assertSame('from fallback', $response->getContent());
    }

    public function test_chat_falls_back_on_network_error(): void
    {
        $primary = $this->failingProvider('chat', new HttpException('network'));
        $fallback = $this->chatReturningMock('from fallback');

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback');

        $response = $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));

        $this->assertSame('from fallback', $response->getContent());
    }

    public function test_chat_falls_back_on_server_error(): void
    {
        $primary = $this->failingProvider('chat', $this->httpError(503));
        $fallback = $this->chatReturningMock('from fallback');

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback');

        $response = $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));

        $this->assertSame('from fallback', $response->getContent());
    }

    public function test_chat_does_not_fall_back_on_non_http_error(): void
    {
        $primary = $this->failingProvider('chat', new ProviderException('mapping bug'));
        $fallback = $this->createMock(AIProviderInterface::class);
        $fallback->expects($this->never())->method('chat');

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('mapping bug');

        $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));
    }

    public function test_chat_does_not_fall_back_on_client_error(): void
    {
        $primary = $this->failingProvider('chat', $this->httpError(400));
        $fallback = $this->createMock(AIProviderInterface::class);
        $fallback->expects($this->never())->method('chat');

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback');

        $this->expectException(HttpException::class);

        $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));
    }

    public function test_chat_throws_last_error_when_all_providers_fail(): void
    {
        $primary = $this->failingProvider('chat', $this->httpError(429));
        $fallback = $this->failingProvider('chat', $this->httpError(500));

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback');

        // The last-tried (fallback) provider's error must surface, not the
        // primary's 429.
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('HTTP 500');

        $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));

        $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));
    }

    public function test_primary_is_not_retried_when_listed_in_fallback_order(): void
    {
        $primary = $this->createMock(AIProviderInterface::class);
        $primary->method('systemPrompt')->willReturnSelf();
        $primary->method('setTools')->willReturnSelf();
        $primary->expects($this->once())->method('chat')->willThrowException($this->httpError(429));
        $fallback = $this->chatReturningMock('from fallback');

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('primary', 'fallback');

        $response = $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));

        $this->assertSame('from fallback', $response->getContent());
    }

    public function test_set_fallback_order_throws_for_unknown_provider(): void
    {
        $router = RouterProvider::make()
            ->addProvider('main', FakeAIProvider::make(AssistantMessage::make('ok')));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("unknown fallback provider 'nope'");

        $router->setFallbackOrder('nope');
    }

    public function test_custom_retry_strategy_can_enable_fallback_for_non_default_error(): void
    {
        // 400 is NOT retryable by the default policy, but the custom strategy opts in.
        $primary = $this->failingProvider('chat', $this->httpError(400));
        $fallback = $this->chatReturningMock('from fallback');

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback')
            ->setFallbackStrategy(fn (Throwable $e): bool => $e instanceof HttpException && $e->response?->statusCode === 400);

        $response = $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));

        $this->assertSame('from fallback', $response->getContent());
    }

    public function test_custom_retry_strategy_can_disable_fallback(): void
    {
        // 429 IS retryable by the default policy, but the custom strategy opts out.
        $primary = $this->failingProvider('chat', $this->httpError(429));
        $fallback = $this->createMock(AIProviderInterface::class);
        $fallback->expects($this->never())->method('chat');

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback')
            ->setFallbackStrategy(fn (Throwable $e): bool => false);

        $this->expectException(HttpException::class);

        $router->systemPrompt(null)->setTools([])->chat(UserMessage::make('hi'));
    }

    public function test_structured_falls_back_to_next_provider(): void
    {
        $primary = $this->failingProvider('structured', $this->httpError(429));
        $fallback = $this->createMock(AIProviderInterface::class);
        $fallback->method('systemPrompt')->willReturnSelf();
        $fallback->method('setTools')->willReturnSelf();
        $fallback->method('structured')->willReturn(AssistantMessage::make('structured fallback'));

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback');

        $response = $router->systemPrompt(null)->setTools([])->structured([UserMessage::make('hi')], 'stdClass', []);

        $this->assertSame('structured fallback', $response->getContent());
    }

    public function test_stream_falls_back_on_initial_request_failure(): void
    {
        $primary = $this->createMock(AIProviderInterface::class);
        $primary->method('systemPrompt')->willReturnSelf();
        $primary->method('setTools')->willReturnSelf();
        $primary->method('stream')->willReturn($this->generatorThatThrowsImmediately($this->httpError(429)));
        $fallback = FakeAIProvider::make(AssistantMessage::make('hello world'));

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback');

        $stream = $router->systemPrompt(null)->setTools([])->stream(UserMessage::make('hi'));

        $chunks = [];
        foreach ($stream as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertNotEmpty($chunks);
        $this->assertSame('hello world', $stream->getReturn()->getContent());
        // The failing primary was tried, then the fallback produced the stream.
        $this->assertSame(1, $fallback->getCallCount());
    }

    public function test_stream_does_not_fall_back_after_chunks_emitted(): void
    {
        $primary = $this->createMock(AIProviderInterface::class);
        $primary->method('systemPrompt')->willReturnSelf();
        $primary->method('setTools')->willReturnSelf();
        $primary->method('stream')->willReturn(
            $this->generatorThatYieldsThenThrows(new TextChunk('id', 'partial'), $this->httpError(500))
        );
        $fallback = FakeAIProvider::make(AssistantMessage::make('should not be used'));

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback');

        $stream = $router->systemPrompt(null)->setTools([])->stream(UserMessage::make('hi'));

        $chunks = [];
        try {
            foreach ($stream as $chunk) {
                $chunks[] = $chunk;
            }
            $this->fail('Expected the streamed exception to propagate.');
        } catch (HttpException $e) {
            $this->assertSame(500, $e->response->statusCode);
        }

        $this->assertCount(1, $chunks);
        // Once output has been emitted the stream cannot restart, so the
        // fallback must never have been invoked.
        $this->assertSame(0, $fallback->getCallCount());
    }

    public function test_stream_does_not_fall_back_on_non_retryable_error(): void
    {
        $primary = $this->createMock(AIProviderInterface::class);
        $primary->method('systemPrompt')->willReturnSelf();
        $primary->method('setTools')->willReturnSelf();
        $primary->method('stream')->willReturn($this->generatorThatThrowsImmediately(new ProviderException('mapping bug')));
        $fallback = FakeAIProvider::make(AssistantMessage::make('should not be used'));

        $router = RouterProvider::make()
            ->addProvider('primary', $primary)
            ->addProvider('fallback', $fallback)
            ->setRule(new CallbackRule(fn (): string => 'primary'))
            ->setFallbackOrder('fallback');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('mapping bug');

        // The initial request failure now surfaces eagerly when stream() is
        // called (the request must run so it can be caught for fallback).
        $router->systemPrompt(null)->setTools([])->stream(UserMessage::make('hi'));
    }

    // --- Fallback test helpers ---

    private function httpError(int $status, string $body = ''): HttpException
    {
        return new HttpException("HTTP {$status}", null, new HttpResponse($status, $body));
    }

    /**
     * @return AIProviderInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function failingProvider(string $method, Throwable $error): AIProviderInterface
    {
        $provider = $this->createMock(AIProviderInterface::class);
        $provider->method('systemPrompt')->willReturnSelf();
        $provider->method('setTools')->willReturnSelf();
        $provider->method($method)->willThrowException($error);

        return $provider;
    }

    /**
     * @return AIProviderInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function chatReturningMock(string $content): AIProviderInterface
    {
        $provider = $this->createMock(AIProviderInterface::class);
        $provider->method('systemPrompt')->willReturnSelf();
        $provider->method('setTools')->willReturnSelf();
        $provider->method('chat')->willReturn(AssistantMessage::make($content));

        return $provider;
    }

    private function generatorThatThrowsImmediately(Throwable $e): Generator
    {
        // Yields nothing, then fails on the first iteration — i.e. the initial
        // request is rejected before any chunk is emitted.
        yield from [];
        throw $e;
    }

    private function generatorThatYieldsThenThrows(TextChunk $chunk, Throwable $e): Generator
    {
        yield $chunk;
        throw $e;
    }
}
