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
                const response = await fetch('../../api/disbursements.php', {
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
                    updateTabBadge('audit-tab', 0);
                    return;
                }

                tbody.innerHTML = auditLogs.map(log => {
                    const actionLabel = log.action_label || log.action;
                    // Derive a concise disbursement ref for the column. Prefer explicit number, then record id,
                    // then try to read from serialized old/new values, then parse description for common patterns.
                    let disbRef = '';
                    if (log.disbursement_number) disbRef = log.disbursement_number;
                    if (!disbRef && log.record_id) disbRef = log.record_id;

                    // Try parsing JSON fields returned by the API (old_values/new_values)
                    if (!disbRef) {
                        try {
                            const nv = log.new_values ? JSON.parse(log.new_values) : null;
                            const ov = log.old_values ? JSON.parse(log.old_values) : null;
                            disbRef = nv?.disbursement_number || ov?.disbursement_number || nv?.disbursement_no || ov?.disbursement_no || '';
                        } catch (e) {
                            // ignore malformed JSON
                        }
                    }

                    // Fallback: parse common textual patterns in the description
                    if (!disbRef && log.action_description) {
                        const m = String(log.action_description).match(/DISB-\d{8}-\d{3}|DISB-\d+|\bID\s*(\d+)\b|(\d{1,6})/i);
                        if (m) {
                            // prefer the full DISB token if present, otherwise numeric capture
                            disbRef = m[0];
                            if (/^ID\s*/i.test(disbRef) && m[1]) disbRef = m[1];
                        }
                    }

                    if (!disbRef) disbRef = 'N/A';
                    const displayUser = log.display_user || log.full_name || log.username || 'System';
                    return '<tr><td>' + escapeHtml(log.formatted_date) + '</td><td>' + escapeHtml(displayUser) + '</td><td><span class="badge bg-info">' + escapeHtml(actionLabel) + '</span></td><td>' + escapeHtml(disbRef) + '</td><td>' + escapeHtml(log.action_description || '') + '</td></tr>';
                }).join('');
                updateTabBadge('audit-tab', auditLogs.length);

                // Populate inline filter dropdowns based on current audit data
                if (typeof populateDisbursementFilters === 'function') {
                    try { populateDisbursementFilters(auditLogs); } catch (e) { /* ignore */ }
                }

            } catch (error) {
                const tbody = document.getElementById('auditTableBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Error loading audit trail</td></tr>';
                }
                updateTabBadge('audit-tab', 0);
            }
        }

        // --- Disbursement Audit Filters ---
        function showDisbursementFilterModal() {
            const modalHTML = `
            <div class="modal fade" id="disbFilterModal" tabindex="-1" aria-labelledby="disbFilterModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="disbFilterModalLabel"><i class="fas fa-filter me-2"></i>Filter Audit Trail</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="disbAuditFilterForm">
                                <div class="mb-3">
                                    <label for="disbFilterDateFrom" class="form-label">Date From</label>
                                    <input type="date" class="form-control" id="disbFilterDateFrom">
                                </div>
                                <div class="mb-3">
                                    <label for="disbFilterDateTo" class="form-label">Date To</label>
                                    <input type="date" class="form-control" id="disbFilterDateTo">
                                </div>
                                <div class="mb-3">
                                    <label for="disbFilterUser" class="form-label">User</label>
                                    <input type="text" class="form-control" id="disbFilterUser" placeholder="Search by name or username">
                                </div>
                                <div class="mb-3">
                                    <label for="disbFilterAction" class="form-label">Action</label>
                                    <select class="form-select" id="disbFilterAction">
                                        <option value="">All Actions</option>
                                        <option value="Created">Created</option>
                                        <option value="Updated">Updated</option>
                                        <option value="Deleted">Deleted</option>
                                        <option value="Viewed">Viewed</option>
                                        <option value="Processed Payment">Processed Payment</option>
                                        <option value="Printed">Printed</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="disbFilterRef" class="form-label">Disbursement Ref / ID</label>
                                    <input type="text" class="form-control" id="disbFilterRef" placeholder="DISB-YYYYMMDD-### or numeric ID">
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="clearDisbursementFilters()" data-bs-dismiss="modal">Clear Filters</button>
                            <button type="button" class="btn btn-primary" onclick="applyDisbursementFilters()">Apply Filters</button>
                        </div>
                    </div>
                </div>
            </div>`;

            const existing = document.getElementById('disbFilterModal');
            if (existing) existing.remove();
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            const modal = new bootstrap.Modal(document.getElementById('disbFilterModal'));
            modal.show();
        }

        // Expose implementation reference so page-level wrappers can invoke it safely
        try {
            if (typeof window !== 'undefined') window._disbFilterImpl = showDisbursementFilterModal;
        } catch (e) {
            // ignore in non-browser contexts
        }

        function buildDisbursementAuditParams() {
            const dateFrom = document.getElementById('disbFilterDateFrom')?.value || '';
            const dateTo = document.getElementById('disbFilterDateTo')?.value || '';
            const user = document.getElementById('disbFilterUser')?.value || '';
            const action = document.getElementById('disbFilterAction')?.value || '';
            const ref = document.getElementById('disbFilterRef')?.value || '';
            const actionMap = {
                'Created': 'created',
                'Updated': 'updated',
                'Deleted': 'deleted',
                'Viewed': 'viewed',
                'Processed Payment': 'processed_payment',
                'Printed': 'printed'
            };
            const mappedAction = actionMap[action] || (action ? action.toLowerCase() : '');

            const params = new URLSearchParams();
            params.append('scope', 'disbursements');
            params.append('table_name', 'disbursements');
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);
            if (user) params.append('user', user);
            if (mappedAction) params.append('action', mappedAction);
            if (ref) {
                // Try to pass record_id if numeric, otherwise pass reference as part of search (record_id param will help server-side)
                const m = String(ref).match(/\d+/);
                if (m && String(ref).toLowerCase().indexOf('disb') === -1) {
                    params.append('record_id', m[0]);
                } else {
                    params.append('reference_search', ref);
                }
            }
            return params;
        }

        // Populate filter inputs (action list, user list) from current audit logs
        function populateDisbursementFilters(logs) {
            if (!Array.isArray(logs)) return;
            const actions = new Set();
            const users = new Set();

            logs.forEach(l => {
                const a = (l.action_label || l.action || '').toString().trim();
                if (a) actions.add(a);
                const u = (l.full_name || l.username || '').toString().trim();
                if (u) users.add(u);
            });

            // Populate action select
            const actionSelect = document.getElementById('disbFilterAction');
            if (actionSelect) {
                // Keep the first option (All Actions)
                const firstOpt = actionSelect.querySelector('option');
                actionSelect.innerHTML = '';
                if (firstOpt) actionSelect.appendChild(firstOpt);
                // Append sorted actions
                Array.from(actions).sort().forEach(act => {
                    const opt = document.createElement('option');
                    opt.value = act;
                    opt.textContent = act;
                    actionSelect.appendChild(opt);
                });
            }

            // Populate user datalist
            const userList = document.getElementById('disbFilterUserList');
            if (userList) {
                userList.innerHTML = '';
                Array.from(users).sort().forEach(u => {
                    const opt = document.createElement('option');
                    opt.value = u;
                    userList.appendChild(opt);
                });
            }
        }

        function applyDisbursementFilters() {
            const params = buildDisbursementAuditParams();
            fetch(`../api/audit.php?${params.toString()}`, { credentials: 'include' })
                .then(res => res.json())
                .then(logs => {
                    const tbody = document.getElementById('auditTableBody');
                    if (!tbody) return;
                    if (!Array.isArray(logs) || logs.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No audit logs found</td></tr>';
                        updateTabBadge('audit-tab', 0);
                        return;
                    }
                    tbody.innerHTML = logs.map(log => {
                        const actionLabel = log.action_label || log.action;
                        let disbRef = log.disbursement_number || log.record_id || '';
                        if (!disbRef) {
                            try {
                                const nv = log.new_values ? JSON.parse(log.new_values) : null;
                                const ov = log.old_values ? JSON.parse(log.old_values) : null;
                                disbRef = nv?.disbursement_number || ov?.disbursement_number || '';
                            } catch (e) {}
                        }
                        if (!disbRef && log.action_description) {
                            const m = String(log.action_description).match(/DISB-\d{8}-\d{3}|DISB-\d+|\bID\s*(\d+)\b|(\d{1,6})/i);
                            if (m) disbRef = m[0];
                        }
                        if (!disbRef) disbRef = 'N/A';
                        return '<tr><td>' + log.formatted_date + '</td><td>' + (log.full_name || log.username || 'Unknown') + '</td><td><span class="badge bg-info">' + actionLabel + '</span></td><td>' + disbRef + '</td><td>' + (log.action_description || '') + '</td></tr>';
                    }).join('');
                    updateTabBadge('audit-tab', logs.length);
                })
                .catch(err => {
                    console.error('Error applying disbursement filters:', err);
                    const tbody = document.getElementById('auditTableBody');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Error loading audit trail</td></tr>';
                });

            const modal = bootstrap.Modal.getInstance(document.getElementById('disbFilterModal'));
            if (modal) modal.hide();
        }

        function clearDisbursementFilters() {
            const form = document.getElementById('disbAuditFilterForm');
            if (form) form.reset();
            loadAuditTrail();
        }

        // Reports and Analytics
        async function loadDisbursementReports() {
            try {
                // Load disbursement summary
                const response = await fetch('../../api/disbursements.php', {
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
                    `../../api/disbursements.php?action=get_vouchers&disbursement_id=${disbursementId}` :
                    '../../api/disbursements.php?action=get_vouchers';

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
            date_to: document.getElementById('filterDateTo').value,
            reference_search: document.getElementById('filterReferenceSearch').value,
            payee_name: document.getElementById('filterPayeeName').value,
            department: document.getElementById('filterDepartment').value
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
        document.getElementById('filterReferenceSearch').value = '';
        document.getElementById('filterPayeeName').value = '';
        document.getElementById('filterDepartment').value = '';
        currentFilters = {};
        loadDisbursements();
    }

    function showFilters() {
        const filtersSection = document.getElementById('filtersSection');
        filtersSection.style.display = filtersSection.style.display === 'none' ? 'block' : 'none';
    }

    // Dynamically populate status filter based on actual data
    function populateStatusFilter(disbursements) {
        const statusSelect = document.getElementById('filterStatus');
        if (!statusSelect) return;

        // Extract unique statuses from the data
        const uniqueStatuses = [...new Set(disbursements.map(d => d.status || 'pending'))];
        
        // Sort statuses alphabetically
        uniqueStatuses.sort();

        // Preserve current selected value
        const currentValue = statusSelect.value;

        // Clear options except the first one (All Status)
        statusSelect.innerHTML = '<option value="">All Status</option>';

        // Add all unique statuses from the data
        uniqueStatuses.forEach(status => {
            const option = document.createElement('option');
            option.value = status;
            option.textContent = status.charAt(0).toUpperCase() + status.slice(1);
            statusSelect.appendChild(option);
        });

        // Restore previous selection if it still exists
        if (currentValue && Array.from(statusSelect.options).some(opt => opt.value === currentValue)) {
            statusSelect.value = currentValue;
        }
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
            const response = await fetch(`../../api/disbursements.php?id=${id}`, {
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
            const response = await fetch(`../../api/disbursements.php?id=${id}`, {
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
            const response = await fetch(`../../api/disbursements.php?id=${id}`, {
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
                ? `../../api/disbursements.php?id=${disbursementData.disbursement_id}`
                : '../../api/disbursements.php';

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
                    <p><strong>Reference:</strong> ${data.disbursement_number || 'N/A'}</p>
                    <p><strong>Payee:</strong> ${data.payee || 'N/A'}</p>
                    <p><strong>Amount:</strong> <span class="amount-cell">₱${parseFloat(data.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></p>
                    <p><strong>Payment Method:</strong> ${getPaymentMethodBadge(data.payment_method)}</p>
                </div>
                <div class="col-md-6">
                    <p><strong>Date:</strong> ${formatDate(data.disbursement_date)}</p>
                    <p><strong>Reference Number:</strong> ${data.reference_number || 'N/A'}</p>
                    <p><strong>Status:</strong> ${getStatusBadge(data.status)}</p>
                    <p><strong>Notes:</strong> ${data.purpose || data.notes || 'N/A'}</p>
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

        function updateTabBadge(tabId, count) {
            const tabButton = document.getElementById(tabId);
            if (!tabButton) return;
            const badge = tabButton.querySelector('[data-tab-badge]');
            if (!badge) return;

            if (tabButton.classList.contains('active')) {
                badge.textContent = '0';
                badge.classList.add('d-none');
                tabButton.classList.remove('tab-attention');
                return;
            }

            if (count > 0) {
                badge.textContent = count;
                badge.classList.remove('d-none');
                tabButton.classList.add('tab-attention');
            } else {
                badge.textContent = '0';
                badge.classList.add('d-none');
                tabButton.classList.remove('tab-attention');
            }
        }
        window.updateTabBadge = updateTabBadge;

        // Load disbursements from API
        async function loadDisbursements() {
            try {
                const params = new URLSearchParams(currentFilters);
                const response = await fetch(`../../api/disbursements.php?${params}`, {
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
                    populateStatusFilter(data);
                } else {
                    if (data.error) {
                        const tbody = document.getElementById('disbursementsTableBody');
                        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Error loading disbursements. Please try again.</td></tr>';
                        showAlert('Error loading disbursements: ' + data.error, 'danger');
                    } else {
                        throw new Error('API returned an error');
                    }
                }
            } catch (error) {
                console.error('Error loading disbursements:', error);
                const tbody = document.getElementById('disbursementsTableBody');
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Error loading disbursements. Please try again.</td></tr>';
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
                    case '#claims':
                        updateTabBadge('claims-tab', 0);
                        break;
                    case '#logistics':
                        if (typeof loadLogisticsActivity === 'function') {
                            loadLogisticsActivity();
                        }
                        break;
                    case '#payroll':
                        updateTabBadge('payroll-tab', 0);
                        break;
                    case '#audit':
                        loadAuditTrail();
                        updateTabBadge('audit-tab', 0);
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
            const key = String(status || '').toLowerCase().trim();
            const labels = {
                'completed': {label: 'Completed', cls: 'bg-success'},
                'pending': {label: 'Pending', cls: 'bg-warning'},
                'pending approval': {label: 'Pending Approval', cls: 'bg-info'},
                'pending_approval': {label: 'Pending Approval', cls: 'bg-info'},
                'approved': {label: 'Approved', cls: 'bg-info'},
                'rejected': {label: 'Rejected', cls: 'bg-danger'},
                'cancelled': {label: 'Cancelled', cls: 'bg-danger'},
                'paid': {label: 'Paid', cls: 'bg-success'}
            };

            const entry = labels[key] || null;
            if (entry) {
                return `<span class="badge ${entry.cls}">${entry.label}</span>`;
            }

            // Fallback: humanize the status text
            const human = String(status || 'Unknown').replace(/[_\-]/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            return `<span class="badge bg-secondary">${human}</span>`;
        }

        function humanizePaymentMethod(key) {
            if (!key) return 'N/A';
            return String(key).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }

        function getPaymentMethodBadge(method) {
            const m = String(method || '').toLowerCase();
            const map = {
                'cash': {label: 'Cash', cls: 'bg-secondary', icon: 'fas fa-money-bill-wave'},
                'bank_transfer': {label: 'Bank Transfer', cls: 'bg-primary', icon: 'fas fa-university'},
                'check': {label: 'Check', cls: 'bg-info', icon: 'fas fa-credit-card'},
                'ewallet': {label: 'E-wallet', cls: 'bg-dark', icon: 'fas fa-mobile-alt'}
            };
            const item = map[m] || {label: humanizePaymentMethod(m) || 'N/A', cls: 'bg-secondary', icon: 'fas fa-credit-card'};
            return `<span class="badge ${item.cls}" aria-label="${item.label}"><i class="${item.icon} me-1"></i>${item.label}</span>`;
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
                tbody.innerHTML = '<tr><td colspan="9" class="text-center">No disbursements found</td></tr>';
                return;
            }

            // Apply client-side filters for reference and payee name
            let filteredDisbursements = disbursements;
            
            const refSearch = (document.getElementById('filterReferenceSearch')?.value || '').trim().toLowerCase();
            const payeeName = (document.getElementById('filterPayeeName')?.value || '').trim().toLowerCase();
            const department = document.getElementById('filterDepartment')?.value || '';

            if (refSearch || payeeName || department) {
                filteredDisbursements = disbursements.filter(d => {
                    // Reference number search - checks both disbursement_number and reference_number fields
                    // Supports partial/substring matching
                    let refMatch = true;
                    if (refSearch) {
                        const refNum = (d.disbursement_number || d.reference_number || '').toLowerCase();
                        const idStr = (d.id || '').toString();
                        refMatch = refNum.includes(refSearch) || idStr.includes(refSearch);
                    }
                    
                    // Payee name search - supports partial/substring matching
                    let payeeMatch = true;
                    if (payeeName) {
                        payeeMatch = (d.payee || '').toLowerCase().includes(payeeName);
                    }
                    
                    // Determine department based on reference_number prefix
                    let deptMatch = true;
                    if (department) {
                        let disbursementDept = 'Payroll';
                        if (d.reference_number && d.reference_number.startsWith('HR3-CLAIM-')) {
                            disbursementDept = 'Claims';
                        } else if (d.reference_number && d.reference_number.startsWith('PAYROLL-')) {
                            disbursementDept = 'Payroll';
                        }
                        deptMatch = disbursementDept === department;
                    }
                    
                    return refMatch && payeeMatch && deptMatch;
                });
            }

            if (filteredDisbursements.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No matching disbursements found</td></tr>';
                return;
            }

            tbody.innerHTML = filteredDisbursements.map(d => {
                // Determine source/department based on reference number
                let source = 'Payroll';
                let sourceBadge = '<span class="badge bg-success">Payroll</span>';
                
                if (d.reference_number) {
                    if (d.reference_number.startsWith('HR3-CLAIM-')) {
                        source = 'Claims';
                        sourceBadge = '<span class="badge bg-info">Claims</span>';
                    } else if (d.reference_number.startsWith('PAYROLL-')) {
                        source = 'Payroll';
                        sourceBadge = '<span class="badge bg-success">Payroll</span>';
                    }
                }

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

                return '<tr><td>' + checkbox + '</td><td>' + escapeHtml(d.disbursement_number || d.reference_number || d.id) + '</td><td>' + escapeHtml(d.payee || 'N/A') + '</td><td>' + getPaymentMethodBadge(d.payment_method) + '</td><td>' + formatDate(d.disbursement_date) + '</td><td><span class="amount-cell">₱' + parseFloat(d.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span></td><td>' + getStatusBadge(d.status || 'pending') + '</td><td>' + sourceBadge + '</td><td>' + actions + '</td></tr>';
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
                        const response = await fetch(`../../api/disbursements.php?id=${id}`, {
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



