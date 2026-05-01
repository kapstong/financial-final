<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';

if (!isset($_SESSION['user'])) {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user']['id'];

$flashMessage = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_budget_approval') {
    $taskId = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;

    if ($taskId <= 0) {
        $flashMessage = 'Invalid task.';
        $flashType = 'danger';
    } else {
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ? AND assigned_to = ? AND category = 'budget' LIMIT 1");
        $stmt->execute([$taskId, $userId]);
        $taskRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$taskRow) {
            $flashMessage = 'Task not found or not eligible for budget approval.';
            $flashType = 'danger';
        } else {
            $stmt = $db->prepare("
                SELECT id FROM users
                WHERE status = 'active' AND role IN ('admin', 'super_admin', 'superadmin')
                ORDER BY FIELD(role, 'admin', 'super_admin', 'superadmin'), last_login DESC
                LIMIT 1
            ");
            $stmt->execute();
            $manager = $stmt->fetch(PDO::FETCH_ASSOC);
            $managerId = $manager['id'] ?? null;

            if (!$managerId) {
                $flashMessage = 'No available manager to approve the budget request.';
                $flashType = 'danger';
            } else {
                $approvalTitle = "Budget Approval Required: Task #{$taskId}";
                $stmt = $db->prepare("SELECT id FROM tasks WHERE title = ? AND assigned_to = ? LIMIT 1");
                $stmt->execute([$approvalTitle, $managerId]);
                $already = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($already) {
                    $flashMessage = 'Approval task already sent to the manager.';
                    $flashType = 'warning';
                } else {
                    $original = json_decode($taskRow['description'], true);
                    $details = [
                        'source_task_id' => $taskId,
                        'submitted_by' => $userId,
                        'submitted_at' => date('c'),
                        'budget_details' => $original ?: $taskRow['description']
                    ];

                    try {
                        $db->beginTransaction();

                        $stmt = $db->prepare("
                            INSERT INTO tasks (title, description, priority, status, assigned_to, created_by, category, created_at)
                            VALUES (?, ?, 'high', 'pending', ?, ?, 'budget_approval', NOW())
                        ");
                        $stmt->execute([
                            $approvalTitle,
                            json_encode($details),
                            $managerId,
                            $userId
                        ]);

                        $stmt = $db->prepare("UPDATE tasks SET status = 'completed' WHERE id = ? AND assigned_to = ?");
                        $stmt->execute([$taskId, $userId]);

                        $db->commit();
                        $flashMessage = 'Sent to manager for approval.';
                        $flashType = 'success';
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $flashMessage = 'Failed to submit approval task.';
                        $flashType = 'danger';
                    }
                }
            }
        }
    }
}

$task = null;
if (!empty($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ? AND assigned_to = ? LIMIT 1");
    $stmt->execute([$_GET['id'], $userId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
}

$stmt = $db->prepare("
    SELECT id, title, description, priority, status, due_date, created_at
    FROM tasks
    WHERE assigned_to = ?
    ORDER BY FIELD(status,'pending','in_progress','completed','cancelled'), due_date IS NULL, due_date ASC, created_at DESC
");
$stmt->execute([$userId]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

function statusBadge($status) {
    $map = [
        'pending' => 'bg-warning text-dark',
        'in_progress' => 'bg-info',
        'completed' => 'bg-success',
        'cancelled' => 'bg-secondary'
    ];
    return $map[$status] ?? 'bg-secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks</title>
    <link rel="icon" type="image/png" href="../logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #F1F7EE;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }
        .sidebar {
            height: 100vh;
            max-height: 100vh;
            overflow-y: auto;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 2rem;
            background-color: #1e2936;
            color: white;
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 300px;
            z-index: 1000;
            transition: transform 0.3s ease, width 0.3s ease;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        .sidebar.sidebar-collapsed {
            width: 120px;
        }
        .sidebar.sidebar-collapsed span {
            display: none;
        }
        .sidebar.sidebar-collapsed .nav-link {
            padding: 10px;
            text-align: center;
        }
        .sidebar.sidebar-collapsed .navbar-brand {
            text-align: center;
        }
        .sidebar.sidebar-collapsed .nav-item i[data-bs-toggle="collapse"] {
            display: none;
        }
        .sidebar.sidebar-collapsed .submenu {
            display: none;
        }
        .sidebar .nav-link {
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            margin-bottom: 10px;
            font-size: 1.1em;
        }
        .sidebar .nav-link i {
            font-size: 1.4em;
        }
        .sidebar .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .sidebar .nav-link.active {
            background-color: rgba(255, 255, 255, 0.2);
        }
        .sidebar .submenu {
            padding-left: 20px;
        }
        .sidebar .submenu .nav-link {
            padding: 5px 20px;
            font-size: 0.9em;
        }
        .sidebar .nav-item {
            position: relative;
        }
        .sidebar .nav-item i[data-bs-toggle="collapse"] {
            position: absolute;
            right: 20px;
            top: 10px;
            transition: transform 0.3s ease;
        }
        .sidebar .nav-item i[aria-expanded="true"] {
            transform: rotate(90deg);
        }
        .sidebar .nav-item i[aria-expanded="false"] {
            transform: rotate(0deg);
        }
        .content {
            margin-left: 120px;
            padding: 20px;
            transition: margin-left 0.3s ease;
            position: relative;
            z-index: 1;
        }
        .sidebar .navbar-brand {
            color: white !important;
            font-weight: bold;
        }
        .sidebar .navbar-brand img {
            height: 50px;
            width: auto;
            max-width: 100%;
            transition: height 0.3s ease;
        }
        .sidebar.sidebar-collapsed .navbar-brand img {
            height: 80px;
        }
        .sidebar-toggle {
            position: fixed;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: white;
            font-size: 1.5em;
            width: 40px;
            height: 40px;
            background-color: #1e2936;
            border: 2px solid white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: left 0.3s ease, background-color 0.3s ease;
            z-index: 1001;
        }
        .sidebar-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .toggle-btn {
            display: none;
        }
        .navbar {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e3e6ea;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 10000;
        }
        .navbar-brand {
            font-weight: 700;
            color: #2c3e50 !important;
            font-size: 1.4rem;
            letter-spacing: -0.02em;
        }
        .navbar .dropdown-toggle {
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            transition: all 0.2s ease;
            text-decoration: none !important;
        }
        .navbar .dropdown-toggle:focus {
            box-shadow: none;
        }
        .navbar .dropdown-toggle:hover {
            background-color: rgba(0,0,0,0.05);
        }
        .navbar .dropdown-toggle span {
            font-weight: 600;
            font-size: 1.1rem;
            color: #495057;
        }
        .navbar .btn-link {
            font-size: 1.1rem;
            border-radius: 8px;
            padding: 0.5rem;
            transition: all 0.2s ease;
            color: #6c757d;
            text-decoration: none !important;
        }
        .navbar .btn-link:focus {
            box-shadow: none;
        }
        .navbar .btn-link:hover {
            background-color: rgba(0,0,0,0.05);
            color: #495057;
        }
        .navbar .input-group {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
            transition: all 0.2s ease;
        }
        .navbar .input-group:focus-within {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            border-color: #007bff;
        }
        .navbar .form-control {
            border: none;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            background-color: #ffffff;
        }
        .navbar .form-control:focus {
            box-shadow: none;
            border-color: transparent;
            background-color: #ffffff;
        }
        .navbar .btn-outline-secondary {
            border: none;
            background-color: #f8f9fa;
            color: #6c757d;
            border-left: 1px solid #e9ecef;
            padding: 0.75rem 1rem;
        }
        .navbar .btn-outline-secondary:hover {
            background-color: #e9ecef;
            color: #495057;
        }
        .navbar .dropdown-menu {
            z-index: 9999;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            border: none;
            border-radius: 8px;
            margin-top: 0.5rem;
        }
        .navbar .dropdown-item {
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
        }
        .navbar .dropdown-item:hover {
            background-color: #f8f9fa;
            color: #495057;
        }
        .hover-link:hover {
            color: #007bff !important;
            transition: color 0.2s ease;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }
        .table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .table thead th {
            background: linear-gradient(135deg, #1e2936 0%, #2c3e50 100%);
            color: white;
            font-weight: 600;
            border: none;
            padding: 1rem;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        .table tbody tr {
            border-bottom: 1px solid #f1f1f1;
        }
        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
            color: #495057;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .content {
                margin-left: 0;
                padding: 20px;
            }
            .toggle-btn {
                display: block;
            }
            .table-responsive {
                font-size: 0.9em;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/staff_navigation.php'; ?>

    <div class="content">
        <?php include '../includes/global_navbar.php'; ?>

        <div class="container-fluid">
            <?php if ($flashMessage): ?>
                <div class="alert alert-<?php echo $flashType; ?>">
                    <?php echo htmlspecialchars($flashMessage); ?>
                </div>
            <?php endif; ?>
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h3 class="mb-1">My Tasks</h3>
                    <p class="text-muted mb-0">Tasks assigned to you.</p>
                </div>
            </div>

            <?php if ($task): ?>
                <div class="card mb-4 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
                            <h5 class="mb-0"><?php echo htmlspecialchars($task['title']); ?></h5>
                            <span class="badge <?php echo statusBadge($task['status']); ?>">
                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $task['status']))); ?>
                            </span>
                        </div>
                        <p class="text-muted"><?php echo nl2br(htmlspecialchars($task['description'] ?? 'No description.')); ?></p>
                        <div class="d-flex flex-wrap gap-3">
                            <div><strong>Priority:</strong> <?php echo htmlspecialchars(ucfirst($task['priority'] ?? 'medium')); ?></div>
                            <div><strong>Due:</strong> <?php echo htmlspecialchars($task['due_date'] ?: 'N/A'); ?></div>
                        </div>
                        <?php if (($task['category'] ?? '') === 'budget' && $task['status'] !== 'completed'): ?>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="action" value="submit_budget_approval">
                                <input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>">
                                <button type="submit" class="btn btn-primary">
                                    Submit to Manager for Approval
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Due Date</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($tasks)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No tasks assigned.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($tasks as $row): ?>
                                        <tr>
                                            <td>
                                                <a href="tasks.php?id=<?php echo (int)$row['id']; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($row['title']); ?>
                                                </a>
                                            </td>
                                            <td><span class="badge <?php echo statusBadge($row['status']); ?>">
                                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $row['status']))); ?>
                                            </span></td>
                                            <td><?php echo htmlspecialchars(ucfirst($row['priority'] ?? 'medium')); ?></td>
                                            <td><?php echo htmlspecialchars($row['due_date'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($row['created_at']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../includes/privacy_mode.js?v=14"></script>
    <script src="../includes/inactivity_timeout.js"></script>
    <script src="../includes/navbar_datetime.js"></script>
</body>
</html>


