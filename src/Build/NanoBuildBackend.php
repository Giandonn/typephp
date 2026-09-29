<?php

namespace TypePhp\Build;

/** Selects the runtime build backend used by a --nano native application. */
final class NanoBuildBackend
{
    /** Windows native application linked through the PHP/PHPX import libraries. */
    public const WINDOWS_DLL = 'windows-dll';

    /** Composer package manifests whose C/C++ sources are compiled into the program. */
    public const COMPOSER_SOURCES = 'composer-sources';

    public static function forHost(string $platformName): string
    {
        return $platformName === 'Windows' ? self::WINDOWS_DLL : self::COMPOSER_SOURCES;
    }

    public static function composesRuntimeSources(string $platformName): bool
    {
        return self::forHost($platformName) === self::COMPOSER_SOURCES;
    }
}
