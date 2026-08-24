package logger

import (
	"fmt"
	"io"
	"log"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
)

type Logger struct {
	mu       sync.Mutex
	file     *os.File
	logger   *log.Logger
	level    string
	logPath  string
	fileOnly bool
}

// safeWriter wraps a writer and ignores errors (used for stdout in Windows services)
type safeWriter struct {
	w io.Writer
}

func (sw *safeWriter) Write(p []byte) (n int, err error) {
	if sw.w == nil {
		return len(p), nil
	}
	n, _ = sw.w.Write(p)
	return len(p), nil
}

// fileWriter wraps the log file so we can sync after each write
type fileWriter struct {
	f *os.File
}

func (fw *fileWriter) Write(p []byte) (int, error) {
	if fw.f == nil {
		return len(p), nil
	}
	n, err := fw.f.Write(p)
	if err == nil {
		_ = fw.f.Sync()
	}
	return n, err
}

func New(logPath, level string) *Logger {
	if level == "" {
		level = "debug"
	}
	level = strings.ToLower(strings.TrimSpace(level))

	l := &Logger{level: level}

	candidatePaths := []string{}
	if logPath != "" {
		candidatePaths = append(candidatePaths, logPath)
	}

	// Fallback paths in case Program Files is not writable (e.g. running non-elevated)
	if pd := os.Getenv("ProgramData"); pd != "" {
		candidatePaths = append(candidatePaths, filepath.Join(pd, "SecureLab Agent", "logs", "agent.log"))
	}
	if lad := os.Getenv("LOCALAPPDATA"); lad != "" {
		candidatePaths = append(candidatePaths, filepath.Join(lad, "SecureLab Agent", "logs", "agent.log"))
	}
	if runtime.GOOS == "windows" {
		candidatePaths = append(candidatePaths, `C:\ProgramData\SecureLab Agent\logs\agent.log`)
		candidatePaths = append(candidatePaths, `C:\Windows\Temp\SecureLab Agent\logs\agent.log`)
	}
	candidatePaths = append(candidatePaths, filepath.Join(os.TempDir(), "SecureLab-Agent", "logs", "agent.log"))
	candidatePaths = append(candidatePaths, filepath.Join("logs", "agent.log"))

	var finalPath string
	for _, p := range candidatePaths {
		if p == "" {
			continue
		}
		p = filepath.Clean(p)
		dir := filepath.Dir(p)
		if dir != "" && dir != "." {
			if err := os.MkdirAll(dir, 0777); err != nil {
				fmt.Fprintf(os.Stderr, "Logger: no se pudo crear directorio %s: %v\n", dir, err)
				continue
			}
		}
		f, err := os.OpenFile(p, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0666)
		if err != nil {
			fmt.Fprintf(os.Stderr, "Logger: no se pudo abrir %s: %v\n", p, err)
			continue
		}
		// Verify we can actually write to this file
		if _, testErr := f.WriteString(""); testErr != nil {
			_ = f.Close()
			fmt.Fprintf(os.Stderr, "Logger: no se pudo escribir en %s: %v\n", p, testErr)
			continue
		}
		l.file = f
		l.logPath = p
		finalPath = p
		break
	}

	if l.file != nil {
		// On Windows services, os.Stdout may not be available or may error.
		// Use a multi-writer where the file is primary and stdout is best-effort.
		writers := []io.Writer{&fileWriter{f: l.file}}
		if os.Stdout != nil {
			writers = append(writers, &safeWriter{w: os.Stdout})
		}
		l.logger = log.New(io.MultiWriter(writers...), "", log.LstdFlags|log.Lshortfile)
		l.logger.Printf("[INFO] Logger initialized: logPath=%s, level=%s", finalPath, level)
		return l
	}

	// Ultimate fallback to stderr
	l.logger = log.New(os.Stderr, "", log.LstdFlags|log.Lshortfile)
	l.logger.Printf("[WARN] Logger fallback: no se pudo crear archivo de log, usando stderr")
	return l
}

func (l *Logger) log(levelTag, format string, args ...interface{}) {
	l.mu.Lock()
	defer l.mu.Unlock()
	msg := fmt.Sprintf(format, args...)
	_ = l.logger.Output(3, "["+levelTag+"] "+msg)
}

func (l *Logger) Info(format string, args ...interface{}) {
	l.log("INFO", format, args...)
}

func (l *Logger) Warn(format string, args ...interface{}) {
	l.log("WARN", format, args...)
}

func (l *Logger) Error(format string, args ...interface{}) {
	l.log("ERROR", format, args...)
}

func (l *Logger) Fatal(format string, args ...interface{}) {
	l.log("FATAL", format, args...)
	os.Exit(1)
}

func (l *Logger) Debug(format string, args ...interface{}) {
	if l.level == "debug" || l.level == "trace" || l.level == "all" {
		l.log("DEBUG", format, args...)
	}
}

func (l *Logger) Flush() error {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.file == nil {
		return nil
	}
	return l.file.Sync()
}

func (l *Logger) Close() error {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.file == nil {
		return nil
	}
	return l.file.Close()
}
