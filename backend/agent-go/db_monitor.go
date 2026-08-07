package main

import (
	"database/sql"
	"fmt"
	"sync"
	"time"
)

type DBMonitor struct {
	mu         sync.RWMutex
	connection *DBConnection
	db         *sql.DB
	connected  bool
	lastStatus string
	stopCh     chan struct{}
}

var dbMonitor = &DBMonitor{stopCh: make(chan struct{})}

func StartDBMonitor() {
	go dbMonitor.loop()
}

func StopDBMonitor() {
	close(dbMonitor.stopCh)
}

func (m *DBMonitor) SetConnection(conn DBConnection) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.connection = &conn
	logMsg("DB Monitor: connection set for %s/%s", conn.Engine, conn.Database)
}

func (m *DBMonitor) loop() {
	logMsg("DB Monitor: started (10s interval)")
	ticker := time.NewTicker(10 * time.Second)
	defer ticker.Stop()

	// Initial check after 2 seconds
	time.Sleep(2 * time.Second)
	m.check()

	for {
		select {
		case <-ticker.C:
			m.check()
		case <-m.stopCh:
			logMsg("DB Monitor: stopped")
			m.close()
			return
		}
	}
}

func (m *DBMonitor) check() {
	m.mu.RLock()
	conn := m.connection
	m.mu.RUnlock()

	if conn == nil {
		return
	}

	m.mu.Lock()
	wasConnected := m.connected
	oldStatus := m.lastStatus
	m.mu.Unlock()

	db, err := openDBWithStrategy(*conn, "direct")
	if err != nil {
		m.setStatus(false, fmt.Sprintf("disconnected: %v", err), nil)
		return
	}
	db.SetMaxOpenConns(1)
	db.SetMaxIdleConns(1)
	db.SetConnMaxLifetime(30 * time.Second)

	err = db.Ping()
	if err != nil {
		db.Close()
		m.setStatus(false, fmt.Sprintf("ping failed: %v", err), nil)
		return
	}

	m.mu.Lock()
	if m.db != nil {
		m.db.Close()
	}
	m.db = db
	m.mu.Unlock()

	m.setStatus(true, "connected", db)

	if !wasConnected || oldStatus != "connected" {
		logMsg("DB Monitor: connected to %s/%s", conn.Engine, conn.Database)
		wsSendEvent("DB Connected", fmt.Sprintf("Conexión establecida con %s/%s", conn.Engine, conn.Database), "db_monitor", "low", false)
	}
}

func (m *DBMonitor) setStatus(connected bool, status string, db *sql.DB) {
	m.mu.Lock()
	defer m.mu.Unlock()

	if !connected && m.connected {
		if m.db != nil {
			m.db.Close()
			m.db = nil
		}
		logMsg("DB Monitor: lost connection: %s", status)
		wsSendEvent("DB Disconnected", fmt.Sprintf("Conexión perdida: %s", status), "db_monitor", "high", false)
	}

	m.connected = connected
	m.lastStatus = status
}

func (m *DBMonitor) GetStatus() (bool, string) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.connected, m.lastStatus
}

func (m *DBMonitor) close() {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.db != nil {
		m.db.Close()
		m.db = nil
	}
	m.connection = nil
	m.connected = false
}
