package logger

import (
	"io"
	"log"
	"os"
	"path/filepath"
	"sync"
)

type Logger struct {
	mu     sync.Mutex
	file   *os.File
	logger *log.Logger
	level  string
}

func New(logPath, level string) *Logger {
	l := &Logger{level: level}

	if logPath != "" {
		dir := filepath.Dir(logPath)
		if err := os.MkdirAll(dir, 0755); err == nil {
			f, err := os.OpenFile(logPath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
			if err == nil {
				l.file = f
				if os.Stdout != nil {
					l.logger = log.New(io.MultiWriter(os.Stdout, f), "", log.LstdFlags)
				} else {
					l.logger = log.New(f, "", log.LstdFlags)
				}
				return l
			}
		}
	}
	l.logger = log.New(os.Stdout, "", log.LstdFlags)
	return l
}

func (l *Logger) Info(format string, args ...interface{}) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.logger.Printf("[INFO] "+format, args...)
}

func (l *Logger) Warn(format string, args ...interface{}) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.logger.Printf("[WARN] "+format, args...)
}

func (l *Logger) Error(format string, args ...interface{}) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.logger.Printf("[ERROR] "+format, args...)
}

func (l *Logger) Fatal(format string, args ...interface{}) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.logger.Printf("[FATAL] "+format, args...)
	os.Exit(1)
}

func (l *Logger) Debug(format string, args ...interface{}) {
	if l.level == "debug" {
		l.mu.Lock()
		defer l.mu.Unlock()
		l.logger.Printf("[DEBUG] "+format, args...)
	}
}

func (l *Logger) Close() {
	if l.file != nil {
		l.file.Close()
	}
}
