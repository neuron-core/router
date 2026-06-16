<?php

declare(strict_types=1);

namespace NeuronAI\Router\Rules;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronCore\Classifier\Classifier;

/**
 * Routes by the difficulty of the conversation's first user message.
 *
 * The provider is chosen ONCE — by classifying the first UserMessage — and then
 * stays sticky: every subsequent inference in the same conversation is driven to
 * the same model, regardless of how the later messages look. This mirrors how an
 * agent routes a whole thread off its opening prompt.
 *
 * Stickiness is scoped to the lifetime of this rule instance, which in turn is
 * the lifetime of the RouterProvider (one agent = one conversation). Build a
 * fresh router per conversation and the decision is re-evaluated.
 *
 * The difficulty score comes from {@see Classifier::overall()} (one number in
 * [0,1]); an optional out-of-domain guard uses {@see Classifier::coverage()}.
 */
class DifficultyRule implements RoutingRuleInterface
{
    /**
     * Provider resolved from the first user message. Once set, it is returned on
     * every subsequent call (sticky).
     */
    protected ?string $resolved = null;

    protected ?string $outOfDomain = null;

    protected float $coverageThreshold = 0.4;

    protected ?string $easy = null;

    protected float $easyMaxScore = 0.33;

    protected ?string $medium = null;

    protected float $mediumMaxScore = 0.70;

    protected ?string $hard = null;

    public function __construct(protected Classifier $classifier)
    {
    }

    /**
     * Provider to fall back to when the first user message is out of the
     * classifier's domain (coverage below $coverage). Unfamiliar territory is
     * safest handled by the most capable model.
     */
    public function outOfDomain(string $provider, float $coverage = 0.4): self
    {
        $this->outOfDomain = $provider;
        $this->coverageThreshold = $coverage;
        return $this;
    }

    /**
     * Provider for easy prompts: overall() score strictly below $maxScore.
     */
    public function easy(string $provider, float $maxScore = 0.33): self
    {
        $this->easy = $provider;
        $this->easyMaxScore = $maxScore;
        return $this;
    }

    /**
     * Provider for medium prompts: overall() score strictly below $maxScore.
     */
    public function medium(string $provider, float $maxScore = 0.70): self
    {
        $this->medium = $provider;
        $this->mediumMaxScore = $maxScore;
        return $this;
    }

    /**
     * Provider for hard prompts: overall() score at or above the medium tier.
     */
    public function hard(string $provider): self
    {
        $this->hard = $provider;
        return $this;
    }

    public function resolveProvider(string $method, array $messages, array $tools): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $prompt = $this->firstUserPrompt($messages);

        // No user message to classify yet: pick the safest tier without caching,
        // so a real user message still triggers classification when it arrives.
        if ($prompt === null) {
            return $this->hardestConfigured();
        }

        if ($this->outOfDomain !== null && $this->classifier->coverage($prompt) < $this->coverageThreshold) {
            return $this->resolved = $this->outOfDomain;
        }

        $score = $this->classifier->overall($prompt);

        if ($this->easy !== null && $score < $this->easyMaxScore) {
            return $this->resolved = $this->easy;
        }

        if ($this->medium !== null && $score < $this->mediumMaxScore) {
            return $this->resolved = $this->medium;
        }

        return $this->resolved = $this->hardestConfigured();
    }

    /**
     * The text of the first message whose role is "user".
     */
    protected function firstUserPrompt(array $messages): ?string
    {
        foreach ($messages as $message) {
            if ($message->getRole() === MessageRole::USER->value) {
                return $message->getContent();
            }
        }
        return null;
    }

    /**
     * The most capable configured tier, used when difficulty is unknown or high.
     * Order: hard → medium → easy → outOfDomain.
     */
    protected function hardestConfigured(): string
    {
        return $this->hard ?? $this->medium ?? $this->easy ?? $this->outOfDomain ?? '';
    }
}
