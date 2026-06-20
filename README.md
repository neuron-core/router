# Neuron AI Router

This package provides you with a `RouterProvider` component. It is a proxy that implements `AIProviderInterface` and routes inference calls (`chat`, `stream`, `structured`) to different underlying providers based on a routing strategy you define.
The agent doesn't know it's talking to a router, it's a drop-in replacement for any Neuron AI provider.

This is possible thanks to the Unified Messaging Layer that Neuron AI provides, with full support for multi-modality. Documentation here: https://docs.neuron-ai.dev/agent/messages

![](/assets/neuron-router.png)

## When to Use It

- Router request to the appropriate models based on the expected difficulty scored by the [classifier](#difficultyrule)
- Route `structured()` calls to a provider with better structured output support (e.g., OpenAI) while using another provider for `chat()`
- Use different providers depending on the content of the messages (e.g., route image-heavy requests to Gemini)
- Implement cost-based or latency-based routing logic

## Installation

Install the composer package:

```
composer require neuron-core/router
```

## Quick Start

```php
use NeuronAI\Router\RouterProvider;
use NeuronAI\Router\Rules\MethodRule;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;

class MyAgent extens Agent
{
    protected function provider(): AIProviderInterface
    {
        return RouterProvider::make()
            ->addProvider('anthropic', new Anthropic(
                key: 'ANTHROPIC_API_KEY',
                model: 'claude-sonnet-4-20250514',
            ))
            ->addProvider('openai', new OpenAI(
                key: 'OPENAI_API_KEY',
                model: 'gpt-4o',
            ))
            ->setRule(
                new RoundRobinRule(['anthropic', 'openai'])
            );
    }

    protected function instructions(): string
    {...}

    protected function tools(): array
    {...}
}
```

## Default Provider

The router delegates `messageMapper()` and `toolPayloadMapper()` to an underlying provider. After each inference call, these delegate to whichever provider the routing rule selected. If you need the mappers available before any inference call (e.g., during agent bootstrapping), set a default:

```php
RouterProvider::make()
    ->addProvider('anthropic', new Anthropic(...))
    ->addProvider('openai', new OpenAI(...))
    ->setDefaultProvider('anthropic')
    ->setRule(new RoundRobinRule(['anthropic', 'openai']));
```

The default is overwritten each time the routing rule resolves a provider, so it only acts as the initial fallback.

## Routing Rules

Routing logic is defined via the `RoutingRuleInterface`. The router calls `resolveProvider()` on the rule, passing context about the current request:

```php
interface RoutingRuleInterface
{
    public function resolveProvider(string $method, array $messages, array $tools): string;
}
```

| Parameter  | Type     | Description                                            |
|------------|----------|--------------------------------------------------------|
| `$method`  | `string` | The inference method: `'chat'`, `'stream'`, or `'structured'` |
| `$messages`| `array`  | The messages being sent to the provider                 |
| `$tools`   | `array`  | The tools configured for this request                   |

The method must return the name of a registered provider (as a string).

### Built-in Rules

#### MethodRule

Routes based on the inference method. Set a default provider and optionally override specific methods:

```php
use NeuronAI\Router\Rules\MethodRule;

$router = RouterProvider::make()->addProvider(...);

// Use Anthropic for everything, except structured output which goes to OpenAI
$router->setRule(
    new MethodRule('anthropic')->structured('openai')
)

// Override each method individually
$router->setRule(
    new MethodRule('openai')
        ->chat('anthropic')
        ->stream('anthropic')
        ->structured('openai')
)
```

#### CallbackRule

Wraps a callable for maximum flexibility. Use this when you need to inspect messages or tools:

```php
use NeuronAI\Router\Rules\CallbackRule;

// Route based on tools presence
$router->setRule(new CallbackRule(function (string $method, array $messages, array $tools): string {
    if (count($tools) > 0) {
        return 'anthropic';
    }
    return 'openai';
}))
```

#### RoundRobinRule

Distributes requests evenly across providers in sequence. Each call cycles to the next provider:

```php
use NeuronAI\Router\Rules\RoundRobinRule;

// Alternate between Anthropic and OpenAI for each request
$router->setRule(
    new RoundRobinRule(['anthropic', 'openai'])
)
```

#### DifficultyRule

Routes by the difficulty of the conversation, using the [Neuron Classifier](https://github.com/neuron-core/llm-classifier) package. It classifies the **first user message** and then **sticks to that provider** for every subsequent message in the same conversation — the whole thread is served by the model the opening prompt was routed to.

> The classifier is an **optional** dependency. Install it only if you use this rule:
> ```
> composer require neuron-core/llm-classifier
> ```

```php
use NeuronAI\Router\Rules\DifficultyRule;
use NeuronCore\Classifier\Classifier;


class MyAgent extens Agent
{
    protected function provider(): AIProviderInterface
    {
        // Load the classifier ONCE (e.g. on app boot or under a long-lived worker).
        $scorer = Classifier::load('storage/model.bin');

        return RouterProvider::make()
            ->addProvider('mini', new OpenAI(key: 'OPENAI_API_KEY', model: 'gpt-4o-mini'))
            ->addProvider('4o', new OpenAI(key: 'OPENAI_API_KEY', model: 'gpt-4o'))
            ->addProvider('o1', new OpenAI(key: 'OPENAI_API_KEY', model: 'o1'))
            ->setRule(
                (new DifficultyRule($scorer))
                    ->outOfDomain('o1', coverage: 0.4) // unfamiliar prompt → most capable
                    ->easy('mini', maxScore: 0.33)     // overall() < 0.33 → cheap & fast
                    ->medium('4o', maxScore: 0.70)     // overall() < 0.70 → solid all-rounder
                    ->hard('o1')                       // otherwise → most capable
            );
    }
}
```

Resolution order on the first user message:

1. If `coverage()` is below the configured threshold, the prompt is out of the classifier's domain — route to the `outOfDomain` provider.
2. Otherwise compare `overall()` (one score in `[0,1]`) against the `easy`/`medium`/`hard` thresholds.

The decision is cached after the first call, so the classifier runs once per conversation. Stickiness is scoped to the lifetime of the `RouterProvider` instance — build a fresh router per conversation to re-evaluate.

When difficulty is unknown (no tier configured for the score, or no user message present), the rule falls back to the most capable configured tier (`hard → medium → easy → outOfDomain`).

#### ContentRule

Routes based on the content blocks inside messages (images, files, audio, video). When a message contains a content type that not all providers support, you can route it to one that does:

```php
use NeuronAI\Router\Rules\ContentRule;

// Use Anthropic by default, route images and video to Gemini, files to OpenAI
$router->setRule(
    new ContentRule('anthropic')
        ->image('gemini')
        ->video('gemini')
        ->file('openai')
)
```

When multiple content types are present in the same request, precedence is: **video → audio → image → file → default**. Content types without a configured provider are ignored and fall through to the next type in the precedence order.

### Custom Rules

Implement `RoutingRuleInterface` to create your own routing logic:

```php
use NeuronAI\Router\Rules\RoutingRuleInterface;

class ImageAwareRule implements RoutingRuleInterface
{
    public function __construct(
        private string $defaultProvider,
        private string $imageProvider,
    ) {}

    public function resolveProvider(string $method, array $messages, array $tools): string
    {
        foreach ($messages as $message) {
            foreach ($message->getContents() as $content) {
                if ($content instanceof ImageContent) {
                    return $this->imageProvider;
                }
            }
        }
        return $this->defaultProvider;
    }
}
```

Then use it:

```php
$router->setRule(
    new ImageAwareRule(
        defaultProvider: 'anthropic',
        imageProvider: 'gemini',
    )
)
```

## Using with an Agent

Inject the router just like any other provider — either via `setAiProvider()` or by overriding the `provider()` method:

```php
class MyAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return RouterProvider::make()
            ->addProvider('anthropic', new Anthropic(
                key: 'ANTHROPIC_API_KEY',
                model: 'claude-sonnet-4-20250514',
            ))
            ->addProvider('openai', new OpenAI(
                key: 'OPENAI_API_KEY',
                model: 'gpt-4o',
            ))
            ->setRule(
                new RoundRobinRule(['anthropic', 'openai'])
            );
    }
}
```

## Error Handling

The router throws `ProviderException` with clear messages for misconfiguration:

| Scenario | Error Message |
|----------|--------------|
| No routing rule set | `no routing strategy configured. Call setRule() to set one.` |
| No providers registered | `no providers registered. Call addProvider() to add one.` |
| Rule returns unknown name | `unknown provider 'name'. Available: ...` |
| Unknown default provider | `unknown provider 'name'. Available: ...` |
| Mapper called with no default or prior call | `no provider available for delegation. Call setDefaultProvider() or make an inference call first.` |

## Limitations

- `messageMapper()` and `toolPayloadMapper()` delegate to the last-resolved provider (or the default). These are internal to each concrete provider and are never called by the agent directly.
- `setHttpClient()` is forwarded to **all** registered providers.
- The routing rule is called on every inference request, so keep it fast.
