<?php

namespace App\Support;

/**
 * Aritmética decimal exacta (bcmath) para montos. Nunca FLOAT (CLAUDE.md §7.5).
 * Todos los valores entran y salen como string decimal.
 */
final class Money
{
    private const SCALE = 8;

    public static function of(string|int|float|null $v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }

        return is_float($v) ? number_format($v, self::SCALE, '.', '') : (string) $v;
    }

    public static function add(string ...$values): string
    {
        return array_reduce($values, fn ($carry, $v) => bcadd($carry, self::of($v), self::SCALE), '0');
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        return bccomp(self::of($b), '0', self::SCALE) === 0 ? '0' : bcdiv(self::of($a), self::of($b), self::SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    /** Redondeo half-up a $decimals decimales. */
    public static function round(string $v, int $decimals = 2): string
    {
        $v = self::of($v);
        $offset = '0.'.str_repeat('0', $decimals).'5';
        $rounded = str_starts_with($v, '-') ? bcsub($v, $offset, $decimals) : bcadd($v, $offset, $decimals);

        return bcadd($rounded, '0', $decimals);
    }
}
