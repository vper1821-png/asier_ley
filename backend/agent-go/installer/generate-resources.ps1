param(
  [string]$PngPath = (Resolve-Path "..\..\frontend-react\public\logo-nuevo.png" -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Path),
  [string]$OutputDir = (Join-Path $PSScriptRoot "resources")
)

if (!$PngPath -or !(Test-Path $PngPath)) {
  Write-Host "[ERROR] Logo PNG not found at: $PngPath" -ForegroundColor Red
  Write-Host "Provide path: .\generate-resources.ps1 -PngPath C:\path\to\logo.png" -ForegroundColor Yellow
  exit 1
}

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

Add-Type -AssemblyName System.Drawing

$img = [System.Drawing.Image]::FromFile($PngPath)

# Banner BMP (493x58)
$bmp = New-Object System.Drawing.Bitmap(493, 58)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.Clear([System.Drawing.Color]::FromArgb(0x0a, 0x0a, 0x12))
$ratio = [Math]::Min(493.0 / $img.Width, 58.0 / $img.Height)
$w = [int]($img.Width * $ratio * 0.6)
$h = [int]($img.Height * $ratio * 0.6)
$x = 10
$y = (58 - $h) / 2
$g.DrawImage($img, $x, $y, $w, $h)
$g.Dispose()
$bmp.Save("$OutputDir\banner.bmp", [System.Drawing.Imaging.ImageFormat]::Bmp)
$bmp.Dispose()
Write-Host "  [OK] Banner: $OutputDir\banner.bmp (493x58)" -ForegroundColor Green

# Dialog BMP (493x312)
$dlog = New-Object System.Drawing.Bitmap(493, 312)
$g = [System.Drawing.Graphics]::FromImage($dlog)
$g.Clear([System.Drawing.Color]::FromArgb(0x0a, 0x0a, 0x12))
$ratio2 = [Math]::Min(300.0 / $img.Width, 250.0 / $img.Height)
$w2 = [int]($img.Width * $ratio2)
$h2 = [int]($img.Height * $ratio2)
$x2 = (493 - $w2) / 2
$y2 = (312 - $h2) / 2
$g.DrawImage($img, $x2, $y2, $w2, $h2)
$g.Dispose()
$dlog.Save("$OutputDir\dialog.bmp", [System.Drawing.Imaging.ImageFormat]::Bmp)
$dlog.Dispose()
Write-Host "  [OK] Dialog: $OutputDir\dialog.bmp (493x312)" -ForegroundColor Green

# ICO (32x32 + 64x64)
$icoPath = "$OutputDir\logo.ico"
$ms = New-Object System.IO.MemoryStream
$writer = New-Object System.IO.BinaryWriter($ms)

$writer.Write([char]0)  # reserved
$writer.Write([char]0)  # reserved
$writer.Write([int16]2) # ICO type
$writer.Write([int16]2) # image count

$offsets = @()
$dataStreams = @()

foreach ($size in @(32, 64)) {
  $bmpIco = New-Object System.Drawing.Bitmap($size, $size)
  $gIco = [System.Drawing.Graphics]::FromImage($bmpIco)
  $gIco.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
  $gIco.Clear([System.Drawing.Color]::Transparent)
  $gIco.DrawImage($img, 0, 0, $size, $size)
  $gIco.Dispose()

  $hBmp = $bmpIco.GetHbitmap([System.Drawing.Color]::Transparent)
  $bmpIco.Dispose()

  $icon = [System.Drawing.Icon]::FromHandle($hBmp)
  $icoMs = New-Object System.IO.MemoryStream
  $icon.Save($icoMs)
  $icon.Dispose()
  [System.Runtime.InteropServices.Marshal]::DestroyIcon($icon.Handle)

  $data = $icoMs.ToArray()
  $icoMs.Dispose()

  # ICO header: first 6 bytes are ICO header, then the actual image data
  $imageData = $data[6..($data.Length - 1)]

  $offsets += @{ Size = $size; Offset = $ms.Length + 6 * 2; Len = $imageData.Length }
  $dataStreams += $imageData
}

foreach ($o in $offsets) {
  $writer.Write([byte]$o.Size)    # width
  $writer.Write([byte]$o.Size)    # height
  $writer.Write([byte]0)          # colors
  $writer.Write([byte]0)          # reserved
  $writer.Write([int16]1)         # planes
  $writer.Write([int16]32)        # bpp
  $writer.Write([int32]$o.Len)    # size
  $writer.Write([int32]$o.Offset) # offset
}

foreach ($data in $dataStreams) {
  $writer.Write($data)
}

$writer.Flush()
[System.IO.File]::WriteAllBytes($icoPath, $ms.ToArray())
$writer.Dispose()
$ms.Dispose()

Write-Host "  [OK] Icon: $icoPath (32x32 + 64x64)" -ForegroundColor Green

$img.Dispose()
Write-Host ""
Write-Host "=== Resources generated ===" -ForegroundColor Cyan
Get-ChildItem $OutputDir | Select-Object Name, Length | Format-Table -AutoSize
