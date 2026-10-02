<?php

declare(strict_types=1);

namespace NeuronAI\Router\Rules;

use InvalidArgumentException;

use function array_key_last;
use function array_sum;
use function is_int;
use function is_string;
use function random_int;

class LoadBalancingRule implements RoutingRuleInterface
{
    /** @var array<string, int> Provider name => weight */
    protected array $weights = [];

    /**
     * @param array<int, string>|array<string, int> $providers A list of provider names
     *     (equal probability), or a map of provider name => integer weight.
     */
    public function __construct(array $providers)
    {
        foreach ($providers as $key => $value) {
            [$name, $weight] = is_int($key) ? [$value, 1] : [$key, $value];

            if (!is_string($name) || !is_int($weight) || $weight < 1) {
                throw new InvalidArgumentException(
                    'LoadBalancingRule: expected a list of provider names or a map of provider name => positive integer weight.',
                );
            }

            $this->weights[$name] = $weight;
        }

        if ($this->weights === []) {
            throw new InvalidArgumentException('LoadBalancingRule: at least one provider is required.');
        }
    }

    public function resolveProvider(string $method, array $messages, array $tools): string
    {
        $pick = $this->random(array_sum($this->weights));

        foreach ($this->weights as $name => $weight) {
            $pick -= $weight;

            if ($pick <= 0) {
                return $name;
            }
        }

        return array_key_last($this->weights);
    }

    /**
     * Random integer between 1 and $max, inclusive.
     */
    protected function random(int $max): int
    {
        return random_int(1, $max);
    }
}
