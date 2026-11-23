<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    public static function toNanos(string $decimal): int
    {
        return self::scale($decimal, 9);
    }

    public static function scale(string $decimal, int $places): int
    {
        $decimal = trim($decimal);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $decimal, $m)) {
            throw new InvalidArgumentException("Not a decimal: {$decimal}");
        }

        $fraction = rtrim($m[3] ?? '', '0');

        if (strlen($fraction) > $places) {
            throw new InvalidArgumentException("{$decimal} has more than {$places} decimal places.");
        }

        $value = (int) $m[2] * (10 ** $places) + (int) str_pad($fraction, $places, '0');

        return ($m[1] ?? '') === '-' ? -$value : $value;
    }

    public static function format(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $nanos = abs($nanos);

        return $sign.intdiv($nanos, self::NANOS_PER_DOLLAR).'.'.str_pad((string) ($nanos % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    public static function display(int $nanos): string
    {
        [$whole, $fraction] = explode('.', self::format($nanos));
        $fraction = rtrim($fraction, '0');

        return '$'.$whole.'.'.str_pad($fraction, 2, '0');
    }

    public static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
