# ur-monitor セットアップの呼び水（Windows 用）。
#
# 【使い方】
#   powershell -ExecutionPolicy Bypass -File tools\setup.ps1
#
# 【この呼び水がすること】
# 前提コマンド（git / GitHub CLI / Node.js）を確かめ、無ければ winget で入れる。
# 中身の対話・検証・API 呼び出しはすべて tools/setup.mjs（Node 製、OS 共通）に任せる。
# ここに処理を増やさないこと。OS ごとに書き分けると、テストスイートが無いこの
# リポジトリでは片方の修正漏れに気づけるのが本番実行時になる。

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

function Test-Cmd($name) {
    return [bool](Get-Command $name -ErrorAction SilentlyContinue)
}

if (-not (Test-Cmd 'winget')) {
    Write-Host '前提の導入には winget が必要です。Windows の「アプリ インストーラー」を' -ForegroundColor Red
    Write-Host 'Microsoft Store から入れてから、もう一度実行してください。' -ForegroundColor Red
    exit 1
}

$packages = @(
    @{ Cmd = 'git'; Id = 'Git.Git' },
    @{ Cmd = 'gh';  Id = 'GitHub.cli' },
    @{ Cmd = 'node'; Id = 'OpenJS.NodeJS.LTS' }
)

$installedAny = $false
foreach ($p in $packages) {
    if (-not (Test-Cmd $p.Cmd)) {
        Write-Host "$($p.Id) を導入しています..."
        winget install --id $p.Id -e --accept-source-agreements --accept-package-agreements
        $installedAny = $true
    }
}

if ($installedAny) {
    Write-Host ''
    Write-Host '導入したコマンドを使えるようにするため、PATH を読み直します。' -ForegroundColor Yellow
    $machinePath = [System.Environment]::GetEnvironmentVariable('Path', 'Machine')
    $userPath    = [System.Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = "$machinePath;$userPath"
}

foreach ($p in $packages) {
    if (-not (Test-Cmd $p.Cmd)) {
        Write-Host "$($p.Cmd) がまだ見つかりません。いったんこのウィンドウを閉じて" -ForegroundColor Red
        Write-Host 'ターミナルを開き直し、もう一度実行してください。' -ForegroundColor Red
        exit 1
    }
}

node tools/setup.mjs @args
exit $LASTEXITCODE
