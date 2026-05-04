<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/logger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = Database::getInstance()->getConnection();

$search = trim($_GET['search'] ?? '');
$dateFromInput = trim($_GET['date_from'] ?? '');
$dateToInput = trim($_GET['date_to'] ?? '');
$period = strtolower(trim($_GET['period'] ?? ''));
$autoPrint = ($_GET['auto_print'] ?? '') === '1';

$logger = Logger::getInstance();

function normalizeDate(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d', $timestamp);
}

function periodRange(string $period): ?array
{
    $today = new DateTimeImmutable('today');
    $start = null;
    $end = $today->setTime(23, 59, 59);

    switch ($period) {
        case 'daily':
            $start = $today;
            break;
        case 'weekly':
            $start = $today->modify('monday this week');
            break;
        case 'monthly':
            $start = $today->modify('first day of this month');
            break;
        case 'quarterly':
            $month = (int) $today->format('n');
            $quarterStartMonth = ((int) floor(($month - 1) / 3) * 3) + 1;
            $start = new DateTimeImmutable($today->format('Y') . '-' . str_pad((string) $quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            break;
        case 'semi-annually':
        case 'semiannually':
            $month = (int) $today->format('n');
            $halfStartMonth = $month <= 6 ? 1 : 7;
            $start = new DateTimeImmutable($today->format('Y') . '-' . str_pad((string) $halfStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            break;
        case 'annually':
        case 'yearly':
            $start = new DateTimeImmutable($today->format('Y') . '-01-01');
            break;
        default:
            return null;
    }

    return [
        'start' => $start,
        'end' => $end
    ];
}

$fromDate = normalizeDate($dateFromInput);
$toDate = normalizeDate($dateToInput);

$periodRange = $period ? periodRange($period) : null;
if ($periodRange) {
    $periodStart = $periodRange['start']->format('Y-m-d');
    $periodEnd = $periodRange['end']->format('Y-m-d');
    if (!$fromDate || $periodStart > $fromDate) {
        $fromDate = $periodStart;
    }
    if (!$toDate || $periodEnd < $toDate) {
        $toDate = $periodEnd;
    }
}

$conditions = [];
$params = [];

if ($fromDate) {
    $conditions[] = 'je.entry_date >= ?';
    $params[] = $fromDate;
}
if ($toDate) {
    $conditions[] = 'je.entry_date <= ?';
    $params[] = $toDate;
}
if ($search !== '') {
    $searchLike = '%' . $search . '%';
    $conditions[] = '(je.entry_number LIKE ? OR je.description LIKE ? OR coa.account_name LIKE ? OR CAST(jel.debit AS CHAR) LIKE ? OR CAST(jel.credit AS CHAR) LIKE ? OR je.entry_date LIKE ?)';
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
}

$sql = "
    SELECT
        je.entry_date as date,
        je.entry_number as reference,
        je.description,
        jel.debit,
        jel.credit,
        coa.account_name
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.journal_entry_id = je.id
    JOIN chart_of_accounts coa ON jel.account_id = coa.id
";
if (!empty($conditions)) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY je.entry_date DESC, je.id DESC LIMIT 1000';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

function formatLabelDate(?string $value): string
{
    if (!$value) {
        return '';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }
    return date('M d, Y', $timestamp);
}

$periodLabel = 'All Dates';
if ($fromDate || $toDate) {
    $fromLabel = $fromDate ? formatLabelDate($fromDate) : 'Any';
    $toLabel = $toDate ? formatLabelDate($toDate) : 'Any';
    $periodLabel = $fromLabel . ' to ' . $toLabel;
} elseif ($periodRange) {
    $startLabel = formatLabelDate($periodRange['start']->format('Y-m-d'));
    $endLabel = formatLabelDate($periodRange['end']->format('Y-m-d'));
    $periodLabel = ucfirst($period) . ' (' . $startLabel . ' - ' . $endLabel . ')';
}

$printedAt = date('M d, Y h:i A');
$totalEntries = count($entries);

if ($autoPrint) {
    $logger->logUserAction('printed', 'journal_entries', '', null, [
        'report' => 'journal_entries',
        'filters' => [
            'search' => $search,
            'date_from' => $dateFromInput,
            'date_to' => $dateToInput,
            'period' => $period
        ]
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal Entries Report</title>
    <style>
        :root {
            --brand: #1e2936;
            --brand-soft: #f8f9fa;
            --border: #e3e6ea;
            --text: #1f2a37;
            --muted: #6c7a89;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            padding: 28px;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: var(--text);
            background: #ffffff;
        }
        .page {
            max-width: 900px;
            margin: 0 auto;
            position: relative;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 28px 32px 24px;
            box-shadow: 0 14px 32px rgba(30, 41, 54, 0.08);
            background: #ffffff;
        }
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 60%;
            max-width: 520px;
            opacity: 0.3;
            pointer-events: none;
            z-index: 0;
        }
        .report-header {
            display: flex;
            align-items: center;
            gap: 18px;
            z-index: 1;
            position: relative;
        }
        .report-header img {
            width: 78px;
            height: auto;
        }
        .brand-title {
            font-size: 21pt;
            font-weight: 700;
            color: var(--brand);
        }
        .brand-subtitle {
            font-size: 10.5pt;
            color: var(--muted);
        }
        .header-meta {
            margin-left: auto;
            text-align: right;
            font-size: 9pt;
            color: var(--muted);
        }
        .header-meta strong {
            color: var(--brand);
            display: block;
            font-size: 10pt;
        }
        .divider {
            height: 2px;
            background: var(--brand);
            border-radius: 999px;
            margin: 16px 0 18px;
        }
        .report-title {
            font-size: 17pt;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .report-subtitle {
            font-size: 10.5pt;
            color: var(--muted);
            margin-bottom: 16px;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
        }
        .meta-card {
            background: var(--brand-soft);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            box-shadow: 0 8px 20px rgba(30, 41, 54, 0.08);
        }
        .meta-label {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 8pt;
            color: var(--muted);
            margin-bottom: 4px;
        }
        .meta-value {
            font-size: 11.5pt;
            font-weight: 600;
        }
        .filters-note {
            font-size: 9.5pt;
            color: var(--muted);
            margin-bottom: 12px;
        }
        .table-wrap {
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            background: #ffffff;
            box-shadow: 0 10px 22px rgba(30, 41, 54, 0.06);
            position: relative;
            z-index: 1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        thead th {
            background: var(--brand);
            color: #ffffff;
            padding: 11px 12px;
            font-size: 9.5pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            text-align: left;
        }
        tbody td {
            padding: 12px 12px;
            border-bottom: 1px solid var(--border);
            font-size: 10pt;
            vertical-align: top;
        }
        td.description {
            word-break: break-word;
        }
        tbody tr:nth-child(even) td {
            background: #f3f5f7;
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        .amount {
            text-align: right;
            white-space: nowrap;
        }
        .empty-state {
            text-align: center;
            padding: 24px;
            color: var(--muted);
        }
        .report-footer {
            margin-top: 18px;
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            color: var(--muted);
            border-top: 1px solid var(--border);
            padding-top: 12px;
        }
        @media print {
            @page {
                size: 8.5in 11in;
                margin: 12mm;
            }
            body {
                padding: 0;
            }
            .page {
                max-width: none;
                box-shadow: none;
            }
            .watermark {
                position: fixed;
            }
            thead th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/loading_screen.php'; ?>
    <div class="page">
        <img src="../logo2.png" class="watermark" alt="ATIERA Watermark">
        <div class="report-header">
            <img src="../logo2.png" alt="ATIERA Logo">
            <div>
                <div class="brand-title">ATIERA : Hotel and Restaurant Management System</div>
                <div class="brand-subtitle">financial.atierahotelandrestaurant.com</div>
            </div>
            <div class="header-meta">
                <div>Printed On</div>
                <strong><?php echo htmlspecialchars($printedAt); ?></strong>
            </div>
        </div>
        <div class="divider"></div>

        <div class="report-title">Journal Entries Report</div>
        <div class="report-subtitle">General Ledger - Journal Entries</div>

        <div class="meta-grid">
            <div class="meta-card">
                <div class="meta-label">Period</div>
                <div class="meta-value"><?php echo htmlspecialchars($periodLabel); ?></div>
            </div>
            <div class="meta-card">
                <div class="meta-label">Total Entries</div>
                <div class="meta-value"><?php echo number_format($totalEntries); ?></div>
            </div>
            <?php if ($search !== ''): ?>
                <div class="meta-card">
                    <div class="meta-label">Search</div>
                    <div class="meta-value"><?php echo htmlspecialchars($search); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($search !== '' || $dateFromInput !== '' || $dateToInput !== '' || $period !== ''): ?>
            <div class="filters-note">Filters applied for this printout.</div>
        <?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                    <th style="width: 110px;">Date</th>
                    <th style="width: 140px;">Reference</th>
                    <th style="width: 170px;">Account</th>
                    <th>Description</th>
                    <th style="width: 110px;">Debit</th>
                    <th style="width: 110px;">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="6" class="empty-state">No journal entries found for the selected filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                                $entryDate = formatLabelDate($entry['date'] ?? '');
                                $debit = $entry['debit'] ?? 0;
                                $credit = $entry['credit'] ?? 0;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($entryDate); ?></td>
                                <td><?php echo htmlspecialchars($entry['reference'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['account_name'] ?? ''); ?></td>
                            <td class="description"><?php echo htmlspecialchars($entry['description'] ?? ''); ?></td>
                                <td class="amount"><?php echo $debit > 0 ? '&#8369;' . number_format((float) $debit, 2) : '-'; ?></td>
                                <td class="amount"><?php echo $credit > 0 ? '&#8369;' . number_format((float) $credit, 2) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="report-footer">
            <div>ATIERA Financial Management System</div>
            <div>Printed: <?php echo htmlspecialchars($printedAt); ?></div>
        </div>
    </div>

    <?php if ($autoPrint): ?>
        <script>
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                }, 300);
            });
        </script>
    <?php endif; ?>
<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>
