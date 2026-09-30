<?php

namespace TypePhp\Tests\Backend;

use PHPUnit\Framework\TestCase;
use TypePhp\Backend\Clang;
use TypePhp\Platform\Linux;
use TypePhp\Platform\Windows;

final class ClangWindowsTest extends TestCase
{
    public function testWindowsAbiFlagsApplyToCAndCpp(): void
    {
        $backend = new Clang(new Windows([], true), 'clang++', 'lld-link');
        foreach ([
            $backend->buildCompileCommand('main.cc', 'main.obj'),
            $backend->buildCCompileCommand('runtime.c', 'runtime.obj'),
        ] as $command) {
            foreach (['-DPHP_WIN32', '-DZEND_WIN32', '-DZEND_DEBUG=0', '-DENABLE_INTSAFE_SIGNED_FUNCTIONS', '-DZTS', '-fms-runtime-lib=dll'] as $flag) {
                self::assertStringContainsString($flag, $command);
            }
        }
    }

    public function testNanoCanOverrideZtsHostForAllCompilationPaths(): void
    {
        $backend = new Clang(new Windows([], true));
        $options = ['is_zts' => false];
        self::assertStringNotContainsString('-DZTS', $backend->buildCompileCommand('main.cc', 'main.obj', $options));
        self::assertStringNotContainsString('-DZTS', $backend->buildCCompileCommand('runtime.c', 'runtime.obj', $options));
    }

    public function testCoffLinkerReceivesJoinedOutputAndNoDriverOptions(): void
    {
        $backend = new Clang(new Windows(), 'clang++', 'lld-link');
        $command = $this->buildLinkCommand($backend, ['main.obj'], 'output path/app.dll', [
            'build_mode' => 'lib', 'lto' => true, 'section_gc' => true,
            'target_platform' => 'x86_64-pc-windows-msvc',
        ]);
        self::assertStringContainsString('/OUT:' . escapeshellarg('output path/app.dll'), $command);
        self::assertStringNotContainsString('/OUT: ', $command);
        self::assertStringContainsString('/DLL', $command);
        self::assertStringContainsString('/OPT:REF /OPT:ICF', $command);
        self::assertStringNotContainsString('-flto', $command);
        self::assertStringNotContainsString('--target=', $command);
        self::assertStringContainsString('-flto', $backend->buildCompileOptions(['lto' => true]));
    }

    public function testUnixStillUsesCompilerDriverLinkOptions(): void
    {
        $backend = new Clang(new Linux());
        $command = $this->buildLinkCommand($backend, ['main.o'], 'app', ['lto' => true, 'target_platform' => 'aarch64-linux-gnu']);
        self::assertStringContainsString('-o ' . escapeshellarg('app'), $command);
        self::assertStringContainsString('-flto', $command);
        self::assertStringContainsString('--target=aarch64-linux-gnu', $command);
        self::assertStringNotContainsString('-DPHP_WIN32', $backend->buildCompileOptions());
    }

    public function testWindowsResourcesUseLlvmResourceCompiler(): void
    {
        $backend = new Clang(new Windows(), 'unavailable-clang-test');
        $command = $backend->compileResourceFile('app resource.rc', 'app resource.res');
        self::assertStringContainsString(escapeshellarg('llvm-rc'), $command);
        self::assertStringContainsString('/C 65001 /FO ' . escapeshellarg('app resource.res'), $command);
        self::assertStringNotContainsString('rc.exe', $command);
    }

    private function buildLinkCommand(Clang $backend, array $objects, string $output, array $options): string
    {
        $responseFile = tempnam(sys_get_temp_dir(), 'typephp-clang-link-');
        try {
            return $backend->buildLinkCommand($objects, $output, $options + ['response_file' => $responseFile]);
        } finally {
            $backend->cleanupResponseFile();
        }
    }
}
