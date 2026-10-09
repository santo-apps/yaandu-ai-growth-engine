<?php

namespace Tests\Unit;

use App\AI\ModelPricing;
use Tests\TestCase;

final class ModelPricingTest extends TestCase
{
    public function test_cost_requires_verified_currency_version_and_non_future_effective_date(): void
    {
        config(['ai.model_pricing_per_1k.openai.test-model' => ['input' => 0.2, 'output' => 0.8,
            'currency' => 'USD', 'effective_from' => '2026-01-01', 'version' => 'owner-verified-2026-01']]);

        $pricing = (new ModelPricing())->resolve('openai', 'test-model');

        self::assertSame('USD', $pricing['currency']);
        self::assertSame(0.0032, (new ModelPricing())->estimate($pricing, 8, 2));
    }

    public function test_missing_or_future_pricing_is_unavailable(): void
    {
        config(['ai.model_pricing_per_1k.openai.test-model' => ['input' => 0.2, 'output' => 0.8]]);
        self::assertNull((new ModelPricing())->resolve('openai', 'test-model'));
        config(['ai.model_pricing_per_1k.openai.test-model' => ['input' => 0.2, 'output' => 0.8,
            'currency' => 'USD', 'effective_from' => now()->addDay()->toDateString(), 'version' => 'future']]);
        self::assertNull((new ModelPricing())->resolve('openai', 'test-model'));
    }
}
