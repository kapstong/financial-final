<?php
/**
 * Budget Alert Evaluator Cron Job
 * Sends threshold notifications (70/80/90/100) to department emails.
 */

require_once '../includes/database.php';
require_once '../includes/logger.php';
require_once '../includes/budget_alerts.php';

try {
    $db = Database::getInstance()->getConnection();
    $logger = Logger::getInstance();

    $logger->info('Starting budget alerts cron job');

    $db->exec("
        CREATE TABLE IF NOT EXISTS budget_alert_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            department_id INT NOT NULL,
            budget_year INT NOT NULL,
            threshold_percent DECIMAL(5,2) NOT NULL,
            last_sent_at DATETIME DEFAULT NULL,
            last_utilization_percent DECIMAL(6,2) DEFAULT NULL,
            created_at DATETIME DEFAULT NOW(),
            UNIQUE KEY uniq_budget_alert (department_id, budget_year, threshold_percent)
        )
    ");

    $alerts = calculateBudgetAlerts($db);
    $thresholds = getBudgetAlertThresholds();
    $thresholdLookup = [];
    foreach ($thresholds as $threshold) {
        $thresholdLookup[$threshold['status']] = $threshold['percent'];
    }

    $sentCount = 0;

    foreach ($alerts as $alert) {
        $departmentId = $alert['department_id'];
        $budgetYear = (int) $alert['budget_year'];
        $severity = $alert['severity'];
        $thresholdPercent = $thresholdLookup[$severity] ?? null;

        if (!$departmentId || !$thresholdPercent) {
            continue;
        }

        $recipients = getDepartmentAlertRecipients(
            $db,
            $departmentId,
            $alert['department'],
            $alert['department_code']
        );

        if (empty($recipients)) {
            continue;
        }

        $stmt = $db->prepare("
            SELECT last_sent_at, last_utilization_percent
            FROM budget_alert_notifications
            WHERE department_id = ? AND budget_year = ? AND threshold_percent = ?
            LIMIT 1
        ");
        $stmt->execute([$departmentId, $budgetYear, $thresholdPercent]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $shouldSend = !$existing || !$existing['last_sent_at'];
        if ($existing && $existing['last_utilization_percent'] !== null) {
            $prevPercent = (float) $existing['last_utilization_percent'];
            $currentPercent = (float) $alert['utilization_percent'];
            if ($currentPercent <= $prevPercent) {
                $shouldSend = false;
            }
        }

        if (!$shouldSend) {
            continue;
        }

        $subject = "Budget Alert: {$alert['department']} at {$alert['utilization_percent']}%";
        $message = "
            <p>Department: <strong>{$alert['department']}</strong></p>
            <p>Budget Year: <strong>{$budgetYear}</strong></p>
            <p>Budgeted Amount: <strong>PHP " . number_format($alert['budgeted_amount'], 2) . "</strong></p>
            <p>Utilized Amount: <strong>PHP " . number_format($alert['utilized_amount'], 2) . "</strong></p>
            <p>Utilization: <strong>" . number_format($alert['utilization_percent'], 2) . "%</strong></p>
            <p>Severity: <strong>{$alert['severity_label']}</strong></p>
            <p>Please review and take action as needed.</p>
        ";

        foreach ($recipients as $email) {
            $logger->info("Budget alert would be sent to: $email (mail system disabled)");
        }

        $upsert = $db->prepare("
            INSERT INTO budget_alert_notifications
                (department_id, budget_year, threshold_percent, last_sent_at, last_utilization_percent)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                last_sent_at = VALUES(last_sent_at),
                last_utilization_percent = VALUES(last_utilization_percent)
        ");
        $upsert->execute([
            $departmentId,
            $budgetYear,
            $thresholdPercent,
            $alert['utilization_percent']
        ]);
    }

    $logger->info('Budget alerts cron completed', ['sent' => $sentCount]);
    echo "Budget alerts cron completed. Emails sent: {$sentCount}\n";

} catch (Exception $e) {
    $logger = Logger::getInstance();
    $logger->error('Budget alerts cron failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    echo "ERROR: Budget alerts cron failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
