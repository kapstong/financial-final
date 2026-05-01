<?php
/**
 * ATIERA Financial Management System - Two-Factor Authentication Management
 * Admin interface for managing 2FA settings and monitoring
 */

require_once '../includes/auth.php';
require_once '../includes/two_factor_auth.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requirePermission('settings.edit'); // Require settings edit permission for 2FA management

$twoFactorAuth = TwoFactorAuth::getInstance();
$user = $auth->getCurrentUser();

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'reset_user_2fa':
            $userId = (int)$_POST['user_id'] ?? 0;
            if ($userId > 0) {
                $result = $twoFactorAuth->disable2FA($userId);
                if ($result['success']) {
                    $message = '2FA has been reset for the user.';
                } else {
                    $error = $result['error'];
                }
            } else {
                $error = 'Invalid user ID.';
            }
            break;

        case 'regenerate_backup_codes':
            $userId = (int)$_POST['user_id'] ?? 0;
            if ($userId > 0) {
                $result = $twoFactorAuth->regenerateBackupCodes($userId);
                if ($result['success']) {
                    $message = 'Backup codes have been regenerated.';
                } else {
                    $error = $result['error'];
                }
            } else {
                $error = 'Invalid user ID.';
            }
            break;

        case 'send_test_sms':
            $phoneNumber = $_POST['phone_number'] ?? '';
            if (!empty($phoneNumber)) {
                $result = $twoFactorAuth->sendSMSCode(0, $phoneNumber); // Use 0 as dummy user ID for testing
                if ($result['success']) {
                    $message = 'Test SMS sent successfully.';
                } else {
                    $error = 'Failed to send test SMS: ' . $result['error'];
                }
            } else {
                $error = 'Phone number is required.';
            }
            break;
    }
}

// Get 2FA statistics
$stats = $twoFactorAuth->get2FAStats();

// Get users with 2FA enabled
$usersWith2FA = [];
try {
    $stmt = $auth->getDatabase()->prepare("
        SELECT u.id, u.username, u.full_name, u.email, u2fa.method, u2fa.created_at
        FROM users u
        LEFT JOIN user_2fa u2fa ON u.id = u2fa.user_id AND u2fa.is_enabled = 1
        WHERE u2fa.id IS NOT NULL
        ORDER BY u2fa.created_at DESC
    ");
    $stmt->execute();
    $usersWith2FA = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without user data
}

// Get recent 2FA attempts
$recentAttempts = [];
try {
    $stmt = $auth->getDatabase()->prepare("
        SELECT a.*, u.username, u.full_name
        FROM twofa_attempts a
        LEFT JOIN users u ON a.user_id = u.id
        ORDER BY a.attempted_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $recentAttempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without attempt data
}

// Get failed attempts summary
$failedAttemptsSummary = [];
try {
    $stmt = $auth->getDatabase()->prepare("
        SELECT u.username, u.full_name, COUNT(*) as failed_count,
               MAX(a.attempted_at) as last_attempt
        FROM twofa_attempts a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.success = 0 AND a.attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY a.user_id, u.username, u.full_name
        HAVING failed_count >= 3
        ORDER BY failed_count DESC
    ");
    $stmt->execute();
    $failedAttemptsSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without summary data
}

$pageTitle = 'Two-Factor Authentication';
include 'legacy_header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-shield-alt"></i> Two-Factor Authentication</h2>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-info" onclick="show2FAStats()">
                        <i class="fas fa-chart-bar"></i> Statistics
                    </button>
                    <button type="button" class="btn btn-outline-warning" onclick="showFailedAttempts()">
                        <i class="fas fa-exclamation-triangle"></i> Failed Attempts
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="showTestSMS()">
                        <i class="fas fa-sms"></i> Test SMS
                    </button>
                </div>
            </div>

            <!-- 2FA Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h3 class="text-primary"><?php echo $stats['total_users']; ?></h3>
                            <p class="text-muted mb-0">Total Users</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h3 class="text-success"><?php echo $stats['enabled_users']; ?></h3>
                            <p class="text-muted mb-0">2FA Enabled</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h3 class="text-info"><?php echo $stats['totp_users']; ?></h3>
                            <p class="text-muted mb-0">Email OTP Users</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h3 class="text-warning"><?php echo $stats['sms_users']; ?></h3>
                            <p class="text-muted mb-0">SMS Users</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Users with 2FA Enabled -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-users"></i> Users with 2FA Enabled</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($usersWith2FA)): ?>
                    <div class="text-center text-muted">
                        <i class="fas fa-user-shield fa-3x mb-3"></i>
                        <h5>No users have 2FA enabled</h5>
                        <p>Users can enable 2FA from their profile settings.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Method</th>
                                    <th>Enabled Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usersWith2FA as $user2fa): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user2fa['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user2fa['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user2fa['email']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $user2fa['method'] === 'email' ? 'primary' : 'info'; ?>">
                                            <?php echo strtoupper($user2fa['method'] === 'totp' ? 'email' : $user2fa['method']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y H:i', strtotime($user2fa['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-warning"
                                                    onclick="regenerateBackupCodes(<?php echo $user2fa['id']; ?>, '<?php echo htmlspecialchars($user2fa['username']); ?>')">
                                                <i class="fas fa-key"></i> Backup Codes
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="resetUser2FA(<?php echo $user2fa['id']; ?>, '<?php echo htmlspecialchars($user2fa['username']); ?>')">
                                                <i class="fas fa-ban"></i> Reset 2FA
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent 2FA Attempts -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Recent 2FA Attempts</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recentAttempts)): ?>
                    <div class="text-center text-muted">
                        <i class="fas fa-clock fa-3x mb-3"></i>
                        <h5>No recent 2FA attempts</h5>
                        <p>2FA attempt logs will appear here.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-sm">
                            <thead class="table-dark">
                                <tr>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentAttempts as $attempt): ?>
                                <tr>
                                    <td><?php echo date('M j, Y H:i:s', strtotime($attempt['attempted_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($attempt['username'] ?: 'Unknown'); ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo strtoupper($attempt['method']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $attempt['success'] ? 'success' : 'danger'; ?>">
                                            <?php echo $attempt['success'] ? 'Success' : 'Failed'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($attempt['ip_address']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Security Recommendations -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-lightbulb"></i> Security Recommendations</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-check-circle text-success"></i> Best Practices</h6>
                            <ul class="list-unstyled">
                                <li><i class="fas fa-check text-success"></i> Encourage Email OTP over SMS for better security</li>
                                <li><i class="fas fa-check text-success"></i> Regularly monitor failed login attempts</li>
                                <li><i class="fas fa-check text-success"></i> Implement account lockout after multiple failures</li>
                                <li><i class="fas fa-check text-success"></i> Require 2FA for administrative accounts</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-exclamation-triangle text-warning"></i> Security Alerts</h6>
                            <ul class="list-unstyled">
                                <?php if (!empty($failedAttemptsSummary)): ?>
                                <?php foreach ($failedAttemptsSummary as $summary): ?>
                                <li><i class="fas fa-exclamation-triangle text-warning"></i>
                                    <strong><?php echo htmlspecialchars($summary['username']); ?></strong>
                                    has <?php echo $summary['failed_count']; ?> failed attempts in the last 24 hours
                                </li>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <li><i class="fas fa-check text-success"></i> No security alerts at this time</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Test SMS Modal -->
<div class="modal fade" id="testSMSModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Test SMS Functionality</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="send_test_sms">
                    <div class="mb-3">
                        <label for="testPhoneNumber" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="testPhoneNumber" name="phone_number"
                               placeholder="+1234567890" required>
                        <small class="form-text text-muted">Enter a phone number to send a test SMS.</small>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> This will simulate the SMS sending process.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Test SMS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Failed Attempts Modal -->
<div class="modal fade" id="failedAttemptsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Failed 2FA Attempts (Last 24 Hours)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($failedAttemptsSummary)): ?>
                <div class="text-center text-muted">
                    <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                    <h5>No failed attempts detected</h5>
                    <p>All 2FA attempts have been successful in the last 24 hours.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>User</th>
                                <th>Failed Attempts</th>
                                <th>Last Attempt</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($failedAttemptsSummary as $summary): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($summary['username']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($summary['full_name']); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-danger"><?php echo $summary['failed_count']; ?></span>
                                </td>
                                <td><?php echo date('M j, Y H:i', strtotime($summary['last_attempt'])); ?></td>
                                <td>
                                    <?php if ($summary['failed_count'] >= 5): ?>
                                    <span class="badge bg-danger">Account at Risk</span>
                                    <?php elseif ($summary['failed_count'] >= 3): ?>
                                    <span class="badge bg-warning">Monitor Closely</span>
                                    <?php else: ?>
                                    <span class="badge bg-info">Normal</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Show test SMS modal
function showTestSMS() {
    const modalEl = document.getElementById('testSMSModal');
    if (modalEl) {
        new bootstrap.Modal(modalEl).show();
    }
}

// Show failed attempts modal
function showFailedAttempts() {
    const modalEl = document.getElementById('failedAttemptsModal');
    if (modalEl) {
        new bootstrap.Modal(modalEl).show();
    }
}

// Show 2FA statistics
function show2FAStats() {
    // This could be expanded to show more detailed statistics
    alert('2FA Statistics:\n\nTotal Users: <?php echo $stats['total_users']; ?>\n2FA Enabled: <?php echo $stats['enabled_users']; ?>\nEmail OTP Users: <?php echo $stats['totp_users']; ?>\nSMS Users: <?php echo $stats['sms_users']; ?>');
}

// Reset user 2FA
function resetUser2FA(userId, username) {
    showConfirmDialog(
        'Reset 2FA',
        `Are you sure you want to reset 2FA for user "${username}"?\n\nThis will disable their 2FA and they will need to set it up again.`,
        () => {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="reset_user_2fa">
                <input type="hidden" name="user_id" value="${userId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    );
}

// Regenerate backup codes
function regenerateBackupCodes(userId, username) {
    showConfirmDialog(
        'Regenerate Backup Codes',
        `Regenerate backup codes for user "${username}"?\n\nThis will invalidate their current backup codes.`,
        () => {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="regenerate_backup_codes">
                <input type="hidden" name="user_id" value="${userId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    );
}

// Auto-refresh failed attempts every 5 minutes
setInterval(() => {
    // Could refresh the failed attempts data if needed
}, 300000);
</script>

<?php include 'legacy_footer.php'; ?>



<?php include '../includes/csrf_auto_form.php'; ?>

