# Windows x64 纯 LLVM 验证结果

测试日期：2026-09-30。测试机：Windows x64。

## 环境

- 编译器：LLVM 23.1.2，目标 `x86_64-pc-windows-msvc`。
- PHP 宿主：PHP 8.4.23 NTS / ZTS，使用各自匹配的 Windows SDK 包与 PHPX。
- Nano 源码：本地 php-nano，PHP 8.6.0beta3 / ABI 80600。
- Microsoft 头文件与库：MSVC 14.44.35207、Windows SDK 10.0.26100.0。
- PHPX 构建：独立 CMake 4.4.3、Ninja 1.13.2；NTS/ZTS 均从头配置并重建成功。

直接设置 INCLUDE、LIB，没有运行 `vcvars64.bat`。构建 PATH 中没有
`cl.exe`、`link.exe`、`lib.exe`、`nmake.exe`、`dumpbin.exe`、`rc.exe`、
`mt.exe`、`cvtres.exe`、`msbuild.exe`。实际 PHPX Ninja 命令也已审计，
NTS/ZTS 各 33 条命令未发现上述工具。

使用的编译/链接工具为 `clang++`、`clang-cl`、`lld-link`；资源与检查使用
`llvm-rc`、`llvm-readobj`，PHPX 的归档与清单工具指定为 `llvm-lib`、`llvm-mt`。
Microsoft 提供的头文件、静态库、导入库和运行时 DLL 仍是依赖。

## 完整矩阵

| 模式 | 宿主 PHP | bin | lib | ext |
| --- | --- | --- | --- | --- |
| 普通 | NTS | 编译、运行通过 | 编译、加载、调用通过 | 编译、加载、调用通过 |
| 普通 | ZTS | 编译、运行通过 | 编译、加载、调用通过 | 编译、加载、调用通过 |
| Nano | NTS | 编译、运行通过 | 编译、加载、调用通过 | 按预期明确拒绝 |
| Nano | ZTS | 编译、运行通过 | 编译、加载、调用通过 | 按预期明确拒绝 |

共 10 个成功的编译运行用例，以及 2 个不支持 ext 模式的拒绝用例。
Nano 当前运行时为 NTS，ZTS 编译器宿主不会将 Nano 改成 ZTS。
Nano 运行时 PATH 仅保留 Windows 系统目录，未依赖 PHP/PHPX DLL 路径。

bin 检查 WinAPI、PHP 与 C++ 的 ZTS 一致性、对象、数组闭包、异常和中文文件
读写。lib 检查 DLL 加载、运行时初始化/关闭、导出函数、静态状态与 ZTS。
ext 使用对应宿主 `php -n` 加载并调用，检查数组、闭包、异常、状态和 ZTS。

## 额外构建选项

| 配置 | 用例 | 结果 |
| --- | --- | --- |
| LTO | 普通 NTS bin、普通 ZTS lib/ext、Nano NTS 宿主 bin、Nano ZTS 宿主 lib | 5 项编译、运行通过 |
| Debug + no-console + 版本资源 | 普通 ZTS bin、Nano NTS 宿主 bin | 2 项编译、运行通过 |

Debug 用例实际生成 PDB，PE 子系统实际为 Windows GUI，版本资源中
`FileVersion` 为 `1.2.3.4`，`FileDescription` 的中文文本与预期一致。

本地相关单元测试：84 项 / 255 个断言，以及运行时入口回归测试
2 项 / 16 个断言，全部通过。

## 修复内容

- 为 Clang 的 C/C++/PCH 编译补齐 Windows PHP 宏、NTS/ZTS 设置与动态 CRT 选项。
- 使用 `lld-link` 的 `/OUT:`、COFF 参数与 DLL 选项，避免将驱动器 LTO 参数传给链接器。
- 为 Clang 增加 `llvm-rc` 资源编译和 `llvm-readobj` Nano 导入审计。
- PHPX 在 `phpx.h` 中处理普通 MSVC PHP 包的常量尺寸分配器兼容性，修正 DLL 导入函数指针的常量初始化。
- Nano 修正字符串比较的编译器条件、随机种子函数的外部定义，以及已注册 Core 模块的依赖解析。
- Nano Windows lib 补齐保留的公共运行时依赖；Composer 源码目录与 PHPX_HOME 不同时仍正确识别库入口。

## 产物与复现

测试机的产物目录为
`D:\workspace\.typephp-tests\windows-clang-20260930`：

- `matrix-llvm-msabi`：完整矩阵。
- `matrix-llvm-msabi-lto`：LTO 用例。
- `matrix-llvm-msabi-options`：资源、调试和 GUI 用例。
- `logs/phpx-*-llvm-only-*.log`：PHPX 配置、构建及命令审计。

每个矩阵目录有 `results.json`，各用例有 `build.log`、`run.log` 和产物。
复现命令见 [README](README.md)。这些结果限定于上述 x64 环境；未验证的
架构、PHP/LLVM 版本、第三方扩展或自定义编译选项需要单独测试。
