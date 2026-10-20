<?php

namespace App\Domain\Shared\Services;

final class DecimalCalculator
{
    public const SCALE = 6;

    public function normalize(
        string|int|float $value
    ): string {
        return bcadd(
            (string) $value,
            '0',
            self::SCALE
        );
    }

    public function add(
        string $a,
        string $b
    ): string {
        return bcadd(
            $a,
            $b,
            self::SCALE
        );
    }

    public function subtract(
        string $a,
        string $b
    ): string {
        return bcsub(
            $a,
            $b,
            self::SCALE
        );
    }

    public function multiply(
        string $a,
        string $b
    ): string {
        $value = bcmul(
            $a,
            $b,
            self::SCALE + 6
        );

        return $this->round(
            $value
        );
    }

    public function compare(
        string $a,
        string $b
    ): int {
        return bccomp(
            $a,
            $b,
            self::SCALE
        );
    }

    public function round(
        string $value
    ): string {
        $increment =
            '0.'
            .str_repeat('0', self::SCALE)
            .'5';

        if (str_starts_with($value, '-')) {
            $increment = '-'.$increment;
        }

        return bcadd(
            $value,
            $increment,
            self::SCALE
        );
    }
}
