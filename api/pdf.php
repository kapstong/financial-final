<?php
/**
 * ATIERA Financial Management System - PDF Generation API
 * Handles PDF report generation requests
 */

require_once '../includes/auth.php';
require_once '../includes/pdf_generator.php';
require_once '../includes/logger.php';

header('Content-Type: application/json');
session_start();
$auth = new Auth();
ensure_api_auth($method, [
    'GET' => 'reports.view',
]);


// Check if user is logged in
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user']['id'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Generate PDF based on type and parameters
            $type = $_GET['type'] ?? '';
            $pdfGenerator = PDFGenerator::getInstance();

            switch ($type) {
                case 'invoice':
                    if (!isset($_GET['id'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invoice ID is required']);
                        exit;
                    }
                    $pdfGenerator->generateInvoicePDF((int)$_GET['id']);
                    break;

                case 'financial_report':
                    $startDate = $_GET['start_date'] ?? date('Y-m-01');
                    $endDate = $_GET['end_date'] ?? date('Y-m-t');
                    $reportType = $_GET['report_type'] ?? 'summary';
                    $pdfGenerator->generateFinancialReportPDF($startDate, $endDate, $reportType);
                    break;

                case 'customer_statement':
                    if (!isset($_GET['customer_id'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Customer ID is required']);
                        exit;
                    }
                    $startDate = $_GET['start_date'] ?? null;
                    $endDate = $_GET['end_date'] ?? null;
                    $pdfGenerator->generateCustomerStatementPDF((int)$_GET['customer_id'], $startDate, $endDate);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid PDF type specified']);
                    exit;
            }

            // Log the action
            Logger::getInstance()->logUserAction(
                'Generated PDF report',
                'pdf_reports',
                null,
                null,
                ['type' => $type, 'params' => $_GET]
            );

            break;

        case 'POST':
            // Handle PDF generation with custom data
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data || !isset($data['type'])) {
                http_response_code(400);
                echo json_encode(['error' => 'PDF type is required']);
                exit;
            }

            $pdfGenerator = PDFGenerator::getInstance();
            $result = [];

            switch ($data['type']) {
                case 'bulk_invoices':
                    if (!isset($data['invoice_ids']) || !is_array($data['invoice_ids'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invoice IDs array is required']);
                        exit;
                    }

                    $results = [];
                    foreach ($data['invoice_ids'] as $invoiceId) {
                        try {
                            // For bulk generation, we'd typically create a combined PDF
                            // For now, we'll just validate the invoices exist
                            $stmt = $db->prepare("SELECT id FROM invoices WHERE id = ?");
                            $stmt->execute([$invoiceId]);
                            if ($stmt->fetch()) {
                                $results[] = ['id' => $invoiceId, 'status' => 'success'];
                            } else {
                                $results[] = ['id' => $invoiceId, 'status' => 'not_found'];
                            }
                        } catch (Exception $e) {
                            $results[] = ['id' => $invoiceId, 'status' => 'error', 'message' => $e->getMessage()];
                        }
                    }

                    echo json_encode([
                        'success' => true,
                        'message' => 'Bulk PDF generation prepared',
                        'results' => $results
                    ]);
                    break;

                case 'custom_report':
                    // Handle custom report generation
                    if (!isset($data['report_data'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Report data is required']);
                        exit;
                    }

                    // Generate custom PDF with provided data
                    $result = generateCustomReportPDF($pdfGenerator, $data['report_data']);
                    echo json_encode($result);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Unsupported PDF type']);
                    exit;
            }

            // Log the action
            Logger::getInstance()->logUserAction(
                'Generated custom PDF',
                'pdf_reports',
                null,
                null,
                ['type' => $data['type']]
            );

            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    Logger::getInstance()->logDatabaseError('PDF generation API operation', $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'PDF generation failed: ' . $e->getMessage()]);
}

/**
 * Generate custom report PDF
 */
function generateCustomReportPDF($pdfGenerator, $reportData) {
    try {
        // Reset PDF for new document
        $pdfGenerator->resetPDF();

        // Add page
        $pdfGenerator->pdf->AddPage();

        // Title
        $pdfGenerator->pdf->SetFont('helvetica', 'B', 16);
        $pdfGenerator->pdf->Cell(0, 10, $reportData['title'] ?? 'Custom Report', 0, 1, 'C');
        $pdfGenerator->pdf->Ln(10);

        // Content sections
        if (isset($reportData['sections']) && is_array($reportData['sections'])) {
            foreach ($reportData['sections'] as $section) {
                // Section header
                $pdfGenerator->pdf->SetFont('helvetica', 'B', 12);
                $pdfGenerator->pdf->Cell(0, 8, $section['title'] ?? 'Section', 0, 1, 'L');
                $pdfGenerator->pdf->Ln(2);

                // Section content
                $pdfGenerator->pdf->SetFont('helvetica', '', 10);

                if (isset($section['content'])) {
                    if (is_array($section['content'])) {
                        foreach ($section['content'] as $line) {
                            $pdfGenerator->pdf->Cell(0, 6, $line, 0, 1);
                        }
                    } else {
                        $pdfGenerator->pdf->MultiCell(0, 6, $section['content'], 0, 'L');
                    }
                }

                // Section table (if provided)
                if (isset($section['table']) && is_array($section['table'])) {
                    $pdfGenerator->pdf->Ln(5);

                    // Table header
                    $pdfGenerator->pdf->SetFont('helvetica', 'B', 9);
                    $pdfGenerator->pdf->SetFillColor(240, 240, 240);

                    if (isset($section['table']['headers'])) {
                        foreach ($section['table']['headers'] as $header) {
                            $pdfGenerator->pdf->Cell(40, 8, $header, 1, 0, 'C', true);
                        }
                        $pdfGenerator->pdf->Ln();
                    }

                    // Table rows
                    $pdfGenerator->pdf->SetFont('helvetica', '', 8);
                    $pdfGenerator->pdf->SetFillColor(255, 255, 255);

                    if (isset($section['table']['rows'])) {
                        foreach ($section['table']['rows'] as $row) {
                            foreach ($row as $cell) {
                                $pdfGenerator->pdf->Cell(40, 6, $cell, 1, 0, 'L');
                            }
                            $pdfGenerator->pdf->Ln();
                        }
                    }
                }

                $pdfGenerator->pdf->Ln(10);
            }
        }

        // Footer information
        $pdfGenerator->pdf->SetFont('helvetica', 'I', 8);
        $pdfGenerator->pdf->Cell(0, 6, 'Generated by ATIERA Financial Management System on ' . date('M j, Y H:i'), 0, 1, 'C');

        $filename = 'custom_report_' . date('Y-m-d_H-i-s') . '.pdf';

        // Output PDF
        $pdfGenerator->pdf->Output($filename, 'D');
        exit;

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Custom report generation failed: ' . $e->getMessage()
        ];
    }
}
?>


