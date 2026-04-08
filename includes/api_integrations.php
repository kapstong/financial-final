<?php
/**
 * ATIERA Financial Management System - External API Integrations
 * Framework for integrating with third-party services and APIs
 */

// Required includes
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/database.php';

class APIIntegrationManager {
    private static $instance = null;
    private $db;
    private $integrations = [];
    private $configDir;

    private function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->configDir = __DIR__ . '/../config/integrations';

        // Ensure config directory exists
        if (!is_dir($this->configDir)) {
            mkdir($this->configDir, 0755, true);
        }

        $this->loadIntegrations();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load available integrations
     */
    private function loadIntegrations() {
        $this->integrations = [
            'hr3' => new HR3Integration(),
            'hr4' => new HR4Integration(),
            'core1' => new Core1HotelPaymentsIntegration(),
            'logistics1' => new Logistics1Integration(),
            'logistics2' => new Logistics2Integration()
        ];
    }

    /**
     * Get integration instance
     */
    public function getIntegration($name) {
        return isset($this->integrations[$name]) ? $this->integrations[$name] : null;
    }

    /**
     * Get all integrations
     */
    public function getAllIntegrations() {
        return $this->integrations;
    }

    /**
     * Configure an integration
     */
    public function configureIntegration($name, $config) {
        if (!isset($this->integrations[$name])) {
            return ['success' => false, 'error' => 'Integration not found'];
        }

        $integration = $this->integrations[$name];

        // Validate configuration
        $validation = $integration->validateConfig($config);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['errors']];
        }

        // Save configuration
        $configFile = $this->configDir . '/' . $name . '.json';
        $encryptedConfig = $this->encryptConfig($config);

        if (file_put_contents($configFile, json_encode($encryptedConfig, JSON_PRETTY_PRINT))) {
            // Update integration status
            $this->updateIntegrationStatus($name, true);

            Logger::getInstance()->logUserAction(
                'Configured integration',
                'api_integrations',
                null,
                null,
                ['integration' => $name]
            );

            return ['success' => true, 'message' => 'Integration configured successfully'];
        }

        return ['success' => false, 'error' => 'Failed to save configuration'];
    }

    /**
     * Get integration configuration
     */
    public function getIntegrationConfig($name) {
        $configFile = $this->configDir . '/' . $name . '.json';

        if (file_exists($configFile)) {
            $encryptedConfig = json_decode(file_get_contents($configFile), true);
            return $this->decryptConfig($encryptedConfig);
        }

        return null;
    }

    /**
     * Test integration connection
     */
    public function testIntegration($name) {
        $integration = $this->getIntegration($name);
        if (!$integration) {
            return ['success' => false, 'error' => 'Integration not found'];
        }

        $config = $this->getIntegrationConfig($name);
        if (!$config) {
            return ['success' => false, 'error' => 'Integration not configured'];
        }

        try {
            $result = $integration->testConnection($config);
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute integration action
     */
    public function executeIntegrationAction($name, $action, $params = []) {
        $integration = $this->getIntegration($name);
        if (!$integration) {
            throw new Exception('Integration not found');
        }

        $config = $this->getIntegrationConfig($name);
        if (!$config) {
            throw new Exception('Integration not configured');
        }

        if (!method_exists($integration, $action)) {
            throw new Exception('Action not supported by integration');
        }

        try {
            $result = $integration->$action($config, $params);

            return $result;
        } catch (Exception $e) {
            Logger::getInstance()->error("Integration action failed: $name->$action", [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            throw $e;
        }
    }

    /**
     * Handle webhook from external service
     */
    public function handleWebhook($integrationName, $payload) {
        $integration = $this->getIntegration($integrationName);
        if (!$integration) {
            throw new Exception('Integration not found');
        }

        $config = $this->getIntegrationConfig($integrationName);
        if (!$config) {
            throw new Exception('Integration not configured');
        }

        try {
            $result = $integration->handleWebhook($config, $payload);

            // Log webhook
            Logger::getInstance()->logUserAction(
                'Processed webhook',
                'api_integrations',
                null,
                null,
                ['integration' => $integrationName, 'payload' => $payload]
            );

            return $result;
        } catch (Exception $e) {
            Logger::getInstance()->error("Webhook processing failed: $integrationName", [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw $e;
        }
    }

    /**
     * Get integration status
     */
    public function getIntegrationStatus($name) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM api_integrations WHERE name = ?");
            $stmt->execute([$name]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Update integration status
     */
    public function updateIntegrationStatus($name, $active) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO api_integrations (name, is_active, last_updated)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE is_active = ?, last_updated = NOW()
            ");
            $stmt->execute([$name, $active, $active]);
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to update integration status: $name");
        }
    }

    /**
     * Encrypt sensitive configuration data
     */
    private function encryptConfig($config) {
        $encKey = Config::get('app.key', 'default_key_change_in_production');
        // Handle base64-encoded keys (Laravel convention)
        if (strpos($encKey, 'base64:') === 0) {
            $encKey = base64_decode(substr($encKey, 7));
        }
        $encrypted = [];

        foreach ($config as $configKey => $value) {
            if (is_string($value) && strlen($value) > 0) {
                $encrypted[$configKey] = openssl_encrypt($value, 'AES-256-CBC', $encKey, 0, substr($encKey, 0, 16));
            } else {
                $encrypted[$configKey] = $value;
            }
        }

        return $encrypted;
    }

    /**
     * Decrypt configuration data
     */
    private function decryptConfig($encryptedConfig) {
        $encKey = Config::get('app.key', 'default_key_change_in_production');
        // Handle base64-encoded keys (Laravel convention)
        if (strpos($encKey, 'base64:') === 0) {
            $encKey = base64_decode(substr($encKey, 7));
        }
        $config = [];

        foreach ($encryptedConfig as $configKey => $value) {
            if (is_string($value) && strlen($value) > 0) {
                $decrypted = openssl_decrypt($value, 'AES-256-CBC', $encKey, 0, substr($encKey, 0, 16));
                $config[$configKey] = $decrypted ?: $value; // Fallback to encrypted if decryption fails
            } else {
                $config[$configKey] = $value;
            }
        }

        return $config;
    }

    /**
     * Get integration statistics
     */
    public function getIntegrationStats() {
        try {
            $stmt = $this->db->query("
                SELECT
                    COUNT(*) as total_integrations,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_integrations,
                    COUNT(CASE WHEN last_updated >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 END) as recently_used
                FROM api_integrations
            ");
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['total_integrations' => 0, 'active_integrations' => 0, 'recently_used' => 0];
        }
    }

}

/**
 * Base Integration Class
 */
abstract class BaseIntegration {
    protected $name;
    protected $displayName;
    protected $description;
    protected $requiredConfig = [];
    protected $webhookSupport = false;

    protected function buildResponsePreview($response, $maxLength = 220) {
        $preview = trim(strip_tags((string) $response));
        $preview = preg_replace('/\s+/', ' ', $preview);

        if ($preview === '') {
            return '';
        }

        if (strlen($preview) > $maxLength) {
            return substr($preview, 0, $maxLength - 3) . '...';
        }

        return $preview;
    }

    protected function buildInvalidResponseMessage($systemLabel, $response) {
        $preview = $this->buildResponsePreview($response);
        if ($preview === '') {
            return "Invalid response from {$systemLabel}";
        }

        return "Invalid response from {$systemLabel}: {$preview}";
    }

    /**
     * Validate configuration
     */
    public function validateConfig($config) {
        $errors = [];

        foreach ($this->requiredConfig as $field) {
            if (empty($config[$field])) {
                $errors[] = "Required field '$field' is missing";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Create journal entry for API transaction (Double-Entry Bookkeeping)
     * This ensures ALL API transactions are recorded in the accounting books
     */
    protected function createJournalEntry($params) {
        try {
            $db = Database::getInstance()->getConnection();

            // Required parameters
            $date = $params['date'] ?? date('Y-m-d');
            $description = $params['description'] ?? 'API Transaction';
            $debitAccountId = $params['debit_account_id'] ?? null;
            $creditAccountId = $params['credit_account_id'] ?? null;
            $amount = floatval($params['amount'] ?? 0);
            $referenceNumber = $params['reference_number'] ?? 'API_' . time();
            $createdBy = $params['created_by'] ?? 1;
            $postedBy = $params['posted_by'] ?? $createdBy;

            if (!$debitAccountId || !$creditAccountId || $amount <= 0) {
                throw new Exception('Invalid journal entry parameters');
            }

            require_once __DIR__ . '/journal_entry_number.php';
            $entryNumber = generateJournalEntryNumber($db, $debitAccountId, $date);

            // Create journal entry header
            $stmt = $db->prepare("
                INSERT INTO journal_entries
                (entry_number, entry_date, description, reference, total_debit, total_credit, status, created_by, posted_by, posted_at)
                VALUES (?, ?, ?, ?, ?, ?, 'posted', ?, ?, NOW())
            ");
            $stmt->execute([$entryNumber, $date, $description, $referenceNumber, $amount, $amount, $createdBy, $postedBy]);
            $journalEntryId = $db->lastInsertId();

            // Create debit line
            $debitStmt = $db->prepare("
                INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit, credit, description)
                VALUES (?, ?, ?, 0, ?)
            ");
            $debitStmt->execute([$journalEntryId, $debitAccountId, $amount, $description]);

            // Create credit line
            $creditStmt = $db->prepare("
                INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit, credit, description)
                VALUES (?, ?, 0, ?, ?)
            ");
            $creditStmt->execute([$journalEntryId, $creditAccountId, $amount, $description]);

            Logger::getInstance()->info('Journal entry created for API transaction', [
                'journal_entry_id' => $journalEntryId,
                'amount' => $amount,
                'reference' => $referenceNumber
            ]);

            return [
                'success' => true,
                'journal_entry_id' => $journalEntryId
            ];

        } catch (Exception $e) {
            Logger::getInstance()->error('Failed to create journal entry for API transaction', [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            throw $e;
        }
    }

    /**
     * Get chart of accounts ID by account code or name
     */
    protected function getAccountId($accountCodeOrName) {
        try {
            $db = Database::getInstance()->getConnection();

            // Try by account code first
            $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$accountCodeOrName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $result['id'];
            }

            // Try by account name
            $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE account_name LIKE ? AND is_active = 1 LIMIT 1");
            $stmt->execute(['%' . $accountCodeOrName . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $result['id'];
            }

            return null;

        } catch (Exception $e) {
            Logger::getInstance()->error('Failed to get account ID', [
                'account' => $accountCodeOrName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Test connection to the service
     */
    abstract public function testConnection($config);

    /**
     * Handle webhook if supported
     */
    public function handleWebhook($config, $payload) {
        if (!$this->webhookSupport) {
            throw new Exception('Webhooks not supported by this integration');
        }
        return ['success' => true, 'message' => 'Webhook processed'];
    }

    /**
     * Get integration metadata
     */
    public function getMetadata() {
        return [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'description' => $this->description,
            'required_config' => $this->requiredConfig,
            'webhook_support' => $this->webhookSupport
        ];
    }
}

/**
 * HR3 Integration - Employee Claims/Expenses System
 */
class HR3Integration extends BaseIntegration {
    protected $name = 'hr3';
    protected $displayName = 'HR3 Employee Claims System';
    protected $description = 'Process employee expense claims and reimbursements from HR3 system';
    protected $requiredConfig = ['api_url'];

    private function buildRequestHeaders($config, array $headers) {
        if (!empty($config['api_key'])) {
            $headers[] = 'Authorization: Bearer ' . $config['api_key'];
        }

        return $headers;
    }

    private function fetchClaimsPayload($config, $params = []) {
        $apiUrl = trim((string) ($config['api_url'] ?? ''));
        if ($apiUrl === '') {
            throw new Exception('HR3 API URL is not configured');
        }

        if (!empty($params)) {
            $query = http_build_query($params);
            if ($query !== '') {
                $separator = strpos($apiUrl, '?') === false ? '?' : '&';
                $apiUrl .= $separator . $query;
            }
        }

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->buildRequestHeaders($config, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json,text/plain,*/*'
        ]));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new Exception('Connection error: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new Exception('HR3 API returned HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid HR3 API response');
        }

        $claims = isset($data['claims']) ? $data['claims'] : (is_array($data) ? $data : []);
        if (!is_array($claims)) {
            throw new Exception('Invalid HR3 API response: claims is not an array');
        }

        return array_values($claims);
    }

    private function normalizeClaimStatus($status) {
        $normalized = strtolower(trim((string) $status));

        if (in_array($normalized, ['approved', 'paid', 'processed', 'completed'], true)) {
            return 'approved';
        }

        if (in_array($normalized, ['submitted', 'pending', 'initiated', 'for approval', 'for_approval'], true)) {
            return 'pending';
        }

        if (in_array($normalized, ['rejected', 'cancelled', 'canceled', 'declined'], true)) {
            return 'rejected';
        }

        return $normalized === '' ? 'pending' : $normalized;
    }

    private function resolveClaimDepartment($claim, $params = []) {
        $department = trim((string) (
            $claim['department']
            ?? $claim['department_name']
            ?? $claim['department_code']
            ?? $params['department_code']
            ?? 'General'
        ));

        return $department === '' ? 'General' : $department;
    }

    public function testConnection($config) {
        try {
            $claimCount = count($this->fetchClaimsPayload($config));
            return [
                'success' => true,
                'message' => "HR3 connection successful. Found {$claimCount} employee claims"
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get approved claims from HR3 API
     */
    public function getApprovedClaims($config, $params = []) {
        try {
            $claims = $this->fetchClaimsPayload($config, $params);
            $approvedClaims = array_filter($claims, function($claim) {
                // If no status field, include the claim
                if (!isset($claim['status'])) {
                    return true;
                }
                // If status is set, only include approved claims
                return strtolower($claim['status']) === 'approved';
            });

            Logger::getInstance()->info('HR3 getApprovedClaims retrieved ' . count($approvedClaims) . ' claims from ' . count($claims) . ' total');
            return array_values($approvedClaims); // Re-index array
        } catch (Exception $e) {
            Logger::getInstance()->error('HR3 getApprovedClaims failed: ' . $e->getMessage());
            throw $e; // Re-throw to let the integration API handle it
        }
    }

    /**
     * Import claims to disbursements system
     */
    public function importClaimsToDisbursements($config, $params = []) {
        try {
            $approvedClaims = $this->getApprovedClaims($config, $params);

            $db = Database::getInstance()->getConnection();
            $importedCount = 0;
            $errors = [];
            $claimsExpenseAccount = $this->getAccountId('5300'); // Employee Claims/Reimbursements Expense (preferred)
            if (!$claimsExpenseAccount) {
                $candidateNames = [
                    'Employee Claims',
                    'Employee Reimbursements',
                    'Claims',
                    'Reimbursements'
                ];
                foreach ($candidateNames as $name) {
                    $claimsExpenseAccount = $this->getAccountId($name);
                    if ($claimsExpenseAccount) {
                        break;
                    }
                }
            }
            if (!$claimsExpenseAccount) {
                $claimsExpenseAccount = $this->getAccountId('5403'); // Office Supplies (fallback)
            }
            if (!$claimsExpenseAccount) {
                $claimsExpenseAccount = $this->getAccountId('6000'); // Salaries Expense (last-resort)
            }

            foreach ($approvedClaims as $claim) {
                try {
                    $claimId = $claim['claim_id'] ?? $claim['id'] ?? 'HR3_' . time() . '_' . $importedCount;
                    $employeeId = $claim['employee_id'] ?? '';
                    $employeeName = $claim['employee_name'] ?? 'Unknown Employee';
                    $amount = floatval($claim['amount'] ?? $claim['total_amount'] ?? 0);
                    $description = $claim['description'] ?? $claim['purpose'] ?? 'Employee Claim';
                    $claimDate = isset($claim['date_submitted']) ? date('Y-m-d', strtotime($claim['date_submitted'])) : date('Y-m-d');

                    if ($amount <= 0) continue;

                    // Map department if available
                    $departmentId = $this->mapDepartment($claim['department'] ?? '');

                    $budgetCheck = $this->checkBudgetAvailability($claimsExpenseAccount, $amount);
                    $budgetExceeded = $budgetCheck['exceeded'];
                    $status = $budgetExceeded ? 'pending' : 'approved';

                    // Check if claim already exists
                    $checkStmt = $db->prepare("SELECT id FROM disbursements WHERE external_reference = ?");
                    $checkStmt->execute([$claimId]);
                    if ($checkStmt->fetch()) {
                        continue; // Skip existing claims
                    }

                    // Insert claim as disbursement
                    $stmt = $db->prepare("
                        INSERT INTO disbursements
                        (disbursement_type, payee_name, amount, description, status,
                         department_id, external_reference, external_system, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'HR3', NOW())
                    ");

                    $stmt->execute([
                        'employee_claim',
                        $employeeName,
                        $amount,
                        $description,
                        $status,
                        $departmentId,
                        $claimId
                    ]);

                    if ($budgetExceeded) {
                        $this->triggerBudgetAlertWorkflow([
                            'source' => 'HR3_CLAIMS',
                            'claim_id' => $claimId,
                            'employee_name' => $employeeName,
                            'department_id' => $departmentId,
                            'department' => $claim['department'] ?? '',
                            'amount' => $amount,
                            'budget_remaining' => $budgetCheck['remaining'],
                            'budgeted_amount' => $budgetCheck['budgeted_amount'],
                            'actual_amount' => $budgetCheck['actual_amount'],
                            'budget_exceeded' => true
                        ]);

                        Logger::getInstance()->warning('HR3 claim pending due to budget limit', [
                            'claim_id' => $claimId,
                            'amount' => $amount,
                            'budget_remaining' => $budgetCheck['remaining']
                        ]);
                    } else {
                        // CREATE JOURNAL ENTRY for proper double-entry bookkeeping
                        // Debit: Employee Claims Expense, Credit: Accounts Payable
                        $accountsPayableAccount = $this->getAccountId('2001'); // Accounts Payable

                        if ($claimsExpenseAccount && $accountsPayableAccount) {
                            $this->createJournalEntry([
                                'date' => $claimDate,
                                'description' => $description,
                                'debit_account_id' => $claimsExpenseAccount,
                                'credit_account_id' => $accountsPayableAccount,
                                'amount' => $amount,
                                'reference_number' => $claimId,
                                'source_system' => 'HR3_CLAIMS'
                            ]);
                        }
                    }

                    $importedCount++;

                    Logger::getInstance()->info('HR3 claim imported', [
                        'claim_id' => $claimId,
                        'employee' => $employeeName,
                        'amount' => $amount,
                        'status' => $status
                    ]);

                } catch (Exception $e) {
                    $errors[] = 'Failed to import claim ' . ($claim['claim_id'] ?? 'Unknown') . ': ' . $e->getMessage();
                    Logger::getInstance()->error('HR3 claim import error', [
                        'claim' => $claim,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return [
                'success' => count($errors) === 0,
                'imported_count' => $importedCount,
                'errors' => $errors,
                'message' => "Imported {$importedCount} approved claims to disbursements system"
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update claim status back to HR3 (bidirectional sync)
     */
    public function updateClaimStatus($config, $params = []) {
        if (!isset($params['claim_id']) || !isset($params['status'])) {
            throw new Exception('Missing required parameters: claim_id and status');
        }

        try {
            $claimId = $params['claim_id'];
            $newStatus = $params['status'];

            // HR3 API expects a PUT request with form-encoded data
            $updateData = http_build_query([
                'claim_id' => $claimId,
                'status' => $newStatus,
                'paid_by' => 'ATIERA_FINANCE_SYSTEM'
            ]);

            $headers = ['Content-Type: application/x-www-form-urlencoded'];
            if (!empty($config['api_key'])) {
                $headers[] = 'Authorization: Bearer ' . $config['api_key'];
            }

            $ch = curl_init($config['api_url']);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $updateData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);
            if ($httpCode === 200 && is_array($result)) {
                if ((isset($result['status']) && $result['status'] === 'success') ||
                    (isset($result['success']) && $result['success'])) {
                    return [
                        'success' => true,
                        'message' => 'Claim status updated successfully in HR3',
                        'claim_id' => $claimId,
                        'new_status' => $newStatus
                    ];
                }
            }

            return [
                'success' => false,
                'error' => is_array($result) ? ($result['error'] ?? $result['message'] ?? 'Failed to update claim status in HR3 API')
                    : 'Failed to update claim status in HR3 API',
                'http_code' => $httpCode
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get claims summary with breakdown structure
     */
    public function getClaimsBreakdown($config, $params = []) {
        try {
            $claims = $this->fetchClaimsPayload($config, $params);

            // Create breakdown by department and status
            $breakdown = [
                'summary' => [
                    'total_claims' => 0,
                    'total_amount' => 0,
                    'department_breakdown' => [],
                    'status_breakdown' => [
                        'approved' => 0,
                        'pending' => 0,
                        'rejected' => 0
                    ]
                ],
                'claims' => []
            ];

            foreach ($claims as $claim) {
                $amount = floatval($claim['amount'] ?? $claim['total_amount'] ?? 0);
                $status = $this->normalizeClaimStatus($claim['status'] ?? 'pending');

                if ($amount <= 0) {
                    continue;
                }

                if (isset($breakdown['summary']['status_breakdown'][$status])) {
                    $breakdown['summary']['status_breakdown'][$status]++;
                }

                if ($status === 'rejected') {
                    continue;
                }

                $department = $this->resolveClaimDepartment($claim, $params);

                $breakdown['summary']['total_claims']++;
                $breakdown['summary']['total_amount'] += $amount;

                // Department breakdown
                if (!isset($breakdown['summary']['department_breakdown'][$department])) {
                    $breakdown['summary']['department_breakdown'][$department] = [
                        'claim_count' => 0,
                        'total_amount' => 0
                    ];
                }
                $breakdown['summary']['department_breakdown'][$department]['claim_count']++;
                $breakdown['summary']['department_breakdown'][$department]['total_amount'] += $amount;

                // Add to claims list
                $breakdown['claims'][] = [
                    'id' => $claim['claim_id'] ?? $claim['id'],
                    'employee_name' => $claim['employee_name'] ?? 'Unknown',
                    'department' => $department,
                    'amount' => $amount,
                    'description' => $claim['description'] ?? $claim['purpose'] ?? '',
                    'date_submitted' => $claim['date_submitted'] ?? $claim['created_at'] ?? '',
                    'status' => $status
                ];
            }

            return [
                'success' => true,
                'data' => $breakdown
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Check if a claim amount fits within the active budget for an account
     */
    private function checkBudgetAvailability($accountId, $amount) {
        $default = [
            'exceeded' => false,
            'remaining' => null,
            'budgeted_amount' => null,
            'actual_amount' => null
        ];

        if (!$accountId || $amount <= 0) {
            return $default;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT bi.budgeted_amount, bi.actual_amount
                FROM budget_items bi
                INNER JOIN budgets b ON bi.budget_id = b.id
                WHERE bi.account_id = ? AND b.status = 'active' AND YEAR(b.budget_year) = YEAR(CURDATE())
                LIMIT 1
            ");
            $stmt->execute([$accountId]);
            $budget = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$budget) {
                return $default;
            }

            $remaining = floatval($budget['budgeted_amount']) - floatval($budget['actual_amount']);

            return [
                'exceeded' => $amount > $remaining,
                'remaining' => $remaining,
                'budgeted_amount' => floatval($budget['budgeted_amount']),
                'actual_amount' => floatval($budget['actual_amount'])
            ];
        } catch (Exception $e) {
            Logger::getInstance()->error('Budget availability check failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            return $default;
        }
    }

    /**
     * Trigger workflow alert when budget is exceeded
     */
    private function triggerBudgetAlertWorkflow($data) {
        try {
            if (!class_exists('WorkflowEngine')) {
                require_once __DIR__ . '/workflow.php';
            }

            $workflowEngine = WorkflowEngine::getInstance();
            $workflowEngine->triggerWorkflow('transaction.posted', $data);
        } catch (Exception $e) {
            Logger::getInstance()->error('Failed to trigger budget alert workflow', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    /**
     * Map department name to department ID
     */
    private function mapDepartment($departmentName = '') {
        $mapping = [
            'kitchen' => 2,
            'food' => 2,
            'beverage' => 2,
            'front office' => 3,
            'front' => 3,
            'desk' => 3,
            'reception' => 3,
            'administration' => 1,
            'admin' => 1,
            'office' => 1,
            'hr' => 1,
            'human resources' => 1,
            'housekeeping' => 4,
            'maintenance' => 5,
            'security' => 6,
            'sales' => 7,
            'marketing' => 7,
            'events' => 8
        ];

        $lowerName = strtolower($departmentName);
        foreach ($mapping as $keyword => $deptId) {
            if (strpos($lowerName, $keyword) !== false) {
                return $deptId;
            }
        }

        return 1; // Default to Administrative
    }
}

/**
 * HR4 Payroll Integration
 */
class HR4Integration extends BaseIntegration {
    protected $name = 'hr4';
    protected $displayName = 'HR4 Payroll System';
    protected $description = 'Employee payroll and salary processing from HR4 system';
    protected $requiredConfig = ['api_url'];

    /**
     * Override validateConfig to handle optional authentication fields
     */
    public function validateConfig($config) {
        $errors = [];

        // Check required config
        if (empty($config['api_url'])) {
            $errors[] = "Required field 'api_url' is missing";
        }

        // api_key and api_secret are optional for now
        // They can be added later when authentication is implemented

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function testConnection($config) {
        try {
            $ch = curl_init($config['api_url']);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->generateAuthToken($config),
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return ['success' => false, 'error' => 'HR4 API returned HTTP ' . $httpCode];
            }

            $result = json_decode($response, true);
            if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
                return ['success' => false, 'error' => 'HR4 API returned invalid JSON'];
            }

            if ((isset($result['status']) && strtolower($result['status']) === 'success') ||
                (isset($result['success']) && $result['success'])) {
                return ['success' => true, 'message' => 'HR4 API connection successful'];
            }

            $errorMessage = $result['error'] ?? $result['message'] ?? 'HR4 API returned unsuccessful response';
            return ['success' => false, 'error' => $errorMessage];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get payroll data from HR4
     */
    public function getPayrollData($config, $params = []) {
        try {
            // Make the actual API call to the HR4 endpoint
            $url = $config['api_url'];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing - remove in production
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // For testing - remove in production

            // Add headers if authentication is configured
            $headers = ['Content-Type: application/json'];
            if (!empty($config['api_key'])) {
                $headers[] = 'Authorization: Bearer ' . $this->generateAuthToken($config);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception('cURL Error: ' . $curlError);
            }

            if ($httpCode === 200) {
                // Try to decode JSON response
                $result = json_decode($response, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('HR4 API returned invalid JSON response');
                }

                // Parse the actual HR4 API response format and convert to our expected format
                if (isset($result['status']) && strtolower($result['status']) === 'success' && isset($result['data'])) {
                    return $this->parseHR4ApprovalData($result);
                }

                if (isset($result['success']) && $result['success'] && isset($result['payroll_data'])) {
                    return $this->parseHR4PayrollData($result);
                }

                throw new Exception('HR4 API response is not in expected format');
            } else {
                $errorMessage = 'HR4 API returned HTTP status code: ' . $httpCode;
                $decoded = json_decode($response, true);
                if (is_array($decoded)) {
                    $apiMessage = $decoded['message'] ?? $decoded['error'] ?? '';
                    if ($apiMessage !== '') {
                        $errorMessage = 'HR4 API error: ' . $apiMessage . ' (HTTP ' . $httpCode . ')';
                    }
                }
                throw new Exception($errorMessage);
            }
        } catch (Exception $e) {
            Logger::getInstance()->error('HR4 getPayrollData failed: ' . $e->getMessage());
            return []; // Return empty array instead of throwing to prevent 500 errors
        }
    }

    /**
     * Get incentive data from HR4
     */
    public function getIncentiveData($config, $params = []) {
        try {
            $url = $config['incentives_api_url'] ?? $config['incentive_api_url'] ?? $config['api_url'] ?? '';
            if ($url === '') {
                throw new Exception('HR4 incentives API URL is not configured');
            }

            $params['_ts'] = $params['_ts'] ?? time();
            $query = http_build_query($params);
            if ($query !== '') {
                $separator = strpos($url, '?') === false ? '?' : '&';
                $url .= $separator . $query;
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $headers = ['Content-Type: application/json'];
            if (!empty($config['api_key'])) {
                $headers[] = 'Authorization: Bearer ' . $this->generateAuthToken($config);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception('cURL Error: ' . $curlError);
            }

            if ($httpCode !== 200) {
                throw new Exception('HR4 incentives API returned HTTP status code: ' . $httpCode);
            }

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('HR4 incentives API returned invalid JSON response');
            }

            if (isset($result['status']) && strtolower($result['status']) === 'success' && isset($result['data'])) {
                return $this->parseHR4IncentivesData($result);
            }

            throw new Exception('HR4 incentives API response is not in expected format');
        } catch (Exception $e) {
            Logger::getInstance()->error('HR4 getIncentiveData failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Parse HR4 payroll approval data format into our expected payroll data format
     */
    private function parseHR4ApprovalData($apiResponse) {
        if (!isset($apiResponse['data']) || !is_array($apiResponse['data'])) {
            throw new Exception('HR4 approval API response missing data array');
        }

        $parsedData = [];

        foreach ($apiResponse['data'] as $entry) {
            $payrollId = $entry['approval_id'] ?? $entry['payroll_id'] ?? $entry['payrollId'] ?? $entry['id'] ?? null;
            $totalAmount = floatval($entry['total_amount'] ?? 0);
            $rawStatus = $entry['status'] ?? '';
            $normalizedStatus = strtolower(trim((string)$rawStatus));
            $displayStatus = $entry['display_status'] ?? '';
            if ($normalizedStatus !== '' && in_array($normalizedStatus, ['approved', 'rejected'], true)) {
                $displayStatus = ucfirst($normalizedStatus);
            } elseif ($displayStatus === '' && $normalizedStatus !== '') {
                $displayStatus = ucfirst($normalizedStatus);
            }
            $canApproveStatuses = ['processed', 'success', 'pending', 'pending approval', 'for approval'];

            $parsedData[] = [
                'payroll_id' => $payrollId,
                'approval_id' => $entry['approval_id'] ?? null,
                'payroll_period' => $entry['payroll_period'] ?? '',
                'period_display' => $entry['period_display'] ?? '',
                'total_amount' => $totalAmount,
                'employee_count' => $entry['employee_count'] ?? null,
                'submitted_by' => $entry['submitted_by'] ?? '',
                'submitted_at' => $entry['submitted_at'] ?? '',
                'finance_approver' => $entry['finance_approver'] ?? '',
                'approved_at' => $entry['approved_at'] ?? '',
                'status' => $rawStatus,
                'display_status' => $displayStatus,
                'notes' => $entry['notes'] ?? '',
                'rejection_reason' => $entry['rejection_reason'] ?? '',
                'can_approve' => in_array($normalizedStatus, $canApproveStatuses, true)
                    || in_array(strtolower((string)$displayStatus), $canApproveStatuses, true),
                'employee_name' => 'Payroll Batch',
                'department' => 'HR4 Payroll',
                'position' => 'Batch',
                'basic_salary' => 0,
                'allowances' => 0,
                'deductions' => 0,
                'net_pay' => $totalAmount,
                'amount' => $totalAmount
            ];
        }

        return $parsedData;
    }

    /**
     * Parse HR4 incentives data format into our expected incentives data format
     */
    private function parseHR4IncentivesData($apiResponse) {
        if (!isset($apiResponse['data']) || !is_array($apiResponse['data'])) {
            throw new Exception('HR4 incentives API response missing data array');
        }

        $parsedData = [];
        foreach ($apiResponse['data'] as $entry) {
            $statusRaw = strtolower(trim((string)($entry['status'] ?? '')));
            $displayStatus = $entry['display_status'] ?? ($statusRaw !== '' ? ucfirst($statusRaw) : '');

            $parsedData[] = [
                'incentive_id' => $entry['id'] ?? null,
                'employee_id' => $entry['employee_id'] ?? null,
                'employee_code' => $entry['employee_code'] ?? '',
                'employee_name' => $entry['employee_name'] ?? trim(($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? '')),
                'department' => $entry['department'] ?? '',
                'position' => $entry['position'] ?? '',
                'period_display' => $entry['period_display'] ?? '',
                'amount' => floatval($entry['incentive_amount'] ?? 0),
                'performance_rating' => $entry['performance_rating'] ?? '',
                'category' => $entry['category'] ?? '',
                'processed_by_name' => $entry['processed_by_name'] ?? '',
                'processed_date' => $entry['processed_date'] ?? '',
                'approved_by' => $entry['approved_by'] ?? null,
                'approved_date' => $entry['approved_date'] ?? null,
                'paid_date' => $entry['paid_date'] ?? null,
                'status' => $entry['status'] ?? '',
                'display_status' => $displayStatus,
                'notes' => $entry['notes'] ?? '',
                'can_approve' => in_array($statusRaw, ['pending', 'pending finance', 'for approval'], true)
            ];
        }

        return $parsedData;
    }

    /**
     * Update payroll approval status in HR4
     */
    public function updatePayrollStatus($config, $params = []) {
        $payrollId = $params['id'] ?? $params['approval_id'] ?? $params['payroll_id'] ?? $params['payrollId'] ?? null;
        $action = strtolower($params['approval_action'] ?? $params['action'] ?? '');

        if (!$payrollId || !in_array($action, ['approve', 'reject'], true)) {
            return ['success' => false, 'error' => 'Invalid payroll approval request'];
        }

        // Do not mark payroll as 'approved' locally — mark as 'processed'
        // since approval is recorded at Disbursement Records instead.
        $statusValue = $action === 'approve' ? 'processed' : 'rejected';

        // Try to update external HR4 system, but don't fail if it's not available
        $externalUpdateSuccess = false;
        $externalError = null;

        try {
            $approver = $params['approver'] ?? $params['approved_by'] ?? $params['user_name'] ?? '';
            $payload = [
                'action' => $action,
                'approval_action' => $action,
                'id' => $payrollId,
                'approval_id' => $payrollId,
                'payroll_id' => $payrollId,
                'status' => $statusValue,
                'approver' => $approver,
                'notes' => $params['notes'] ?? '',
                'reason' => $params['rejection_reason'] ?? ''
            ];

            $baseUrl = rtrim($config['api_url'], '/');
            $endpoint = $baseUrl;
            if (stripos($baseUrl, 'payroll_approval_api.php') !== false) {
                $endpoint .= $action === 'approve' ? '/approve' : '/reject';
            }

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Shorter timeout
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $headers = ['Content-Type: application/json'];
            if (!empty($config['api_key'])) {
                $headers[] = 'Authorization: Bearer ' . $this->generateAuthToken($config);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                if ($result !== null &&
                    ((isset($result['status']) && strtolower($result['status']) === 'success') ||
                     (isset($result['success']) && $result['success']))) {
                    $externalUpdateSuccess = true;
                }
            }
        } catch (Exception $e) {
            $externalError = $e->getMessage();
        }

        // Always log the approval action locally
        if (class_exists('Logger')) {
            Logger::getInstance()->logUserAction(
                $statusValue,
                'payroll',
                $payrollId,
                ['status' => 'processed'],
                ['status' => $statusValue, 'action' => $action, 'external_sync' => $externalUpdateSuccess]
            );
        }

        // If external update failed, log a warning but still allow the approval to proceed
        if (!$externalUpdateSuccess) {
            if (class_exists('Logger')) {
                Logger::getInstance()->warning('HR4 payroll approval external sync failed', [
                    'payroll_id' => $payrollId,
                    'action' => $action,
                    'error' => $externalError,
                    'proceeding_locally' => true
                ]);
            }
        }

        // Always return success for the approval action to allow local processing
        return [
            'success' => true,
            'message' => 'Payroll ' . $statusValue . ' successfully' .
                        ($externalUpdateSuccess ? '' : ' (external sync pending)'),
            'external_sync' => $externalUpdateSuccess
        ];
    }

    /**
     * Update incentive approval status in HR4
     */
    public function updateIncentiveStatus($config, $params = []) {
        $incentiveId = $params['id'] ?? $params['incentive_id'] ?? null;
        $action = strtolower($params['approval_action'] ?? $params['action'] ?? '');

        if (!$incentiveId || !in_array($action, ['approve', 'reject', 'paid'], true)) {
            return ['success' => false, 'error' => 'Invalid incentive approval request'];
        }

        $endpoint = $config['incentives_update_url'] ?? $config['incentive_update_url'] ?? '';
        if ($endpoint === '') {
            return ['success' => false, 'error' => 'HR4 incentives update URL is not configured'];
        }

        $externalUpdateSuccess = false;
        $externalError = null;
        $externalMessage = null;

        try {
            $approver = $params['approver'] ?? $params['approved_by'] ?? $params['user_name'] ?? '';
            $approverId = null;
            if (isset($params['approver_id']) && is_numeric($params['approver_id'])) {
                $approverId = (int)$params['approver_id'];
            } elseif (isset($params['approved_by_id']) && is_numeric($params['approved_by_id'])) {
                $approverId = (int)$params['approved_by_id'];
            } elseif (is_numeric($approver)) {
                $approverId = (int)$approver;
            }
            $approverValue = $approverId !== null ? $approverId : $approver;
            $rejectionReason = $params['rejection_reason'] ?? $params['reason'] ?? '';
            $payload = [
                'incentive_ids' => [(int)$incentiveId]
            ];

            if ($action === 'approve') {
                $payload['approved_by'] = $approverValue;
                if (!empty($params['notes'])) {
                    $payload['notes'] = $params['notes'];
                }
            } elseif ($action === 'reject') {
                $payload['rejected_by'] = $approverValue;
                $payload['rejection_reason'] = $rejectionReason;
            } else {
                $payload['paid_by'] = $approverValue;
            }

            $resolvedEndpoint = $endpoint;
            if (stripos($endpoint, 'api_incentives.php') !== false) {
                $actionPath = $action === 'approve' ? 'approve' : ($action === 'reject' ? 'reject' : 'paid');
                $resolvedEndpoint = rtrim($endpoint, '/') . '/' . $actionPath;
            }

            $ch = curl_init($resolvedEndpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $headers = ['Content-Type: application/json'];
            if (!empty($config['api_key'])) {
                $headers[] = 'Authorization: Bearer ' . $this->generateAuthToken($config);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = $response !== '' ? json_decode($response, true) : null;
            if ($httpCode === 200) {
                if ($result !== null &&
                    ((isset($result['status']) && strtolower($result['status']) === 'success') ||
                     (isset($result['success']) && $result['success']))) {
                    $externalUpdateSuccess = true;
                } else {
                    $externalMessage = is_array($result) ? ($result['message'] ?? $result['error'] ?? null) : null;
                }
            } else {
                $externalMessage = is_array($result) ? ($result['message'] ?? $result['error'] ?? null) : null;
                if (!$externalMessage && $response) {
                    $externalMessage = $response;
                }
            }
        } catch (Exception $e) {
            $externalError = $e->getMessage();
        }

        if (class_exists('Logger')) {
            Logger::getInstance()->logUserAction(
                'incentive_' . $action,
                'payroll',
                $incentiveId,
                null,
                ['action' => $action, 'external_sync' => $externalUpdateSuccess]
            );
        }

        if (!$externalUpdateSuccess) {
            if (class_exists('Logger')) {
                Logger::getInstance()->warning('HR4 incentive approval external sync failed', [
                    'incentive_id' => $incentiveId,
                    'action' => $action,
                    'error' => $externalError,
                    'proceeding_locally' => false
                ]);
            }
        }

        return [
            'success' => $externalUpdateSuccess,
            'message' => $externalUpdateSuccess ? 'Incentive updated successfully' : 'Incentive update failed',
            'error' => $externalUpdateSuccess ? null : ($externalMessage ?? $externalError ?? 'HR4 incentives update failed'),
            'external_sync' => $externalUpdateSuccess
        ];
    }

    /**
     * Parse the actual HR4 API response format into our expected payroll data format
     */
    private function parseHR4PayrollData($apiResponse) {
        $parsedData = [];

        if (!isset($apiResponse['payroll_data']) || !is_array($apiResponse['payroll_data'])) {
            throw new Exception('HR4 API response missing payroll_data array');
        }

        $payrollMonth = $apiResponse['month'] ?? date('Y-m');

        foreach ($apiResponse['payroll_data'] as $employeeData) {
            // Map department name to department ID
            $departmentMapping = [
                'Human Resources Department' => 1, // Administrative
                'Kitchen Department' => 2,
                'Front Office Department' => 3,
                'Housekeeping Department' => 4,
                'Food & Beverage Department' => 2, // Map to Kitchen
                'Maintenance Department' => 5,
                'Security Department' => 6,
                'Accounting Department' => 1, // Administrative
                'Sales & Marketing Department' => 7,
            ];

            $departmentName = $employeeData['department'] ?? 'General';
            $departmentId = $departmentMapping[$departmentName] ?? 1; // Default to Administrative

            // Convert employee data to our expected format
            $parsedEntry = [
                'payroll_id' => 'HR4_' . $employeeData['id'],
                'employee_id' => 'EMP_' . $employeeData['id'],
                'employee_name' => $employeeData['name'] ?? 'Unknown Employee',
                'department_id' => $departmentId,
                'department' => $departmentName,
                'payroll_date' => date('Y-m-d'), // Use current date as payroll date
                'payroll_period' => $payrollMonth . '-01 to ' . $payrollMonth . '-' . date('t', strtotime($payrollMonth . '-01')),
                'basic_salary' => floatval($employeeData['basic_salary'] ?? 0),
                'overtime_pay' => floatval($employeeData['overtime_pay'] ?? 0),
                'bonus' => floatval($employeeData['bonus'] ?? 0),
                'allowances' => floatval($employeeData['additions'] ?? 0),
                'deductions' => floatval($employeeData['deductions'] ?? 0),
                'gross' => floatval($employeeData['gross'] ?? 0),
                'net_pay' => floatval($employeeData['net'] ?? 0),
                'amount' => floatval($employeeData['net'] ?? 0), // For compatibility
                'currency_code' => 'PHP',
                'status' => 'processed',
                'position' => $employeeData['position'] ?? 'Staff',
                'email' => $employeeData['email'] ?? '',
                'date' => date('Y-m-d'), // For compatibility
                'id' => $employeeData['id'] ?? 0
            ];

            $parsedData[] = $parsedEntry;
        }

        return $parsedData;
    }

    /**
     * Get appropriate expense account for payroll based on department
     * Returns the account id to use for payroll expenses
     */
    private function getPayrollExpenseAccount($payroll = []) {
        // Base payroll expense accounts by department
        $departmentAccounts = [
            1 => '5401', // Administrative Salaries (Department 1)
            2 => '5402', // Kitchen/F&B Salaries (Department 2)
            3 => '5403', // Front Desk/Service Salaries (Department 3)
            // Add more department mappings as needed
        ];

        $departmentId = $payroll['department_id'] ?? 1;
        $accountCode = $departmentAccounts[$departmentId] ?? '5401';
        $accountId = $this->getAccountId($accountCode);
        if ($accountId) {
            return $accountId;
        }

        // Prefer centralized payroll expense account if department-specific codes are inactive
        $accountId = $this->getAccountId('6000'); // Salaries Expense
        if ($accountId) {
            return $accountId;
        }

        // Final fallback: any active expense account
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'expense' AND is_active = 1 ORDER BY account_code LIMIT 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['id'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get appropriate liability account for accrued payroll
     * Returns the account id to use for accrued wages payable
     */
    private function getPayrollLiabilityAccount() {
        $candidateNames = [
            'Accrued Salaries Payable',
            'Payroll Payable',
            'Salaries Payable',
            'Wages Payable'
        ];

        foreach ($candidateNames as $name) {
            $accountId = $this->getAccountId($name);
            if ($accountId) {
                return $accountId;
            }
        }

        // Fallback to Accounts Payable if no payroll liability account exists
        return $this->getAccountId('2001');
    }

    /**
     * Import payroll data into financials system
     * Updates department expense tracking that feeds into automatic journal entry generation
     */
    public function importPayroll($config, $params = []) {
        $payrollData = $this->getPayrollData($config, $params);

        $importedCount = 0;
        $errors = [];

        foreach ($payrollData as $payroll) {
            try {
                $db = Database::getInstance()->getConnection();
                $amount = floatval($payroll['net_pay'] ?? $payroll['amount'] ?? 0);

                if ($amount <= 0) {
                    continue; // Skip invalid amounts
                }

                $batchId = 'PAYROLL_' . date('Ymd_His');
                $entryDate = $payroll['payroll_date'] ?? $payroll['date'] ?? date('Y-m-d');
                $description = 'Payroll: ' . ($payroll['employee_name'] ?? 'Employee') . ' - ' . ($payroll['payroll_period'] ?? 'Period');
                $externalId = $payroll['payroll_id'] ?? $payroll['id'] ?? 'PAY' . time() . '_' . $importedCount;
                $departmentId = $payroll['department_id'] ?? 1;

                // Import to imported_transactions for audit trail and system processing
                $stmt = $db->prepare("
                    INSERT INTO imported_transactions
                    (import_batch, source_system, transaction_date, transaction_type,
                     external_id, department_id, amount, description, raw_data, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");

                $stmt->execute([
                    $batchId,
                    'HR_SYSTEM',
                    $entryDate,
                    'payroll_expense',
                    $externalId,
                    $departmentId,
                    $amount,
                    $description,
                    json_encode($payroll)
                ]);

                // Update daily_expense_summary - this feeds into automatic department reporting
                $summaryStmt = $db->prepare("
                    INSERT INTO daily_expense_summary
                    (business_date, department_id, expense_category, source_system, total_transactions, total_amount)
                    VALUES (?, ?, ?, ?, 1, ?)
                    ON DUPLICATE KEY UPDATE
                        total_transactions = total_transactions + 1,
                        total_amount = total_amount + VALUES(total_amount),
                        updated_at = NOW()
                ");
                $summaryStmt->execute([$entryDate, $departmentId, 'labor_payroll', 'HR_SYSTEM', $amount]);

                // CREATE JOURNAL ENTRY for proper double-entry bookkeeping
                // Debit: Salaries Expense, Credit: Accrued Salaries Payable
                $salariesExpenseAccount = $this->getPayrollExpenseAccount($payroll);
                $accruedSalariesAccount = $this->getPayrollLiabilityAccount();

                if ($salariesExpenseAccount && $accruedSalariesAccount) {
                    $this->createJournalEntry([
                        'date' => $entryDate,
                        'description' => $description,
                        'debit_account_id' => $salariesExpenseAccount,
                        'credit_account_id' => $accruedSalariesAccount,
                        'amount' => $amount,
                        'reference_number' => $externalId,
                        'source_system' => 'HR4_PAYROLL'
                    ]);
                }

                $importedCount++;

                Logger::getInstance()->info('Payroll imported with journal entry', [
                    'payroll_id' => $externalId,
                    'employee' => $payroll['employee_name'] ?? 'Unknown',
                    'amount' => $amount,
                    'department_id' => $departmentId
                ]);

            } catch (Exception $e) {
                $errors[] = 'Failed to import payroll for ' . ($payroll['employee_name'] ?? 'Unknown') . ': ' . $e->getMessage();
                Logger::getInstance()->error('Payroll import error', [
                    'payroll' => $payroll,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'success' => count($errors) === 0,
            'imported_count' => $importedCount,
            'errors' => $errors,
            'message' => "Imported {$importedCount} payroll records to department expense tracking" . (count($errors) > 0 ? " with " . count($errors) . " errors" : "")
        ];
    }


    /**
     * Generate authentication token for HR4 API
     */
    private function generateAuthToken($config) {
        // Simple token generation - can be enhanced based on HR4 requirements
        $payload = $config['api_key'] . ':' . $config['api_secret'];
        return base64_encode(hash('sha256', $payload, true));
    }
}

/**
 * Core 1 Hotel Billing & Payments Integration
 */
class Core1HotelPaymentsIntegration extends BaseIntegration {
    protected $name = 'core1';
    protected $displayName = 'Core 1 Hotel Payments';
    protected $description = 'Import billing payments from Core 1 Hotel system';
    protected $requiredConfig = ['api_url'];

    public function testConnection($config) {
        try {
            $data = $this->fetchPayments($config);
            $count = count($data);
            return [
                'success' => true,
                'message' => "Core 1 connection successful. Found {$count} payment records"
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Import completed hotel payments into imported_transactions
     */
    public function importPayments($config, $params = []) {
        try {
            $payments = $this->fetchPayments($config);
            $importedCount = 0;
            $paymentsInserted = 0;
            $transactionsInserted = 0;
            $errors = [];
            $database = Database::getInstance();
            $db = $database->getConnection();
            $this->assertTablesExist($db, ['payments_received', 'imported_transactions']);
            $defaultCustomerId = $this->getOrCreateDefaultCustomerId($database);
            $outlet = $this->getHotelOutlet($db);

            foreach ($payments as $payment) {
                try {
                    $status = strtolower($payment['status'] ?? '');
                    if ($status !== 'completed') {
                        continue;
                    }

                    $amount = floatval($payment['amount'] ?? 0);
                    if ($amount <= 0) {
                        continue;
                    }

                    $externalId = 'CORE1_PAY_' . ($payment['id'] ?? time() . '_' . $importedCount);

                    $existsStmt = $db->prepare("
                        SELECT COUNT(*) as count
                        FROM imported_transactions
                        WHERE source_system = 'HOTEL_CORE1' AND external_id = ?
                    ");
                    $existsStmt->execute([$externalId]);
                    $exists = $existsStmt->fetch(PDO::FETCH_ASSOC);
                    if (!empty($exists['count'])) {
                        // If already imported but journal entry missing, create it now
                        $existingRowStmt = $db->prepare("
                            SELECT id, journal_entry_id, transaction_date, amount, external_reference
                            FROM imported_transactions
                            WHERE source_system = 'HOTEL_CORE1' AND external_id = ?
                            LIMIT 1
                        ");
                        $existingRowStmt->execute([$externalId]);
                        $existingRow = $existingRowStmt->fetch(PDO::FETCH_ASSOC);
                        if ($existingRow && empty($existingRow['journal_entry_id'])) {
                            $journalEntryId = $this->createCore1JournalEntryFromPayment(
                                $existingRow,
                                $payment
                            );
                            if ($journalEntryId) {
                                $db->prepare("
                                    UPDATE imported_transactions
                                    SET journal_entry_id = ?, status = 'posted', posted_at = NOW(), posted_by = 1
                                    WHERE id = ?
                                ")->execute([$journalEntryId, $existingRow['id']]);
                            }
                        }
                        continue;
                    }

                    $paymentDate = $payment['created_at'] ?? date('Y-m-d');
                    $transactionDate = date('Y-m-d', strtotime($paymentDate));
                    $paymentMethodRaw = $payment['payment_method'] ?? 'unknown';
                    $paymentMethod = $this->normalizePaymentMethod($paymentMethodRaw);
                    $reference = $payment['payment_intent_id'] ?? '';
                    $userId = $payment['user_id'] ?? '';
                    $customerLabel = $userId ? "Core 1 Hotel Guest #{$userId}" : 'Core 1 Hotel Guest';
                    $description = "Hotel payment {$reference} ({$paymentMethodRaw})";
                    $batchId = 'CORE1_PAY_' . date('Ymd_His');

                    $stmt = $db->prepare("
                        INSERT INTO imported_transactions
                        (import_batch, source_system, transaction_date, transaction_type,
                         external_id, external_reference, department_id, customer_name,
                         description, amount, raw_data, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");

                    $stmt->execute([
                        $batchId,
                        'HOTEL_CORE1',
                        $transactionDate,
                        'hotel_payment',
                        $externalId,
                        $reference,
                        1,
                        $customerLabel,
                        $description,
                        $amount,
                        json_encode($payment)
                    ]);
                    $transactionsInserted++;

                    $paymentResult = $this->insertPaymentReceived($database, $db, [
                        'customer_id' => $defaultCustomerId,
                        'payment_date' => $transactionDate,
                        'amount' => $amount,
                        'payment_method' => $paymentMethod,
                        'reference_number' => $reference,
                        'notes' => $description
                    ]);
                    $paymentNumber = is_array($paymentResult) ? $paymentResult['payment_number'] : $paymentResult;
                    $journalEntryId = is_array($paymentResult) ? ($paymentResult['journal_entry_id'] ?? null) : null;
                    $paymentsInserted++;

                    if ($outlet) {
                        $this->upsertOutletDailySales($db, $outlet['id'], $transactionDate, $amount, $paymentNumber);
                        $this->upsertDailyRevenueSummary($db, $transactionDate, $outlet['department_id'], $outlet['revenue_center_id']);
                    }

                    if ($journalEntryId) {
                        $db->prepare("
                            UPDATE imported_transactions
                            SET journal_entry_id = ?, status = 'posted', posted_at = NOW(), posted_by = 1
                            WHERE external_id = ? AND source_system = 'HOTEL_CORE1'
                        ")->execute([$journalEntryId, $externalId]);
                    }

                    $importedCount++;
                } catch (Exception $e) {
                    $errors[] = 'Failed to import payment ' . ($payment['id'] ?? 'Unknown') . ': ' . $e->getMessage();
                }
            }

            return [
                'success' => count($errors) === 0,
                'imported_count' => $importedCount,
                'payments_inserted' => $paymentsInserted,
                'transactions_inserted' => $transactionsInserted,
                'db_name' => Config::get('database.name'),
                'db_host' => Config::get('database.host'),
                'errors' => $errors,
                'message' => "Imported {$importedCount} Core 1 hotel payments" . (count($errors) > 0 ? " with " . count($errors) . " errors" : "")
            ];
        } catch (Exception $e) {
            Logger::getInstance()->error('Core 1 payment import failed', [
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch payments from Core 1 API
     */
    private function fetchPayments($config) {
        $ch = curl_init($config['api_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception('Core 1 API connection error: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new Exception('Core 1 API returned HTTP ' . $httpCode);
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Core 1 API returned invalid JSON');
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        if (is_array($decoded)) {
            return $decoded;
        }

        throw new Exception('Core 1 API response missing data array');
    }

    private function getOrCreateDefaultCustomerId($database) {
        $db = $database->getConnection();
        $stmt = $db->prepare("
            SELECT id
            FROM customers
            WHERE company_name = ? OR email = ?
            LIMIT 1
        ");
        $stmt->execute(['Core 1 Hotel Guest', 'core1-guest@atierahotelandrestaurant.com']);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($customer) {
            return (int)$customer['id'];
        }

        $countStmt = $db->query("SELECT COUNT(*) as count FROM customers");
        $count = $countStmt->fetch()['count'] + 1;
        $customerCode = 'C' . str_pad($count, 3, '0', STR_PAD_LEFT);

        return $database->insert(
            "INSERT INTO customers (customer_code, company_name, contact_person, email, phone, address, credit_limit, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $customerCode,
                'Core 1 Hotel Guest',
                'Core 1 Hotel Guest',
                'core1-guest@atierahotelandrestaurant.com',
                null,
                null,
                0,
                'active'
            ]
        );
    }

    private function getHotelOutlet($db) {
        $stmt = $db->query("
            SELECT id, department_id, revenue_center_id
            FROM outlets
            WHERE is_active = 1
              AND (outlet_type = 'rooms' OR outlet_name LIKE '%Room%' OR outlet_code = 'ROOMS')
            ORDER BY id
            LIMIT 1
        ");
        $outlet = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($outlet) {
            return $outlet;
        }

        $fallback = $db->query("
            SELECT id, department_id, revenue_center_id
            FROM outlets
            WHERE is_active = 1
            ORDER BY id
            LIMIT 1
        ");
        return $fallback->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getRevenueAccountId($db) {
        // Prefer a configured revenue account code
        $revenueAccount = $this->getAccountId('4001');
        if ($revenueAccount) {
            return $revenueAccount;
        }

        // Fallback: first active revenue account
        $stmt = $db->query("
            SELECT id
            FROM chart_of_accounts
            WHERE account_type = 'revenue' AND is_active = 1
            ORDER BY account_code ASC
            LIMIT 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['id'] ?? null;
    }

    private function insertPaymentReceived($database, $db, $data) {
        $stmt = $database->query("SELECT COUNT(*) as count FROM payments_received WHERE YEAR(created_at) = YEAR(CURDATE())");
        $count = $stmt->fetch()['count'] + 1;
        $paymentNumber = 'PAY-' . date('Y') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

        $columns = ['payment_number', 'customer_id', 'payment_date', 'amount', 'payment_method', 'reference_number', 'notes', 'recorded_by'];
        $values = [
            $paymentNumber,
            $data['customer_id'],
            $data['payment_date'],
            $data['amount'],
            $this->normalizePaymentMethod($data['payment_method'] ?? 'other'),
            $data['reference_number'],
            $data['notes'],
            1
        ];
        $placeholders = array_fill(0, count($columns), '?');

        if ($database->columnExists('payments_received', 'vendor_id')) {
            $columns[] = 'vendor_id';
            $columns[] = 'bill_id';
            $values[] = null;
            $values[] = null;
            $placeholders[] = '?';
            $placeholders[] = '?';
        }

        if ($database->columnExists('payments_received', 'invoice_id')) {
            $columns[] = 'invoice_id';
            $values[] = null;
            $placeholders[] = '?';
        }

        $database->insert(
            "INSERT INTO payments_received (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', $placeholders) . ")",
            $values
        );

        $cashAccount = $this->getAccountId('1001');
        $revenueAccount = $this->getRevenueAccountId($db);
        $journalEntryId = null;
        if ($cashAccount && $revenueAccount) {
            $result = $this->createJournalEntry([
                'date' => $data['payment_date'],
                'description' => 'Core 1 payment ' . $paymentNumber,
                'debit_account_id' => $cashAccount,
                'credit_account_id' => $revenueAccount,
                'amount' => $data['amount'],
                'reference_number' => $paymentNumber,
                'source_system' => 'HOTEL_CORE1'
            ]);
            $journalEntryId = $result['journal_entry_id'] ?? null;
        }

        return [
            'payment_number' => $paymentNumber,
            'journal_entry_id' => $journalEntryId
        ];
    }

    private function createCore1JournalEntryFromPayment($existingRow, $payment) {
        try {
            $cashAccount = $this->getAccountId('1001');
            $revenueAccount = $this->getRevenueAccountId(Database::getInstance()->getConnection());
            if (!$cashAccount || !$revenueAccount) {
                throw new Exception('Missing required chart of accounts for Core 1');
            }

            $paymentMethod = $payment['payment_method'] ?? 'unknown';
            $reference = $payment['payment_intent_id'] ?? ($existingRow['external_reference'] ?? '');
            $description = "Core 1 payment {$reference} ({$paymentMethod})";
            $date = isset($existingRow['transaction_date'])
                ? date('Y-m-d', strtotime($existingRow['transaction_date']))
                : date('Y-m-d');
            $amount = floatval($existingRow['amount'] ?? ($payment['amount'] ?? 0));
            if ($amount <= 0) {
                return null;
            }

            $result = $this->createJournalEntry([
                'date' => $date,
                'description' => $description,
                'debit_account_id' => $cashAccount,
                'credit_account_id' => $revenueAccount,
                'amount' => $amount,
                'reference_number' => $reference ?: ('CORE1_PAY_' . ($payment['id'] ?? time())),
                'source_system' => 'HOTEL_CORE1'
            ]);

            return $result['journal_entry_id'] ?? null;
        } catch (Exception $e) {
            Logger::getInstance()->error('Core 1 journal entry backfill failed', [
                'error' => $e->getMessage(),
                'external_id' => $existingRow['id'] ?? null
            ]);
            return null;
        }
    }

    private function normalizePaymentMethod($method) {
        $method = strtolower(trim((string)$method));
        if ($method === '') {
            return 'other';
        }

        $mapping = [
            'gcash' => 'other',
            'paymaya' => 'other',
            'maya' => 'other',
            'card' => 'credit_card',
            'credit' => 'credit_card',
            'debit' => 'credit_card',
            'bank' => 'bank_transfer',
            'bank_transfer' => 'bank_transfer',
            'transfer' => 'bank_transfer',
            'cash' => 'cash',
            'check' => 'check',
            'cheque' => 'check',
            'refund' => 'refund'
        ];

        if (isset($mapping[$method])) {
            return $mapping[$method];
        }

        return 'other';
    }

    private function upsertOutletDailySales($db, $outletId, $businessDate, $amount, $paymentNumber) {
        $stmt = $db->prepare("
            SELECT id, notes
            FROM outlet_daily_sales
            WHERE business_date = ? AND outlet_id = ?
            LIMIT 1
        ");
        $stmt->execute([$businessDate, $outletId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $db->prepare("
                UPDATE outlet_daily_sales
                SET gross_sales = gross_sales + ?,
                    net_sales = net_sales + ?,
                    notes = CONCAT(IFNULL(notes, ''), ?)
                WHERE id = ?
            ")->execute([
                $amount,
                $amount,
                ($existing['notes'] ? "\n" : '') . 'Core 1 payment ' . $paymentNumber,
                $existing['id']
            ]);
            return;
        }

        $db->prepare("
            INSERT INTO outlet_daily_sales
            (business_date, outlet_id, gross_sales, discounts, service_charge, taxes, net_sales, covers, room_nights, notes, created_by)
            VALUES (?, ?, ?, 0, 0, 0, ?, NULL, NULL, ?, ?)
        ")->execute([
            $businessDate,
            $outletId,
            $amount,
            $amount,
            'Core 1 payment ' . $paymentNumber,
            1
        ]);
    }

    private function upsertDailyRevenueSummary($db, $businessDate, $departmentId, $revenueCenterId) {
        $sumStmt = $db->prepare("
            SELECT
                COUNT(*) as total_transactions,
                COALESCE(SUM(gross_sales), 0) as gross_revenue,
                COALESCE(SUM(discounts), 0) as discounts,
                COALESCE(SUM(service_charge), 0) as service_charge,
                COALESCE(SUM(taxes), 0) as taxes,
                COALESCE(SUM(net_sales), 0) as net_revenue
            FROM outlet_daily_sales
            WHERE business_date = ?
              AND outlet_id IN (
                  SELECT id FROM outlets
                  WHERE department_id <=> ?
                    AND revenue_center_id <=> ?
              )
        ");
        $sumStmt->execute([
            $businessDate,
            $departmentId,
            $revenueCenterId
        ]);
        $totals = $sumStmt->fetch(PDO::FETCH_ASSOC);

        $upsert = $db->prepare("
            INSERT INTO daily_revenue_summary
            (business_date, department_id, revenue_center_id, source_system, total_transactions,
             gross_revenue, discounts, service_charge, taxes, net_revenue)
            VALUES (?, ?, ?, 'HOTEL_CORE1', ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_transactions = VALUES(total_transactions),
                gross_revenue = VALUES(gross_revenue),
                discounts = VALUES(discounts),
                service_charge = VALUES(service_charge),
                taxes = VALUES(taxes),
                net_revenue = VALUES(net_revenue),
                updated_at = NOW()
        ");

        $upsert->execute([
            $businessDate,
            $departmentId,
            $revenueCenterId,
            $totals['total_transactions'],
            $totals['gross_revenue'],
            $totals['discounts'],
            $totals['service_charge'],
            $totals['taxes'],
            $totals['net_revenue']
        ]);
    }

    private function assertTablesExist($db, $tables) {
        foreach ($tables as $table) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count
                FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = ?
            ");
            $stmt->execute([$table]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (empty($result['count'])) {
                throw new Exception("Required table missing: {$table}");
            }
        }
    }
}

/**
 * Logistics 1 Integration - Purchase Orders, Delivery Receipts, and Invoices
 */
class Logistics1Integration extends BaseIntegration {
    protected $name = 'logistics1';
    protected $displayName = 'Logistics 1 - Procurement System';
    protected $description = 'Import purchase orders, delivery receipts, and supplier invoices from Logistics 1 system';
    protected $requiredConfig = ['api_url'];

    public function getName() {
        return 'Logistics 1 - Procurement System';
    }

    public function getDescription() {
        return 'Import purchase orders, delivery receipts, and supplier invoices from Logistics 1 system';
    }

    public function getCategory() {
        return 'Procurement & Inventory';
    }

    public function getRequiredFields() {
        return ['api_url'];
    }

    public function getFormFields() {
        return [
            'api_url' => [
                'label' => 'API URL',
                'type' => 'url',
                'required' => true,
                'default' => 'https://logistics1.atierahotelandrestaurant.com/api/docu/docu.php'
            ]
        ];
    }

    public function validateConfig($config) {
        if (empty($config['api_url'])) {
            return ['valid' => false, 'errors' => ['API URL is required']];
        }
        return ['valid' => true];
    }

    public function testConnection($config) {
        try {
            $data = $this->getProcurementData($config);
            $poCount = count($data['purchase_orders'] ?? []);
            $drCount = count($data['delivery_receipts'] ?? []);
            $invCount = count($data['invoices'] ?? []);

            return [
                'success' => true,
                'message' => "Connected successfully. Found {$poCount} POs, {$drCount} DRs, {$invCount} invoices"
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getActions() {
        return [
            'importInvoices' => [
                'label' => 'Import Supplier Invoices',
                'description' => 'Import supplier invoices to Accounts Payable',
                'icon' => 'fa-file-invoice-dollar'
            ],
            'importPurchaseOrders' => [
                'label' => 'Import Purchase Orders',
                'description' => 'Import POs for expense tracking',
                'icon' => 'fa-shopping-cart'
            ]
        ];
    }

    public function executeAction($action, $config, $params = []) {
        switch ($action) {
            case 'importInvoices':
                return $this->importInvoices($config, $params);
            case 'importPurchaseOrders':
                return $this->importPurchaseOrders($config, $params);
            default:
                return ['success' => false, 'error' => 'Unknown action'];
        }
    }

    /**
     * Fetch procurement data from Logistics 1 API
     */
    private function getProcurementData($config) {
        $ch = curl_init($config['api_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json,text/plain,*/*'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new Exception('Connection error to Logistics 1 API: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new Exception('Logistics 1 API returned HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception($this->buildInvalidResponseMessage('Logistics 1 API', $response));
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        if (array_is_list($data)) {
            $data = ['purchase_orders' => $data];
        }

        if (isset($data['success']) && !$data['success']) {
            $errorMessage = trim((string) ($data['error'] ?? $data['message'] ?? ''));
            throw new Exception($errorMessage !== '' ? $errorMessage : $this->buildInvalidResponseMessage('Logistics 1 API', $response));
        }

        if (
            !isset($data['purchase_orders'])
            && !isset($data['invoices'])
            && !isset($data['delivery_receipts'])
        ) {
            throw new Exception($this->buildInvalidResponseMessage('Logistics 1 API', $response));
        }

        $data['success'] = true;
        return $data;
    }

    /**
     * Import supplier invoices to Accounts Payable
     */
    public function importInvoices($config, $params = []) {
        try {
            $data = $this->getProcurementData($config);
            $invoices = $data['invoices'] ?? [];

            $importedCount = 0;
            $errors = [];

            foreach ($invoices as $invoice) {
                try {
                    $db = Database::getInstance()->getConnection();

                    $totalAmount = floatval($invoice['total'] ?? 0);
                    if ($totalAmount <= 0) continue;

                    $batchId = 'LOG1_INV_' . date('Ymd_His');
                    $invoiceDate = $invoice['date_issued'] ?? date('Y-m-d');
                    $description = 'Invoice #' . ($invoice['invoice_number'] ?? 'N/A') . ' from ' . ($invoice['supplier_name'] ?? 'Supplier');
                    $externalId = 'LOG1_INV_' . ($invoice['invoice_id'] ?? time() . '_' . $importedCount);

                    // Map department based on purchase order department
                    $departmentName = $invoice['department_name'] ?? '';
                    $departmentId = $this->mapDepartment($departmentName);

                    // Import to imported_transactions
                    $stmt = $db->prepare("
                        INSERT INTO imported_transactions
                        (import_batch, source_system, transaction_date, transaction_type,
                         external_id, external_reference, department_id, customer_name,
                         description, amount, raw_data, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");

                    $stmt->execute([
                        $batchId,
                        'LOGISTICS1',
                        $invoiceDate,
                        'supplier_invoice',
                        $externalId,
                        $invoice['invoice_number'] ?? '',
                        $departmentId,
                        $invoice['supplier_name'] ?? '',
                        $description,
                        $totalAmount,
                        json_encode($invoice)
                    ]);

                    // Update daily_expense_summary for expense tracking
                    $summaryStmt = $db->prepare("
                        INSERT INTO daily_expense_summary
                        (business_date, department_id, expense_category, source_system, total_transactions, total_amount)
                        VALUES (?, ?, ?, ?, 1, ?)
                        ON DUPLICATE KEY UPDATE
                            total_transactions = total_transactions + 1,
                            total_amount = total_amount + VALUES(total_amount),
                            updated_at = NOW()
                    ");
                    $summaryStmt->execute([$invoiceDate, $departmentId, 'supplies_materials', 'LOGISTICS1', $totalAmount]);

                    // CREATE JOURNAL ENTRY for proper double-entry bookkeeping
                    // Debit: Supplies/Materials Expense, Credit: Accounts Payable
                    $suppliesExpenseAccount = $this->getAccountId('5403'); // Office Supplies
                    $accountsPayableAccount = $this->getAccountId('2001'); // Accounts Payable

                    if ($suppliesExpenseAccount && $accountsPayableAccount) {
                        $this->createJournalEntry([
                            'date' => $invoiceDate,
                            'description' => $description,
                            'debit_account_id' => $suppliesExpenseAccount,
                            'credit_account_id' => $accountsPayableAccount,
                            'amount' => $totalAmount,
                            'reference_number' => $externalId,
                            'source_system' => 'LOGISTICS1'
                        ]);
                    }

                    $importedCount++;

                    Logger::getInstance()->info('Logistics 1 invoice imported with journal entry', [
                        'invoice_id' => $externalId,
                        'supplier' => $invoice['supplier_name'] ?? 'Unknown',
                        'amount' => $totalAmount
                    ]);

                } catch (Exception $e) {
                    $errors[] = 'Failed to import invoice ' . ($invoice['invoice_number'] ?? 'Unknown') . ': ' . $e->getMessage();
                    Logger::getInstance()->error('Logistics 1 invoice import error', [
                        'invoice' => $invoice,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return [
                'success' => count($errors) === 0,
                'imported_count' => $importedCount,
                'errors' => $errors,
                'message' => "Imported {$importedCount} supplier invoices" . (count($errors) > 0 ? " with " . count($errors) . " errors" : "")
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Import purchase orders for expense tracking
     */
    public function importPurchaseOrders($config, $params = []) {
        try {
            $data = $this->getProcurementData($config);
            $purchaseOrders = $data['purchase_orders'] ?? [];

            $importedCount = 0;
            $errors = [];

            foreach ($purchaseOrders as $po) {
                try {
                    // Only import approved POs
                    if (($po['status_type'] ?? '') !== 'Approved') continue;

                    $db = Database::getInstance()->getConnection();

                    // Calculate total from items
                    $totalAmount = 0;
                    foreach (($po['items'] ?? []) as $item) {
                        $totalAmount += floatval($item['total_price'] ?? 0);
                    }

                    if ($totalAmount <= 0) continue;

                    $batchId = 'LOG1_PO_' . date('Ymd_His');
                    $poDate = $po['purchase_date'] ?? date('Y-m-d');
                    $description = 'PO #' . ($po['purchase_number'] ?? 'N/A') . ' - ' . ($po['supplier_name'] ?? 'Supplier');
                    $externalId = 'LOG1_PO_' . ($po['purchase_id'] ?? time() . '_' . $importedCount);

                    $departmentName = $po['department_name'] ?? '';
                    $departmentId = $this->mapDepartment($departmentName);

                    // Import to imported_transactions
                    $stmt = $db->prepare("
                        INSERT INTO imported_transactions
                        (import_batch, source_system, transaction_date, transaction_type,
                         external_id, external_reference, department_id, customer_name,
                         description, amount, raw_data, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");

                    $stmt->execute([
                        $batchId,
                        'LOGISTICS1',
                        $poDate,
                        'purchase_order',
                        $externalId,
                        $po['purchase_number'] ?? '',
                        $departmentId,
                        $po['supplier_name'] ?? '',
                        $description,
                        $totalAmount,
                        json_encode($po)
                    ]);

                    $importedCount++;

                    Logger::getInstance()->info('Logistics 1 PO imported', [
                        'po_id' => $externalId,
                        'supplier' => $po['supplier_name'] ?? 'Unknown',
                        'amount' => $totalAmount
                    ]);

                } catch (Exception $e) {
                    $errors[] = 'Failed to import PO ' . ($po['purchase_number'] ?? 'Unknown') . ': ' . $e->getMessage();
                }
            }

            return [
                'success' => count($errors) === 0,
                'imported_count' => $importedCount,
                'errors' => $errors,
                'message' => "Imported {$importedCount} purchase orders" . (count($errors) > 0 ? " with " . count($errors) . " errors" : "")
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Map department name to department ID
     */
    private function mapDepartment($departmentName) {
        $mapping = [
            'kitchen' => 2,
            'food' => 2,
            'beverage' => 2,
            'front' => 3,
            'desk' => 3,
            'reception' => 3,
            'admin' => 1,
            'office' => 1,
            'hr' => 1,
            'human resources' => 1
        ];

        $lowerName = strtolower($departmentName);
        foreach ($mapping as $keyword => $deptId) {
            if (strpos($lowerName, $keyword) !== false) {
                return $deptId;
            }
        }

        return 1; // Default to Administrative
    }
}

/**
 * Logistics 2 Integration - Trip Costs and Transportation
 */
class Logistics2Integration extends BaseIntegration {
    public function getName() {
        return 'Logistics 2 - Transportation System';
    }

    public function getDescription() {
        return 'Import trip costs, fuel expenses, and vehicle transportation data from Logistics 2 system';
    }

    public function getCategory() {
        return 'Transportation & Logistics';
    }

    public function getRequiredFields() {
        return ['api_url'];
    }

    public function getFormFields() {
        return [
            'api_url' => [
                'label' => 'API URL',
                'type' => 'url',
                'required' => true,
                'default' => 'https://logistic2.atierahotelandrestaurant.com/integration/trip-costs-api.php'
            ]
        ];
    }

    public function validateConfig($config) {
        if (empty($config['api_url'])) {
            return ['valid' => false, 'errors' => ['API URL is required']];
        }
        return ['valid' => true];
    }

    public function testConnection($config) {
        try {
            $data = $this->getTripData($config);
            $tripCount = count($data['trips'] ?? []);
            $totalCost = floatval($data['summary']['grand_total'] ?? 0);

            return [
                'success' => true,
                'message' => "Connected successfully. Found {$tripCount} trips with total cost of ₱" . number_format($totalCost, 2)
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getActions() {
        return [
            'importTripCosts' => [
                'label' => 'Import Trip Costs',
                'description' => 'Import transportation and fuel expenses',
                'icon' => 'fa-truck'
            ]
        ];
    }

    public function executeAction($action, $config, $params = []) {
        switch ($action) {
            case 'importTripCosts':
                return $this->importTripCosts($config, $params);
            default:
                return ['success' => false, 'error' => 'Unknown action'];
        }
    }

    /**
     * Fetch trip costs from Logistics 2 API
     */
    private function getTripData($config) {
        $ch = curl_init($config['api_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new Exception('Connection error to Logistics 2 API: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new Exception('Logistics 2 API returned HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception($this->buildInvalidResponseMessage('Logistics 2 API', $response));
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        if (isset($data['success']) && !$data['success']) {
            $errorMessage = trim((string) ($data['error'] ?? $data['message'] ?? ''));
            throw new Exception($errorMessage !== '' ? $errorMessage : $this->buildInvalidResponseMessage('Logistics 2 API', $response));
        }

        if (!isset($data['trips']) || !is_array($data['trips'])) {
            throw new Exception($this->buildInvalidResponseMessage('Logistics 2 API', $response));
        }

        return $data;
    }

    /**
     * Import trip costs to operating expenses
     */
    public function importTripCosts($config, $params = []) {
        try {
            $data = $this->getTripData($config);
            $trips = $data['trips'] ?? [];

            $importedCount = 0;
            $errors = [];

            foreach ($trips as $trip) {
                try {
                    $db = Database::getInstance()->getConnection();

                    $totalAmount = floatval($trip['total_amount'] ?? 0);
                    if ($totalAmount <= 0) continue;

                    // Only import completed trips
                    if (($trip['status'] ?? '') !== 'Completed') continue;

                    $batchId = 'LOG2_TRIP_' . date('Ymd_His');
                    $tripDate = isset($trip['date_added']) ? date('Y-m-d', strtotime($trip['date_added'])) : date('Y-m-d');
                    $description = 'Trip: ' . ($trip['trip_description'] ?? 'N/A') . ' - ' . ($trip['driver_name'] ?? 'Driver') . ' (' . ($trip['vehicle_name'] ?? 'Vehicle') . ')';
                    $externalId = 'LOG2_TRIP_' . ($trip['trip_ID'] ?? time() . '_' . $importedCount);

                    // Transportation costs go to administrative/operations department
                    $departmentId = 1; // Administrative

                    // Import to imported_transactions
                    $stmt = $db->prepare("
                        INSERT INTO imported_transactions
                        (import_batch, source_system, transaction_date, transaction_type,
                         external_id, external_reference, department_id, description,
                         amount, raw_data, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");

                    $stmt->execute([
                        $batchId,
                        'LOGISTICS2',
                        $tripDate,
                        'transportation_expense',
                        $externalId,
                        $trip['trip_ID'] ?? '',
                        $departmentId,
                        $description,
                        $totalAmount,
                        json_encode($trip)
                    ]);

                    // Update daily_expense_summary - split between fuel and other costs
                    $fuelCost = floatval($trip['total_fuel_cost'] ?? 0);
                    $otherCost = floatval($trip['total_cost_amount'] ?? 0);

                    if ($fuelCost > 0) {
                        $fuelStmt = $db->prepare("
                            INSERT INTO daily_expense_summary
                            (business_date, department_id, expense_category, source_system, total_transactions, total_amount)
                            VALUES (?, ?, ?, ?, 1, ?)
                            ON DUPLICATE KEY UPDATE
                                total_transactions = total_transactions + 1,
                                total_amount = total_amount + VALUES(total_amount),
                                updated_at = NOW()
                        ");
                        $fuelStmt->execute([$tripDate, $departmentId, 'fuel_transportation', 'LOGISTICS2', $fuelCost]);

                        // CREATE JOURNAL ENTRY for fuel costs
                        // Debit: Fuel Expense, Credit: Accounts Payable
                        $fuelExpenseAccount = $this->getAccountId('5255'); // F&B - Kitchen Fuel
                        $accountsPayableAccount = $this->getAccountId('2001'); // Accounts Payable

                        if ($fuelExpenseAccount && $accountsPayableAccount) {
                            $this->createJournalEntry([
                                'date' => $tripDate,
                                'description' => 'Fuel Cost: ' . $description,
                                'debit_account_id' => $fuelExpenseAccount,
                                'credit_account_id' => $accountsPayableAccount,
                                'amount' => $fuelCost,
                                'reference_number' => $externalId . '_FUEL',
                                'source_system' => 'LOGISTICS2'
                            ]);
                        }
                    }

                    if ($otherCost > 0) {
                        $costStmt = $db->prepare("
                            INSERT INTO daily_expense_summary
                            (business_date, department_id, expense_category, source_system, total_transactions, total_amount)
                            VALUES (?, ?, ?, ?, 1, ?)
                            ON DUPLICATE KEY UPDATE
                                total_transactions = total_transactions + 1,
                                total_amount = total_amount + VALUES(total_amount),
                                updated_at = NOW()
                        ");
                        $costStmt->execute([$tripDate, $departmentId, 'transportation_other', 'LOGISTICS2', $otherCost]);

                        // CREATE JOURNAL ENTRY for other transportation costs
                        // Debit: Transportation Expense, Credit: Accounts Payable
                        $transportExpenseAccount = $this->getAccountId('5403'); // Office Supplies (fallback for transport)
                        $accountsPayableAccount = $this->getAccountId('2001'); // Accounts Payable

                        if ($transportExpenseAccount && $accountsPayableAccount) {
                            $this->createJournalEntry([
                                'date' => $tripDate,
                                'description' => 'Transportation Cost: ' . $description,
                                'debit_account_id' => $transportExpenseAccount,
                                'credit_account_id' => $accountsPayableAccount,
                                'amount' => $otherCost,
                                'reference_number' => $externalId . '_OTHER',
                                'source_system' => 'LOGISTICS2'
                            ]);
                        }
                    }

                    $importedCount++;

                    Logger::getInstance()->info('Logistics 2 trip cost imported with journal entries', [
                        'trip_id' => $externalId,
                        'driver' => $trip['driver_name'] ?? 'Unknown',
                        'amount' => $totalAmount,
                        'fuel' => $fuelCost,
                        'other' => $otherCost
                    ]);

                } catch (Exception $e) {
                    $errors[] = 'Failed to import trip ' . ($trip['trip_ID'] ?? 'Unknown') . ': ' . $e->getMessage();
                    Logger::getInstance()->error('Logistics 2 trip import error', [
                        'trip' => $trip,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return [
                'success' => count($errors) === 0,
                'imported_count' => $importedCount,
                'errors' => $errors,
                'message' => "Imported {$importedCount} trip cost records" . (count($errors) > 0 ? " with " . count($errors) . " errors" : "")
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
?>
