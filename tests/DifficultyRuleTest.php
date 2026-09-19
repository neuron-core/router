<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Router;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Classifier\ClassifierInterface;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Router\RouterProvider;
use NeuronAI\Router\Rules\DifficultyRule;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;

use const INF;
use const NAN;
use const JSON_THROW_ON_ERROR;

class DifficultyRuleTest extends TestCase
{
    #[DataProvider('scores')]
    public function test_routes_normalized_scores(float $score, string $expected): void
    {
        $classifier = new FakeClassifier([['difficulty' => [1 - $score, 0, $score]]]);
        $rule = (new DifficultyRule($classifier))->easy('mini')->medium('standard')->hard('reasoning');

        $this->assertSame($expected, $rule->resolveProvider('chat', [new UserMessage('Help me.')], []));
        $classifier->assertCallCount(1);
    }

    public static function scores(): array
    {
        return [
            'minimum' => [0.0, 'mini'],
            'below easy threshold' => [0.32, 'mini'],
            'easy boundary' => [0.33, 'standard'],
            'middle' => [0.5, 'standard'],
            'below medium threshold' => [0.69, 'standard'],
            'medium boundary' => [0.70, 'reasoning'],
            'maximum' => [1.0, 'reasoning'],
        ];
    }

    public function test_custom_thresholds(): void
    {
        $classifier = new FakeClassifier([
            ['difficulty' => [0, 1, 0]],
            ['difficulty' => [0, 0.5, 0.5]],
        ]);
        $rule = (new DifficultyRule($classifier))
            ->easy('mini', maxScore: 0.6)
            ->medium('standard', maxScore: 0.8)
            ->hard('reasoning');

        $this->assertSame('mini', $rule->resolveProvider('chat', [new UserMessage('First task')], []));
        $this->assertSame('standard', $rule->resolveProvider('chat', [new UserMessage('Second task')], []));
    }

    public function test_passes_entire_history_and_reclassifies_for_each_request(): void
    {
        $classifier = new FakeClassifier([
            ['difficulty' => [1, 0, 0]],
            ['difficulty' => [0, 0, 1]],
        ]);
        $rule = (new DifficultyRule($classifier))->easy('mini')->hard('reasoning');
        $messages = [new UserMessage('What is a queue?')];

        $this->assertSame('mini', $rule->resolveProvider('chat', $messages, []));

        $messages[] = new AssistantMessage('A queue stores items in order.');
        $messages[] = new UserMessage('Now design a distributed queue with exactly-once processing.');

        $this->assertSame('reasoning', $rule->resolveProvider('chat', $messages, []));
        $classifier->assertCallCount(2);
        $input = $classifier->getRecorded()[1]->input;
        $this->assertIsString($input);
        $history = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(3, $history);
        foreach ($messages as $index => $message) {
            $this->assertSame($message->getRole(), $history[$index]['role']);
            $this->assertSame($message->getContent(), $history[$index]['content'][0]['content']);
        }
    }

    #[DataProvider('fallbacks')]
    public function test_uses_most_capable_configured_tier(bool $easy, bool $medium, bool $hard, string $expected): void
    {
        $classifier = new FakeClassifier([['difficulty' => [0, 0, 1]]]);
        $rule = new DifficultyRule($classifier);

        if ($easy) {
            $rule->easy('mini');
        }
        if ($medium) {
            $rule->medium('standard');
        }
        if ($hard) {
            $rule->hard('reasoning');
        }

        $this->assertSame($expected, $rule->resolveProvider('chat', [], []));
        $classifier->assertNothingSent();
        $this->assertSame($expected, $rule->resolveProvider('chat', [new UserMessage('A hard task')], []));
    }

    public static function fallbacks(): array
    {
        return [
            [true, true, true, 'reasoning'],
            [true, true, false, 'standard'],
            [true, false, false, 'mini'],
            [false, false, true, 'reasoning'],
        ];
    }

    public function test_requires_a_configured_provider_before_classifying(): void
    {
        $classifier = $this->createMock(ClassifierInterface::class);
        $classifier->expects($this->never())->method('classify');
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('no providers configured');

        (new DifficultyRule($classifier))->resolveProvider('chat', [new UserMessage('Hello')], []);
    }

    #[DataProvider('invalidThresholds')]
    public function test_rejects_invalid_thresholds(string $tier, float $threshold): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DifficultyRule(new FakeClassifier()))->{$tier}('mini', $threshold);
    }

    public static function invalidThresholds(): array
    {
        return [
            ['easy', -0.1],
            ['medium', 1.1],
            ['easy', NAN],
            ['medium', INF],
        ];
    }

    public function test_propagates_classifier_failure(): void
    {
        $exception = new ProviderException('Classifier unavailable');
        $classifier = $this->createMock(ClassifierInterface::class);
        $classifier->method('classify')->willThrowException($exception);
        $this->expectExceptionObject($exception);

        (new DifficultyRule($classifier))->hard('reasoning')->resolveProvider('chat', [new UserMessage('Hello')], []);
    }

    #[DataProvider('inferenceMethods')]
    public function test_router_uses_selected_provider(string $method): void
    {
        $classifier = new FakeClassifier([['difficulty' => [0, 0, 1]]]);
        $mini = FakeAIProvider::make(new AssistantMessage('mini'));
        $reasoning = FakeAIProvider::make(new AssistantMessage('reasoning'));
        $router = RouterProvider::make()
            ->addProvider('mini', $mini)
            ->addProvider('reasoning', $reasoning)
            ->setRule((new DifficultyRule($classifier))->easy('mini')->hard('reasoning'));
        $message = new UserMessage('Solve a difficult problem.');

        if ($method === 'stream') {
            $stream = $router->stream($message);
            foreach ($stream as $chunk) {
                // Consume the response to obtain the provider's final message.
            }
            $response = $stream->getReturn();
        } elseif ($method === 'structured') {
            $response = $router->structured($message, 'stdClass', []);
        } else {
            $response = $router->chat($message);
        }

        $this->assertSame('reasoning', $response->message()->getContent());
        $this->assertSame(0, $mini->getCallCount());
        $classifier->assertCallCount(1);
    }

    public static function inferenceMethods(): array
    {
        return [['chat'], ['stream'], ['structured']];
    }
}
