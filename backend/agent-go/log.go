package main

import (
	"fmt"
	"os"
	"path/filepath"
	"time"
)

func logMsg(format string, args ...interface{}) {
	line := fmt.Sprintf("[%s] %s", time.Now().UTC().Format(time.RFC3339), fmt.Sprintf(format, args...))
	fmt.Println(line)
	cfg := GetConfig()
	if cfg.LogFile == "" {
		return
	}
	f, err := os.OpenFile(cfg.LogFile, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	if err != nil {
		return
	}
	defer f.Close()
	f.WriteString(line + "\n")

	if fi, _ := f.Stat(); fi != nil && fi.Size() > cfg.MaxLogSize {
		rotateLog()
	}
}

func rotateLog() {
	cfg := GetConfig()
	data, err := os.ReadFile(cfg.LogFile)
	if err != nil {
		return
	}
	lines := splitLines(string(data))
	if len(lines) > 1000 {
		lines = lines[len(lines)-1000:]
	}
	var out string
	for _, l := range lines {
		out += l + "\n"
	}
	os.WriteFile(cfg.LogFile, []byte(out), 0644)
}

func writeDebug(format string, args ...interface{}) {
	line := fmt.Sprintf("[%s] %s", time.Now().UTC().Format(time.RFC3339), fmt.Sprintf(format, args...))
	debugPath := filepath.Join(os.TempDir(), "debug-securelab.txt")
	f, err := os.OpenFile(debugPath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	if err != nil {
		return
	}
	defer f.Close()
	f.WriteString(line + "\n")
}

func splitLines(s string) []string {
	var lines []string
	start := 0
	for i := 0; i < len(s); i++ {
		if s[i] == '\n' {
			lines = append(lines, s[start:i])
			start = i + 1
		}
	}
	if start < len(s) {
		lines = append(lines, s[start:])
	}
	return lines
}
