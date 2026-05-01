<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';

/**
 * Enhanced Logger class with audit trail support
 */
class Logger {
    private static $instance = null;
    private $logFile;
    private $logLevel;
    private $db;

    // Log levels
    const DEBUG = 0;
    const INFO = 1;
    const WARNING = 2;
    const ERROR = 3;
    const CRITICAL = 4;

    private $levels = [
        self::DEBUG => 'DEBUG',
        self::INFO => 'INFO',
        self::WARNING => 'WARNING',
        self::ERROR => 'ERROR',
        self::CRITICAL => 'CRITICAL'
    ];

    private function __construct() {
        $this->logFile = Config::get('logging.file');
        $this->logLevel = $this->getLogLevelValue(Config::get('logging.level', 'error'));
        $this->db = Database::getInstance()->getConnection();

        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function getLogLevelValue($level) {
        $levels = [
            'debug' => self::DEBUG,
            'info' => self::INFO,
            'warning' => self::WARNING,
            'error' => self::ERROR,
            'critical' => self::CRITICAL
        ];

        return $levels[strtolower($level)] ?? self::ERROR;
    }

    public function log($level, $message, $context = []) {
        if ($level < $this->logLevel) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $levelName = $this->levels[$level] ?? 'UNKNOWN';
        $userId = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $contextStr = empty($context) ? '' : ' | Context: ' . json_encode($context);
        $logEntry = sprintf(
            "[%s] %s | User: %s | IP: %s | %s%s\n",
            $timestamp,
            $levelName,
            $userId,
            $ip,
            $message,
            $contextStr
        );

        // Write to log file
        if ($this->logFile) {
            file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        }

        // In development, also output to screen for debugging
        if (Config::isDevelopment() && $level >= self::ERROR) {
            error_log($logEntry);
        }
    }

    public function debug($message, $context = []) {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info($message, $context = []) {
        $this->log(self::INFO, $message, $context);
    }

    public function warning($message, $context = []) {
        $this->log(self::WARNING, $message, $context);
    }

    public function error($message, $context = []) {
        $this->log(self::ERROR, $message, $context);
    }

    public function critical($message, $context = []) {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Log database errors
     */
    public function logDatabaseError($operation, $error, $query = '', $params = []) {
        $context = [
            'operation' => $operation,
            'error' => $error,
            'query' => $query,
            'params' => $params
        ];
        $this->error("Database error during $operation", $context);
    }

    /**
     * Log user actions for audit trail (both file and database)
     */
    public function logUserAction($action, $table = '', $recordId = '', $oldValues = null, $newValues = null) {
        if ($action === 'integration_execute' || $action === 'Executed integration action') {
            return;
        }
        $userId = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null;
        if (empty($userId)) {
            return;
        }
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Log to file
        $context = [
            'action' => $action,
            'table' => $table,
            'record_id' => $recordId,
            'old_values' => $oldValues,
            'new_values' => $newValues
        ];
        $this->info("User action: $action", $context);
        // Normalize common field aliases so audit records align with disbursements table
        $aliases = [
            'payment_date' => 'disbursement_date',
            'disb_no' => 'disbursement_number',
            'disb_number' => 'disbursement_number',
            'ref' => 'reference_number',
            'ref_number' => 'reference_number',
            'reference' => 'reference_number'
        ];

        // Ensure arrays for manipulation (handle JSON strings passed in)
        if (is_string($oldValues)) {
            $decoded = json_decode($oldValues, true);
            $oldValues = $decoded === null ? [] : $decoded;
        }
        if (is_string($newValues)) {
            $decoded = json_decode($newValues, true);
            $newValues = $decoded === null ? [] : $decoded;
        }

        if (!is_array($oldValues)) $oldValues = (array)$oldValues;
        if (!is_array($newValues)) $newValues = (array)$newValues;

        foreach ($aliases as $alias => $canonical) {
            if (isset($newValues[$alias]) && !isset($newValues[$canonical])) {
                $newValues[$canonical] = $newValues[$alias];
            }
            if (isset($oldValues[$alias]) && !isset($oldValues[$canonical])) {
                $oldValues[$canonical] = $oldValues[$alias];
            }
        }

        // Log to database audit trail
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $oldValuesJson = $oldValues ? json_encode($oldValues) : null;
            $newValuesJson = $newValues ? json_encode($newValues) : null;

            $stmt->execute([
                $userId,
                $action,
                $table ?: null,
                $recordId ?: null,
                $oldValuesJson,
                $newValuesJson,
                $ipAddress,
                $userAgent
            ]);
        } catch (Exception $e) {
            // If database logging fails, at least we have file logging
            $this->error("Failed to log audit trail to database: " . $e->getMessage());
        }

        if ($this->shouldCaptureDeletedItem($action, $table, $newValues)) {
            $this->captureDeletedItem($table, $recordId, $userId, $oldValues);
        }
    }

    private function shouldCaptureDeletedItem($action, $table, $newValues) {
        $actionValue = strtolower(trim((string)$action));
        $tableValue = strtolower(trim((string)$table));

        if ($tableValue === '' || $tableValue === 'trash' || $tableValue === 'users') {
            return false;
        }

        if (strpos($actionValue, 'delete') === false) {
            return false;
        }

        if (strpos($actionValue, 'restore') !== false || strpos($actionValue, 'permanent') !== false) {
            return false;
        }

        if (!empty($newValues)) {
            return false;
        }

        return true;
    }

    private function captureDeletedItem($table, $recordId, $userId, $oldValues) {
        try {
            $resolvedRecordId = $recordId;
            if ($resolvedRecordId === '' || $resolvedRecordId === null) {
                if (is_array($oldValues) && isset($oldValues['id'])) {
                    $resolvedRecordId = $oldValues['id'];
                } elseif (is_object($oldValues) && isset($oldValues->id)) {
                    $resolvedRecordId = $oldValues->id;
                }
            }

            if ($resolvedRecordId === '' || $resolvedRecordId === null) {
                return;
            }

            $deleteStmt = $this->db->prepare("
                DELETE FROM deleted_items
                WHERE table_name = ? AND record_id = ?
            ");
            $deleteStmt->execute([$table, $resolvedRecordId]);

            $insertStmt = $this->db->prepare("
                INSERT INTO deleted_items (table_name, record_id, deleted_by, deleted_at, auto_delete_at)
                VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))
            ");
            $insertStmt->execute([$table, $resolvedRecordId, $userId]);
        } catch (Exception $e) {
            $this->warning('Failed to write deleted item to trash tracking', [
                'table' => $table,
                'record_id' => $recordId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Log security events
     */
    public function logSecurityEvent($event, $details = []) {
        $context = array_merge(['event' => $event], $details);
        $this->warning("Security event: $event", $context);
    }

    /**
     * Get recent log entries
     */
    public function getRecentLogs($limit = 100) {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $logs = [];
        $lines = array_slice(file($this->logFile), -$limit);

        foreach ($lines as $line) {
            $logs[] = trim($line);
        }

        return array_reverse($logs);
    }

    /**
     * Get audit logs from database
     */
    public function getAuditLogs($filters = [], $limit = 100, $offset = 0) {
        try {
            $where = [];
            $params = [];
            if (empty($filters['include_system'])) {
                $where[] = "al.user_id IS NOT NULL";
            }

            if (!empty($filters['user_id'])) {
                $where[] = "al.user_id = ?";
                $params[] = $filters['user_id'];
            }

            if (!empty($filters['action'])) {
                $where[] = "al.action LIKE ?";
                $params[] = '%' . $filters['action'] . '%';
            }

            if (!empty($filters['table_name'])) {
                $where[] = "al.table_name = ?";
                $params[] = $filters['table_name'];
            }

            if (!empty($filters['date_from'])) {
                $where[] = "al.created_at >= ?";
                $params[] = $filters['date_from'] . ' 00:00:00';
            }

            if (!empty($filters['date_to'])) {
                $where[] = "al.created_at <= ?";
                $params[] = $filters['date_to'] . ' 23:59:59';
            }

            if (!empty($filters['ip_address'])) {
                $where[] = "al.ip_address = ?";
                $params[] = $filters['ip_address'];
            }

            $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

            $stmt = $this->db->prepare("
                SELECT al.*, u.username, u.full_name
                FROM audit_log al
                LEFT JOIN users u ON al.user_id = u.id
                {$whereClause}
                ORDER BY al.created_at DESC
                LIMIT ? OFFSET ?
            ");

            $params[] = $limit;
            $params[] = $offset;

            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $this->error("Failed to retrieve audit logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get audit log statistics
     */
    public function getAuditStats($days = 30) {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as total_logs,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 END) as recent_logs,
                    MAX(created_at) as last_activity
                FROM audit_log
                WHERE user_id IS NOT NULL
            ");
            $stmt->execute([$days]);
            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $this->error("Failed to get audit stats: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get audit logs by user
     */
    public function getUserAuditLogs($userId, $limit = 50) {
        return $this->getAuditLogs(['user_id' => $userId], $limit);
    }

    /**
     * Get audit logs by action type
     */
    public function getActionAuditLogs($action, $limit = 50) {
        return $this->getAuditLogs(['action' => $action], $limit);
    }

    /**
     * Get audit logs by table
     */
    public function getTableAuditLogs($tableName, $limit = 50) {
        return $this->getAuditLogs(['table_name' => $tableName], $limit);
    }

    /**
     * Clean up old audit logs
     */
    public function cleanupAuditLogs($days = 365) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM audit_log
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            $deletedCount = $stmt->rowCount();

            $this->info("Cleaned up {$deletedCount} old audit log entries older than {$days} days");
            return $deletedCount;

        } catch (Exception $e) {
            $this->error("Failed to cleanup audit logs: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear old log entries
     */
    public function cleanup($days = 30) {
        if (!file_exists($this->logFile)) {
            return;
        }

        $cutoff = strtotime("-{$days} days");
        $lines = file($this->logFile);
        $newLines = [];

        foreach ($lines as $line) {
            // Extract timestamp from log line
            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                $timestamp = strtotime($matches[1]);
                if ($timestamp >= $cutoff) {
                    $newLines[] = $line;
                }
            } else {
                $newLines[] = $line; // Keep lines without timestamps
            }
        }

        file_put_contents($this->logFile, implode('', $newLines));
    }
}

// Global logging functions
function log_debug($message, $context = []) {
    Logger::getInstance()->debug($message, $context);
}

function log_info($message, $context = []) {
    Logger::getInstance()->info($message, $context);
}

function log_warning($message, $context = []) {
    Logger::getInstance()->warning($message, $context);
}

function log_error($message, $context = []) {
    Logger::getInstance()->error($message, $context);
}

function log_critical($message, $context = []) {
    Logger::getInstance()->critical($message, $context);
}

// Set up error handler to log PHP errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $message = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    Logger::getInstance()->error($message);
    return false; // Let PHP handle the error as well
});

// Set up exception handler
set_exception_handler(function($exception) {
    $message = "Uncaught Exception: " . $exception->getMessage();
    $context = [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ];
    Logger::getInstance()->critical($message, $context);
});
?>

