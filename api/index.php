<?php
// Enable CORS for all origins (you can restrict this in production)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database setup
class JsonStorageAPI
{
    private $db;

    public function __construct()
    {
        try {
            // Use /tmp directory for SQLite database on Vercel
            $this->db = new SQLite3('/tmp/json_storage.db');
            $this->db->exec("CREATE TABLE IF NOT EXISTS json_data (
                id TEXT PRIMARY KEY,
                data TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Exception $e) {
            $this->sendError(500, 'Database connection failed: ' . $e->getMessage());
        }
    }

    public function handleRequest()
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // Parse the request path
        $uri = $_SERVER['REQUEST_URI'];
        // Remove query string if present
        if (strpos($uri, '?') !== false) {
            $uri = substr($uri, 0, strpos($uri, '?'));
        }

        // Remove base path (/api) and split
        $path = str_replace('/api', '', $uri);
        $pathParts = array_filter(explode('/', $path));

        $id = !empty($pathParts) ? array_shift($pathParts) : null;

        switch ($method) {
            case 'GET':
                if ($id) {
                    $this->getRecord($id);
                } else {
                    $this->listRecords();
                }
                break;

            case 'POST':
                $this->createRecord();
                break;

            case 'PUT':
                if ($id) {
                    $this->updateRecord($id);
                } else {
                    $this->sendError(400, 'ID is required for update');
                }
                break;

            case 'DELETE':
                if ($id) {
                    $this->deleteRecord($id);
                } else {
                    $this->sendError(400, 'ID is required for delete');
                }
                break;

            default:
                $this->sendError(405, 'Method not allowed');
        }
    }

    private function getRequestBody()
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true);
    }

    private function sendResponse($data, $code = 200)
    {
        http_response_code($code);
        echo json_encode($data);
        exit();
    }

    private function sendError($code, $message)
    {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit();
    }

    private function generateId()
    {
        return bin2hex(random_bytes(16));
    }

    // GET /api - List all records (without data content)
    private function listRecords()
    {
        try {
            $stmt = $this->db->prepare("SELECT id, created_at, updated_at FROM json_data ORDER BY updated_at DESC");
            $result = $stmt->execute();

            $records = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $records[] = $row;
            }

            $this->sendResponse($records);
        } catch (Exception $e) {
            $this->sendError(500, 'Failed to list records: ' . $e->getMessage());
        }
    }

    // GET /api/{id} - Get specific record
    private function getRecord($id)
    {
        try {
            $stmt = $this->db->prepare("SELECT id, data, created_at, updated_at FROM json_data WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $stmt->execute();
            $record = $result->fetchArray(SQLITE3_ASSOC);

            if (!$record) {
                $this->sendError(404, 'Record not found');
                return;
            }

            // Parse JSON data
            $record['data'] = json_decode($record['data'], true);
            $this->sendResponse($record);
        } catch (Exception $e) {
            $this->sendError(500, 'Failed to retrieve record: ' . $e->getMessage());
        }
    }

    // POST /api - Create new record
    private function createRecord()
    {
        try {
            $requestData = $this->getRequestBody();

            if (!isset($requestData['data'])) {
                $this->sendError(400, 'Data field is required');
                return;
            }

            $id = $this->generateId();
            $jsonData = json_encode($requestData['data']);

            $stmt = $this->db->prepare("INSERT INTO json_data (id, data) VALUES (:id, :data)");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt->bindValue(':data', $jsonData, SQLITE3_TEXT);

            if (!$stmt->execute()) {
                $this->sendError(500, 'Failed to create record');
                return;
            }

            // Return the created record
            $this->getRecord($id);
        } catch (Exception $e) {
            $this->sendError(500, 'Failed to create record: ' . $e->getMessage());
        }
    }

    // PUT /api/{id} - Update existing record
    private function updateRecord($id)
    {
        try {
            $requestData = $this->getRequestBody();

            if (!isset($requestData['data'])) {
                $this->sendError(400, 'Data field is required');
                return;
            }

            // Check if record exists
            $checkStmt = $this->db->prepare("SELECT id FROM json_data WHERE id = :id");
            $checkStmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $checkStmt->execute();

            if (!$result->fetchArray(SQLITE3_ASSOC)) {
                $this->sendError(404, 'Record not found');
                return;
            }

            $jsonData = json_encode($requestData['data']);

            $stmt = $this->db->prepare("UPDATE json_data SET data = :data, updated_at = datetime('now') WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt->bindValue(':data', $jsonData, SQLITE3_TEXT);

            if (!$stmt->execute()) {
                $this->sendError(500, 'Failed to update record');
                return;
            }

            // Return the updated record
            $this->getRecord($id);
        } catch (Exception $e) {
            $this->sendError(500, 'Failed to update record: ' . $e->getMessage());
        }
    }

    // DELETE /api/{id} - Delete record
    private function deleteRecord($id)
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM json_data WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);

            $result = $stmt->execute();

            if ($this->db->changes() === 0) {
                $this->sendError(404, 'Record not found');
                return;
            }

            http_response_code(204);
            exit();
        } catch (Exception $e) {
            $this->sendError(500, 'Failed to delete record: ' . $e->getMessage());
        }
    }
}

// Initialize and run the API
$api = new JsonStorageAPI();
$api->handleRequest();
