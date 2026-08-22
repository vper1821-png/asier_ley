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
				// Use MultiWriter to write to both stdout and file
				if os.Stdout != nil {
					l.logger = log.New(io.MultiWriter(os.Stdout, f), "", log.LstdFlags|log.Lshortfile)
				} else {
					l.logger = log.New(f, "", log.LstdFlags|log.Lshortfile)
				}
				// Ensure logs are written immediately
				if f != nil {
					f.Sync()
				}
				l.logger.Printf("[INFO] Logger initialized: logPath=%s, level=%s", logPath, level)
				return l
			} else {
				// Log to stderr if file creation fails
				log.Printf("[ERROR] Failed to open log file %s: %v", logPath, err)
			}
		} else {
			log.Printf("[ERROR] Failed to create log directory %s: %v", dir, err)
		}
	}
	// Fallback to stdout
	l.logger = log.New(os.Stdout, "", log.LstdFlags|log.Lshortfile)
	l.logger.Printf("[INFO] Logger initialized: logPath=stdout, level=%s", level)
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
		if l.file != nil {
			l.file.Sync()
		}
	}
}

func (l *Logger) Flush() {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.file != nil {
		l.file.Sync()
	}
}

func (l *Logger) Close() {
	if l.file != nil {
		l.file.Sync()
		l.file.Close()
	}
}
