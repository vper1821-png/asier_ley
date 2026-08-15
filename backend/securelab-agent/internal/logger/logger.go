package logger

import (
	"io"
	"log"
	"os"
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
	var w io.Writer = os.Stdout
	if logPath != "" {
		f, err := os.OpenFile(logPath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
		if err == nil {
			l.file = f
			w = io.MultiWriter(os.Stdout, f)
		}
	}
	l.logger = log.New(w, "", log.LstdFlags)
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
