<?php
/**
 * ATIERA Financial Management System - File Upload Manager
 * Handles secure file uploads, storage, and management
 */

class FileUploadManager {
    private static $instance = null;
    private $db;
    private $uploadDir;
    private $maxFileSize;
    private $allowedTypes;
    private $allowedExtensions;

    private function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->initializeSettings();
        $this->ensureDirectories();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initializeSettings() {
        // Base upload directory
        $this->uploadDir = __DIR__ . '/../uploads/';

        // Maximum file size (10MB default)
        $this->maxFileSize = getenv('MAX_FILE_SIZE') ?: 10 * 1024 * 1024;

        // Allowed MIME types
        $this->allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'text/plain'
        ];

        // Allowed file extensions
        $this->allowedExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'pdf',
            'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'
        ];
    }

    private function ensureDirectories() {
        $directories = [
            $this->uploadDir,
            $this->uploadDir . 'invoices/',
            $this->uploadDir . 'bills/',
            $this->uploadDir . 'receipts/',
            $this->uploadDir . 'documents/',
            $this->uploadDir . 'temp/'
        ];

        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Upload a file
     */
    public function uploadFile($file, $category = 'documents', $referenceId = null, $referenceType = null) {
        try {
            // Validate file
            $this->validateFile($file);

            // Generate unique filename
            $fileName = $this->generateUniqueFileName($file['name']);
            $categoryDir = $this->uploadDir . $category . '/';
            $filePath = $categoryDir . $fileName;

            // Ensure category directory exists
            if (!file_exists($categoryDir)) {
                mkdir($categoryDir, 0755, true);
            }

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception('Failed to move uploaded file');
            }

            // Save file record to database
            $fileId = $this->saveFileRecord([
                'original_name' => $file['name'],
                'file_name' => $fileName,
                'file_path' => str_replace(__DIR__ . '/../', '', $filePath),
                'file_size' => $file['size'],
                'mime_type' => $file['type'],
                'category' => $category,
                'reference_id' => $referenceId,
                'reference_type' => $referenceType,
                'uploaded_by' => $_SESSION['user']['id'] ?? null
            ]);

            return [
                'success' => true,
                'file_id' => $fileId,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_url' => $this->getFileUrl($fileId)
            ];

        } catch (Exception $e) {
            error_log("File upload error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Upload multiple files
     */
    public function uploadMultipleFiles($files, $category = 'documents', $referenceId = null, $referenceType = null) {
        $results = [];
        $errors = [];

        foreach ($files as $file) {
            $result = $this->uploadFile($file, $category, $referenceId, $referenceType);
            if ($result['success']) {
                $results[] = $result;
            } else {
                $errors[] = $result['error'];
            }
        }

        return [
            'success' => count($results) > 0,
            'uploaded' => $results,
            'errors' => $errors,
            'total_uploaded' => count($results),
            'total_errors' => count($errors)
        ];
    }

    /**
     * Validate uploaded file
     */
    private function validateFile($file) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception($this->getUploadErrorMessage($file['error']));
        }

        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            throw new Exception('File size exceeds maximum allowed size of ' . $this->formatFileSize($this->maxFileSize));
        }

        // Check MIME type
        if (!in_array($file['type'], $this->allowedTypes)) {
            throw new Exception('File type not allowed. Allowed types: ' . implode(', ', $this->allowedTypes));
        }

        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions)) {
            throw new Exception('File extension not allowed. Allowed extensions: ' . implode(', ', $this->allowedExtensions));
        }

        // Additional security checks
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Invalid file upload');
        }

        // Check for malicious content (basic check)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($mimeType !== $file['type']) {
            throw new Exception('File type mismatch detected');
        }
    }

    /**
     * Generate unique filename
     */
    private function generateUniqueFileName($originalName) {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $basename = pathinfo($originalName, PATHINFO_FILENAME);

        // Clean filename
        $basename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $basename);
        $basename = substr($basename, 0, 50); // Limit length

        do {
            $uniqueId = uniqid() . '_' . rand(1000, 9999);
            $fileName = $basename . '_' . $uniqueId . '.' . $extension;
        } while (file_exists($this->uploadDir . $fileName));

        return $fileName;
    }

    /**
     * Save file record to database
     */
    private function saveFileRecord($fileData) {
        $stmt = $this->db->prepare("
            INSERT INTO uploaded_files
            (original_name, file_name, file_path, file_size, mime_type, category, reference_id, reference_type, uploaded_by, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $fileData['original_name'],
            $fileData['file_name'],
            $fileData['file_path'],
            $fileData['file_size'],
            $fileData['mime_type'],
            $fileData['category'],
            $fileData['reference_id'],
            $fileData['reference_type'],
            $fileData['uploaded_by']
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Get file information
     */
    public function getFile($fileId) {
        $stmt = $this->db->prepare("
            SELECT * FROM uploaded_files WHERE id = ?
        ");
        $stmt->execute([$fileId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get files by reference
     */
    public function getFilesByReference($referenceId, $referenceType) {
        $stmt = $this->db->prepare("
            SELECT * FROM uploaded_files
            WHERE reference_id = ? AND reference_type = ?
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute([$referenceId, $referenceType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Download file
     */
    public function downloadFile($fileId) {
        $file = $this->getFile($fileId);

        if (!$file) {
            throw new Exception('File not found');
        }

        $filePath = __DIR__ . '/../' . $file['file_path'];

        if (!file_exists($filePath)) {
            throw new Exception('File does not exist on disk');
        }

        // Set headers for download
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
        header('Content-Length: ' . $file['file_size']);
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        // Output file
        readfile($filePath);
        exit;
    }

    /**
     * Delete file
     */
    public function deleteFile($fileId) {
        $file = $this->getFile($fileId);

        if (!$file) {
            throw new Exception('File not found');
        }

        // Delete physical file
        $filePath = __DIR__ . '/../' . $file['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete database record
        $stmt = $this->db->prepare("DELETE FROM uploaded_files WHERE id = ?");
        $stmt->execute([$fileId]);

        return ['success' => true, 'message' => 'File deleted successfully'];
    }

    /**
     * Get file URL
     */
    public function getFileUrl($fileId) {
        return "/download.php?file_id=" . $fileId;
    }

    /**
     * Get upload error message
     */
    private function getUploadErrorMessage($errorCode) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];

        return $messages[$errorCode] ?? 'Unknown upload error';
    }

    /**
     * Format file size
     */
    private function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Clean up old temporary files
     */
    public function cleanupTempFiles($maxAge = 3600) { // 1 hour default
        $tempDir = $this->uploadDir . 'temp/';
        if (!file_exists($tempDir)) return;

        $files = glob($tempDir . '*');
        $now = time();

        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
                unlink($file);
            }
        }
    }

    /**
     * Get storage statistics
     */
    public function getStorageStats() {
        $totalSize = 0;
        $fileCount = 0;

        $stmt = $this->db->query("SELECT file_size FROM uploaded_files");
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($files as $file) {
            $totalSize += $file['file_size'];
            $fileCount++;
        }

        return [
            'total_files' => $fileCount,
            'total_size' => $totalSize,
            'total_size_formatted' => $this->formatFileSize($totalSize),
            'max_file_size' => $this->maxFileSize,
            'max_file_size_formatted' => $this->formatFileSize($this->maxFileSize)
        ];
    }
}
?>

