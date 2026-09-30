<?php

$expectedZts = $argv[1] === 'zts';
if ((bool) PHP_ZTS !== $expectedZts || clang_lib_zts() !== $expectedZts) {
    throw new RuntimeException('Extension ZTS mismatch');
}
if (clang_lib_add(40, 2) !== 42 || clang_lib_state(20) !== 20 || clang_lib_state(22) !== 42) {
    throw new RuntimeException('Extension calls failed');
}
if (clang_lib_array() !== [2, 4, 6]) {
    throw new RuntimeException('Extension closure or array failed');
}
$caught = false;
try {
    clang_lib_exception();
} catch (RuntimeException $error) {
    $caught = $error->getMessage() === 'clang-library-exception';
}
if (!$caught) {
    throw new RuntimeException('Extension exception failed');
}
echo 'ext-ok:', $argv[1], "\n";
