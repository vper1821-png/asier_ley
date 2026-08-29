package dblogger

import (
	"testing"
	"time"

	"go.mongodb.org/mongo-driver/bson"
)

func TestBsonToMap(t *testing.T) {
	m := bson.M{
		"name": "test",
		"value": 123,
		"nested": bson.M{
			"key": "value",
		},
		"array": bson.A{1, 2, 3},
	}

	result := bsonToMap(m)

	if result["name"] != "test" {
		t.Errorf("Expected name to be 'test', got %v", result["name"])
	}

	if result["value"] != 123 {
		t.Errorf("Expected value to be 123, got %v", result["value"])
	}
}

func TestNewDBLogger(t *testing.T) {
	config := Config{
		Enabled:    true,
		BufferSize: 100,
	}

	db := NewDBLogger(config)

	if !db.IsEnabled() {
		t.Error("Expected DBLogger to be enabled")
	}

	if db.bufferSize != 100 {
		t.Errorf("Expected bufferSize to be 100, got %d", db.bufferSize)
	}
}

func TestAddLog(t *testing.T) {
	config := Config{
		Enabled:    true,
		BufferSize: 10,
	}

	db := NewDBLogger(config)

	logEntry := DBLog{
		Timestamp:  time.Now(),
		Operation:  "find",
		Collection: "test",
		Database:   "testdb",
		Query:      bson.M{"name": "test"},
		Success:    true,
		Duration:   100,
	}

	db.addLog(logEntry)

	logs := db.GetLogs()
	if len(logs) != 1 {
		t.Errorf("Expected 1 log, got %d", len(logs))
	}

	if logs[0].Operation != "find" {
		t.Errorf("Expected operation to be 'find', got %s", logs[0].Operation)
	}
}

func TestEnableDisable(t *testing.T) {
	config := Config{
		Enabled: false,
	}

	db := NewDBLogger(config)

	if db.IsEnabled() {
		t.Error("Expected DBLogger to be disabled initially")
	}

	db.Enable()

	if !db.IsEnabled() {
		t.Error("Expected DBLogger to be enabled after Enable()")
	}

	db.Disable()

	if db.IsEnabled() {
		t.Error("Expected DBLogger to be disabled after Disable()")
	}
}