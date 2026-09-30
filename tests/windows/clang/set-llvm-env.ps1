# Dot-source this script in a dedicated PowerShell test session.
param(
    [Parameter(Mandatory=$true)][string]$LlvmHome,
    [Parameter(Mandatory=$true)][string]$VcToolsRoot,
    [Parameter(Mandatory=$true)][string]$WindowsSdkRoot,
    [Parameter(Mandatory=$true)][string]$WindowsSdkVersion
)

$env:LLVM_HOME = (Resolve-Path $LlvmHome).Path
$env:INCLUDE = (@(
    "$VcToolsRoot\include",
    "$WindowsSdkRoot\Include\$WindowsSdkVersion\ucrt",
    "$WindowsSdkRoot\Include\$WindowsSdkVersion\shared",
    "$WindowsSdkRoot\Include\$WindowsSdkVersion\um",
    "$WindowsSdkRoot\Include\$WindowsSdkVersion\winrt"
) -join ';')
$env:LIB = (@(
    "$VcToolsRoot\lib\x64",
    "$WindowsSdkRoot\Lib\$WindowsSdkVersion\ucrt\x64",
    "$WindowsSdkRoot\Lib\$WindowsSdkVersion\um\x64"
) -join ';')
$env:LIBPATH = ''
$env:PATH = "$env:LLVM_HOME\bin;$env:SystemRoot\System32;$env:SystemRoot\System32\WindowsPowerShell\v1.0;$env:SystemRoot"
foreach ($directory in (($env:INCLUDE + ';' + $env:LIB) -split ';')) {
    if (!(Test-Path $directory -PathType Container)) { throw "SDK directory is missing: $directory" }
}
foreach ($program in @('clang++','clang-cl','lld-link','llvm-readobj','llvm-rc','llvm-lib','llvm-mt')) {
    if (!(Get-Command $program -ErrorAction SilentlyContinue)) { throw "LLVM tool is missing: $program" }
}
foreach ($program in @('cl.exe','link.exe','lib.exe','nmake.exe','dumpbin.exe','rc.exe','mt.exe','cvtres.exe','msbuild.exe')) {
    if (Get-Command $program -ErrorAction SilentlyContinue) { throw "MSVC tool is visible: $program" }
}
