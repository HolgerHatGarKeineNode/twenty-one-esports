<?php

namespace App\Support\FairPlay;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The AI scan of one account (plan "Profile standard and impersonation", P3,
 * D11), run by {@see AccountScans} when an admin starts it: always on
 * Anthropic with the league's own `ANTHROPIC_API_KEY` (the provider switch
 * of the voice, TextModel, does not apply), the model and the instructions
 * from {@see AccountCheckSettings} (database). Thinking is not asked for
 * and effort is low: Haiku 5.5 takes `disabled`, Sonnet 5.5 `between_tools`,
 * Opus and later reject both (400), so the key is left out for them.
 * Answers in a fixed schema; tests fake it.
 */
#[CacheInstructions]
final class AccountScanAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /** The most reasons a scan may give. */
    public const MAX_REASONS = 5;

    /** The longest reason kept. */
    public const REASON_MAX = 300;

    public function __construct(private string $modelId, private string $prompt) {}

    public function instructions(): string
    {
        return $this->prompt;
    }

    public function provider(): string
    {
        return Lab::Anthropic->value;
    }

    public function model(): string
    {
        return $this->modelId;
    }

    public function timeout(): int
    {
        return 90;
    }

    /** Room for adaptive thinking on models that always think, plus five short reasons. */
    public function maxTokens(): int
    {
        return 4000;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;

        if ($name !== Lab::Anthropic->value) {
            return [];
        }

        $thinking = match (true) {
            str_contains($this->modelId, 'haiku') => ['thinking' => ['type' => 'disabled']],
            str_starts_with($this->modelId, 'claude-sonnet-5-5') => ['thinking' => ['type' => 'between_tools']],
            default => [],
        };

        return [...$thinking, 'output_config' => ['effort' => 'low']];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'verdict' => $schema->string()->enum(['fine', 'unclear', 'suspicious'])->required(),
            'reasons' => $schema->array()->items($schema->string())->max(self::MAX_REASONS)->required(),
        ];
    }
}
