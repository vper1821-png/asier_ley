#!/bin/bash
# Invisia V2 Agent - Cross-compilation script
# Outputs binaries to agent-go/build/

set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="$DIR/build"
mkdir -p "$OUT"

echo "=== Invisia V2 Go Agent Build ==="
echo ""

# Windows x64 (with hidden console)
echo "[1/4] Building Windows x64..."
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -ldflags="-s -w" -o "$OUT/invisia-agent-win-x64.exe" .
if command -v goversioninfo &>/dev/null; then
    goversioninfo -platform-specific -product-version "2.0.0" -o "$OUT/res.syso"
fi
# Hide PE subsystem (console -> windows) using a Go helper
cat > "$OUT/hide_console.go" << 'GOEOF'
package main
import (
    "encoding/binary"
    "os"
)
func main() {
    if len(os.Args) < 2 { return }
    data, err := os.ReadFile(os.Args[1])
    if err != nil { panic(err) }
    // PE header offset at 0x3C
    peOff := int(binary.LittleEndian.Uint32(data[0x3C:]))
    // Subsystem is at offset peOff + 0x5C (PE32+) or 0x44 (PE32)
    subsystem := peOff + 0x5C
    if data[peOff+4] == 0x10 { // PE32+
        data[subsystem] = 2 // WINDOWS_GUI
        os.WriteFile(os.Args[1], data, 0755)
    }
}
GOEOF
cd "$OUT"
go run hide_console.go "invisia-agent-win-x64.exe"
rm -f hide_console.go
cd "$DIR"

# Linux x64
echo "[2/4] Building Linux x64..."
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -ldflags="-s -w" -o "$OUT/invisia-agent-linux-x64" .

# macOS x64
echo "[3/4] Building macOS x64..."
GOOS=darwin GOARCH=amd64 CGO_ENABLED=0 go build -ldflags="-s -w" -o "$OUT/invisia-agent-mac-x64" .

# macOS arm64 (Apple Silicon)
echo "[4/4] Building macOS arm64..."
GOOS=darwin GOARCH=arm64 CGO_ENABLED=0 go build -ldflags="-s -w" -o "$OUT/invisia-agent-mac-arm64" .

echo ""
echo "=== Build complete! ==="
ls -lh "$OUT"
