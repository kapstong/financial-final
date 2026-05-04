<?php
/**
 * ATIERA Financial Management System - Translation Management
 * Admin interface for managing multi-language translations
 */

require_once '../includes/auth.php';
require_once '../includes/i18n.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requirePermission('settings.edit'); // Use settings.edit as translation permission

$i18n = I18n::getInstance();
$user = $auth->getCurrentUser();

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'set_language':
            if (!empty($_POST['language']) && $i18n->setLanguage($_POST['language'])) {
                $message = 'Language changed successfully.';
            } else {
                $error = 'Failed to change language.';
            }
            break;

        case 'add_translation':
            if (!empty($_POST['key']) && !empty($_POST['value'])) {
                $language = $_POST['language'] ?: $i18n->getCurrentLanguage();
                if ($i18n->setTranslation($_POST['key'], $_POST['value'], $language)) {
                    $message = 'Translation added successfully.';
                    Logger::getInstance()->logUserAction(
                        'Added translation',
                        'translations',
                        null,
                        null,
                        ['key' => $_POST['key'], 'language' => $language]
                    );
                } else {
                    $error = 'Failed to add translation.';
                }
            } else {
                $error = 'Key and value are required.';
            }
            break;

        case 'import_translations':
            if (!empty($_FILES['translation_file']['tmp_name'])) {
                $language = $_POST['import_language'] ?? $i18n->getCurrentLanguage();
                $jsonContent = file_get_contents($_FILES['translation_file']['tmp_name']);

                if ($i18n->importTranslations($language, $jsonContent)) {
                    $message = 'Translations imported successfully.';
                    Logger::getInstance()->logUserAction(
                        'Imported translations',
                        'translations',
                        null,
                        null,
                        ['language' => $language, 'file' => $_FILES['translation_file']['name']]
                    );
                } else {
                    $error = 'Failed to import translations. Invalid JSON format.';
                }
            } else {
                $error = 'Please select a file to import.';
            }
            break;
    }
}

// Handle AJAX requests for export/delete
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['action'])) {
    header('Content-Type: application/json');

    $action = $_GET['action'];

    switch ($action) {
        case 'delete_translation':
            if (!isset($_GET['key']) || !isset($_GET['language'])) {
                echo json_encode(['success' => false, 'error' => 'Key and language required']);
                exit;
            }

            // For deletion, we need to load and modify the file directly
            $language = $_GET['language'] ?? '';
            // Sanitize language to prevent path traversal
            if (!preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $language)) {
                echo json_encode(['success' => false, 'error' => 'Invalid language code']);
                exit;
            }
            $file = '../languages/' . $language . '.php';
            if (file_exists($file)) {
                $translations = include $file;
                if (is_array($translations) && isset($translations[$_GET['key']])) {
                    unset($translations[$_GET['key']]);
                    $content = "<?php\nreturn " . var_export($translations, true) . ";\n?>";

                    if (file_put_contents($file, $content) !== false) {
                        Logger::getInstance()->logUserAction(
                            'Deleted translation',
                            'translations',
                            null,
                            null,
                            ['key' => $_GET['key'], 'language' => $language]
                        );
                        echo json_encode(['success' => true, 'message' => 'Translation deleted successfully']);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Failed to save changes']);
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'Translation not found']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Language file not found']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit;
}

// Handle export requests
if (isset($_GET['export']) && isset($_GET['language'])) {
    $language = $_GET['language'] ?? '';
    // Sanitize language to prevent path traversal
    if (!preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $language)) {
        die('Invalid language code');
    }
    $jsonData = $i18n->exportTranslations($language);

    if ($jsonData !== false) {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="translations_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $language) . '.json"');
        echo $jsonData;
        exit;
    } else {
        $error = 'Failed to export translations.';
    }
}

// Get data for display
$currentLanguage = $i18n->getCurrentLanguage();
$supportedLanguages = $i18n->getSupportedLanguages();
$languageNames = $i18n->getLanguageNames();
$translationStats = $i18n->getTranslationStats();
$allTranslations = $i18n->getAllTranslations();

$pageTitle = 'Translation Management';
include 'legacy_header.php';
?>

    <?php include '../includes/superadmin_navigation.php'; ?>

    <div class="content">
        <!-- Top Navbar -->
        <?php include '../includes/global_navbar.php'; ?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-language"></i> Translation Management</h2>
                <div>
                    <select id="languageSelector" class="form-select form-select-sm d-inline-block w-auto me-2">
                        <?php foreach ($supportedLanguages as $lang): ?>
                        <option value="<?php echo $lang; ?>" <?php echo ($lang === $currentLanguage) ? 'selected' : ''; ?>>
                            <?php echo $languageNames[$lang] ?? $lang; ?> (<?php echo $lang; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-primary btn-sm" onclick="changeLanguage()">
                        <i class="fas fa-check"></i> Set Language
                    </button>
                </div>
            </div>

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

            <!-- Language Statistics -->
            <div class="row mb-4">
                <?php foreach ($translationStats as $lang => $stats): ?>
                <div class="col-md-3 mb-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="card-title"><?php echo $languageNames[$lang] ?? $lang; ?></h5>
                            <h3 class="text-primary"><?php echo number_format($stats['translations']); ?></h3>
                            <small class="text-muted">translations</small>
                            <div class="mt-2">
                                <span class="badge bg-<?php echo $stats['file_exists'] ? 'success' : 'danger'; ?>">
                                    <?php echo $stats['file_exists'] ? 'File Exists' : 'No File'; ?>
                                </span>
                            </div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="exportTranslations('<?php echo $lang; ?>')">
                                    <i class="fas fa-download"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="viewMissingTranslations('<?php echo $lang; ?>')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Translation Management -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-edit"></i> Translation Editor</h5>
                    <div>
                        <button type="button" class="btn btn-success btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addTranslationModal">
                            <i class="fas fa-plus"></i> Add Translation
                        </button>
                        <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                            <i class="fas fa-upload"></i> Import
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Key</th>
                                    <th>Translation</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allTranslations as $key => $value): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($key); ?></code></td>
                                    <td><?php echo htmlspecialchars($value); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-warning me-1" onclick="editTranslation('<?php echo htmlspecialchars($key); ?>', '<?php echo htmlspecialchars(addslashes($value)); ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteTranslation('<?php echo htmlspecialchars($key); ?>', '<?php echo $currentLanguage; ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Usage Examples -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-code"></i> Usage Examples</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>PHP Code:</h6>
                            <pre class="bg-light p-2"><code><?php echo __('dashboard.welcome'); ?>
// With parameters:
__('validation.min_length', ['min' => 5])
// Echo directly:
_e('save')</code></pre>
                        </div>
                        <div class="col-md-6">
                            <h6>JavaScript (future implementation):</h6>
                            <pre class="bg-light p-2"><code>// Translation keys can be loaded via AJAX
// and used in frontend JavaScript
__('save')</code></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Translation Modal -->
<div class="modal fade" id="addTranslationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Translation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_translation">
                    <div class="mb-3">
                        <label for="translation_key" class="form-label">Translation Key *</label>
                        <input type="text" class="form-control" id="translation_key" name="key" required placeholder="e.g., dashboard.welcome">
                        <small class="form-text text-muted">Use dots to organize keys (e.g., section.subsection.key)</small>
                    </div>
                    <div class="mb-3">
                        <label for="translation_value" class="form-label">Translation Value *</label>
                        <textarea class="form-control" id="translation_value" name="value" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="translation_language" class="form-label">Language</label>
                        <select class="form-control" id="translation_language" name="language">
                            <?php foreach ($supportedLanguages as $lang): ?>
                            <option value="<?php echo $lang; ?>" <?php echo ($lang === $currentLanguage) ? 'selected' : ''; ?>>
                                <?php echo $languageNames[$lang] ?? $lang; ?> (<?php echo $lang; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Translation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Import Translations</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="import_translations">
                    <div class="mb-3">
                        <label for="import_language" class="form-label">Language *</label>
                        <select class="form-control" id="import_language" name="import_language" required>
                            <?php foreach ($supportedLanguages as $lang): ?>
                            <option value="<?php echo $lang; ?>" <?php echo ($lang === $currentLanguage) ? 'selected' : ''; ?>>
                                <?php echo $languageNames[$lang] ?? $lang; ?> (<?php echo $lang; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="translation_file" class="form-label">JSON File *</label>
                        <input type="file" class="form-control" id="translation_file" name="translation_file" accept=".json" required>
                        <small class="form-text text-muted">Upload a JSON file containing translation key-value pairs</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import Translations</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Change language
function changeLanguage() {
    const language = document.getElementById('languageSelector').value;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="set_language"><input type="hidden" name="language" value="' + language + '">';
    document.body.appendChild(form);
    form.submit();
}

// Export translations
function exportTranslations(language) {
    window.open(`translations.php?export=1&language=${language}`, '_blank');
}

// View missing translations
function viewMissingTranslations(language) {
    fetch(`api/translations.php?action=missing&language=${language}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const missing = data.missing;
                const count = Object.keys(missing).length;

                if (count === 0) {
                    alert('No missing translations for this language.');
                } else {
                    let message = `Missing translations for ${language.toUpperCase()}: ${count}\n\n`;
                    message += 'Keys:\n' + Object.keys(missing).join('\n');
                    alert(message);
                }
            } else {
                alert('Error loading missing translations: ' + data.error);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
}

// Edit translation
function editTranslation(key, value) {
    document.getElementById('translation_key').value = key;
    document.getElementById('translation_value').value = value;
    document.getElementById('translation_key').readOnly = true; // Don't allow key changes
    new bootstrap.Modal(document.getElementById('addTranslationModal')).show();
}

// Delete translation
function deleteTranslation(key, language) {
    showConfirmDialog(
        'Delete Translation',
        `Delete translation "${key}" for language ${language.toUpperCase()}?`,
        async () => {
            try {
                const response = await fetch(`translations.php?action=delete_translation&key=${encodeURIComponent(key)}&language=${language}`, {
                    method: 'DELETE'
                });
                const data = await response.json();
                if (data.success) {
                    showAlert('Translation deleted successfully.', 'success');
                    location.reload();
                } else {
                    showAlert('Delete failed: ' + data.error, 'danger');
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'danger');
            }
        }
    );
}

// Reset modal when closed
document.getElementById('addTranslationModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('translation_key').readOnly = false;
    document.getElementById('translation_key').value = '';
    document.getElementById('translation_value').value = '';
});
</script>
    </div>
    <!-- End content div -->

<?php include 'legacy_footer.php'; ?>



