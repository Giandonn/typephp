# TypePHP Taskboard (Qt Widgets)

This desktop project management demo uses PHP for task validation, status
transitions, search, filtering, counts and PDO_SQLITE persistence. C++ creates
Qt widgets, renders PHP-provided data and sends raw UI events back to PHP.

The first launch creates five example tasks. Data is saved to
`%APPDATA%\TypePHP\taskboard.sqlite` on Windows or
`$HOME/TypePHP/taskboard.sqlite` on Unix. Set `TYPEPHP_TASKBOARD_DATA` to override
the file path for demos or tests.

## Linux

Install Qt 6 Widgets, build PHPX, enable the `pdo_sqlite` PHP extension and set
`PHPX_HOME` and `PHP_HOME`. From the compiler repository root:

```bash
php bin/tpc.php examples/qt-taskboard/project.yml --job 2 --no-progress
./typephp_taskboard
```

## Windows

Use a matching x64 MSVC 2022 PHP SDK, PHPX and the Qt 6.8.3 MSVC 2022 x64
Widgets SDK. Replace the Qt root paths in `project.windows.yml` with your
installation path. The `/Zc:__cplusplus` and `/permissive-` flags are required
by this Qt SDK. In a VS x64 Native Tools shell, from the compiler repository root:

```bat
set "PHP_HOME=D:\workspace\php-8.4.23"
set "PHPX_HOME=D:\workspace\phpx"
set "PATH=D:\workspace\qt\6.8.3\msvc2022_64\bin;%PHPX_HOME%\lib;%PHP_HOME%;%PATH%"
"%PHP_HOME%\php.exe" -n bin\tpc.php examples\qt-taskboard\project.windows.yml --job 2 --no-progress
```

The embedded PHP runtime needs PDO_SQLITE. Create a separate
`examples\qt-taskboard\runtime.ini`:

```ini
extension_dir=D:\workspace\php-8.4.23\ext
extension=php_pdo_sqlite.dll
```

Then run from the compiler repository root:

```bat
set "PHPRC=D:\workspace\compiler\examples\qt-taskboard\runtime.ini"
set "PHP_INI_SCAN_DIR="
typephp_taskboard.exe
```

Set `TYPEPHP_TASKBOARD_SCREENSHOT` to a `.png` path to render the window once,
save a screenshot and exit. After validating console output, pass
`--no-console` for a GUI-subsystem build. Run `windeployqt` to deploy Qt
libraries and plugins; PHP/PHPX and other non-Qt DLLs must be deployed
separately.

## Domain test

Run with a PHP interpreter that has `pdo_sqlite` enabled:

```bash
php examples/qt-taskboard/tests/task_store_test.php
```
