param(
    [Parameter(Mandatory=$true)][string]$PhpNts,
    [Parameter(Mandatory=$true)][string]$PhpZts,
    [Parameter(Mandatory=$true)][string]$PhpxNts,
    [Parameter(Mandatory=$true)][string]$PhpxZts,
    [Parameter(Mandatory=$true)][string]$ArtifactDir,
    [string]$Compiler = 'clang++',
    [string]$Linker = 'lld-link',
    [string]$Optimization = '2',
    [string[]]$Cases = @(),
    [switch]$Lto,
    [switch]$DebugBuild,
    [switch]$NoConsole,
    [switch]$BinResources,
    [switch]$NoMsvcTools
)

$ErrorActionPreference = 'Continue'
$testRoot = $PSScriptRoot
$compilerRoot = (Resolve-Path (Join-Path $testRoot '..\..\..')).Path
$savedEnvironment = @{}
foreach ($name in @('PHP_HOME','PHPX_HOME','PHPRC','PHP_INI_SCAN_DIR','PATH')) {
    $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
$initialPath = $env:PATH
$Cases = @($Cases | ForEach-Object { $_ -split ',' })
$results = [System.Collections.Generic.List[object]]::new()
New-Item -ItemType Directory -Force $ArtifactDir | Out-Null

function Invoke-Logged([string]$Executable, [string[]]$Arguments, [string]$Log, [bool]$AllowFailure = $false) {
    & $Executable @Arguments > $Log 2>&1
    $code = $LASTEXITCODE
    if ($code -ne 0 -and !$AllowFailure) {
        Get-Content $Log -Tail 80 | ForEach-Object { Write-Host $_ }
        throw "$Executable failed with exit code $code; see $Log"
    }
    return $code
}

try {
    Push-Location $compilerRoot
    if ($NoMsvcTools) {
        foreach ($program in @('cl.exe','link.exe','lib.exe','nmake.exe','dumpbin.exe','rc.exe','mt.exe','cvtres.exe','msbuild.exe')) {
            if (Get-Command $program -ErrorAction SilentlyContinue) { throw "MSVC tool is visible: $program" }
        }
    }
    $triple = (& $Compiler -dumpmachine | Out-String).Trim()
    if ($triple -notmatch 'windows-msvc') { throw "Expected Microsoft ABI target; got $triple" }
    Write-Output "COMPILER_TARGET=$triple NO_MSVC=$NoMsvcTools"
    $loader = Join-Path $ArtifactDir 'lib-loader.exe'
    $loaderArguments = @('-std=c++17','-c',(Join-Path $testRoot 'lib-loader.cc'),'-o',(Join-Path $ArtifactDir 'lib-loader.obj'))
    $loaderArguments += '-fms-runtime-lib=dll'
    Invoke-Logged $Compiler $loaderArguments (Join-Path $ArtifactDir 'loader-compile.log') | Out-Null
    $loaderLinkArguments = @('/NOLOGO',('/OUT:' + $loader),(Join-Path $ArtifactDir 'lib-loader.obj'),'kernel32.lib')
    Invoke-Logged $Linker $loaderLinkArguments (Join-Path $ArtifactDir 'loader-link.log') | Out-Null

    foreach ($runtime in @('normal','nano')) {
        foreach ($hostMode in @('nts','zts')) {
            $env:PHP_HOME = if ($hostMode -eq 'nts') { $PhpNts } else { $PhpZts }
            $env:PHPX_HOME = if ($hostMode -eq 'nts') { $PhpxNts } else { $PhpxZts }
            $env:PATH = "$env:PHP_HOME;$env:PHPX_HOME\build;$env:PHPX_HOME\lib;$initialPath"
            $env:PHPRC = ''
            $env:PHP_INI_SCAN_DIR = ''
            $php = Join-Path $env:PHP_HOME 'php.exe'
            $expectedMode = if ($runtime -eq 'nano') { 'nts' } else { $hostMode }

            foreach ($mode in @('bin','lib','ext')) {
                $caseName = "$runtime-$hostMode-$mode"
                if ($Cases.Count -gt 0 -and $caseName -notin $Cases) { continue }
                $caseDir = Join-Path $ArtifactDir $caseName
                New-Item -ItemType Directory -Force $caseDir | Out-Null
                $extension = if ($mode -eq 'bin') { 'exe' } else { 'dll' }
                $target = Join-Path $caseDir "clang_matrix_$mode.$extension"
                $project = if ($BinResources -and $mode -eq 'bin') { 'bin-resource.yml' } else { "$mode.yml" }
                $arguments = @('-n',(Join-Path $compilerRoot 'bin\tpc.php'),(Join-Path $testRoot $project),'--compiler',$Compiler,'--output',$target,'--build-dir',(Join-Path $caseDir 'build'),'--job','4','--no-progress','-O',$Optimization)
                if ($runtime -eq 'nano') { $arguments += '--nano' }
                if ($Lto) { $arguments += '--lto' }
                if ($DebugBuild) { $arguments += '--debug' }
                if ($NoConsole -and $mode -eq 'bin') { $arguments += '--no-console' }
                $record = [ordered]@{ case=$caseName; runtime_zts=$expectedMode; passed=$false; build_log=(Join-Path $caseDir 'build.log'); run_log=(Join-Path $caseDir 'run.log') }
                Write-Output "RUN $caseName"
                try {
                    $code = Invoke-Logged $php $arguments $record.build_log ($runtime -eq 'nano' -and $mode -eq 'ext')
                    if ($runtime -eq 'nano' -and $mode -eq 'ext') {
                        $log = Get-Content $record.build_log -Raw
                        if ($code -eq 0 -or !$log.Contains('--nano does not support extension mode (-m ext)')) {
                            throw 'Nano extension mode was not explicitly rejected'
                        }
                        $record.passed = $true
                        $record.expected_rejection = $true
                        Write-Output "PASS $caseName (expected rejection)"
                    } else {
                        if ($DebugBuild -and !(Test-Path ([IO.Path]::ChangeExtension($target, 'pdb')))) {
                            throw 'Debug build did not produce a PDB'
                        }
                        if ($BinResources -and $mode -eq 'bin') {
                            $version = [Diagnostics.FileVersionInfo]::GetVersionInfo($target)
                            # PowerShell 5.1 reads BOM-less scripts as ANSI. Keep the expected value in ASCII.
                            $description = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('VHlwZVBIUCDkuK3mlofotYTmupDmtYvor5U='))
                            if ($version.FileVersion -ne '1.2.3.4' -or $version.FileDescription -ne $description) {
                                throw 'Version resource is missing or has incorrect UTF-8 text'
                            }
                        }
                        if ($NoConsole -and $mode -eq 'bin') {
                            $headersLog = Join-Path $caseDir 'headers.log'
                            Invoke-Logged 'llvm-readobj' @('--file-headers',$target) $headersLog | Out-Null
                            if ((Get-Content $headersLog -Raw) -notmatch 'IMAGE_SUBSYSTEM_WINDOWS_GUI') {
                                throw 'Expected Windows GUI subsystem'
                            }
                        }
                        $buildPath = $env:PATH
                        try {
                            if ($runtime -eq 'nano') { $env:PATH = "$env:SystemRoot\System32;$env:SystemRoot" }
                            switch ($mode) {
                                'bin' { Invoke-Logged $target @($expectedMode) $record.run_log | Out-Null }
                                'lib' { Invoke-Logged $loader @($target,$expectedMode) $record.run_log | Out-Null }
                                'ext' { Invoke-Logged $php @('-n','-d',"extension=$target",(Join-Path $testRoot 'ext-runner.php'),$expectedMode) $record.run_log | Out-Null }
                            }
                        } finally {
                            $env:PATH = $buildPath
                        }
                        $record.passed = $true
                        Write-Output "PASS $caseName"
                        Get-Content $record.run_log
                    }
                } catch {
                    $record.error = $_.Exception.Message
                    Write-Output "FAIL $caseName $($record.error)"
                }
                $results.Add([pscustomobject]$record)
                $results | ConvertTo-Json -Depth 5 | Set-Content -Encoding UTF8 (Join-Path $ArtifactDir 'results.json')
            }
        }
    }
} finally {
    Pop-Location
    foreach ($name in $savedEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process')
    }
}
$results | Format-Table case,runtime_zts,passed,error -AutoSize
if ($results.Count -eq 0) { Write-Error 'No matching test cases'; exit 1 }
if (@($results | Where-Object { !$_.passed }).Count -ne 0) { exit 1 }
exit 0
