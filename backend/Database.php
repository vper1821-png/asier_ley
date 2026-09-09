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

        // Try to use MongoDB, fallback to file storage
        try {
            $this->mongoClient = new MongoDB\Client(MONGODB_URI);
            $this->db = $this->mongoClient->selectDatabase('invisia');
            $this->db->command(['ping' => 1]);
            $this->useMongo = true;
            error_log('[DB] MongoDB connection successful');
        } catch (\Throwable $e) {
            $this->useMongo = false;
            error_log('[DB] MongoDB connection failed: ' . $e->getMessage() . ', using file storage');
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
        file_put_contents($this->collectionFile($collection), json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    // ── Filter engine for file-based storage ──

    private function matchesFilter($doc, $filter) {
        foreach ($filter as $k => $v) {
            if ($k === '$or') {
                if (!is_array($v)) return false;
                $orMatch = false;
                foreach ($v as $sub) {
                    if ($this->matchesFilter($doc, $sub)) { $orMatch = true; break; }
                }
                if (!$orMatch) return false;
            } elseif ($k === '$and') {
                if (!is_array($v)) return false;
                foreach ($v as $sub) {
                    if (!$this->matchesFilter($doc, $sub)) return false;
                }
            } elseif ($k === '$in') {
                // Para $in, el valor debe estar dentro del array
                if (!is_array($v)) return false;
                $fieldValue = $doc[$k] ?? null;
                // Si la clave es userId, comparamos como strings
                if ($k === 'userId') {
                    $fieldValue = (string)$fieldValue;
                    $v = array_map('strval', $v);
                }
                if (!in_array($fieldValue, $v, false)) return false;
            } else {
                $field = array_key_exists($k, $doc) ? $doc[$k] : null;
                if (is_array($v)) {
                    foreach ($v as $op => $opVal) {
                        switch ($op) {
                            case '$in':
                                if (!in_array($field, (array)$opVal, false)) return false;
                                break;
                            case '$nin':
                                if (in_array($field, (array)$opVal, false)) return false;
                                break;
                            case '$gte':
                                if ($field === null || $field < $opVal) return false;
                                break;
                            case '$lte':
                                if ($field === null || $field > $opVal) return false;
                                break;
                            case '$gt':
                                if ($field === null || $field <= $opVal) return false;
                                break;
                            case '$lt':
                                if ($field === null || $field >= $opVal) return false;
                                break;
                            case '$ne':
                                if ($field == $opVal) return false;
                                break;
                            case '$exists':
                                if ($opVal && !array_key_exists($k, $doc)) return false;
                                if (!$opVal && array_key_exists($k, $doc)) return false;
                                break;
                            default:
                                if ($field != $v) return false;
                        }
                    }
                } else {
                    if ($field != $v) return false;
                }
            }
        }
        return true;
    }

    // ── MongoDB operations ──

    public function findOne($collection, $filter = []) {
        if ($this->useMongo) {
            $filter = $this->normalizeFilter($filter);
            error_log("[DB] findOne on {$collection}: " . json_encode($filter));
            $result = $this->db->selectCollection($collection)->findOne($filter);
            if (!$result) {
                error_log("[DB] findOne result: null");
                return null;
            }
            $result = (array)$result;
            if (isset($result['_id'])) $result['_id'] = (string)$result['_id'];
            error_log("[DB] findOne result: found document with _id=" . $result['_id']);
            return $result;
        }
        $data = $this->readCollection($collection);
        foreach ($data as $doc) {
            if ($this->matchesFilter($doc, $filter)) return $doc;
        }
        return null;
    }

    public function find($collection, $filter = [], $options = []) {
        if ($this->useMongo) {
            $filter = $this->normalizeFilter($filter);
            $mongoOptions = $options;
            if (isset($mongoOptions['offset'])) {
                $mongoOptions['skip'] = (int)$mongoOptions['offset'];
                unset($mongoOptions['offset']);
            }
            error_log("[DB] find on {$collection}: " . json_encode($filter) . " options: " . json_encode($mongoOptions));
            $cursor = $this->db->selectCollection($collection)->find($filter, $mongoOptions);
            $results = [];
            foreach ($cursor as $doc) {
                $doc = (array)$doc;
                if (isset($doc['_id'])) $doc['_id'] = (string)$doc['_id'];
                $results[] = $doc;
            }
            error_log("[DB] find result: returned " . count($results) . " documents");
            return $results;
        }
        $data = $this->readCollection($collection);
        $results = [];
        foreach ($data as $doc) {
            if ($this->matchesFilter($doc, $filter)) $results[] = $doc;
        }
        usort($results, fn($a, $b) => strcmp($b['_id'] ?? '', $a['_id'] ?? ''));
        $offset = isset($options['offset']) ? (int)$options['offset'] : 0;
        if (isset($options['limit'])) {
            $results = array_slice($results, $offset, (int)$options['limit']);
        } elseif ($offset > 0) {
            $results = array_slice($results, $offset);
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
            error_log("[DB] insertOne on {$collection}: " . json_encode($document, JSON_UNESCAPED_UNICODE));
            $idValue = $document['_id'] ?? $this->generateId();
            if (is_string($idValue) && preg_match('/^[0-9a-fA-F]{24}$/', $idValue)) {
                $document['_id'] = new MongoDB\BSON\ObjectId($idValue);
            } else {
                $document['_id'] = new MongoDB\BSON\ObjectId();
            }
            $result = $this->db->selectCollection($collection)->insertOne($document);
            $document['_id'] = (string)$result->getInsertedId();
            error_log("[DB] insertOne result: insertedId=" . $document['_id']);
            return $document;
        }
        $data = $this->readCollection($collection);
        $data[] = $document;
        $this->writeCollection($collection, $data);
        return $document;
    }

    public function updateOne($collection, $filter, $update) {
        if (is_array($update)) {
            unset($update['_id']);
        }
        if ($this->useMongo) {
            $filter = $this->normalizeFilter($filter);
            error_log("[DB] updateOne on {$collection}: " . json_encode($filter) . " -> " . json_encode($update));
            $result = $this->db->selectCollection($collection)->updateOne($filter, ['$set' => $update]);
            error_log("[DB] updateOne result: matchedCount=" . $result->getMatchedCount() . ", modifiedCount=" . $result->getModifiedCount());
            return $this->findOne($collection, $filter);
        }
        $data = $this->readCollection($collection);
        foreach ($data as &$doc) {
            if ($this->matchesFilter($doc, $filter)) {
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
            error_log("[DB] deleteOne on {$collection}: " . json_encode($filter));
            $result = $this->db->selectCollection($collection)->deleteOne($filter);
            error_log("[DB] deleteOne result: deletedCount=" . $result->getDeletedCount());
            return true;
        }
        $data = $this->readCollection($collection);
        $newData = [];
        foreach ($data as $doc) {
            if (!$this->matchesFilter($doc, $filter)) $newData[] = $doc;
        }
        $this->writeCollection($collection, $newData);
        return true;
    }

    public function deleteMany($collection, $filter) {
        if ($this->useMongo) {
            $filter = $this->normalizeFilter($filter);
            error_log("[DB] deleteMany on {$collection}: " . json_encode($filter));
            $result = $this->db->selectCollection($collection)->deleteMany($filter);
            error_log("[DB] deleteMany result: deletedCount=" . $result->getDeletedCount());
            return $result;
        }
        $data = $this->readCollection($collection);
        $newData = [];
        foreach ($data as $doc) {
            if (!$this->matchesFilter($doc, $filter)) $newData[] = $doc;
        }
        $this->writeCollection($collection, $newData);
        return ['deletedCount' => count($data) - count($newData)];
    }

    public function count($collection, $filter = []) {
        if ($this->useMongo) {
            $filter = $this->normalizeFilter($filter);
            error_log("[DB] count on {$collection}: " . json_encode($filter));
            $count = $this->db->selectCollection($collection)->countDocuments($filter);
            error_log("[DB] count result: {$count} documents");
            return $count;
        }
        $data = $this->readCollection($collection);
        if (empty($filter)) return count($data);
        $count = 0;
        foreach ($data as $doc) {
            if ($this->matchesFilter($doc, $filter)) $count++;
        }
        return $count;
    }

    // ── NUEVO: Método aggregate para pipelines de agregación ──

    /**
     * Ejecuta un pipeline de agregación en MongoDB.
     * Soporta: $match, $sort, $skip, $limit, $count, $group, $facet.
     * En modo fallback (sin MongoDB), simula el comportamiento básico.
     *
     * @param string $collection Nombre de la colección
     * @param array  $pipeline   Pipeline de agregación
     * @return array
     */
    public function aggregate($collection, array $pipeline) {
        if ($this->useMongo) {
            // Normalizar filtros en $match
            foreach ($pipeline as &$stage) {
                if (isset($stage['$match'])) {
                    $stage['$match'] = $this->normalizeFilter($stage['$match']);
                }
            }
            error_log("[DB] aggregate on {$collection}: " . json_encode($pipeline, JSON_UNESCAPED_UNICODE));
            $cursor = $this->db->selectCollection($collection)->aggregate($pipeline);
            $results = [];
            foreach ($cursor as $doc) {
                $doc = (array)$doc;
                if (isset($doc['_id'])) {
                    $doc['_id'] = (string)$doc['_id'];
                }
                $results[] = $doc;
            }
            error_log("[DB] aggregate result: " . count($results) . " documents");
            return $results;
        }

        // ── Fallback: simulación en archivos JSON ──
        $data = $this->readCollection($collection);
        $results = $data;

        foreach ($pipeline as $stage) {
            if (isset($stage['$match'])) {
                $filter = $stage['$match'];
                $results = array_filter($results, function($doc) use ($filter) {
                    return $this->matchesFilter($doc, $filter);
                });
                $results = array_values($results);
            } elseif (isset($stage['$sort'])) {
                $sort = $stage['$sort'];
                usort($results, function($a, $b) use ($sort) {
                    foreach ($sort as $field => $order) {
                        $valA = $a[$field] ?? null;
                        $valB = $b[$field] ?? null;
                        if ($valA == $valB) continue;
                        $cmp = ($valA < $valB) ? -1 : 1;
                        return ($order === -1 || $order === -1) ? -$cmp : $cmp;
                    }
                    return 0;
                });
            } elseif (isset($stage['$skip'])) {
                $skip = (int)$stage['$skip'];
                $results = array_slice($results, $skip);
            } elseif (isset($stage['$limit'])) {
                $limit = (int)$stage['$limit'];
                $results = array_slice($results, 0, $limit);
            } elseif (isset($stage['$count'])) {
                $field = $stage['$count'];
                $results = [[$field => count($results)]];
            } elseif (isset($stage['$group'])) {
                $group = $stage['$group'];
                $grouped = [];
                foreach ($results as $doc) {
                    $id = $doc[$group['_id']] ?? null;
                    if (!isset($grouped[$id])) {
                        $grouped[$id] = ['_id' => $id];
                    }
                    foreach ($group as $field => $expr) {
                        if ($field === '_id') continue;
                        if (isset($expr['$sum'])) {
                            $grouped[$id][$field] = ($grouped[$id][$field] ?? 0) + (float)($doc[$expr['$sum']] ?? 0);
                        } elseif (isset($expr['$push'])) {
                            $grouped[$id][$field][] = $doc[$expr['$push']] ?? null;
                        } elseif (isset($expr['$cond'])) {
                            // Simplificación básica para $cond (solo para estadísticas)
                            $cond = $expr['$cond'];
                            if (isset($cond['$in']) && isset($cond['$in'][1])) {
                                $value = $doc[$cond['$in'][0]] ?? null;
                                if (in_array($value, $cond['$in'][1], false)) {
                                    $grouped[$id][$field] = ($grouped[$id][$field] ?? 0) + 1;
                                }
                            } else {
                                $grouped[$id][$field] = ($grouped[$id][$field] ?? 0) + 0;
                            }
                        } else {
                            // Asignación directa
                            $grouped[$id][$field] = $doc[$field] ?? null;
                        }
                    }
                }
                $results = array_values($grouped);
            } elseif (isset($stage['$facet'])) {
                $facet = $stage['$facet'];
                $facetResult = [];
                foreach ($facet as $key => $subPipeline) {
                    $subResults = $results;
                    foreach ($subPipeline as $subStage) {
                        if (isset($subStage['$count'])) {
                            $field = $subStage['$count'];
                            $subResults = [[$field => count($subResults)]];
                        } elseif (isset($subStage['$skip'])) {
                            $subResults = array_slice($subResults, (int)$subStage['$skip']);
                        } elseif (isset($subStage['$limit'])) {
                            $subResults = array_slice($subResults, 0, (int)$subStage['$limit']);
                        }
                    }
                    $facetResult[$key] = $subResults;
                }
                $results = [$facetResult];
            }
        }

        return $results;
    }

    // ── Normalización de filtros ──

    private function normalizeFilter($filter) {
        return $this->normalizeFilterRecursive($filter);
    }

    private function normalizeFilterRecursive($filter) {
        if (!is_array($filter)) return $filter;
        $out = [];
        foreach ($filter as $k => $v) {
            // ✅ Solo convertir _id a ObjectId (NO userId, ni otros campos)
            if ($k === '_id' && is_string($v) && preg_match('/^[0-9a-fA-F]{24}$/', $v)) {
                $out[$k] = new MongoDB\BSON\ObjectId($v);
            }
            // ✅ Para $in y $nin: solo convertir si la clave es _id
            elseif (($k === '$in' || $k === '$nin') && is_array($v)) {
                // Detectar si el array contiene IDs para el campo _id
                // Esta lógica es compleja, mejor manejar caso por caso
                $out[$k] = array_map(function($item) {
                    // No convertir automáticamente; dejamos los valores como están
                    return $item;
                }, $v);
            }
            // Si es un array anidado, recursión
            elseif (is_array($v)) {
                $out[$k] = $this->normalizeFilterRecursive($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function generateId() {
        return bin2hex(random_bytes(12));
    }
}