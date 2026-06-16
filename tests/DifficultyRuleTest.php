<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Router;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Router\Rules\DifficultyRule;
use NeuronAI\Router\Rules\RoutingRuleInterface;
use NeuronCore\Classifier\Classifier;
use NeuronCore\Classifier\Head;
use NeuronCore\Classifier\Model;
use PHPUnit\Framework\TestCase;

class DifficultyRuleTest extends TestCase
{
    /**
     * One-head model where "hello" is easy and "calculus" is hard.
     *
     * Both tokens are in vocabulary, so coverage is 1.0 (in-domain) and the
     * score is driven purely by the head: sigmoid(bias) for hello (zero vector),
     * sigmoid(10*1 - 5) for calculus.
     */
    private function scorer(): Classifier
    {
        return Classifier::fromArtifact(new Model(
            language: 'en',
            dim: 1,
            pattern: '/[\p{L}\p{N}]+/u',
            vectors: [
                'hello' => [0.0],
                'calculus' => [10.0],
            ],
            heads: [
                new Head('difficulty', [1.0], -5.0),
            ],
        ));
    }

    public function test_implements_routing_rule_interface(): void
    {
        $this->assertInstanceOf(
            RoutingRuleInterface::class,
            new DifficultyRule($this->scorer()),
        );
    }

    public function test_routes_easy_prompt_to_easy_provider(): void
    {
        $rule = (new DifficultyRule($this->scorer()))
            ->easy('mini')
            ->medium('4o')
            ->hard('o1');

        $this->assertSame(
            'mini',
            $rule->resolveProvider('chat', [UserMessage::make('hello')], []),
        );
    }

    public function test_routes_hard_prompt_to_hard_provider(): void
    {
        $rule = (new DifficultyRule($this->scorer()))
            ->easy('mini')
            ->medium('4o')
            ->hard('o1');

        $this->assertSame(
            'o1',
            $rule->resolveProvider('chat', [UserMessage::make('calculus')], []),
        );
    }

    public function test_routes_out_of_domain_prompt_to_fallback(): void
    {
        // Empty vocabulary: every prompt is fully out-of-domain (coverage 0).
        $outOfDomain = Classifier::fromArtifact(new Model(
            language: 'en',
            dim: 1,
            pattern: '/[\p{L}\p{N}]+/u',
            vectors: [],
            heads: [new Head('difficulty', [0.0], 0.0)],
        ));

        $rule = (new DifficultyRule($outOfDomain))
            ->outOfDomain('o1')
            ->easy('mini')
            ->medium('4o')
            ->hard('o1');

        $this->assertSame(
            'o1',
            $rule->resolveProvider('chat', [UserMessage::make('an unknown prompt')], []),
        );
    }

    public function test_uses_first_user_message_not_the_latest(): void
    {
        // The first user message is easy; a later one is hard. Routing must be
        // driven by the opening prompt, so the whole thread stays on "mini".
        $rule = (new DifficultyRule($this->scorer()))
            ->easy('mini')
            ->medium('4o')
            ->hard('o1');

        $messages = [
            UserMessage::make('hello'),
            AssistantMessage::make('hi there'),
            UserMessage::make('calculus'),
        ];

        $this->assertSame('mini', $rule->resolveProvider('chat', $messages, []));
    }

    public function test_sticks_to_first_decision_across_subsequent_calls(): void
    {
        // First resolution locks in "mini" from "hello". A later call carrying
        // only a hard prompt must still return "mini" — the cached decision.
        $rule = (new DifficultyRule($this->scorer()))
            ->easy('mini')
            ->medium('4o')
            ->hard('o1');

        $first = $rule->resolveProvider('chat', [UserMessage::make('hello')], []);
        $second = $rule->resolveProvider('chat', [UserMessage::make('calculus')], []);

        $this->assertSame('mini', $first);
        $this->assertSame('mini', $second);
    }

    public function test_skips_non_user_leading_messages(): void
    {
        // A leading system/assistant message must not prevent finding the first
        // real user message.
        $rule = (new DifficultyRule($this->scorer()))
            ->easy('mini')
            ->medium('4o')
            ->hard('o1');

        $this->assertSame(
            'mini',
            $rule->resolveProvider('chat', [AssistantMessage::make('preamble'), UserMessage::make('hello')], []),
        );
    }
}
