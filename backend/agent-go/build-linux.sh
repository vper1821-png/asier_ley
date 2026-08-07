#!/bin/bash
# Invisia V2 Agent - Cross-compilation script for Linux
# Outputs binaries to agent-go/build/
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="$DIR/build"
NAME="invisia-agent"

mkdir -p "$OUT"

echo "=== Invisia V2 Go Agent Build ==="
echo ""

# Windows x64 (for MSI packaging)
echo "[1/2] Building Windows x64 (for MSI)..."
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -ldflags="-s -w" -o "$OUT/$NAME-win-x64.exe" .
echo "  -> $OUT/$NAME-win-x64.exe"

# Linux x64
echo "[2/2] Building Linux x64..."
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -ldflags="-s -w" -o "$OUT/$NAME-linux-x64" .
echo "  -> $OUT/$NAME-linux-x64"

echo ""
echo "=== Build complete! ==="
ls -lh "$OUT/"
echo ""
echo "Windows binary ready for MSI packaging: $NAME-win-x64.exe"
echo "To build the MSI, copy the exe to a Windows machine with WiX Toolset and run:"
echo "  candle -arch x64 -out product.wixobj installer/product.wxs"
echo "  light -out invisia-agent.msi product.wixobj -ext WixUIExtension"
echo ""
echo "Linux binary ready for systemd: $NAME-linux-x64"
echo "  sudo ./build/$NAME-linux-x64 install"
