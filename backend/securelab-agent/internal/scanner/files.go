package scanner

import (
	"archive/zip"
	"bufio"
	"bytes"
	"encoding/csv"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/ledongthuc/pdf"
	"github.com/xuri/excelize/v2"
)

// ScanFile analiza un archivo en busca de PII
func ScanFile(path string) (map[string][]string, error) {
	ext := strings.ToLower(filepath.Ext(path))
	switch ext {
	case ".xlsx", ".xls":
		return scanExcel(path)
	case ".csv":
		return scanCSV(path)
	case ".txt":
		return scanTXT(path)
	case ".json":
		return scanJSON(path)
	case ".xml":
		return scanXML(path)
	case ".pdf":
		return scanPDF(path)
	case ".doc", ".docx":
		return scanDOC(path)
	}
	return nil, nil
}

func scanExcel(path string) (map[string][]string, error) {
	f, err := excelize.OpenFile(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	sheets := f.GetSheetList()
	if len(sheets) == 0 {
		return nil, nil
	}
	rows, err := f.GetRows(sheets[0])
	if err != nil || len(rows) == 0 {
		return nil, err
	}
	headers := rows[0]
	result := make(map[string][]string)

	for colIdx, header := range headers {
		cats := DetectPersonalData(header)
		for cat := range cats {
			result[header] = append(result[header], cat)
		}
		for _, row := range rows[1:minInt(len(rows), 10)] {
			if colIdx < len(row) {
				cats := DetectPersonalData(row[colIdx])
				for cat := range cats {
					if !stringInSlice(cat, result[header]) {
						result[header] = append(result[header], cat)
					}
				}
				break
			}
		}
	}
	return result, nil
}

func scanCSV(path string) (map[string][]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	r := csv.NewReader(f)
	headers, err := r.Read()
	if err != nil {
		return nil, err
	}
	result := make(map[string][]string)

	for _, header := range headers {
		cats := DetectPersonalData(header)
		for cat := range cats {
			result[header] = append(result[header], cat)
		}
	}

	f.Seek(0, 0)
	r = csv.NewReader(f)
	r.Read() // saltar headers
	for i := 0; i < 10; i++ {
		row, err := r.Read()
		if err == io.EOF {
			break
		}
		if err != nil {
			continue
		}
		for colIdx, val := range row {
			cats := DetectPersonalData(val)
			for cat := range cats {
				if colIdx < len(headers) {
					header := headers[colIdx]
					if !stringInSlice(cat, result[header]) {
						result[header] = append(result[header], cat)
					}
				}
			}
		}
	}
	return result, nil
}

func scanTXT(path string) (map[string][]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	scanner := bufio.NewScanner(f)
	// Aumentar buffer para líneas largas (256KB)
	scanner.Buffer(make([]byte, 0, 256*1024), 256*1024)

	result := make(map[string][]string)
	allText := ""
	lineNum := 0
	for scanner.Scan() && lineNum < 200 {
		line := scanner.Text()
		allText += line + "\n"
		cats := DetectPersonalData(line)
		for cat := range cats {
			key := fmt.Sprintf("line_%d", lineNum)
			if !stringInSlice(cat, result[key]) {
				result[key] = append(result[key], cat)
			}
		}
		lineNum++
	}
	// También detectar PII en el texto completo (cruza líneas)
	if allText != "" {
		cats := DetectPersonalData(allText)
		for cat := range cats {
			if !stringInSlice(cat, result["fulltext"]) {
				result["fulltext"] = append(result["fulltext"], cat)
			}
		}
	}
	if err := scanner.Err(); err != nil {
		return result, fmt.Errorf("error leyendo archivo TXT: %w", err)
	}
	return result, nil
}

func scanJSON(path string) (map[string][]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	scanner := bufio.NewScanner(f)
	result := make(map[string][]string)
	lineNum := 0
	for scanner.Scan() && lineNum < 100 {
		line := scanner.Text()
		cats := DetectPersonalData(line)
		for cat := range cats {
			key := fmt.Sprintf("line_%d", lineNum)
			if !stringInSlice(cat, result[key]) {
				result[key] = append(result[key], cat)
			}
		}
		lineNum++
	}
	if err := scanner.Err(); err != nil {
		return result, fmt.Errorf("error leyendo archivo JSON: %w", err)
	}
	return result, nil
}

func scanXML(path string) (map[string][]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	scanner := bufio.NewScanner(f)
	result := make(map[string][]string)
	lineNum := 0
	for scanner.Scan() && lineNum < 100 {
		line := scanner.Text()
		cats := DetectPersonalData(line)
		for cat := range cats {
			key := fmt.Sprintf("line_%d", lineNum)
			if !stringInSlice(cat, result[key]) {
				result[key] = append(result[key], cat)
			}
		}
		lineNum++
	}
	if err := scanner.Err(); err != nil {
		return result, fmt.Errorf("error leyendo archivo XML: %w", err)
	}
	return result, nil
}

// scanPDF analiza un PDF en 6 niveles de fallback para cubrir todos los casos:
//  1. PDF cifrado con contraseña → pdf_cifrado
//  2. ledongthuc/pdf → PDFs estándar
//  3. ExtractPDFText propio → Form XObjects, Identity-H, subsets SII
//  4. DeShiftPDFText → encoding desplazado (DTEs con fuentes custom)
//  5. Bytes crudos → RUT en claro en metadata o streams sin comprimir
//  6. Estructura → pdf_escaneado si solo hay imágenes, documento_no_analizable en otro caso
func scanPDF(path string) (map[string][]string, error) {
	// 1) PDF cifrado con contraseña
	if isEncryptedPDF(path) {
		return map[string][]string{
			"content": {"pdf_cifrado"},
		}, nil
	}

	// 2) Analizar estructura del PDF para saber si tiene imágenes y/o texto
	structure := analyzePDFStructure(path)

	// 3) Intento 1: ledongthuc/pdf (rápido, bueno para PDFs estándar)
	if text := extractPDFWithLedongthuc(path); len(text) > 50 {
		result := detectFromText(text)
		if len(result) > 0 {
			return result, nil
		}
	}

	// 4) Intento 2: extractor propio (maneja Form XObjects, Identity-H, subsets SII)
	if text, err := ExtractPDFText(path); err == nil && len(text) > 20 {
		// Intentar des-ofuscar texto con encoding desplazado (SII)
		text = DeShiftPDFText(text)
		result := detectFromText(text)
		if len(result) > 0 {
			return result, nil
		}
	}

	// 5) Intento 3: bytes crudos (RUT en claro en metadata o streams sin comprimir)
	if result := scanPDFRawBytes(path); len(result) > 0 {
		return result, nil
	}

	// 6) No se pudo extraer texto. ¿Es un PDF escaneado?
	if structure.hasImages && !structure.hasTextContent {
		return map[string][]string{
			"content": {"pdf_escaneado"},
		}, nil
	}

	// 7) Fallback final: no auditable por razones desconocidas
	return map[string][]string{
		"content": {"documento_no_analizable"},
	}, nil
}

// detectFromText aplica DetectPersonalData y devuelve el map en el formato
// que espera scanFileAndReport.
func detectFromText(text string) map[string][]string {
	result := make(map[string][]string)
	cats := DetectPersonalData(text)
	for cat := range cats {
		result["content"] = append(result["content"], cat)
	}
	return result
}

// pdfStructure describe las características del PDF que nos interesan
// para decidir si es un escaneo o un PDF con texto.
type pdfStructure struct {
	hasImages      bool
	hasFonts       bool
	hasTextContent bool
	imageCount     int
	textStreams    int
}

// analyzePDFStructure lee los bytes crudos del PDF y busca marcadores
// para determinar si es un escaneo (solo imágenes) o tiene texto real.
func analyzePDFStructure(path string) pdfStructure {
	raw, err := os.ReadFile(path)
	if err != nil {
		return pdfStructure{}
	}
	s := string(raw)
	var st pdfStructure

	// Imágenes
	st.imageCount = strings.Count(s, "/Subtype /Image") + strings.Count(s, "/Subtype/Image")
	st.hasImages = st.imageCount > 0

	// Filtros típicos de imágenes escaneadas
	if strings.Contains(s, "/DCTDecode") ||
		strings.Contains(s, "/CCITTFaxDecode") ||
		strings.Contains(s, "/JBIG2Decode") ||
		strings.Contains(s, "/JPXDecode") {
		st.hasImages = true
	}

	// Fuentes
	st.hasFonts = strings.Contains(s, "/Font") ||
		strings.Contains(s, "/BaseFont") ||
		strings.Contains(s, "/FontFile")

	// Operadores de texto en streams sin comprimir
	st.textStreams = strings.Count(s, " Tj") +
		strings.Count(s, " TJ") +
		strings.Count(s, " BT")
	st.hasTextContent = st.textStreams > 0

	return st
}

// scanPDFRawBytes lee el PDF completo como bytes y aplica los patrones
// directamente sobre el binario.
func scanPDFRawBytes(path string) map[string][]string {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil
	}
	return detectFromText(string(raw))
}

// extractPDFWithLedongthuc encapsula la llamada a la librería externa.
func extractPDFWithLedongthuc(path string) string {
	f, r, err := pdf.Open(path)
	if err != nil {
		return ""
	}
	defer f.Close()

	var buf strings.Builder
	total := r.NumPage()
	if total > 100 {
		total = 100
	}
	for i := 1; i <= total; i++ {
		p := r.Page(i)
		if p.V.IsNull() {
			continue
		}
		text, err := p.GetPlainText(nil)
		if err != nil {
			continue
		}
		buf.WriteString(text)
		buf.WriteString("\n")
		if buf.Len() >= 5*1024*1024 {
			break
		}
	}
	return buf.String()
}

// isEncryptedPDF busca /Encrypt en el archivo.
func isEncryptedPDF(path string) bool {
	f, err := os.Open(path)
	if err != nil {
		return false
	}
	defer f.Close()

	info, err := f.Stat()
	if err != nil {
		return false
	}
	size := info.Size()

	readSize := size
	if readSize > 2*1024*1024 {
		readSize = 2 * 1024 * 1024
	}
	buf := make([]byte, readSize)
	if _, err := io.ReadFull(f, buf); err != nil && err != io.ErrUnexpectedEOF && err != io.EOF {
		return false
	}

	if size > 2*1024*1024 {
		tail := make([]byte, 4096)
		if _, err := f.ReadAt(tail, size-int64(len(tail))); err == nil {
			buf = append(buf, tail...)
		}
	}

	return bytes.Contains(buf, []byte("/Encrypt"))
}

func scanDOC(path string) (map[string][]string, error) {
	ext := strings.ToLower(filepath.Ext(path))

	if ext == ".docx" {
		return scanDOCX(path)
	}

	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	buf := make([]byte, 10240)
	n, err := f.Read(buf)
	if err != nil && err != io.EOF {
		return nil, err
	}

	content := string(buf[:n])
	result := make(map[string][]string)
	cats := DetectPersonalData(content)
	for cat := range cats {
		key := "metadata"
		if !stringInSlice(cat, result[key]) {
			result[key] = append(result[key], cat)
		}
	}
	return result, nil
}

func scanDOCX(path string) (map[string][]string, error) {
	r, err := zip.OpenReader(path)
	if err != nil {
		return scanDOC(path)
	}
	defer r.Close()

	result := make(map[string][]string)
	allText := ""

	for _, f := range r.File {
		name := strings.ToLower(f.Name)
		if !strings.Contains(name, "document.xml") &&
			!strings.Contains(name, "comments.xml") &&
			!strings.Contains(name, "footnotes.xml") &&
			!strings.Contains(name, "headers") &&
			!strings.Contains(name, "footers") &&
			!strings.Contains(name, "core.xml") &&
			!strings.Contains(name, "app.xml") &&
			!strings.Contains(name, "custom.xml") {
			continue
		}

		rc, err := f.Open()
		if err != nil {
			continue
		}

		limitedReader := io.LimitReader(rc, 51200)
		buf, readErr := io.ReadAll(limitedReader)
		rc.Close()

		if len(buf) > 0 && readErr == nil {
			allText += string(buf) + " "
		}
	}

	if allText == "" {
		return result, nil
	}

	cats := DetectPersonalData(allText)
	for cat := range cats {
		if !stringInSlice(cat, result["content"]) {
			result["content"] = append(result["content"], cat)
		}
	}

	return result, nil
}

// minInt devuelve el mínimo de dos enteros
func minInt(a, b int) int {
	if a < b {
		return a
	}
	return b
}

// stringInSlice comprueba si un string está en un slice
func stringInSlice(s string, slice []string) bool {
	for _, v := range slice {
		if v == s {
			return true
		}
	}
	return false
}
