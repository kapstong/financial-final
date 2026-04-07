<?php
/**
 * ATIERA External API Documentation
 * Comprehensive documentation for the external API
 */

require_once '../includes/auth.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: ../index.php');
    exit;
}

$pageTitle = 'External API Documentation';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>External API Documentation - ATIERA Financial Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/enhanced-ui.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/superadmin_navigation.php'; ?>

    <div class="content">
        <!-- Top Navbar -->
        <?php include '../includes/global_navbar.php'; ?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">
                        <i class="fas fa-book"></i> ATIERA External API Documentation
                    </h4>
                    <p class="card-description">
                        Complete guide for integrating with ATIERA's external API
                    </p>
                </div>

                <div class="card-body">
                    <!-- API Overview -->
                    <div class="mb-5">
                        <h5 class="text-primary">📋 API Overview</h5>
                        <p>The ATIERA External API allows third-party applications to integrate with your financial management system. You can create, read, and update invoices, bills, payments, and access customer/vendor data programmatically.</p>

                        <div class="alert alert-info">
                            <strong>Base URL:</strong> <code><?php echo htmlspecialchars('https://' . $_SERVER['HTTP_HOST'] . '/api/v1/'); ?></code>
                        </div>
                    </div>

                    <!-- Authentication -->
                    <div class="mb-5">
                        <h5 class="text-primary">🔐 Authentication</h5>
                        <p>All API requests require authentication using an API key. Include your API key in the request header:</p>

                        <div class="card bg-light">
                            <div class="card-body">
                                <h6>Header Authentication</h6>
                                <pre><code>Authorization: Bearer your_api_key_here
# OR
X-API-Key: your_api_key_here</code></pre>
                            </div>
                        </div>

                        <div class="card bg-light mt-3">
                            <div class="card-body">
                                <h6>Query Parameter (less secure)</h6>
                                <pre><code>GET /api/v1/invoices
Authorization: Bearer your_api_key_here</code></pre>
                            </div>
                        </div>

                        <p class="mt-3"><strong>Rate Limits:</strong> 100 requests per hour per API key</p>
                    </div>

                    <!-- API Endpoints -->
                    <div class="mb-5">
                        <h5 class="text-primary">📡 API Endpoints</h5>

                        <!-- Invoices -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0">📄 Invoices</h6>
                            </div>
                            <div class="card-body">
                                <h7>GET /api/v1/invoices</h7>
                                <p>Retrieve invoices with optional filters</p>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Parameter</th>
                                                <th>Type</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>id</td><td>integer</td><td>Get specific invoice</td></tr>
                                            <tr><td>status</td><td>string</td><td>Filter by status (draft, sent, approved, paid, overdue)</td></tr>
                                            <tr><td>customer_id</td><td>integer</td><td>Filter by customer</td></tr>
                                            <tr><td>date_from</td><td>date</td><td>Filter invoices from date</td></tr>
                                            <tr><td>date_to</td><td>date</td><td>Filter invoices to date</td></tr>
                                            <tr><td>limit</td><td>integer</td><td>Limit results (default: 50, max: 100)</td></tr>
                                            <tr><td>offset</td><td>integer</td><td>Pagination offset</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <h7 class="mt-3">POST /api/v1/invoices</h7>
                                <p>Create a new invoice</p>
                                <pre><code>{
  "customer_id": 123,
  "invoice_date": "2025-01-15",
  "due_date": "2025-02-14",
  "items": [
    {
      "description": "Web Development Services",
      "quantity": 40,
      "unit_price": 125.00,
      "account_id": 456
    }
  ],
  "tax_rate": 12.00,
  "status": "draft",
  "notes": "Monthly web development work"
}</code></pre>

                                <h7 class="mt-3">PUT /api/v1/invoices?id=123</h7>
                                <p>Update an existing invoice</p>
                            </div>
                        </div>

                        <!-- Customers -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0">👥 Customers</h6>
                            </div>
                            <div class="card-body">
                                <h7>GET /api/v1/customers</h7>
                                <p>Retrieve customers</p>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Parameter</th>
                                                <th>Type</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>id</td><td>integer</td><td>Get specific customer</td></tr>
                                            <tr><td>search</td><td>string</td><td>Search by company name or code</td></tr>
                                            <tr><td>limit</td><td>integer</td><td>Limit results</td></tr>
                                            <tr><td>offset</td><td>integer</td><td>Pagination offset</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Vendors -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0">🏢 Vendors</h6>
                            </div>
                            <div class="card-body">
                                <h7>GET /api/v1/vendors</h7>
                                <p>Retrieve vendors</p>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Parameter</th>
                                                <th>Type</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>id</td><td>integer</td><td>Get specific vendor</td></tr>
                                            <tr><td>search</td><td>string</td><td>Search by company name or code</td></tr>
                                            <tr><td>limit</td><td>integer</td><td>Limit results</td></tr>
                                            <tr><td>offset</td><td>integer</td><td>Pagination offset</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <!-- Department Data APIs -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0">Department Data APIs</h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <strong>Base URL:</strong> <code><?php echo htmlspecialchars('https://' . $_SERVER['HTTP_HOST'] . '/api/'); ?></code>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Module</th>
                                                <th>Endpoint</th>
                                                <th>Purpose</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>Hotel</td><td>GET /api/hotel/bookings</td><td>Revenue tracking and forecasting</td></tr>
                                            <tr><td>Hotel</td><td>GET /api/hotel/payments</td><td>Revenue collection validation</td></tr>
                                            <tr><td>Hotel</td><td>GET /api/hotel/pos-sales</td><td>Daily sales reporting</td></tr>
                                            <tr><td>Hotel</td><td>GET /api/hotel/maintenance-costs</td><td>Operational expense tracking</td></tr>
                                            <tr><td>Restaurant</td><td>GET /api/restaurant/pos-sales</td><td>Restaurant revenue monitoring</td></tr>
                                            <tr><td>Restaurant</td><td>GET /api/restaurant/payments</td><td>Restaurant revenue collection</td></tr>
                                            <tr><td>Restaurant</td><td>GET /api/restaurant/inventory-usage</td><td>COGS computation</td></tr>
                                            <tr><td>HR</td><td>GET /api/hr/recruitment-costs</td><td>Hiring cost tracking</td></tr>
                                            <tr><td>HR</td><td>GET /api/hr/training-expenses</td><td>Employee development expenses</td></tr>
                                            <tr><td>HR</td><td>GET /api/hr/claims</td><td>Employee reimbursements and claims (HR3)</td></tr>
                                            <tr><td>HR</td><td>GET /api/hr/payroll</td><td>Payroll expenses (HR4)</td></tr>
                                            <tr><td>Logistics</td><td>GET /api/logistics/procurement</td><td>Procurement expenses (Logistics 1)</td></tr>
                                            <tr><td>Logistics</td><td>GET /api/logistics/trip-costs</td><td>Transportation costs (Logistics 2)</td></tr>
                                            <tr><td>Admin</td><td>GET /api/admin/facility-costs</td><td>Facilities reservation costs</td></tr>
                                            <tr><td>Admin</td><td>GET /api/admin/legal-expenses</td><td>Legal and document expenses</td></tr>
                                            <tr><td>Financial</td><td>GET /api/financial/revenue-summary</td><td>Consolidated revenue summary</td></tr>
                                            <tr><td>Financial</td><td>GET /api/financial/expense-summary</td><td>Consolidated expense summary</td></tr>
                                            <tr><td>Financial</td><td>GET /api/financial/profit-loss</td><td>Profit and loss summary</td></tr>
                                            <tr><td>Financial</td><td>GET /api/financial/financial-forecast</td><td>Forecasted revenue, expenses, and profit</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <p class="text-muted mb-0">All endpoints support optional <code>date_from</code> and <code>date_to</code> query parameters where applicable.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Response Format -->
                    <div class="mb-5">
                        <h5 class="text-primary">📤 Response Format</h5>
                        <p>All API responses follow a consistent JSON format:</p>

                        <div class="card bg-light">
                            <div class="card-body">
                                <h6>Success Response</h6>
                                <pre><code>{
  "success": true,
  "data": { ... },
  "pagination": {
    "total": 150,
    "limit": 50,
    "offset": 0,
    "has_more": true
  }
}</code></pre>
                            </div>
                        </div>

                        <div class="card bg-light mt-3">
                            <div class="card-body">
                                <h6>Error Response</h6>
                                <pre><code>{
  "success": false,
  "error": "Error message",
  "timestamp": "2025-01-15T10:30:00Z"
}</code></pre>
                            </div>
                        </div>
                    </div>

                    <!-- Error Codes -->
                    <div class="mb-5">
                        <h5 class="text-primary">⚠️ HTTP Status Codes</h5>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td>200</td><td>Success</td></tr>
                                    <tr><td>400</td><td>Bad Request - Invalid data</td></tr>
                                    <tr><td>401</td><td>Unauthorized - Invalid API key</td></tr>
                                    <tr><td>403</td><td>Forbidden - Insufficient permissions</td></tr>
                                    <tr><td>404</td><td>Not Found - Resource doesn't exist</td></tr>
                                    <tr><td>429</td><td>Too Many Requests - Rate limit exceeded</td></tr>
                                    <tr><td>500</td><td>Internal Server Error</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Code Examples -->
                    <div class="mb-5">
                        <h5 class="text-primary">💻 Code Examples</h5>

                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">cURL Examples</h6>
                            </div>
                            <div class="card-body">
                                <h7>Get Invoices</h7>
                                <pre><code>curl -H "Authorization: Bearer your_api_key" \
     -H "Content-Type: application/json" \
     https://your-domain.com/api/v1/invoices</code></pre>

                                <h7>Create Invoice</h7>
                                <pre><code>curl -X POST \
     -H "Authorization: Bearer your_api_key" \
     -H "Content-Type: application/json" \
     -d '{
       "customer_id": 123,
       "invoice_date": "2025-01-15",
       "due_date": "2025-02-14",
       "items": [
         {
           "description": "Service",
           "quantity": 1,
           "unit_price": 100.00
         }
       ]
     }' \
     https://your-domain.com/api/v1/invoices</code></pre>
                            </div>
                        </div>

                        <div class="card mt-3">
                            <div class="card-header">
                                <h6 class="mb-0">JavaScript (Node.js) Example</h6>
                            </div>
                            <div class="card-body">
                                <pre><code>const axios = require('axios');

const apiClient = axios.create({
  baseURL: 'https://your-domain.com/api/v1/',
  headers: {
    'Authorization': 'Bearer your_api_key',
    'Content-Type': 'application/json'
  }
});

// Get invoices
apiClient.get('invoices')
  .then(response => console.log(response.data))
  .catch(error => console.error(error.response.data));

// Create invoice
apiClient.post('invoices', {
  customer_id: 123,
  invoice_date: '2025-01-15',
  due_date: '2025-02-14',
  items: [
    {
      description: 'Web Development',
      quantity: 40,
      unit_price: 125.00
    }
  ]
})
.then(response => console.log(response.data))
.catch(error => console.error(error.response.data));</code></pre>
                            </div>
                        </div>
                    </div>

                    <!-- Webhooks -->
                    <div class="mb-5">
                        <h5 class="text-primary">🪝 Webhooks (Coming Soon)</h5>
                        <p>Receive real-time notifications when important events occur in your ATIERA system.</p>

                        <div class="alert alert-info">
                            <strong>Supported Events:</strong> invoice.created, invoice.updated, payment.received, bill.created, bill.paid
                        </div>

                        <p>Configure webhooks in the <a href="webhooks.php">Webhooks Management</a> section.</p>
                    </div>

                    <!-- Getting Started -->
                    <div class="mb-5">
                        <h5 class="text-primary">🚀 Getting Started</h5>
                        <ol>
                            <li><strong>Create an API Client:</strong> Go to <a href="api_clients.php">API Clients</a> and create a new client to get your API key.</li>
                            <li><strong>Test the API:</strong> Use the examples above to test basic functionality.</li>
                            <li><strong>Handle Errors:</strong> Always check the <code>success</code> field in responses and handle errors appropriately.</li>
                            <li><strong>Rate Limits:</strong> Monitor your API usage and implement retry logic with exponential backoff.</li>
                            <li><strong>Security:</strong> Keep your API keys secure and rotate them regularly.</li>
                        </ol>
                    </div>

                    <!-- Support -->
                    <div class="alert alert-success">
                        <h6 class="alert-heading">📞 Need Help?</h6>
                        <p>If you encounter any issues or need assistance with API integration, please contact our support team or refer to the system logs for detailed error information.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
pre {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    padding: 1rem;
}

code {
    background-color: #f1f3f4;
    padding: 0.125rem 0.25rem;
    border-radius: 0.25rem;
    font-size: 0.875em;
}
</style>

    </div>
    <!-- End content div -->

    <?php include '../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../includes/inactivity_timeout.js"></script>
<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>



