<?php

namespace App\Support;

final class Pricing
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public const MARKUP_PERCENT = 125;

    public static function providerCost(array $usage, array $rates): int
    {
        $scaled = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $tokens = (int) ($usage[$type] ?? 0);
            $scaled += $tokens * Money::toNanos((string) $rates[$type]);
        }

        return Money::ceilDiv($scaled, 1_000_000);
    }

    public static function userCharge(int $providerCostNanos): int
    {
        return Money::ceilDiv($providerCostNanos * self::MARKUP_PERCENT, 100);
    }

    public static function estimate(array $pricing, string $prompt, string $size, string $quality, int $referenceImages): array
    {
        $t = $pricing['tokens'];
        $chars = mb_strlen($prompt, 'UTF-8');
        $textEstimate = (int) ceil($chars / (int) $t['characters_per_text_token']);
        $variance = (float) $t['text_token_variance'];

        $output = (int) $t['output'][$quality][$size];
        $images = $referenceImages * (int) $t['per_reference_image'];
        $reasoning = $t['reasoning'][$quality];

        $min = [
            'input_text_tokens' => (int) floor($textEstimate * (1 - $variance)),
            'input_image_tokens' => $images,
            'output_tokens' => $output,
            'reasoning_tokens' => (int) $reasoning['min'],
        ];
        $max = [
            'input_text_tokens' => (int) ceil($textEstimate * (1 + $variance)),
            'input_image_tokens' => $images,
            'output_tokens' => $output,
            'reasoning_tokens' => (int) $reasoning['max'],
        ];
        $expected = [
            'input_text_tokens' => $textEstimate,
            'input_image_tokens' => $images,
            'output_tokens' => $output,
            'reasoning_tokens' => intdiv((int) $reasoning['min'] + (int) $reasoning['max'], 2),
        ];

        $rates = $pricing['rates'];
        $costMin = self::providerCost($min, $rates);
        $costMax = self::providerCost($max, $rates);
        $costExpected = self::providerCost($expected, $rates);

        return [
            'tokens_min' => $min,
            'tokens_max' => $max,
            'tokens_expected' => $expected,
            'cost_min' => $costMin,
            'cost_max' => $costMax,
            'cost_expected' => $costExpected,
            'charge_min' => self::userCharge($costMin),
            'charge_max' => self::userCharge($costMax),
            'charge_expected' => self::userCharge($costExpected),
        ];
    }
}
