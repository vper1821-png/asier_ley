package queue

import (
	"database/sql"
	"encoding/json"
	"time"

	_ "modernc.org/sqlite"
)

type PendingEvent struct {
	ID          int
	EventType   string
	Payload     string
	CreatedAt   time.Time
	Retries     int
	LastAttempt *time.Time
}

type Queue struct {
	db *sql.DB
}

func NewQueue(dbPath string) (*Queue, error) {
	db, err := sql.Open("sqlite", dbPath+"?_journal_mode=WAL&_busy_timeout=5000")
	if err != nil {
		return nil, err
	}
	_, err = db.Exec(`
		CREATE TABLE IF NOT EXISTS pending_events (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			event_type TEXT NOT NULL,
			payload TEXT NOT NULL,
			created_at DATETIME NOT NULL,
			retries INTEGER DEFAULT 0,
			last_attempt DATETIME,
			sent INTEGER DEFAULT 0
		)
	`)
	if err != nil {
		return nil, err
	}
	return &Queue{db: db}, nil
}

func (q *Queue) Enqueue(eventType string, payload interface{}) error {
	data, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	_, err = q.db.Exec(
		`INSERT INTO pending_events (event_type, payload, created_at) VALUES (?, ?, ?)`,
		eventType, string(data), time.Now().UTC().Format(time.RFC3339),
	)
	return err
}

func (q *Queue) Dequeue(limit int) ([]PendingEvent, error) {
	rows, err := q.db.Query(`
		SELECT id, event_type, payload, created_at, retries, last_attempt
		FROM pending_events WHERE sent = 0 ORDER BY created_at ASC LIMIT ?
	`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var events []PendingEvent
	for rows.Next() {
		var ev PendingEvent
		var lastAttempt sql.NullString
		err := rows.Scan(&ev.ID, &ev.EventType, &ev.Payload, &ev.CreatedAt, &ev.Retries, &lastAttempt)
		if err != nil {
			continue
		}
		if lastAttempt.Valid {
			t, _ := time.Parse(time.RFC3339, lastAttempt.String)
			ev.LastAttempt = &t
		}
		events = append(events, ev)
	}
	// VERIFICAR ERRORES DESPUÉS DEL BUCLE (corrige sqlrowserr)
	if err = rows.Err(); err != nil {
		return nil, err
	}

	if len(events) > 0 {
		ids := make([]interface{}, len(events))
		for i, e := range events {
			ids[i] = e.ID
		}
		placeholders := ""
		for i := range ids {
			if i > 0 {
				placeholders += ","
			}
			placeholders += "?"
		}
		query := "UPDATE pending_events SET sent = 1 WHERE id IN (" + placeholders + ")"
		_, err = q.db.Exec(query, ids...)
		if err != nil {
			return nil, err
		}
	}
	return events, nil
}

func (q *Queue) MarkAsSent(id int) error {
	_, err := q.db.Exec("DELETE FROM pending_events WHERE id = ?", id)
	return err
}

func (q *Queue) Close() error {
	return q.db.Close()
}
