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

// maxRowsToSample es la cantidad de filas que se muestrean para detección de PII.
// Antes era 10 — insuficiente para nóminas o planillas RR.HH. donde los datos
// están en filas avanzadas.
const maxRowsToSample = 200

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

// CountRows cuenta las filas/registros de un archivo tabular.
// Devuelve 0 para formatos no tabulares (PDF, TXT, DOCX, etc.).
// Se usa para reportar rowCount real en lugar del scanCount fijo.
func CountRows(path string) int {
	ext := strings.ToLower(filepath.Ext(path))
	switch ext {
	case ".xlsx", ".xls":
		return countExcelRows(path)
	case ".csv":
		return countCSVRows(path)
	}
	return 0
}

func countExcelRows(path string) int {
	f, err := excelize.OpenFile(path)
	if err != nil {
		return 0
	}
	defer f.Close()

	sheets := f.GetSheetList()
	if len(sheets) == 0 {
		return 0
	}
	rows, err := f.GetRows(sheets[0])
	if err != nil {
		return 0
	}
	if len(rows) <= 1 {
		return 0
	}
	return len(rows) - 1 // excluir header
}

func countCSVRows(path string) int {
	f, err := os.Open(path)
	if err != nil {
		return 0
	}
	defer f.Close()

	r := csv.NewReader(f)
	r.FieldsPerRecord = -1
	count := 0
	for {
		_, err := r.Read()
		if err == io.EOF {
			break
		}
		if err != nil {
			break
		}
		count++
	}
	if count > 0 {
		count-- // excluir header
	}
	return count
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

	// Detectar PII en headers
	for _, header := range headers {
		cats := DetectPersonalData(header)
		for cat := range cats {
			if !stringInSlice(cat, result[header]) {
				result[header] = append(result[header], cat)
			}
		}
	}

	// Detectar PII en las primeras maxRowsToSample filas de datos
	maxRows := maxRowsToSample
	if len(rows)-1 < maxRows {
		maxRows = len(rows) - 1
	}

	for rowIdx := 1; rowIdx <= maxRows; rowIdx++ {
		row := rows[rowIdx]
		for colIdx, val := range row {
			if colIdx >= len(headers) {
				break
			}
			header := headers[colIdx]
			cats := DetectPersonalData(val)
			for cat := range cats {
				if !stringInSlice(cat, result[header]) {
					result[header] = append(result[header], cat)
				}
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
	r.FieldsPerRecord = -1
	headers, err := r.Read()
	if err != nil {
		return nil, err
	}
	result := make(map[string][]string)

	// Detectar PII en headers
	for _, header := range headers {
		cats := DetectPersonalData(header)
		for cat := range cats {
			if !stringInSlice(cat, result[header]) {
				result[header] = append(result[header], cat)
			}
		}
	}

	// Detectar PII en las primeras maxRowsToSample filas
	for i := 0; i < maxRowsToSample; i++ {
		row, err := r.Read()
		if err == io.EOF {
			break
		}
		if err != nil {
			continue
		}
		for colIdx, val := range row {
			if colIdx < len(headers) {
				header := headers[colIdx]
				cats := DetectPersonalData(val)
				for cat := range cats {
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

// scanPDF analiza un PDF en 6 niveles de fallback
func scanPDF(path string) (map[string][]string, error) {
	if isEncryptedPDF(path) {
		return map[string][]string{
			"content": {"pdf_cifrado"},
		}, nil
	}

	structure := analyzePDFStructure(path)

	if text := extractPDFWithLedongthuc(path); len(text) > 50 {
		result := detectFromText(text)
		if len(result) > 0 {
			return result, nil
		}
	}

	if text, err := ExtractPDFText(path); err == nil && len(text) > 20 {
		text = DeShiftPDFText(text)
		result := detectFromText(text)
		if len(result) > 0 {
			return result, nil
		}
	}

	if result := scanPDFRawBytes(path); len(result) > 0 {
		return result, nil
	}

	if structure.hasImages && !structure.hasTextContent {
		return map[string][]string{
			"content": {"pdf_escaneado"},
		}, nil
	}

	return map[string][]string{
		"content": {"documento_no_analizable"},
	}, nil
}

func detectFromText(text string) map[string][]string {
	result := make(map[string][]string)
	cats := DetectPersonalData(text)
	for cat := range cats {
		result["content"] = append(result["content"], cat)
	}
	return result
}

type pdfStructure struct {
	hasImages      bool
	hasFonts       bool
	hasTextContent bool
	imageCount     int
	textStreams    int
}

func analyzePDFStructure(path string) pdfStructure {
	raw, err := os.ReadFile(path)
	if err != nil {
		return pdfStructure{}
	}
	s := string(raw)
	var st pdfStructure

	st.imageCount = strings.Count(s, "/Subtype /Image") + strings.Count(s, "/Subtype/Image")
	st.hasImages = st.imageCount > 0

	if strings.Contains(s, "/DCTDecode") ||
		strings.Contains(s, "/CCITTFaxDecode") ||
		strings.Contains(s, "/JBIG2Decode") ||
		strings.Contains(s, "/JPXDecode") {
		st.hasImages = true
	}

	st.hasFonts = strings.Contains(s, "/Font") ||
		strings.Contains(s, "/BaseFont") ||
		strings.Contains(s, "/FontFile")

	st.textStreams = strings.Count(s, " Tj") +
		strings.Count(s, " TJ") +
		strings.Count(s, " BT")
	st.hasTextContent = st.textStreams > 0

	return st
}

func scanPDFRawBytes(path string) map[string][]string {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil
	}
	return detectFromText(string(raw))
}

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

func minInt(a, b int) int {
	if a < b {
		return a
	}
	return b
}

func stringInSlice(s string, slice []string) bool {
	for _, v := range slice {
		if v == s {
			return true
		}
	}
	return false
}
