<?php
declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Decimal money arithmetic.
 *
 * Every amount is a STRING like "1234.50", never a float. In PHP,
 * 0.1 + 0.2 !== 0.3, and an invoice that is one paisa out is a bug a clinic
 * will notice and a regulator will care about.
 *
 * bcmath does the arithmetic when available (it is in this XAMPP build); the
 * fallback works in integer minor units so behaviour does not change if an
 * install lacks the extension.
 *
 * Scale: intermediate results are kept at 6 decimal places so a chain of
 * percentage operations does not accumulate rounding error, and only the
 * final value is rounded to 2 with round(). Rates use 4 decimals to match
 * DECIMAL(6,4) in the schema.
 */
final class Money
{
    private const SCALE = 6;
    private const OUT   = 2;

    private static ?bool $bc = null;

    private static function bc(): bool
    {
        return self::$bc ??= extension_loaded('bcmath');
    }

    /** Normalise anything numeric into a decimal string. */
    public static function of(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_float($value)) {
            // A float arriving here already lost precision; capture what is
            // left rather than compounding it.
            return number_format($value, self::SCALE, '.', '');
        }
        return (string) $value;
    }

    public static function add(int|float|string $a, int|float|string $b): string
    {
        $a = self::of($a);
        $b = self::of($b);
        return self::bc()
            ? bcadd($a, $b, self::SCALE)
            : self::fromUnits(self::toUnits($a) + self::toUnits($b));
    }

    public static function subtract(int|float|string $a, int|float|string $b): string
    {
        $a = self::of($a);
        $b = self::of($b);
        return self::bc()
            ? bcsub($a, $b, self::SCALE)
            : self::fromUnits(self::toUnits($a) - self::toUnits($b));
    }

    public static function multiply(int|float|string $a, int|float|string $b): string
    {
        $a = self::of($a);
        $b = self::of($b);
        if (self::bc()) {
            return bcmul($a, $b, self::SCALE);
        }
        // Fallback: units x plain factor, rounded half-up at the output scale.
        return self::fromUnits((int) round(self::toUnits($a) * (float) $b));
    }

    public static function divide(int|float|string $a, int|float|string $b): string
    {
        $a = self::of($a);
        $b = self::of($b);
        if (self::isZero($b)) {
            throw new \InvalidArgumentException('Money: division by zero');
        }
        return self::bc()
            ? bcdiv($a, $b, self::SCALE)
            : self::fromUnits((int) round(self::toUnits($a) / (float) $b));
    }

    /**
     * Round half-up to 2 decimals — the value that is written to the DB.
     *
     * The sign is tested with bccomp directly rather than via isNegative().
     * isNegative() delegates to compare(), and compare() rounds its operands,
     * so calling it here would recurse until the process ran out of memory.
     */
    public static function round(int|float|string $value): string
    {
        $v = self::of($value);

        if (!self::bc()) {
            return number_format((float) $v, self::OUT, '.', '');
        }

        // bcmath truncates, so add a half at the target scale before cutting.
        $half     = '0.' . str_repeat('0', self::OUT) . '5';
        $adjusted = bccomp($v, '0', self::SCALE) < 0
            ? bcsub($v, $half, self::SCALE + 1)
            : bcadd($v, $half, self::SCALE + 1);

        return bcadd($adjusted, '0', self::OUT);
    }

    /** @param list<int|float|string> $values */
    public static function sum(array $values): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = self::add($total, $value);
        }
        return $total;
    }

    /** -1, 0 or 1 — compare at output scale so "1.001" and "1.00" agree. */
    public static function compare(int|float|string $a, int|float|string $b): int
    {
        $a = self::round($a);
        $b = self::round($b);
        return self::bc()
            ? bccomp($a, $b, self::OUT)
            : self::toUnits($a) <=> self::toUnits($b);
    }

    public static function isZero(int|float|string $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function isNegative(int|float|string $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    public static function greaterThan(int|float|string $a, int|float|string $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    /** A percentage OF an amount: percentOf("1000", "10") = "100.00". */
    public static function percentOf(int|float|string $amount, int|float|string $percent): string
    {
        return self::divide(self::multiply($amount, $percent), '100');
    }

    // ---- integer-minor-unit fallback ----

    private static function toUnits(string $value): int
    {
        return (int) round(((float) $value) * (10 ** self::OUT));
    }

    private static function fromUnits(int $units): string
    {
        return number_format($units / (10 ** self::OUT), self::SCALE, '.', '');
    }
}
