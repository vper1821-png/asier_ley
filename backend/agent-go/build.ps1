param(
  [string]$Token = "",
  [string]$ApiBase = "",
  [string]$SignCertPath = "",
  [string]$SignCertPassword = "",
  [switch]$SkipMsi = $false
)

$DIR = Split-Path -Parent $MyInvocation.MyCommand.Path
$OUT = Join-Path $DIR "build"
$VERSION = "2.0.0"
$NAME = "invisia-agent"

New-Item -ItemType Directory -Force -Path $OUT | Out-Null

Write-Host "╔══════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║      Invisia V2 Agent - Production Build     ║" -ForegroundColor Cyan
Write-Host "╚══════════════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""

# Validate tools
$goOk = $null -ne (Get-Command "go" -ErrorAction SilentlyContinue)
if (!$goOk) { Write-Host "[ERROR] Go not found in PATH" -ForegroundColor Red; exit 1 }

$wixOk = Test-Path "${env:ProgramFiles(x86)}\WiX Toolset v3.11\bin\candle.exe" -or
         Test-Path "${env:ProgramFiles}\WiX Toolset v3.11\bin\candle.exe"
if (!$wixOk -and !$SkipMsi) {
  Write-Host "[WARN] WiX Toolset not found. Install from: https://wixtoolset.org" -ForegroundColor Yellow
  Write-Host "[INFO] Building without MSI..." -ForegroundColor Yellow
}

$goversioninfoOk = $null -ne (Get-Command "goversioninfo" -ErrorAction SilentlyContinue)
if (!$goversioninfoOk) {
  Write-Host "[INFO] Installing goversioninfo..." -ForegroundColor Yellow
  & go install github.com/josephspurrier/goversioninfo/cmd/goversioninfo@latest
}

# Build ldflags
$ldflags = "-s -w"
if ($Token -ne "") { $ldflags += " -X main.builtinToken=$Token" }
if ($ApiBase -ne "") { $ldflags += " -X main.builtinAPIBase=$ApiBase" }

# Generate version info resource
Write-Host "[1/5] Generating version info resource..." -ForegroundColor Yellow
& goversioninfo -64 -platform-specific -product-version $VERSION -file-version $VERSION `
  -description "Invisia V2 Agent - Endpoint security and database compliance monitoring" `
  -company "Invisia" `
  -product "Invisia V2 Agent" `
  -copyright "Copyright (c) 2026 Invisia. All rights reserved." `
  -o "$OUT\versioninfo.syso"

# Build Windows x64
Write-Host "[2/5] Building Windows x64 (amd64)..." -ForegroundColor Yellow
$env:GOOS = "windows"
$env:GOARCH = "amd64"
$env:CGO_ENABLED = "0"
& go build -ldflags="$ldflags" -o "$OUT\$NAME-win-x64.exe" .
if ($LASTEXITCODE -ne 0) { Write-Host "[ERROR] Windows build failed!" -ForegroundColor Red; exit 1 }

# Strip the versioninfo.syso from the output (it gets embedded in the build)
Remove-Item "$OUT\versioninfo.syso" -ErrorAction SilentlyContinue

# Code sign (optional)
if ($SignCertPath -ne "" -and (Test-Path $SignCertPath)) {
  Write-Host "[3/5] Signing binary..." -ForegroundColor Yellow
  $signtool = "${env:ProgramFiles(x86)}\Windows Kits\10\bin\10.0.22621.0\x64\signtool.exe"
  if (!(Test-Path $signtool)) {
    $signtool = "${env:ProgramFiles}\Windows Kits\10\bin\10.0.22621.0\x64\signtool.exe"
    if (!(Test-Path $signtool)) {
      $signtool = "signtool.exe"
    }
  }
  if ($SignCertPassword -ne "") {
    & $signtool sign /fd SHA256 /f $SignCertPath /p $SignCertPassword /t "http://timestamp.digicert.com" "$OUT\$NAME-win-x64.exe"
  } else {
    & $signtool sign /fd SHA256 /f $SignCertPath /t "http://timestamp.digicert.com" "$OUT\$NAME-win-x64.exe"
  }
  if ($LASTEXITCODE -eq 0) {
    Write-Host "  [OK] Signed successfully" -ForegroundColor Green
  } else {
    Write-Host "  [WARN] Signing failed (continuing anyway)" -ForegroundColor Yellow
  }
} else {
  Write-Host "[3/5] Skipping code signing (no cert provided)" -ForegroundColor Gray
}

# Build Linux x64
Write-Host "[4/5] Building Linux x64 (amd64)..." -ForegroundColor Yellow
$env:GOOS = "linux"
$env:GOARCH = "amd64"
$env:CGO_ENABLED = "0"
& go build -ldflags="$ldflags" -o "$OUT\$NAME-linux-x64" .
if ($LASTEXITCODE -ne 0) { Write-Host "[ERROR] Linux build failed!" -ForegroundColor Red; exit 1 }

# macOS x64
Write-Host "[4/5] Building macOS x64 (Intel)..." -ForegroundColor Yellow
$env:GOOS = "darwin"
$env:GOARCH = "amd64"
$env:CGO_ENABLED = "0"
& go build -ldflags="$ldflags" -o "$OUT\$NAME-mac-x64" .
if ($LASTEXITCODE -ne 0) { Write-Host "[ERROR] macOS Intel build failed!" -ForegroundColor Red; exit 1 }

# macOS arm64
Write-Host "[4/5] Building macOS arm64 (Apple Silicon)..." -ForegroundColor Yellow
$env:GOOS = "darwin"
$env:GOARCH = "arm64"
$env:CGO_ENABLED = "0"
& go build -ldflags="$ldflags" -o "$OUT\$NAME-mac-arm64" .
if ($LASTEXITCODE -ne 0) { Write-Host "[ERROR] macOS ARM build failed!" -ForegroundColor Red; exit 1 }

# Build MSI
if (!$SkipMsi -and $wixOk) {
  Write-Host "[5/5] Building MSI installer..." -ForegroundColor Yellow

  $wixDir = if (Test-Path "${env:ProgramFiles(x86)}\WiX Toolset v3.11\bin") {
    "${env:ProgramFiles(x86)}\WiX Toolset v3.11\bin"
  } else {
    "${env:ProgramFiles}\WiX Toolset v3.11\bin"
  }
  $candle = Join-Path $wixDir "candle.exe"
  $light = Join-Path $wixDir "light.exe"
  $wixobj = Join-Path $OUT "product.wixobj"
  $msiOut = Join-Path $OUT "invisia-agent-$VERSION.msi"

  # Compile .wxs -> .wixobj
  & $candle -arch x64 -out $wixobj -dVersion=$VERSION "$DIR\installer\product.wxs"
  if ($LASTEXITCODE -ne 0) { Write-Host "[ERROR] WiX candle failed!" -ForegroundColor Red; exit 1 }

  # Link .wixobj -> .msi
  & $light -out $msiOut $wixobj -ext WixUIExtension -cultures:en-us
  if ($LASTEXITCODE -ne 0) { Write-Host "[ERROR] WiX light failed!" -ForegroundColor Red; exit 1 }

  # Sign MSI (same cert)
  if ($SignCertPath -ne "" -and (Test-Path $SignCertPath)) {
    $signtool = if (Get-Command "signtool.exe" -ErrorAction SilentlyContinue) { "signtool.exe" } else { "${env:ProgramFiles(x86)}\Windows Kits\10\bin\10.0.22621.0\x64\signtool.exe" }
    if ($SignCertPassword -ne "") {
      & $signtool sign /fd SHA256 /f $SignCertPath /p $SignCertPassword /t "http://timestamp.digicert.com" $msiOut
    } else {
      & $signtool sign /fd SHA256 /f $SignCertPath /t "http://timestamp.digicert.com" $msiOut
    }
    if ($LASTEXITCODE -eq 0) {
      Write-Host "  [OK] MSI signed successfully" -ForegroundColor Green
    }
  }

  Remove-Item $wixobj -ErrorAction SilentlyContinue
  Write-Host "  [OK] MSI: $msiOut" -ForegroundColor Green
} else {
  Write-Host "[5/5] Skipping MSI build" -ForegroundColor Gray
}

Write-Host ""
Write-Host "=== Build complete! ===" -ForegroundColor Green
Get-ChildItem -Path $OUT | Where-Object { !$_.PSIsContainer } | Select-Object Name, Length | Format-Table -AutoSize
