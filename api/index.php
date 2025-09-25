<?php

use Darkterminal\TursoHttp\LibSQL;

require __DIR__ . '/../vendor/autoload.php';

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
            $dbname = getenv('DB_URL');
            $authToken = getenv('DB_AUTH_TOKEN');

            $this->db = new LibSQL("dbname=$dbname&authToken=$authToken");
            $this->db->execute("CREATE TABLE IF NOT EXISTS json_data (
                id TEXT PRIMARY KEY,
                data TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Exception $e) {
            $this->sendError(500, 'Database connection failed: ' . $e->getMessage());
        }
    }

    private function isProduction(): bool
    {
        return getenv('APP_ENV') === 'production' && $_SERVER['HTTP_USER_AGENT'] === 'GitHub Actions';
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
                if ($this->isProduction()) {
                    $this->sendError(403, 'POST method is disabled in production');
                }

                $this->createRecord();
                break;

            case 'PUT':
                if ($this->isProduction()) {
                    $this->sendError(403, 'PUT method is disabled in production');
                }

                if ($id) {
                    $this->updateRecord($id);
                } else {
                    $this->sendError(400, 'ID is required for update');
                }
                break;

            case 'DELETE':
                if ($this->isProduction()) {
                    $this->sendError(403, 'DELETE method is disabled in production');
                }

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
            $result = $this->db->query("SELECT id, created_at, updated_at FROM json_data ORDER BY updated_at DESC");
            $rows = $result->fetchArray(LibSQL::LIBSQL_ASSOC);

            if (!$rows) {
                $this->sendError(404, 'No records found');
                return;
            }

            $data = array_map(fn($row) => [
                'id' => $row['id'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ], $rows);

            $this->sendResponse($data);
        } catch (Exception $e) {
            $this->sendError(500, 'Failed to list records: ' . $e->getMessage());
        }
    }

    // GET /api/{id} - Get specific record
    private function getRecord($id)
    {
        try {
            $query = $this->db->query("SELECT id, data, created_at, updated_at FROM json_data WHERE id = ?", [$id]);
            $record = $query->fetchArray(LibSQL::LIBSQL_ASSOC);

            if (!$record) {
                $this->sendError(404, 'Record not found');
                return;
            }

            // Parse JSON data
            $result = array_shift($record);
            $response['data'] = json_decode($result['data'], true);
            $this->sendResponse($response);
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

            $stmt = $this->db->execute("INSERT INTO json_data (id, data) VALUES (?, ?)", [$id, $jsonData]);

            if (!$stmt) {
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
            $checkStmt = $this->db->query("SELECT id FROM json_data WHERE id = ?", [$id]);
            if (!$checkStmt->fetchArray(LibSQL::LIBSQL_ASSOC)) {
                $this->sendError(404, 'Record not found');
                return;
            }

            $jsonData = $requestData['data'];

            $stmt = $this->db->execute("UPDATE json_data SET data = ?, updated_at = datetime('now') WHERE id = ?", [$jsonData, $id]);

            if (!$stmt) {
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
            $this->db->execute("DELETE FROM json_data WHERE id = ?", [$id]);

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
