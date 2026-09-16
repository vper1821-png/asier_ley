package scanner

import (
	"bytes"
	"compress/zlib"
	"fmt"
	"io"
	"os"
	"regexp"
	"strconv"
	"strings"
)

// ExtractPDFText extrae el texto de un PDF sin depender de librerías externas.
func ExtractPDFText(path string) (string, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	return extractPDFTextFromBytes(raw), nil
}

func extractPDFTextFromBytes(raw []byte) string {
	s := string(raw)
	streams := extractStreams(s)

	var (
		allText   strings.Builder
		toUnicode = map[string]string{}
	)

	// 1. Procesar ToUnicode CMaps
	for _, st := range streams {
		if !st.decompressed || st.kind != kindCMap {
			continue
		}
		cmap := parseToUnicodeCMap(string(st.data))
		if cmap == "" {
			continue
		}
		name := extractFontName(st.dict)
		toUnicode[name] = cmap
	}

	// CMap global si solo hay uno
	globalCmap := toUnicode[""]
	if globalCmap == "" && len(toUnicode) == 1 {
		for _, v := range toUnicode {
			globalCmap = v
		}
	}

	// 2. Procesar solo content streams
	for _, st := range streams {
		if !st.decompressed || st.kind != kindContent {
			continue
		}
		content := string(st.data)
		if !containsTextOperators(content) {
			continue
		}
		text := extractTextFromContentStream(content, globalCmap)
		if text != "" {
			allText.WriteString(text)
			allText.WriteString("\n")
		}
	}

	return allText.String()
}

// --- Clasificación de streams ---

type streamKind int

const (
	kindUnknown streamKind = iota
	kindContent
	kindCMap
	kindFont
	kindImage
	kindMetadata
)

type pdfStream struct {
	dict         string
	data         []byte
	decompressed bool
	kind         streamKind
}

var streamRe = regexp.MustCompile(`(?s)(<<.*?>>)\s*stream\r?\n`)

func extractStreams(s string) []pdfStream {
	var out []pdfStream
	matches := streamRe.FindAllStringSubmatchIndex(s, -1)

	for _, m := range matches {
		dictStart, dictEnd := m[2], m[3]
		streamStart := m[1]

		dict := s[dictStart:dictEnd]

		endIdx := strings.Index(s[streamStart:], "endstream")
		if endIdx < 0 {
			continue
		}
		data := []byte(s[streamStart : streamStart+endIdx])
		data = bytes.TrimRight(data, "\r\n")

		st := pdfStream{dict: dict}

		switch {
		case strings.Contains(dict, "/FlateDecode"):
			if dec, err := decompressFlate(data); err == nil {
				st.data = dec
				st.decompressed = true
			} else if dec, err := decompressFlateRaw(data); err == nil {
				st.data = dec
				st.decompressed = true
			}
		case strings.Contains(dict, "/Filter"):
			st.decompressed = false
		default:
			st.data = data
			st.decompressed = true
		}

		st.kind = classifyStream(dict)

		if st.kind == kindUnknown && st.decompressed {
			st.kind = classifyByContent(st.data)
		}

		out = append(out, st)
	}
	return out
}

func classifyStream(dict string) streamKind {
	if strings.Contains(dict, "/FontFile") ||
		strings.Contains(dict, "/Length1") ||
		strings.Contains(dict, "/Length2") ||
		strings.Contains(dict, "/Length3") {
		return kindFont
	}
	if strings.Contains(dict, "/ToUnicode") ||
		strings.Contains(dict, "/CMapName") ||
		strings.Contains(dict, "/CIDSystemInfo") {
		return kindCMap
	}
	if strings.Contains(dict, "/Subtype /Image") ||
		strings.Contains(dict, "/DCTDecode") ||
		strings.Contains(dict, "/JPXDecode") ||
		strings.Contains(dict, "/CCITTFaxDecode") ||
		strings.Contains(dict, "/JBIG2Decode") {
		return kindImage
	}
	if strings.Contains(dict, "/Subtype /XML") ||
		strings.Contains(dict, "/Metadata") {
		return kindMetadata
	}
	if strings.Contains(dict, "/Subtype /Form") ||
		strings.Contains(dict, "/Contents") {
		return kindContent
	}
	if len(dict) < 200 && !strings.Contains(dict, "/Subtype") {
		return kindContent
	}
	return kindUnknown
}

func classifyByContent(data []byte) streamKind {
	if !looksLikeText(data) {
		return kindFont
	}
	content := string(data)
	if strings.Contains(content, "BT") ||
		strings.Contains(content, "Tj") ||
		strings.Contains(content, "TJ") ||
		strings.Contains(content, "Tf") {
		return kindContent
	}
	if strings.Contains(content, "beginbfchar") ||
		strings.Contains(content, "beginbfrange") ||
		strings.Contains(content, "begincmap") {
		return kindCMap
	}
	return kindUnknown
}

func looksLikeText(data []byte) bool {
	if len(data) == 0 {
		return false
	}
	printable := 0
	for _, b := range data {
		if (b >= 0x20 && b < 0x7F) || b == '\n' || b == '\r' || b == '\t' {
			printable++
		}
	}
	return float64(printable)/float64(len(data)) > 0.7
}

func decompressFlate(data []byte) ([]byte, error) {
	r, err := zlib.NewReader(bytes.NewReader(data))
	if err != nil {
		return nil, err
	}
	defer r.Close()
	return io.ReadAll(io.LimitReader(r, 10*1024*1024))
}

func extractFontName(dict string) string {
	re := regexp.MustCompile(`/BaseFont\s*/([A-Za-z0-9+\-]+)`)
	if m := re.FindStringSubmatch(dict); len(m) > 1 {
		return m[1]
	}
	return ""
}

// --- ToUnicode CMap ---

var (
	bfcharRe  = regexp.MustCompile(`<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>`)
	bfrangeRe = regexp.MustCompile(`<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>`)
)

func parseToUnicodeCMap(content string) string {
	var entries []string

	if start := strings.Index(content, "beginbfchar"); start >= 0 {
		if end := strings.Index(content[start:], "endbfchar"); end > 0 {
			block := content[start : start+end]
			for _, m := range bfcharRe.FindAllStringSubmatch(block, -1) {
				if len(m) == 3 {
					entries = append(entries, m[1]+":"+m[2])
				}
			}
		}
	}

	if start := strings.Index(content, "beginbfrange"); start >= 0 {
		if end := strings.Index(content[start:], "endbfrange"); end > 0 {
			block := content[start : start+end]
			for _, m := range bfrangeRe.FindAllStringSubmatch(block, -1) {
				if len(m) == 4 {
					lo, err1 := strconv.ParseInt(m[1], 16, 32)
					hi, err2 := strconv.ParseInt(m[2], 16, 32)
					dst, err3 := strconv.ParseInt(m[3], 16, 32)
					if err1 != nil || err2 != nil || err3 != nil {
						continue
					}
					if hi-lo > 256 {
						hi = lo + 256
					}
					for g := lo; g <= hi; g++ {
						r := dst + (g - lo)
						entries = append(entries, fmt.Sprintf("%04X:%04X", g, r))
					}
				}
			}
		}
	}

	if len(entries) == 0 {
		return ""
	}
	return strings.Join(entries, ";")
}

func decodeWithCMap(raw string, cmap string) string {
	if cmap == "" {
		return ""
	}
	table := make(map[uint16]rune, 256)
	for _, entry := range strings.Split(cmap, ";") {
		parts := strings.Split(entry, ":")
		if len(parts) != 2 {
			continue
		}
		gid, err1 := strconv.ParseUint(parts[0], 16, 32)
		r, err2 := strconv.ParseUint(parts[1], 16, 32)
		if err1 != nil || err2 != nil {
			continue
		}
		table[uint16(gid)] = rune(r)
	}
	var out strings.Builder
	b := []byte(raw)
	for i := 0; i+1 < len(b); i += 2 {
		gid := uint16(b[i])<<8 | uint16(b[i+1])
		if r, ok := table[gid]; ok {
			out.WriteRune(r)
		}
	}
	return out.String()
}

// --- Content stream ---

var (
	parenStringRe = regexp.MustCompile(`\(((?:\\.|[^\\()])*)\)`)
	hexStringRe   = regexp.MustCompile(`<([0-9A-Fa-f\s]+)>`)
)

func containsTextOperators(content string) bool {
	return strings.Contains(content, "Tj") ||
		strings.Contains(content, "TJ") ||
		strings.Contains(content, "BT")
}

func extractTextFromContentStream(content string, cmap string) string {
	var out strings.Builder

	for _, m := range parenStringRe.FindAllStringSubmatch(content, -1) {
		if len(m) < 2 {
			continue
		}
		s := unescapePDFString(m[1])
		if s != "" {
			out.WriteString(s)
			out.WriteString(" ")
		}
	}

	for _, m := range hexStringRe.FindAllStringSubmatch(content, -1) {
		if len(m) < 2 {
			continue
		}
		clean := strings.Map(func(r rune) rune {
			if (r >= '0' && r <= '9') || (r >= 'A' && r <= 'F') || (r >= 'a' && r <= 'f') {
				return r
			}
			return -1
		}, m[1])
		if len(clean) < 4 || len(clean)%2 != 0 {
			continue
		}
		if cmap != "" {
			decoded := decodeWithCMap(hexToBytesString(clean), cmap)
			if decoded != "" {
				out.WriteString(decoded)
				out.WriteString(" ")
				continue
			}
		}
		if len(clean)%4 == 0 {
			decoded := hexToUTF16(clean)
			if decoded != "" {
				out.WriteString(decoded)
				out.WriteString(" ")
			}
		}
	}

	return out.String()
}

func unescapePDFString(s string) string {
	var out strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] != '\\' {
			out.WriteByte(s[i])
			continue
		}
		if i+1 >= len(s) {
			break
		}
		i++
		switch s[i] {
		case 'n':
			out.WriteByte('\n')
		case 'r':
			out.WriteByte('\r')
		case 't':
			out.WriteByte('\t')
		case 'b':
			out.WriteByte('\b')
		case 'f':
			out.WriteByte('\f')
		case '(', ')', '\\':
			out.WriteByte(s[i])
		default:
			if s[i] >= '0' && s[i] <= '7' {
				oct := string(s[i])
				if i+2 < len(s) && s[i+1] >= '0' && s[i+1] <= '7' {
					oct += string(s[i+1])
					i++
				}
				if i+1 < len(s) && s[i+1] >= '0' && s[i+1] <= '7' {
					oct += string(s[i+1])
					i++
				}
				if v, err := strconv.ParseUint(oct, 8, 16); err == nil {
					out.WriteByte(byte(v))
				}
			} else {
				out.WriteByte(s[i])
			}
		}
	}
	return out.String()
}

func hexToBytesString(hex string) string {
	b := make([]byte, 0, len(hex)/2)
	for i := 0; i+1 < len(hex); i += 2 {
		v, err := strconv.ParseUint(hex[i:i+2], 16, 8)
		if err != nil {
			return ""
		}
		b = append(b, byte(v))
	}
	return string(b)
}

func hexToUTF16(hex string) string {
	b := make([]byte, 0, len(hex)/2)
	for i := 0; i+1 < len(hex); i += 2 {
		v, err := strconv.ParseUint(hex[i:i+2], 16, 8)
		if err != nil {
			return ""
		}
		b = append(b, byte(v))
	}
	var out strings.Builder
	for i := 0; i+1 < len(b); i += 2 {
		r := rune(uint16(b[i])<<8 | uint16(b[i+1]))
		if r == 0 || r == 0xFFFF || r == 0xFFFE {
			continue
		}
		out.WriteRune(r)
	}
	return out.String()
}

// --- De-shift para PDFs del SII con encoding desplazado ---

// DeShiftPDFText intenta "des-ofuscar" texto de PDFs que usan fuentes subset
// con encoding custom (típico de DTEs del SII). Prueba desplazamientos ASCII
// en el rango imprimible y elige el que más palabras clave chilenas produce.
func DeShiftPDFText(text string) string {
	if len(text) < 10 {
		return text
	}

	best := text
	bestScore := scoreChileanText(text)

	for shift := 1; shift <= 100; shift++ {
		shifted := shiftPrintableASCII(text, shift)
		if score := scoreChileanText(shifted); score > bestScore {
			bestScore = score
			best = shifted
		}
	}

	for shift := -1; shift >= -100; shift-- {
		shifted := shiftPrintableASCII(text, shift)
		if score := scoreChileanText(shifted); score > bestScore {
			bestScore = score
			best = shifted
		}
	}

	if bestScore < 30 {
		return text
	}
	return best
}

// shiftPrintableASCII desplaza cada carácter imprimible ASCII con wraparound.
func shiftPrintableASCII(s string, shift int) string {
	const lo = 0x20
	const hi = 0x7E
	const rangeSize = hi - lo + 1

	var b strings.Builder
	b.Grow(len(s))
	for i := 0; i < len(s); i++ {
		c := s[i]
		if c >= lo && c <= hi {
			shifted := int(c) + shift
			for shifted > hi {
				shifted -= rangeSize
			}
			for shifted < lo {
				shifted += rangeSize
			}
			b.WriteByte(byte(shifted))
		} else {
			b.WriteByte(c)
		}
	}
	return b.String()
}

// scoreChileanText puntúa un texto según cuántas palabras clave chilenas contiene.
func scoreChileanText(s string) int {
	lower := strings.ToLower(s)

	highValue := []string{
		"factura electrónica", "factura electronica",
		"guía de despacho", "guia de despacho",
		"boleta electrónica", "boleta electronica",
		"servicio de impuestos internos",
		"timbre electrónico", "timbre electronico",
	}
	mediumValue := []string{
		"factura", "boleta", "guía", "guia", "rut", "r.u.t", "r.u.n",
		"dirección", "direccion", "comuna", "provincia", "región", "region",
		"vendedor", "comprador", "señor", "señores", "giro",
		"santiago", "valparaíso", "valparaiso", "concepción", "concepcion",
		"chile", "sii", "dte", "electrónica", "electronica",
	}
	lowValue := []string{
		"fecha", "total", "neto", "iva", "exento", "cliente",
		"nombre", "direccion", "teléfono", "telefono",
	}

	score := 0
	for _, kw := range highValue {
		score += strings.Count(lower, kw) * 30
	}
	for _, kw := range mediumValue {
		score += strings.Count(lower, kw) * 10
	}
	for _, kw := range lowValue {
		score += strings.Count(lower, kw) * 3
	}
	return score
}
