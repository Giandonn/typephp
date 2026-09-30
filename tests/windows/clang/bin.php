<?php

final class ClangMatrixCounter
{
    public function __construct(private int $value) {}

    public function add(int $delta): int
    {
        $this->value += $delta;
        return $this->value;
    }
}

function clangCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    clangCheck($argc === 2, 'Expected nts or zts');
    $expectedZts = $argv[1] === 'zts';
    clangCheck((PHP_ZTS !== 0 && PHP_ZTS !== false) === $expectedZts, 'PHP_ZTS mismatch');
    clangCheck(windows_php_is_zts() === $expectedZts, 'Native ZTS macro mismatch');
    clangCheck(PHP_OS_FAMILY === 'Windows', 'Platform constant mismatch');
    clangCheck(windows_current_process_id() > 0 && windows_has_module_handle(), 'WinAPI failed');

    $counter = new ClangMatrixCounter(40);
    clangCheck($counter->add(2) === 42, 'Object method failed');
    $values = array_map(static fn(int $n): int => $n * 2, [1, 2, 3]);
    clangCheck(implode(',', $values) === '2,4,6', 'Closure or array failed');
    $caught = false;
    try {
        throw new RuntimeException('clang-exception');
    } catch (RuntimeException $error) {
        $caught = $error->getMessage() === 'clang-exception';
    }
    clangCheck($caught, 'Exception handling failed');

    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clang-matrix-' . windows_current_process_id() . '.txt';
    $content = 'TypePHP 中文文件内容';
    clangCheck(file_put_contents($path, $content) === strlen($content), 'File write failed');
    clangCheck(file_get_contents($path) === $content, 'File read failed');
    clangCheck(unlink($path), 'File cleanup failed');
    echo 'bin-ok:', $expectedZts ? 'zts' : 'nts', "\n";
}
