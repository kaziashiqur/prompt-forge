
const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

function toNanos(decimal) {
    const [whole, fraction = ''] = String(decimal).split('.');
    return BigInt(whole) * 1_000_000_000n + BigInt((fraction + '000000000').slice(0, 9));
}

function ceilDiv(a, b) {
    return (a + b - 1n) / b;
}

export function providerCost(usage, rates) {
    let scaled = 0n;
    for (const type of TOKEN_TYPES) {
        scaled += BigInt(usage[type] ?? 0) * toNanos(rates[type]);
    }
    return ceilDiv(scaled, 1_000_000n);
}

export function userCharge(costNanos) {
    return ceilDiv(costNanos * 125n, 100n);
}

export function formatUsd(nanos) {
    const whole = nanos / 1_000_000_000n;
    let fraction = (nanos % 1_000_000_000n).toString().padStart(9, '0').replace(/0+$/, '');
    return `$${whole}.${fraction.padEnd(2, '0')}`;
}

export function estimate(pricing, prompt, size, quality, referenceCount) {
    const t = pricing.tokens;
    const chars = [...prompt].length;
    const text = Math.ceil(chars / t.characters_per_text_token);
    const variance = Number(t.text_token_variance);
    const output = t.output[quality][size];
    const images = referenceCount * t.per_reference_image;
    const reasoning = t.reasoning[quality];

    const min = {
        input_text_tokens: Math.floor(text * (1 - variance)),
        input_image_tokens: images,
        output_tokens: output,
        reasoning_tokens: reasoning.min,
    };
    const max = {
        input_text_tokens: Math.ceil(text * (1 + variance)),
        input_image_tokens: images,
        output_tokens: output,
        reasoning_tokens: reasoning.max,
    };
    const expected = {
        input_text_tokens: text,
        input_image_tokens: images,
        output_tokens: output,
        reasoning_tokens: Math.floor((reasoning.min + reasoning.max) / 2),
    };

    return {
        min: userCharge(providerCost(min, pricing.rates)),
        max: userCharge(providerCost(max, pricing.rates)),
        expected: userCharge(providerCost(expected, pricing.rates)),
    };
}
