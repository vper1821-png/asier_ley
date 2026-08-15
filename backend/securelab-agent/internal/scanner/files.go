package scanner

import (
	"bufio"
	"encoding/csv"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

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
		if ok, cat := DetectPersonalData(header); ok {
			result[header] = append(result[header], cat)
		}
		for _, row := range rows[1:minInt(len(rows), 10)] {
			if colIdx < len(row) {
				if ok, cat := DetectPersonalData(row[colIdx]); ok {
					if !stringInSlice(cat, result[header]) {
						result[header] = append(result[header], cat)
					}
					break
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
	headers, err := r.Read()
	if err != nil {
		return nil, err
	}
	result := make(map[string][]string)

	for _, header := range headers {
		if ok, cat := DetectPersonalData(header); ok {
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
			if ok, cat := DetectPersonalData(val); ok {
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
	result := make(map[string][]string)
	lineNum := 0
	for scanner.Scan() && lineNum < 100 {
		line := scanner.Text()
		if ok, cat := DetectPersonalData(line); ok {
			key := fmt.Sprintf("line_%d", lineNum)
			if !stringInSlice(cat, result[key]) {
				result[key] = append(result[key], cat)
			}
		}
		lineNum++
	}
	// Verificar errores del scanner
	if err := scanner.Err(); err != nil {
		return result, fmt.Errorf("error leyendo archivo TXT: %w", err)
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
