<?php

function clang_lib_add(int $left, int $right): int
{
    return $left + $right;
}

function clang_lib_state(int $delta): int
{
    static $total = 0;
    $total += $delta;
    return $total;
}

function clang_lib_zts(): bool
{
    return PHP_ZTS !== 0 && PHP_ZTS !== false;
}

function clang_lib_exception(): void
{
    throw new RuntimeException('clang-library-exception');
}

function clang_lib_array(): array
{
    return array_map(static fn(int $value): int => $value * 2, [1, 2, 3]);
}
