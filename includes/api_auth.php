<?php
/**
 * ATIERA External API Authentication
 * Handles API key authentication for external API access
 */

class APIAuth {
    private static $instance = null;
    private $db;

    private function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Authenticate API request using API key
     */
    public function authenticate() {
        $apiKey = $this->getApiKeyFromRequest();

        if (!$apiKey) {
            $this->sendUnauthorizedResponse('API key required');
        }

        $client = $this->validateApiKey($apiKey);

        if (!$client) {
            $this->sendUnauthorizedResponse('Invalid API key');
        }

        // Check if client is active
        if (!$client['is_active']) {
            $this->sendUnauthorizedResponse('API client is inactive');
        }

        // Check rate limits
        if (!$this->checkRateLimit($client['id'])) {
            $this->sendRateLimitResponse();
        }

        // Log API request
        $this->logApiRequest($client['id']);

        return $client;
    }

    /**
     * Get API key from request headers
     */
    private function getApiKeyFromRequest() {
        // Check Authorization header first
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        // Check X-API-Key header
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

        // Optional compatibility mode for legacy clients.
        if (empty($apiKey) && Config::get('api.allow_query_key', false)) {
            $apiKey = $_GET['api_key'] ?? '';
        }

        return $apiKey;
    }

    /**
     * Validate API key against database
     */
    private function validateApiKey($apiKey) {
        try {
            $stmt = $this->db->prepare("
                SELECT ac.*, u.full_name as created_by_name
                FROM api_clients ac
                LEFT JOIN users u ON ac.created_by = u.id
                WHERE ac.api_key = ? AND ac.is_active = 1
            ");
            $stmt->execute([$apiKey]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            Logger::getInstance()->error("API key validation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check rate limits for API client
     */
    private function checkRateLimit($clientId) {
        $maxRequests = Config::get('api.rate_limit', 100);
        $timeWindow = 3600; // 1 hour

        try {
            // Clean old requests
            $stmt = $this->db->prepare("
                DELETE FROM api_requests
                WHERE client_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$clientId, $timeWindow]);

            // Count current requests in time window
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as request_count
                FROM api_requests
                WHERE client_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$clientId, $timeWindow]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result['request_count'] < $maxRequests;
        } catch (Exception $e) {
            Logger::getInstance()->error("Rate limit check failed: " . $e->getMessage());
            return true; // Allow request if rate limit check fails
        }
    }

    /**
     * Log API request
     */
    private function logApiRequest($clientId) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO api_requests (client_id, method, endpoint, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");

            $method = $_SERVER['REQUEST_METHOD'];
            $endpoint = $this->sanitizeEndpoint($_SERVER['REQUEST_URI'] ?? '');
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $stmt->execute([$clientId, $method, $endpoint, $ipAddress, $userAgent]);
        } catch (Exception $e) {
            // Don't fail the request if logging fails
            Logger::getInstance()->error("API request logging failed: " . $e->getMessage());
        }
    }

    private function sanitizeEndpoint($uri) {
        if ($uri === '') {
            return '';
        }

        $sensitiveParams = [
            'api_key',
            'token',
            'signature',
            'client_secret',
            'access_token',
            'refresh_token'
        ];

        $parts = parse_url($uri);
        if ($parts === false) {
            return preg_replace('/([?&])(api_key|token|signature|client_secret|access_token|refresh_token)=[^&]*/i', '$1$2=[REDACTED]', $uri);
        }

        $path = $parts['path'] ?? '';
        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            foreach ($sensitiveParams as $param) {
                if (array_key_exists($param, $params)) {
                    $params[$param] = '[REDACTED]';
                }
            }
            $query = http_build_query($params);
        }

        $sanitized = $path;
        if ($query !== '') {
            $sanitized .= '?' . $query;
        }
        if (!empty($parts['fragment'])) {
            $sanitized .= '#' . $parts['fragment'];
        }

        return $sanitized;
    }

    /**
     * Send unauthorized response
     */
    private function sendUnauthorizedResponse($message = 'Unauthorized') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ]);
        exit;
    }

    /**
     * Send rate limit exceeded response
     */
    private function sendRateLimitResponse() {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Rate limit exceeded',
            'timestamp' => date('c')
        ]);
        exit;
    }

    /**
     * Generate new API key
     */
    public function generateApiKey() {
        return 'ak_' . bin2hex(random_bytes(32));
    }

    /**
     * Create new API client
     */
    public function createApiClient($name, $description, $createdBy) {
        $apiKey = $this->generateApiKey();

        try {
            $stmt = $this->db->prepare("
                INSERT INTO api_clients (name, description, api_key, is_active, created_by, created_at)
                VALUES (?, ?, ?, 1, ?, NOW())
            ");
            $stmt->execute([$name, $description, $apiKey, $createdBy]);

            $clientId = $this->db->lastInsertId();

            Logger::getInstance()->logUserAction(
                'Created API client',
                'api_clients',
                $clientId,
                null,
                ['name' => $name, 'description' => $description]
            );

            return [
                'success' => true,
                'client_id' => $clientId,
                'api_key' => $apiKey
            ];
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to create API client: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to create API client'
            ];
        }
    }

    /**
     * Get API clients
     */
    public function getApiClients() {
        try {
            $stmt = $this->db->query("
                SELECT ac.*, u.full_name as created_by_name,
                       (SELECT COUNT(*) FROM api_requests ar WHERE ar.client_id = ac.id) as total_requests,
                       (SELECT COUNT(*) FROM api_requests ar WHERE ar.client_id = ac.id AND ar.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) as requests_today
                FROM api_clients ac
                LEFT JOIN users u ON ac.created_by = u.id
                ORDER BY ac.created_at DESC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to get API clients: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update API client status
     */
    public function updateApiClientStatus($clientId, $isActive) {
        try {
            $stmt = $this->db->prepare("
                UPDATE api_clients
                SET is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$isActive, $clientId]);

            Logger::getInstance()->logUserAction(
                'Updated API client status',
                'api_clients',
                $clientId,
                null,
                ['is_active' => $isActive]
            );

            return ['success' => true];
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to update API client status: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to update client status'];
        }
    }

    /**
     * Regenerate API key
     */
    public function regenerateApiKey($clientId) {
        $newApiKey = $this->generateApiKey();

        try {
            $stmt = $this->db->prepare("
                UPDATE api_clients
                SET api_key = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newApiKey, $clientId]);

            Logger::getInstance()->logUserAction(
                'Regenerated API key',
                'api_clients',
                $clientId,
                null,
                ['new_api_key' => $newApiKey]
            );

            return [
                'success' => true,
                'api_key' => $newApiKey
            ];
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to regenerate API key: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to regenerate API key'];
        }
    }

    /**
     * Delete API client
     */
    public function deleteApiClient($clientId) {
        try {
            // Get client info for logging
            $stmt = $this->db->prepare("SELECT * FROM api_clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$client) {
                return ['success' => false, 'error' => 'API client not found'];
            }

            // Delete API requests first
            $this->db->prepare("DELETE FROM api_requests WHERE client_id = ?")->execute([$clientId]);

            // Delete client
            $stmt = $this->db->prepare("DELETE FROM api_clients WHERE id = ?");
            $stmt->execute([$clientId]);

            Logger::getInstance()->logUserAction(
                'Deleted API client',
                'api_clients',
                $clientId,
                $client,
                null
            );

            return ['success' => true];
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to delete API client: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to delete API client'];
        }
    }
}
?>

