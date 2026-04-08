<?php
header('Content-Type: application/javascript');
?>

        // Process Payment Functionality
        async function processPayment() {
            const formData = {
                action: 'process_payment',
                payee: document.getElementById('processPayee').value,
                payment_date: document.getElementById('paymentDate').value,
                payment_type: document.getElementById('paymentType').value,
                payment_method: document.getElementById('paymentMethodModal').value,
                amount: document.getElementById('processAmount').value,
                reference_number: document.getElementById('paymentReference').value,
                description: document.getElementById('processDescription').value
            };

            // Validate required fields
            if (!formData.payee || !formData.payment_date || !formData.payment_method || !formData.amount) {
                showAlert('Please fill in all required fields', 'warning');
                return;
            }

            try {
                const response = await fetch('../api/disbursements.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    credentials: 'include',
                    body: JSON.stringify(formData)
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Payment processed successfully!', 'success');
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('processPaymentModal'));
                    if (modal) modal.hide();
                    // Reset form
                    document.getElementById('processPaymentForm').reset();
                    // Reload disbursements
                    loadDisbursements();
                } else {
                    showAlert('Error: ' + result.error, 'danger');
                }
            } catch (error) {
                showAlert('An error occurred while processing payment', 'danger');
            }
        }

        // Audit Trail Functionality
        async function loadAuditTrail() {
            try {
                const response = await fetch('../api/audit.php?scope=disbursements', {
                    credentials: 'include'
                });
                const auditLogs = await response.json();

                const tbody = document.getElementById('auditTableBody');
                if (!tbody) return;

                if (auditLogs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center">No audit logs found</td></tr>';
                    return;
                }

                tbody.innerHTML = auditLogs.map(log => {
                    const actionLabel = log.action_label || log.action;
                    // Prefer explicit reference number, then disbursement number, then record id, then try JSON fields, then parse description
                    let disbRef = '';
                    if (log.reference_number) disbRef = log.reference_number;
                    if (!disbRef && log.disbursement_ref) disbRef = log.disbursement_ref;
                    if (!disbRef && log.disbursement_number) disbRef = log.disbursement_number;
                    if (!disbRef && log.record_id) disbRef = log.record_id;

                    if (!disbRef) {
                        try {
                            const nv = log.new_values ? JSON.parse(log.new_values) : null;
                            const ov = log.old_values ? JSON.parse(log.old_values) : null;
                            disbRef = nv?.disbursement_number || ov?.disbursement_number || nv?.disbursement_no || ov?.disbursement_no || '';
                        } catch (e) {
                            // ignore malformed JSON
                        }
                    }

                    if (!disbRef && log.action_description) {
                        const m = String(log.action_description).match(/DISB-\d{8}-\d{3}|DISB-\d+|\bID\s*(\d+)\b|(\d{1,6})/i);
                        if (m) {
                            disbRef = m[0];
                            if (/^ID\s*/i.test(disbRef) && m[1]) disbRef = m[1];
                        }
                    }

                    if (!disbRef) disbRef = 'N/A';
                    const displayUser = log.display_user || log.full_name || log.username || 'System';
                    return '<tr><td>' + escapeHtml(log.formatted_date) + '</td><td>' + escapeHtml(displayUser) + '</td><td><span class="badge bg-info">' + escapeHtml(actionLabel) + '</span></td><td>' + escapeHtml(disbRef) + '</td><td>' + escapeHtml(log.action_description || '') + '</td></tr>';
                }).join('');

            } catch (error) {
                const tbody = document.getElementById('auditTableBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Error loading audit trail</td></tr>';
                }
            }
        }

        // Reports and Analytics
        async function loadDisbursementReports() {
            try {
                // Load disbursement summary
                const response = await fetch('../api/disbursements.php', {
                    credentials: 'include'
                });
                const disbursements = await response.json();

                // Calculate monthly totals for the chart
                const monthlyData = {};
                disbursements.forEach(d => {
                    const month = new Date(d.disbursement_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short' });
                    if (!monthlyData[month]) {
                        monthlyData[month] = 0;
                    }
                    monthlyData[month] += parseFloat(d.amount);
                });

                // Create chart - destroy existing chart if it exists
                const ctx = document.getElementById('cashFlowChart');
                if (ctx && window.Chart) {
                    // Destroy existing chart if it exists to prevent "Canvas is already in use" error
                    if (Chart.getChart(ctx)) {
                        Chart.getChart(ctx).destroy();
                    }

                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: Object.keys(monthlyData),
                            datasets: [{
                                label: 'Monthly Disbursements',
                                data: Object.values(monthlyData),
                                borderColor: '#1e2936',
                                backgroundColor: 'rgba(30, 41, 54, 0.1)',
                                tension: 0.3,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Cash Flow Outflows - Disbursements'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '₱' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // Update summary cards
                const totalDisbursements = disbursements.length;
                const totalAmount = disbursements.reduce((sum, d) => sum + parseFloat(d.amount), 0);
                const pendingCount = disbursements.filter(d => d.status === 'pending').length;

                document.getElementById('totalDisbursementsCount').textContent = totalDisbursements;
                document.getElementById('totalDisbursementsAmount').innerHTML = '<span class="amount-cell">₱' + totalAmount.toLocaleString() + '</span>';
                document.getElementById('pendingDisbursementsCount').textContent = pendingCount;

            } catch (error) {
                // Reports loading error handled
            }
        }

        // Voucher Management
        async function loadVouchers(disbursementId = null) {
            try {
                const url = disbursementId ?
                    `../api/disbursements.php?action=get_vouchers&disbursement_id=${disbursementId}` :
                    '../api/disbursements.php?action=get_vouchers';

                const response = await fetch(url, {
                    credentials: 'include'
                });
                const vouchers = await response.json();

                const tbody = disbursementId ?
                    document.querySelector('#vouchersTable tbody') :
                    document.querySelector('#vouchersTable tbody');

                if (tbody && vouchers.length > 0) {
                    tbody.innerHTML = vouchers.map(v =>
                        '<tr><td>' + escapeHtml(v.file_name) + '</td><td>Voucher</td><td>' + escapeHtml(v.disbursement_number || 'N/A') + '</td><td>' + formatDate(v.uploaded_at) + '</td><td><i class="fas fa-paperclip"></i> ' + escapeHtml(v.original_name) + '</td><td><button class="btn btn-sm btn-outline-primary">View</button><button class="btn btn-sm btn-outline-secondary">Download</button></td></tr>'
                    ).join('');
                }
            } catch (error) {
                // Voucher loading error handled silently
            }
        }

    // Additional functions for disbursements module

    function showFilters() {
        const filtersSection = document.getElementById('filtersSection');
        filtersSection.style.display = filtersSection.style.display === 'none' ? 'block' : 'none';
    }

    async function applyFilters() {
        currentFilters = {
            status: document.getElementById('filterStatus').value,
            date_from: document.getElementById('filterDateFrom').value,
            date_to: document.getElementById('filterDateTo').value
        };

        Object.keys(currentFilters).forEach(key => {
            if (!currentFilters[key]) {
                delete currentFilters[key];
            }
        });

        await loadDisbursements();
    }

    function clearFilters() {
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        currentFilters = {};
        loadDisbursements();
    }

    function showAddDisbursementModal() {
        document.getElementById('disbursementForm').reset();
        document.getElementById('disbursementId').value = '';
        document.getElementById('modalTitle').textContent = 'Add Disbursement';
        populateVendorDropdown();

        const modal = new bootstrap.Modal(document.getElementById('disbursementModal'));
        modal.show();
    }

    // View disbursement details
    async function viewDisbursement(id) {
        try {
            const response = await fetch(`../api/disbursements.php?id=${id}`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.error) {
                showAlert('Error loading disbursement: ' + data.error, 'danger');
                return;
            }

            showDisbursementDetails(data);
        } catch (error) {
            showAlert('Error loading disbursement details', 'danger');
        }
    }

    // Edit disbursement
    async function editDisbursement(id) {
        try {
            const response = await fetch(`../api/disbursements.php?id=${id}`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.error) {
                showAlert('Error loading disbursement: ' + data.error, 'danger');
                return;
            }

            populateDisbursementForm(data);
            document.getElementById('modalTitle').textContent = 'Edit Disbursement';

            const modal = new bootstrap.Modal(document.getElementById('disbursementModal'));
            modal.show();
        } catch (error) {
            showAlert('Error loading disbursement for editing', 'danger');
        }
    }

    // Populate form with disbursement data
    function populateDisbursementForm(data) {
        document.getElementById('disbursementId').value = data.id;
        document.getElementById('vendorId').value = data.vendor_id || '';
        document.getElementById('amount').value = data.amount;
        document.getElementById('paymentMethod').value = data.payment_method;
        document.getElementById('referenceNumber').value = data.reference_number || '';
        document.getElementById('disbursementDate').value = data.disbursement_date;
        document.getElementById('notes').value = data.purpose || data.notes || '';
        document.getElementById('billId').value = data.bill_id || '';
    }

    // Delete disbursement
    async function deleteDisbursement(id) {
        showConfirmDialog(
            'Delete Disbursement',
            'Are you sure you want to delete this disbursement?',
            async () => {
        try {
            const response = await fetch(`../api/disbursements.php?id=${id}`, {
                method: 'DELETE',
                credentials: 'include'
            });
            const data = await response.json();

            if (data.error) {
                showAlert('Error deleting disbursement: ' + data.error, 'danger');
                return;
            }

            showAlert('Disbursement deleted successfully', 'success');
            loadDisbursements();
        } catch (error) {
            console.error('Error deleting disbursement:', error);
            showAlert('Error deleting disbursement', 'danger');
        }
        }
    );
    }

    // Save disbursement
    async function saveDisbursement() {
        const formData = new FormData(document.getElementById('disbursementForm'));
        const data = Object.fromEntries(formData);

        // Map fields correctly
        const disbursementData = {
            payee: document.getElementById('vendorId').options[document.getElementById('vendorId').selectedIndex]?.text || '',
            disbursement_date: data.disbursement_date,
            amount: data.amount,
            payment_method: data.payment_method,
            reference_number: data.reference_number,
            purpose: data.notes,
            bill_id: data.bill_id,
            disbursement_id: data.disbursement_id // For updates
        };

        // Validate required fields
        if (!disbursementData.payee || !disbursementData.amount || !disbursementData.payment_method || !disbursementData.disbursement_date) {
            showAlert('Please fill in all required fields', 'warning');
            return;
        }

        try {
            const method = disbursementData.disbursement_id ? 'PUT' : 'POST';
            const url = disbursementData.disbursement_id
                ? `../api/disbursements.php?id=${disbursementData.disbursement_id}`
                : '../api/disbursements.php';

            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(disbursementData)
            });

            const result = await response.json();

            if (result.success || result.message) {
                showAlert(result.message || 'Disbursement saved successfully', 'success');
                const modal = bootstrap.Modal.getInstance(document.getElementById('disbursementModal'));
                if (modal) modal.hide();
                loadDisbursements();
            } else {
                showAlert('Error: ' + (result.error || 'Unknown error'), 'danger');
            }
        } catch (error) {
            console.error('Error saving disbursement:', error);
            showAlert('An error occurred while saving disbursement', 'danger');
        }
    }

    // Show disbursement details modal
    function showDisbursementDetails(data) {
        const detailsHtml = `
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Reference:</strong> ${escapeHtml(data.disbursement_number || 'N/A')}</p>
                    <p><strong>Payee:</strong> ${escapeHtml(data.payee || 'N/A')}</p>
                    <p><strong>Amount:</strong> <span class="amount-cell">₱${parseFloat(data.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></p>
                    <p><strong>Payment Method:</strong> ${escapeHtml(data.payment_method || 'N/A')}</p>
                </div>
                <div class="col-md-6">
                    <p><strong>Date:</strong> ${formatDate(data.disbursement_date)}</p>
                    <p><strong>Reference Number:</strong> ${escapeHtml(data.reference_number || 'N/A')}</p>
                    <p><strong>Status:</strong> ${getStatusBadge(data.status)}</p>
                    <p><strong>Notes:</strong> ${escapeHtml(data.purpose || data.notes || 'N/A')}</p>
                </div>
            </div>
        `;

        // Create or update details modal
        let detailsModal = document.getElementById('disbursementDetailsModal');
        if (!detailsModal) {
            detailsModal = document.createElement('div');
            detailsModal.className = 'modal fade';
            detailsModal.id = 'disbursementDetailsModal';
            detailsModal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Disbursement Details</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="disbursementDetailsContent"></div>
                    </div>
                </div>
            `;
            document.body.appendChild(detailsModal);
        }

        document.getElementById('disbursementDetailsContent').innerHTML = detailsHtml;
        const modal = new bootstrap.Modal(detailsModal);
        modal.show();
    }

        // Global variables (guarded to allow safe reload)
        var currentFilters = window.currentFilters = window.currentFilters || {};

        // Helper function to show loading in table
        function showTableLoading(tbodyId, loadingText) {
            const tbody = document.getElementById(tbodyId);
            if (!tbody) return;
            const table = tbody.closest('table');
            const thCount = table ? table.querySelectorAll('thead th').length : 1;
            tbody.innerHTML = `<tr><td colspan="${thCount}" class="text-center"><div class="loading"><i class="fas fa-spinner fa-spin me-2"></i>${loadingText}</div></td></tr>`;
        }

        // Helper function to show error in table
        function showTableError(tbodyId, errorText) {
            const tbody = document.getElementById(tbodyId);
            if (!tbody) return;
            const table = tbody.closest('table');
            const thCount = table ? table.querySelectorAll('thead th').length : 1;
            tbody.innerHTML = `<tr><td colspan="${thCount}" class="text-center text-muted">${errorText}</td></tr>`;
        }

        // Load disbursements from API
        async function loadDisbursements() {
            showTableLoading('disbursementsTableBody', 'Loading disbursements...');

            try {
                const params = new URLSearchParams(currentFilters);
                const response = await fetch(`../api/disbursements.php?${params}`, {
                    credentials: 'include'
                });

                if (!response.ok) {
                    try {
                        const errorData = await response.text();
                        throw new Error(`HTTP ${response.status}: ${errorData || response.statusText}`);
                    } catch (e) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                }

                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const errorText = await response.text();
                    throw new Error(errorText || 'Server returned an unexpected response format');
                }

                const data = await response.json();

                if (response.ok) {
                    renderDisbursementsTable(data);
                } else {
                    if (data.error) {
                        showTableError('disbursementsTableBody', 'Error loading disbursements. Please try again.');
                        showAlert('Error loading disbursements: ' + data.error, 'danger');
                    } else {
                        throw new Error('API returned an error');
                    }
                }
            } catch (error) {
                console.error('Error loading disbursements:', error);
                showTableError('disbursementsTableBody', 'Error loading disbursements. Please try again.');
                showAlert('Error loading disbursements. Please try again.', 'warning');
            }
        }

        // Load vendors for dropdown
        async function loadVendors() {
            try {
                const response = await fetch('../api/vendors.php', {
                    credentials: 'include'
                });

                if (!response.ok) {
                    try {
                        const errorData = await response.text();
                        throw new Error(`HTTP ${response.status}: ${errorData || response.statusText}`);
                    } catch (e) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                }

                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const errorText = await response.text();
                    throw new Error(errorText || 'Server returned an unexpected response format');
                }

                const data = await response.json();

                if (response.ok) {
                    window.vendors = data;
                    populateVendorDropdown();
                } else {
                    if (data.error) {
                        showAlert('Error loading vendors: ' + data.error, 'danger');
                    } else {
                        throw new Error('API returned an error');
                    }
                }
            } catch (error) {
                showAlert('Error loading vendors. Please try again.', 'warning');
            }
        }

        // Populate vendor dropdown
        function populateVendorDropdown() {
            const vendorSelect = document.getElementById('vendorId');
            vendorSelect.innerHTML = '<option value="">Select Vendor</option>';

            if (window.vendors) {
                window.vendors.forEach(vendor => {
                    vendorSelect.innerHTML += `<option value="${vendor.id}">${escapeHtml(vendor.company_name)}</option>`;
                });
            }
        }

        // Connect payment processing buttons
        document.addEventListener('DOMContentLoaded', function() {
            // Add click handlers for payment method buttons
            document.querySelectorAll('#processing .btn-outline-primary').forEach(btn => {
                btn.addEventListener('click', function() {
                    const method = this.textContent.toLowerCase().replace(' ', '_');
                    document.getElementById('paymentType').value = method;
                    // Open the process payment modal
                    const modal = new bootstrap.Modal(document.getElementById('processPaymentModal'));
                    modal.show();
                });
            });

            // Connect process payment button and setup access control
            const processBtn = document.querySelector('#processPaymentModal .btn-primary');
            if (processBtn) {
                processBtn.addEventListener('click', processPayment);
            }

            // OCP queue event handlers (must be here to not interfere with other DOMContentLoaded listeners)
            // Google Analytics tracking removed - gtag not available
        });

        // Initialize department access on DOM ready
        document.addEventListener('DOMContentLoaded', function() {
            setupDepartmentAccess();
        });


        // Department-based access control setup
        function setupDepartmentAccess() {
            // Get user department from PHP session via a hidden element
            const userDepartment = '<?php echo $_SESSION["user"]["department"] ?? ""; ?>';
            const userRole = '<?php echo $_SESSION["user"]["role"] ?? ""; ?>';

            // Define permissions (same as PHP backend)
            const deptPermissions = {
                'finance': ['view', 'create', 'edit', 'delete', 'process_claims'],
                'accounting': ['view', 'create', 'edit', 'delete'],
                'hr': ['view', 'process_claims', 'upload_vouchers'],
                'procurement': ['view', 'create', 'upload_vouchers'],
                'admin': ['view', 'create', 'edit', 'delete', 'process_claims', 'configure'],
                'super_admin': ['view', 'create', 'edit', 'delete', 'process_claims', 'configure']
            };

            // Get user perms or fallback to admin role
            const userPerms = deptPermissions[userDepartment] || [];
            // TREAT EMPTY/MISSING SESSION AS ADMIN (Development mode)
            const hasAdminRole = (userRole === 'admin') || (userRole === 'super_admin') || (userRole === '');

            // Define tab permissions
            const tabPermissions = {
                'records-tab': 'view',           // Disbursement Records - basic view permission
                'processing-tab': 'create',      // Payment Processing - requires create permission
                'claims-tab': 'process_claims',  // Claims Processing - requires process_claims permission
                'logistics-tab': 'view',         // Logistics Sync - shared expense import workspace
                'vouchers-tab': 'upload_vouchers', // Vouchers - requires upload permission
                'reports-tab': 'view',           // Reports - basic view permission
                'audit-tab': 'delete'            // Audit Trail - requires delete permission
            };

            // Hide tabs based on permissions - Show ALL by default for development/sessions issues
            Object.keys(tabPermissions).forEach(tabId => {
                const requiredPerm = tabPermissions[tabId];
                const tabElement = document.getElementById(tabId);

                if (tabElement) {
                    // Show tab if user has permission OR is admin OR session is empty (dev mode)
                    if (userPerms.includes(requiredPerm) || hasAdminRole) {
                        tabElement.style.display = ''; // Default display
                    } else {
                        // Only hide for non-admin users with specific restrictions
                        if (userDepartment !== '' && userRole !== '') {
                            tabElement.style.display = 'none';
                        }
                        // If session is empty, show all tabs (development mode)
                    }
                }
            });

            // Hide buttons based on permissions
            // Bulk delete button - requires delete permission
            if (!userPerms.includes('delete') && !hasAdminRole) {
                document.getElementById('bulkDeleteBtn').style.display = 'none';
            }

            // Add Disbursement button - requires create permission
            if (!userPerms.includes('create') && !hasAdminRole) {
                const addBtn = document.querySelector('[onclick="showAddDisbursementModal()"]');
                if (addBtn) addBtn.style.display = 'none';
            }

            // Upload Voucher button - requires upload_vouchers permission
            if (!userPerms.includes('upload_vouchers') && !hasAdminRole) {
                const uploadBtn = document.querySelector('[data-bs-target="#uploadVoucherModal"]');
                if (uploadBtn) uploadBtn.style.display = 'none';
            }

            // Export Report button - requires delete permission (admin-level action)
            if (!userPerms.includes('delete') && !hasAdminRole) {
                const exportBtn = document.querySelector('.btn-outline-secondary [href="#"]');
                if (exportBtn && exportBtn.innerHTML.includes('Export')) {
                    exportBtn.style.display = 'none';
                }
            }

            // Modify table actions based on permissions
            // This will be applied when rendering table rows
            window.userPermissions = {
                canEdit: userPerms.includes('edit') || hasAdminRole,
                canDelete: userPerms.includes('delete') || hasAdminRole,
                canView: userPerms.includes('view') || hasAdminRole,
                department: userDepartment,
                role: userRole
            };
        }

        // Connect tab events
        document.querySelectorAll('#disbursementsTabs .nav-link').forEach(tab => {
            tab.addEventListener('shown.bs.tab', function(e) {
                const target = e.target.getAttribute('data-bs-target');
                switch(target) {
                    case '#audit':
                        loadAuditTrail();
                        break;
                    case '#logistics':
                        if (typeof loadLogisticsActivity === 'function') {
                            loadLogisticsActivity();
                        }
                        break;
                    case '#reports':
                        loadDisbursementReports();
                        break;
                    case '#vouchers':
                        loadVouchers();
                        break;
                }
            });
        });

        // Load initial data and setup permissions
        // loadDisbursements(); // Called from HTML DOMContentLoaded in disbursements.php
        // loadVendors(); // Called from HTML DOMContentLoaded in disbursements.php

        function toggleSidebarDesktop() {
            const sidebar = document.getElementById('sidebar');
            const content = document.querySelector('.content');
            const arrow = document.getElementById('sidebarArrow');
            const toggle = document.querySelector('.sidebar-toggle');
            const logoImg = document.querySelector('.navbar-brand img');
            sidebar.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('sidebar-collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            if (isCollapsed) {
                logoImg.src = 'atieralogo2.png';
                content.style.marginLeft = '120px';
                arrow.classList.remove('fa-chevron-left');
                arrow.classList.add('fa-chevron-right');
                toggle.style.left = '110px';
            } else {
                logoImg.src = 'atieralogo.png';
                content.style.marginLeft = '300px';
                arrow.classList.remove('fa-chevron-right');
                arrow.classList.add('fa-chevron-left');
                toggle.style.left = '290px';
            }
        }

        // Utility functions
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            return new Date(dateString).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        // Escape HTML to prevent XSS when inserting untrusted content
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function getStatusBadge(status) {
            const badges = {
                'completed': '<span class="badge bg-success">Completed</span>',
                'pending': '<span class="badge bg-warning">Pending</span>',
                'approved': '<span class="badge bg-info">Approved</span>',
                'cancelled': '<span class="badge bg-danger">Cancelled</span>',
                'paid': '<span class="badge bg-success">Paid</span>'
            };
            return badges[status] || `<span class="badge bg-secondary">${status}</span>`;
        }

        function showAlert(message, type = 'info') {
            // Create alert element
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            document.body.appendChild(alertDiv);

            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Global variables for bulk delete
        let selectedDisbursements = new Set();

        // Update table row rendering to include checkboxes and department permissions
        function renderDisbursementsTable(disbursements) {
            const tbody = document.getElementById('disbursementsTableBody');

            if (disbursements.length === 0) {
                const table = tbody.closest('table');
                const thCount = table ? table.querySelectorAll('thead th').length : 8;
                tbody.innerHTML = `<tr><td colspan="${thCount}" class="text-center">No disbursements found</td></tr>`;
                return;
            }

            tbody.innerHTML = disbursements.map(d => {
                // Build action buttons based on user permissions
                let actions = '<button class="btn btn-sm btn-outline-primary me-1" onclick="viewDisbursement(' + d.id + ')"><i class="fas fa-eye"></i></button>';

                if (window.userPermissions && window.userPermissions.canEdit) {
                    actions += '<button class="btn btn-sm btn-outline-secondary me-1" onclick="editDisbursement(' + d.id + ')"><i class="fas fa-edit"></i></button>';
                }

                if (window.userPermissions && window.userPermissions.canDelete) {
                    actions += '<button class="btn btn-sm btn-outline-danger" onclick="deleteDisbursement(' + d.id + ')"><i class="fas fa-trash"></i></button>';
                }

                // Add checkbox only if user can delete (for bulk operations)
                const checkbox = (window.userPermissions && window.userPermissions.canDelete) ?
                    '<input type="checkbox" class="disbursement-checkbox" value="' + d.id + '" onchange="toggleSelection(this)">' :
                    '<input type="checkbox" disabled title="You do not have delete permissions">';

                return '<tr><td>' + checkbox + '</td><td>' + escapeHtml(d.disbursement_number || d.id) + '</td><td>' + escapeHtml(d.payee || 'N/A') + '</td><td><span class="badge bg-secondary">' + escapeHtml(d.payment_method || 'N/A') + '</span></td><td>' + formatDate(d.disbursement_date) + '</td><td><span class="amount-cell">₱' + parseFloat(d.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span></td><td>' + getStatusBadge(d.status || 'pending') + '</td><td>' + actions + '</td></tr>';
            }).join('');

            // Update header checkbox
            const headerCheckbox = document.getElementById('selectAllCheckbox');
            if (headerCheckbox) {
                headerCheckbox.checked = false; // Reset select all when data changes
                // Disable header checkbox if user cannot delete
                if (!(window.userPermissions && window.userPermissions.canDelete)) {
                    headerCheckbox.disabled = true;
                    headerCheckbox.title = 'You do not have delete permissions';
                } else {
                    headerCheckbox.disabled = false;
                    headerCheckbox.title = '';
                }
            }
        }

        // Toggle individual selection
        function toggleSelection(checkbox) {
            const id = checkbox.value;
            if (checkbox.checked) {
                selectedDisbursements.add(id);
            } else {
                selectedDisbursements.delete(id);
            }
            updateBulkDeleteButton();
        }

        // Select/deselect all
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.disbursement-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                toggleSelection(cb);
            });
        }

        // Update bulk delete button visibility and count
        function updateBulkDeleteButton() {
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const selectedCountSpan = document.getElementById('selectedCount');

            if (!bulkDeleteBtn || !selectedCountSpan) {
                return;
            }

            if (selectedDisbursements.size > 0) {
                bulkDeleteBtn.style.display = 'inline-block';
                selectedCountSpan.textContent = selectedDisbursements.size;
            } else {
                bulkDeleteBtn.style.display = 'none';
                selectedCountSpan.textContent = '0';
            }
        }

        // Bulk delete function
        async function bulkDeleteDisbursements() {
            if (selectedDisbursements.size === 0) {
                showAlert('No disbursements selected', 'warning');
                return;
            }

            const count = selectedDisbursements.size;
            const confirmMessage = `Are you sure you want to delete ${count} disbursement${count === 1 ? '' : 's'}? This action cannot be undone.`;

            showConfirmDialog(
                'Delete Disbursements',
                confirmMessage,
                async () => {
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const originalText = bulkDeleteBtn.innerHTML;
            bulkDeleteBtn.disabled = true;
            bulkDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';

            try {
                let successCount = 0;
                let failCount = 0;

                // Delete each selected disbursement
                for (const id of selectedDisbursements) {
                    try {
                        const response = await fetch(`../api/disbursements.php?id=${id}`, {
                            method: 'DELETE',
                            credentials: 'include'
                        });

                        if (response.ok) {
                            const result = await response.json();
                            if (result.success) {
                                successCount++;
                            } else {
                                failCount++;
                                console.error('Failed to delete disbursement', id, result.error);
                            }
                        } else {
                            failCount++;
                            console.error('HTTP error deleting disbursement', id, response.status);
                        }
                    } catch (error) {
                        failCount++;
                    }
                }

                // Clear selections
                selectedDisbursements.clear();
                updateBulkDeleteButton();

                // Show results
                if (failCount === 0) {
                    showAlert(`Successfully deleted ${successCount} disbursement(s)`, 'success');
                } else {
                    showAlert(`Deleted ${successCount} disbursement(s), ${failCount} failed`, 'warning');
                }

                // Reload table
                loadDisbursements();

            } catch (error) {
                console.error('Bulk delete error:', error);
                showAlert('An error occurred during bulk deletion', 'danger');
            } finally {
                bulkDeleteBtn.disabled = false;
                bulkDeleteBtn.innerHTML = originalText;
            }
            }
        );
        }

        // Ensure key handlers are available on window for inline onclick usage
        window.processPayment = window.processPayment || processPayment;
        window.showFilters = window.showFilters || showFilters;
        window.applyFilters = window.applyFilters || applyFilters;
        window.clearFilters = window.clearFilters || clearFilters;
        window.showAddDisbursementModal = window.showAddDisbursementModal || showAddDisbursementModal;
        window.saveDisbursement = window.saveDisbursement || saveDisbursement;
        window.refreshVouchers = window.refreshVouchers || refreshVouchers;
        window.uploadVoucher = window.uploadVoucher || uploadVoucher;
        window.bulkDeleteDisbursements = window.bulkDeleteDisbursements || bulkDeleteDisbursements;
