package scanner

import (
	"bytes"
	"compress/flate"
	"io"
)

// decompressFlateRaw intenta descomprimir un stream deflate sin header zlib.
func decompressFlateRaw(data []byte) ([]byte, error) {
	r := flate.NewReader(bytes.NewReader(data))
	defer r.Close()
	return io.ReadAll(io.LimitReader(r, 10*1024*1024))
}
