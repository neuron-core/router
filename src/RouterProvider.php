<?php

declare(strict_types=1);

namespace NeuronAI\Router;

use Generator;
use Throwable;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Router\Rules\RoutingRuleInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ToolInterface;

use function implode;
use function is_array;
use function array_keys;
use function in_array;
use function array_key_last;
use function array_values;

class RouterProvider implements AIProviderInterface
{
    use StaticConstructor;

    /**
     * @var array<string, AIProviderInterface>
     */
    protected array $providers = [];

    /**
     * @var list<string> Provider names tried, in order, after the primary fails
     *     with a retryable error. Empty by default (no fallback).
     */
    protected array $fallbackOrder = [];

    protected RoutingRuleInterface $rule;

    protected ?string $systemPrompt = null;

    protected ?AIProviderInterface $resolvedProvider = null;

    /**
     * @var array<ToolInterface>
     */
    protected array $tools = [];

    public function addProvider(string $name, AIProviderInterface $provider): self
    {
        $this->providers[$name] = $provider;
        return $this;
    }

    public function setRule(RoutingRuleInterface $rule): self
    {
        $this->rule = $rule;
        return $this;
    }

    /**
     * @throws ProviderException
     */
    public function setDefaultProvider(string $name): self
    {
        if (!isset($this->providers[$name])) {
            throw new ProviderException(
                "RouterProvider: unknown provider '{$name}'. Available: " . implode(', ', array_keys($this->providers)),
            );
        }
        $this->resolvedProvider = $this->providers[$name];
        return $this;
    }

    /**
     * Configure the ordered list of providers used as fallback when the primary
     * provider selected by the rule fails with a retryable error. The primary is
     * always tried first and is implicitly excluded from this list.
     *
     * @param string ...$names Registered provider names, in fallback order.
     *
     * @throws ProviderException If any name is not a registered provider.
     */
    public function setFallbackOrder(string ...$names): self
    {
        foreach ($names as $name) {
            if (!isset($this->providers[$name])) {
                throw new ProviderException(
                    "RouterProvider: unknown fallback provider '{$name}'. Available: " . implode(', ', array_keys($this->providers)),
                );
            }
        }
        $this->fallbackOrder = array_values($names);
        return $this;
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        $this->tools = $tools;
        return $this;
    }

    /**
     * @throws ProviderException
     * @throws Throwable When the primary and all fallback providers fail with a retryable error.
     */
    public function chat(Message ...$messages): Message
    {
        return $this->withFallback(
            'chat',
            $messages,
            fn (AIProviderInterface $provider) => $provider->chat(...$messages),
        );
    }

    /**
     * @return Generator<int, StreamChunk, mixed, Message>
     * @throws ProviderException
     * @throws Throwable When no chunk has been emitted and the primary and all fallback providers fail with a retryable error.
     */
    public function stream(Message ...$messages): Generator
    {
        $candidates = $this->candidates('stream', $messages);

        foreach ($candidates as $index => $name) {
            $isLast = $index === array_key_last($candidates);
            $this->resolvedProvider = $this->providers[$name];
            $generator = $this->prepare($name)->stream(...$messages);

            // Drive the initial request explicitly. A failure here (quota, auth,
            // 5xx, timeout) happens before any chunk is emitted, so we can fall
            // back to the next provider.
            try {
                $generator->rewind();
            } catch (Throwable $e) {
                if (!$this->isRetryable($e) || $isLast) {
                    throw $e;
                }
                continue;
            }

            // The initial request succeeded. Hand the (already-started) generator
            // back to the caller; PHP iteration resumes it from the current
            // position. Any failure from here is mid-stream and propagates as-is.
            return $generator;
        }

        // Unreachable: candidates() always returns at least the primary, so the
        // last iteration always returns or throws.
        throw new ProviderException('RouterProvider: all providers failed.');
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed> $response_schema
     * @throws ProviderException
     * @throws Throwable When the primary and all fallback providers fail with a retryable error.
     */
    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        return $this->withFallback(
            'structured',
            is_array($messages) ? $messages : [$messages],
            fn (AIProviderInterface $provider) => $provider->structured($messages, $class, $response_schema),
        );
    }

    /**
     * @throws ProviderException
     */
    public function messageMapper(): MessageMapperInterface
    {
        if ($this->resolvedProvider === null) {
            throw new ProviderException(
                'RouterProvider: no provider available for delegation. Call setDefaultProvider() or make an inference call first.',
            );
        }
        return $this->resolvedProvider->messageMapper();
    }

    /**
     * @throws ProviderException
     */
    public function toolPayloadMapper(): ToolMapperInterface
    {
        if ($this->resolvedProvider === null) {
            throw new ProviderException(
                'RouterProvider: no provider available for delegation. Call setDefaultProvider() or make an inference call first.',
            );
        }
        return $this->resolvedProvider->toolPayloadMapper();
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        foreach ($this->providers as $provider) {
            $provider->setHttpClient($client);
        }
        return $this;
    }

    /**
     * Ordered candidate provider names: the rule's primary first, followed by
     * the configured fallback order (deduplicated, primary excluded).
     *
     * @param Message[] $messages
     * @return list<string>
     *
     * @throws ProviderException
     */
    protected function candidates(string $method, array $messages): array
    {
        $primary = $this->resolveProviderName($method, $messages);
        $candidates = [$primary];

        foreach ($this->fallbackOrder as $name) {
            if (!in_array($name, $candidates, true)) {
                $candidates[] = $name;
            }
        }

        return $candidates;
    }

    /**
     * @param Message[] $messages
     *
     * @throws ProviderException
     */
    protected function resolveProviderName(string $method, array $messages): string
    {
        if (!isset($this->rule)) {
            throw new ProviderException(
                'RouterProvider: no routing strategy configured. Call setRule() to set one.',
            );
        }

        if ($this->providers === []) {
            throw new ProviderException(
                'RouterProvider: no providers registered. Call addProvider() to add one.',
            );
        }

        $name = $this->rule->resolveProvider($method, $messages, $this->tools);

        if (!isset($this->providers[$name])) {
            throw new ProviderException(
                "RouterProvider: unknown provider '{$name}'. Available: " . implode(', ', array_keys($this->providers)),
            );
        }

        return $name;
    }

    /**
     * Resolve the primary provider via the rule, then fall back through the
     * configured fallback order until one succeeds. A non-retryable error from
     * any provider is rethrown immediately.
     *
     * @param Message[] $messages
     * @param callable(AIProviderInterface): Message $callback
     *
     * @throws Throwable
     */
    protected function withFallback(string $method, array $messages, callable $callback): Message
    {
        $candidates = $this->candidates($method, $messages);

        foreach ($candidates as $index => $name) {
            $isLast = $index === array_key_last($candidates);
            $this->resolvedProvider = $this->providers[$name];

            try {
                return $callback($this->prepare($name));
            } catch (Throwable $e) {
                if (!$this->isRetryable($e) || $isLast) {
                    throw $e;
                }
            }
        }

        // Unreachable: candidates() always returns at least the primary, so the
        // last iteration always either returns or throws.
        throw new ProviderException('RouterProvider: all providers failed.');
    }

    protected function prepare(string $name): AIProviderInterface
    {
        return $this->providers[$name]
            ->systemPrompt($this->systemPrompt)
            ->setTools($this->tools);
    }

    /**
     * A failure is eligible for fallback only when it is a transient HTTP error:
     * a network/timeout failure (no response), a rate limit (429) or an upstream
     * server error (5xx). Anything else is rethrown unchanged.
     */
    protected function isRetryable(Throwable $e): bool
    {
        if (!$e instanceof HttpException) {
            return false;
        }

        $status = $e->response?->statusCode;

        return $status === null || $status === 429 || $status >= 500;
    }
}
