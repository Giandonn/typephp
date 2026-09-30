<?php

namespace TypePhp\Backend;

use TypePhp\Platform\PlatformBase;
use TypePhp\Platform\Windows;
use TypePhp\Platform\Macos;

/**
 * Clang compiler backend implementation.
 */
class Clang extends GccLikeBackend
{
    public function __construct(PlatformBase $platform, string $compilerCommand = 'clang++', ?string $linkerCommand = null)
    {
        parent::__construct($platform, $compilerCommand, $linkerCommand);
    }

    public function getName(): string
    {
        return 'Clang';
    }

    public function getLinkerCommand(): string
    {
        if ($this->linkerCommand !== null) {
            return $this->linkerCommand;
        }

        if ($this->platform instanceof Windows) {
            return 'lld-link';
        }
        return $this->compilerCommand;
    }

    /**
     * On Windows, use the LLVM linker with the Microsoft ABI.
     */
    public static function detectWindowsLinker(string $compilerCommand = ''): string
    {
        $output = [];
        $returnCode = 0;
        exec('lld-link --version 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            return 'lld-link';
        }

        $directories = [];
        $compiler = \TypePhp\Build\ExecutableLocator::resolve(CompilerFactory::getCommandProgram($compilerCommand));
        if ($compiler !== null) {
            $directories[] = dirname($compiler);
        }
        $llvmHome = getenv('LLVM_HOME');
        if ($llvmHome) {
            $directories[] = rtrim($llvmHome, '\/') . '/bin';
            $directories[] = rtrim($llvmHome, '\/') . '/x64/bin';
        }
        foreach ($directories as $directory) {
            $lldLinkPath = $directory . '/lld-link.exe';
            if (is_file($lldLinkPath)) {
                return escapeshellarg($lldLinkPath);
            }
        }

        return 'lld-link';
    }

    public function compileResourceFile(string $rcFile, string $resFile): string
    {
        $compiler = \TypePhp\Build\ExecutableLocator::resolve(CompilerFactory::getCommandProgram($this->compilerCommand));
        $resourceCompiler = $compiler === null ? null
            : \TypePhp\Build\ExecutableLocator::resolve(dirname($compiler) . '/llvm-rc.exe');
        return escapeshellarg($resourceCompiler ?? 'llvm-rc')
            . ' /C 65001 /FO ' . escapeshellarg($resFile)
            . ' ' . escapeshellarg($rcFile);
    }

    // ──── Hook method overrides ────

    protected function getCompilerPrefixFlags(): string
    {
        if ($this->platform instanceof Windows) {
            return ' -fms-compatibility'
                . ' -fms-compatibility-version=19.40'
                . ' -fdelayed-template-parsing'
                . ' -fms-extensions';
        }
        return '';
    }

    protected function getLinkerOutputFlag(): string
    {
        return $this->platform instanceof Windows ? '/OUT:' : '-o';
    }

    protected function formatLinkerOutputArgument(string $outputFile): string
    {
        if ($this->platform instanceof Windows) {
            return '/OUT:' . escapeshellarg($outputFile);
        }
        return parent::formatLinkerOutputArgument($outputFile);
    }

    protected function buildSharedCompileFlags(array $config, bool $includeCppStd = false): string
    {
        $flags = parent::buildSharedCompileFlags($config, $includeCppStd);
        if ($this->platform instanceof Windows) {
            // The Windows PHP SDK selects its config and symbol ABI using these
            // macros. Apply them to C sources and PCHs as well as generated C++.
            $flags .= ' -DZEND_WIN32 -DPHP_WIN32 -DZEND_DEBUG=0 -DENABLE_INTSAFE_SIGNED_FUNCTIONS';
            $flags .= ' -fms-runtime-lib=dll';
            if (!empty($config['debug'])) {
                $flags .= ' -gcodeview';
            }
            if ($config['is_zts'] ?? $this->platform->isZts()) {
                $flags .= ' -DZTS';
            }
        }
        return $flags;
    }

    protected function formatSanitizerFlag(string $sanitizer): string
    {
        return '-fsanitize=' . $sanitizer;
    }

    public function getPrecompiledHeaderArtifact(string $headerFile): string
    {
        return dirname($headerFile) . DIRECTORY_SEPARATOR . pathinfo($headerFile, PATHINFO_FILENAME) . '.pch';
    }

    public function buildLinkOptions(array $config = []): string
    {
        if ($this->platform instanceof Windows) {
            // lld-link reads LLVM bitcode directly. Driver options such as
            // -flto and --target are not COFF linker options.
            return $this->getPlatformLinkFlags($config);
        }
        return parent::buildLinkOptions($config);
    }

    protected function formatPrecompiledHeaderFlag(array $precompiledHeader): string
    {
        return ' -include-pch ' . escapeshellarg($precompiledHeader['artifact']);
    }

    protected function getPICFlag(array $config): string
    {
        if ($this->platform instanceof Windows) {
            return '';
        }
        if ((!empty($config['build_mode']) && ($config['build_mode'] === 'ext' || $config['build_mode'] === 'lib')) || !empty($config['pic'])) {
            return ' -fPIC';
        }
        return '';
    }

    protected function getPlatformLinkFlags(array $config): string
    {
        if ($this->platform instanceof Windows) {
            $flags = '';
            if (!empty($config['debug'])) {
                $flags .= ' /DEBUG';
            }
            if (!empty($config['no_console'])) {
                $flags .= ' ' . $this->platform->getSubsystemOptions(true);
            }
            $flags .= ' ' . $this->platform->getCrtConfig();
            if (!empty($config['section_gc'])) {
                $flags .= ' /OPT:REF /OPT:ICF';
            }

            if (!empty($config['build_mode']) && ($config['build_mode'] === 'ext' || $config['build_mode'] === 'lib')) {
                $flags .= ' /DLL';
            }
            return $flags;
        }

        return parent::getPlatformLinkFlags($config);
    }

}
