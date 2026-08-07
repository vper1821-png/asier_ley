package main

import (
	"context"
	"fmt"
	"strings"
	"time"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
)

type MongoDBMonitor struct {
	client    *mongo.Client
	database  string
	connected bool
}

func (m *ActivityMonitor) connectMongoNative(conn *DBConnection) *MongoDBMonitor {
	mon := &MongoDBMonitor{
		database: conn.Database,
	}

	uri := fmt.Sprintf("mongodb://%s:%d", conn.Host, conn.Port)
	if conn.Host == "" {
		conn.Host = "127.0.0.1"
	}
	if conn.Port == 0 {
		conn.Port = 27017
	}
	if conn.Username != "" && conn.Password != "" {
		uri = fmt.Sprintf("mongodb://%s:%s@%s:%d", conn.Username, conn.Password, conn.Host, conn.Port)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	client, err := mongo.Connect(ctx, options.Client().ApplyURI(uri))
	if err != nil {
		logMsg("MongoDB native: connect failed: %v", err)
		return nil
	}

	err = client.Ping(ctx, nil)
	if err != nil {
		logMsg("MongoDB native: ping failed: %v", err)
		client.Disconnect(context.Background())
		return nil
	}

	mon.client = client
	mon.connected = true
	logMsg("MongoDB native: connected to %s:%d", conn.Host, conn.Port)

	return mon
}

func (m *MongoDBMonitor) watchOplog(activityMon *ActivityMonitor) {
	if !m.connected || m.client == nil {
		return
	}

	ctx := context.Background()

	// Try local.oplog.rs (replica set)
	oplog := m.client.Database("local").Collection("oplog.rs")
	var result bson.M
	err := oplog.FindOne(ctx, bson.M{}).Decode(&result)
	if err != nil {
		logMsg("MongoDB: oplog.rs not available (standalone instance)")
		return
	}

	logMsg("MongoDB: starting oplog tailing")

	pipeline := mongo.Pipeline{
		{{Key: "$match", Value: bson.D{
			{Key: "ns", Value: bson.D{
				{Key: "$ne", Value: "local.oplog.rs"},
				{Key: "$ne", Value: "admin.$cmd"},
				{Key: "$ne", Value: "config.$cmd"},
			}},
		}}},
	}

	opts := options.ChangeStream().SetFullDocument(options.UpdateLookup)
	stream, err := oplog.Watch(ctx, pipeline, opts)
	if err != nil {
		logMsg("MongoDB: watch failed: %v", err)
		return
	}
	defer stream.Close(ctx)

	for stream.Next(ctx) {
		var event bson.M
		if err := stream.Decode(&event); err != nil {
			continue
		}

		entry := m.oplogToQueryEntry(event)
		if entry != nil {
			activityMon.addQueryLog(*entry)
		}
	}
}

func (m *MongoDBMonitor) oplogToQueryEntry(event bson.M) *QueryLogEntry {
	ns, _ := event["ns"].(string)
	op, _ := event["op"].(string)
	o, _ := event["o"].(bson.M)
	o2, _ := event["o2"].(bson.M)
	ts, _ := event["ts"].(bson.M)

	var timestamp time.Time
	if ts != nil {
		if t, ok := ts["$timestamp"]; ok {
			if tsMap, ok2 := t.(bson.M); ok2 {
				if sec, ok3 := tsMap["t"].(int64); ok3 {
					timestamp = time.Unix(sec, 0)
				}
			}
		}
	}
	if timestamp.IsZero() {
		timestamp = time.Now()
	}

	var dbName, collName string
	if parts := strings.SplitN(ns, ".", 2); len(parts) == 2 {
		dbName = parts[0]
		collName = parts[1]
	}

	var queryStr string
	var operation string

	switch op {
	case "i":
		operation = "INSERT"
		bs, _ := bson.MarshalExtJSON(o, false, false)
		queryStr = fmt.Sprintf("db.%s.insert(%s)", collName, string(bs))
	case "u":
		operation = "UPDATE"
		bs, _ := bson.MarshalExtJSON(o, false, false)
		bs2, _ := bson.MarshalExtJSON(o2, false, false)
		queryStr = fmt.Sprintf("db.%s.update(%s, %s)", collName, string(bs2), string(bs))
	case "d":
		operation = "DELETE"
		bs, _ := bson.MarshalExtJSON(o, false, false)
		queryStr = fmt.Sprintf("db.%s.delete(%s)", collName, string(bs))
	default:
		operation = strings.ToUpper(op)
		bs, _ := bson.MarshalExtJSON(o, false, false)
		queryStr = string(bs)
	}

	return &QueryLogEntry{
		Timestamp: timestamp,
		User:      "mongodb",
		Database:  dbName,
		Query:     queryStr,
		Engine:    "mongodb",
		Operation: operation,
		Tables:    []string{collName},
	}
}

func (m *MongoDBMonitor) listDatabases() []string {
	if !m.connected || m.client == nil {
		return nil
	}

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	dbs, err := m.client.ListDatabaseNames(ctx, bson.M{})
	if err != nil {
		logMsg("MongoDB: listDatabases failed: %v", err)
		return nil
	}
	return dbs
}

func (m *MongoDBMonitor) listCollections(dbName string) []string {
	if !m.connected || m.client == nil {
		return nil
	}

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	cols, err := m.client.Database(dbName).ListCollectionNames(ctx, bson.M{})
	if err != nil {
		return nil
	}
	return cols
}

func (m *MongoDBMonitor) countDocuments(dbName, collName string) int64 {
	if !m.connected || m.client == nil {
		return 0
	}

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	count, err := m.client.Database(dbName).Collection(collName).CountDocuments(ctx, bson.M{})
	if err != nil {
		return 0
	}
	return count
}

func (m *MongoDBMonitor) close() {
	if m.client != nil {
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		m.client.Disconnect(ctx)
		m.connected = false
	}
}
