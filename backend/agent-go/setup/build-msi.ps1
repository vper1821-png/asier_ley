param(
    [string]$ApiBase = "http://localhost:3838/api/agents",
    [string]$Token = "",
    [string]$Version = "1.0.0"
)

$ErrorActionPreference = "Stop"
$SetupDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectDir = Split-Path -Parent $SetupDir
$WixBin = "C:\Program Files (x86)\WiX Toolset v3.14\bin"

# Ensure WiX exists
if (-not (Test-Path "$WixBin\candle.exe")) {
    Write-Error "WiX Toolset v3.14 not found at $WixBin"
    exit 1
}
$env:Path += ";$WixBin"

Write-Host "=== Building Invisia Agent MSI ===" -ForegroundColor Cyan

# Step 1: Build Go binary
Write-Host "[1/3] Building Go binary..." -ForegroundColor Yellow
Push-Location $ProjectDir
go build -ldflags "-X main.builtinToken=$Token -X main.builtinAPIBase=$ApiBase" -o invisia-agent.exe .
if ($LASTEXITCODE -ne 0) {
    Pop-Location
    exit 1
}
Pop-Location

# Step 2: Compile WiX source
Write-Host "[2/3] Compiling WiX source..." -ForegroundColor Yellow
$wxsFile = Join-Path $SetupDir "product.wxs"
$objFile = Join-Path $SetupDir "product.wixobj"

# Update version in .wxs
(Get-Content $wxsFile) -replace 'Version="[\d.]+"', "Version=`"$Version`"" | Set-Content $wxsFile

candle.exe -arch x64 -out "$objFile" "$wxsFile"
if ($LASTEXITCODE -ne 0) {
    Write-Error "WiX compilation failed"
    exit 1
}

# Step 3: Link MSI
Write-Host "[3/3] Linking MSI..." -ForegroundColor Yellow
$msiFile = Join-Path $SetupDir "InvisiaAgent-$Version.msi"
light.exe -out "$msiFile" "$objFile"
if ($LASTEXITCODE -ne 0) {
    Write-Error "WiX linking failed"
    exit 1
}

Write-Host "=== MSI created: $msiFile ===" -ForegroundColor Green
Write-Host "Size: $((Get-Item $msiFile).Length / 1KB) KB" -ForegroundColor Green
