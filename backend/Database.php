<?php
// SecureLab2v - MongoDB Database Layer (using file-based JSON storage as fallback)

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class Database {
    private static $instance = null;
    private $mongoClient = null;
    private $db = null;
    private $useMongo = false;
    private $dataDir;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->dataDir = __DIR__ . '/data';
        if (!is_dir($this->dataDir)) mkdir($this->dataDir, 0755, true);

        // Try MongoDB connection
        if (class_exists('MongoDB\Client')) {
            try {
                $this->mongoClient = new MongoDB\Client(MONGODB_URI);
                $uriParts = parse_url(MONGODB_URI);
                $dbName = trim($uriParts['path'] ?? 'invisia', '/') ?: 'invisia';
                $this->db = $this->mongoClient->selectDatabase($dbName);
                $this->db->command(['ping' => 1]);
                $this->useMongo = true;
            } catch (\Exception $e) {
                error_log('[DB] MongoDB not available, using file storage: ' . $e->getMessage());
            }
        }
    }

    private function collectionFile($collection) {
        return $this->dataDir . '/' . $collection . '.json';
    }

    private function readCollection($collection) {
        $file = $this->collectionFile($collection);
        if (!file_exists($file)) return [];
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function writeCollection($collection, $data) {
        file_put_contents($this->collectionFile($collection), json_encode($data, JSON_PRETTY_PRINT));
    }

    // ── MongoDB operations ──

    public function findOne($collection, $filter = []) {
        if ($this->useMongo) {
            $filter = $this->normalizeFilter($filter);
            $result = $this->db->selectCollection($collection)->findOne($filter);
            if (!$result) return null;
            $result = (array)$result;
            if (isset($result['_id'])) $result['_id'] = (string)$result['_id'];
            return $result;
        }
        $data = $this->readCollection($collection);
        foreach ($data as $doc) {
            $match = true;
            foreach ($filter as $k => $v) {
                if (!isset($doc[$k]) || $doc[$k] != $v) { $match = false; break; }
            }
            if ($match) return $doc;
        }
        return null;
    }

    public function find($collection, $filter = [], $options = []) {
        if ($this->useMongo) {
            $filter = $this->normalizeFilter($filter);
            $cursor = $this->db->selectCollection($collection)->find($filter, $options);
            $results = [];
            foreach ($cursor as $doc) {
                $doc = (array)$doc;
                if (isset($doc['_id'])) $doc['_id'] = (string)$doc['_id'];
                $results[] = $doc;
            }
            return $results;
        }
        $data = $this->readCollection($collection);
        $results = [];
        foreach ($data as $doc) {
            $match = true;
            foreach ($filter as $k => $v) {
                if (is_array($v) && isset($v['$in'])) {
                    if (!in_array($doc[$k] ?? null, $v['$in'])) { $match = false; break; }
                } elseif (!isset($doc[$k]) || $doc[$k] != $v) {
                    $match = false; break;
                }
            }
            if ($match) $results[] = $doc;
        }
        // Sort by _id descending (newest first)
        usort($results, fn($a, $b) => strcmp($b['_id'] ?? '', $a['_id'] ?? ''));
        if (isset($options['limit'])) {
            $results = array_slice($results, 0, $options['limit']);
        }
        return $results;
    }

    public function insertOne($collection, $document) {
        if (!isset($document['_id'])) {
            $document['_id'] = $this->generateId();
        }
        $document['createdAt'] = $document['createdAt'] ?? date('c');
        $document['updatedAt'] = date('c');

        if ($this->useMongo) {
            $idValue = $document['_id'] ?? $this->generateId();
            if (is_string($idValue) && preg_match('/^[0-9a-fA-F]{24}$/', $idValue)) {
                $document['_id'] = new MongoDB\BSON\ObjectId($idValue);
            } else {
                $document['_id'] = new MongoDB\BSON\ObjectId();
            }
            $result = $this->db->selectCollection($collection)->insertOne($document);
            $document['_id'] = (string)$result->getInsertedId();
            return $document;
        }
        $data = $this->readCollection($collection);
        $data[] = $document;
        $this->writeCollection($collection, $data);
        return $document;
    }

    public function updateOne($collection, $filter, $update) {
        if ($this->useMongo) {
            $filter = $this->normalizeFilter($filter);
            $this->db->selectCollection($collection)->updateOne($filter, ['$set' => $update]);
            return $this->findOne($collection, $filter);
        }
        $data = $this->readCollection($collection);
        foreach ($data as &$doc) {
            $match = true;
            foreach ($filter as $k => $v) {
                if (!isset($doc[$k]) || $doc[$k] != $v) { $match = false; break; }
            }
            if ($match) {
                foreach ($update as $k => $v) $doc[$k] = $v;
                $doc['updatedAt'] = date('c');
                $this->writeCollection($collection, $data);
                return $doc;
            }
        }
        return null;
    }

    public function deleteOne($collection, $filter) {
        if ($this->useMongo) {
            $filter = $this->normalizeFilter($filter);
            $this->db->selectCollection($collection)->deleteOne($filter);
            return true;
        }
        $data = $this->readCollection($collection);
        $newData = [];
        foreach ($data as $doc) {
            $match = true;
            foreach ($filter as $k => $v) {
                if (!isset($doc[$k]) || $doc[$k] != $v) { $match = false; break; }
            }
            if (!$match) $newData[] = $doc;
        }
        $this->writeCollection($collection, $newData);
        return true;
    }

    public function count($collection, $filter = []) {
        if ($this->useMongo) {
            $filter = $this->normalizeFilter($filter);
            return $this->db->selectCollection($collection)->countDocuments($filter);
        }
        $data = $this->readCollection($collection);
        if (empty($filter)) return count($data);
        $count = 0;
        foreach ($data as $doc) {
            $match = true;
            foreach ($filter as $k => $v) {
                if (!isset($doc[$k]) || $doc[$k] != $v) { $match = false; break; }
            }
            if ($match) $count++;
        }
        return $count;
    }

    private function normalizeFilter($filter) {
        // Convert string _id to ObjectId for MongoDB queries
        // The database stores _id as ObjectId but returns as string
        if (isset($filter['_id']) && is_string($filter['_id']) && preg_match('/^[0-9a-fA-F]{24}$/', $filter['_id'])) {
            $filter['_id'] = new MongoDB\BSON\ObjectId($filter['_id']);
        }
        return $filter;
    }

    private function generateId() {
        return bin2hex(random_bytes(12));
    }
}
