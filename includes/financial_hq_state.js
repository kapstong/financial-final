(function() {
    'use strict';

    const ENDPOINT = '../api/financial_seed_state.php';
    const EMPTY_STATE = {
        vendors: [],
        customers: [],
        bills: [],
        invoices: [],
        payments_made: [],
        payments_received: [],
        adjustments: [],
        hr_claims: [],
        hr_payroll: [],
        hr_incentives: [],
        disbursements: [],
        budget_plans: [],
        budget_allocations: [],
        budget_adjustments: []
    };

    let state = clone(EMPTY_STATE);

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function request(method, payload) {
        try {
            const xhr = new XMLHttpRequest();
            xhr.open(method, ENDPOINT, false);
            xhr.withCredentials = true;
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(payload ? JSON.stringify(payload) : null);
            if (xhr.status < 200 || xhr.status >= 300) {
                return null;
            }
            return JSON.parse(xhr.responseText || '{}');
        } catch (error) {
            return null;
        }
    }

    function loadState() {
        const payload = request('GET');
        if (payload && !payload.error) {
            state = normalizeState({ ...clone(EMPTY_STATE), ...payload });
        }
        return state;
    }

    function post(action, payload) {
        const response = request('POST', { action, ...(payload || {}) });
        if (response?.state) {
            state = normalizeState({ ...clone(EMPTY_STATE), ...response.state });
        } else {
            loadState();
        }
        return response;
    }

    function normalizeState(input) {
        const next = { ...clone(EMPTY_STATE), ...(input || {}) };
        next.bills = enrichBills(next);
        next.invoices = enrichInvoices(next);
        next.payments_made = enrichPayments(next, 'made');
        next.payments_received = enrichPayments(next, 'received');
        next.budget_allocations = (next.budget_allocations || []).map(item => ({
            ...item,
            remaining: Number(item.remaining ?? (Number(item.total_amount || 0) - Number(item.utilized_amount || 0)))
        }));
        return next;
    }

    function formatDateOnly(value) {
        if (!value) {
            return '';
        }
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? '' : date.toISOString().slice(0, 10);
    }

    function currencyBucketLabel(daysPastDue) {
        if (daysPastDue > 90) return '90+';
        if (daysPastDue > 60) return '61-90';
        if (daysPastDue > 30) return '31-60';
        if (daysPastDue > 0) return '1-30';
        return 'current';
    }

    function enrichBills(sourceState) {
        return (sourceState.bills || []).map(bill => {
            const vendor = (sourceState.vendors || []).find(item => Number(item.id) === Number(bill.vendor_id));
            return {
                ...bill,
                vendor_name: bill.vendor_name || vendor?.company_name || 'Unknown Vendor',
                vendor_code: bill.vendor_code || vendor?.vendor_code || ''
            };
        });
    }

    function enrichInvoices(sourceState) {
        return (sourceState.invoices || []).map(invoice => {
            const customer = (sourceState.customers || []).find(item => Number(item.id) === Number(invoice.customer_id));
            return {
                ...invoice,
                customer_name: invoice.customer_name || customer?.company_name || 'Unknown Customer',
                customer_code: invoice.customer_code || customer?.customer_code || ''
            };
        });
    }

    function enrichPayments(sourceState, type) {
        const key = type === 'received' ? 'payments_received' : 'payments_made';
        return (sourceState[key] || []).map(payment => {
            if (type === 'received') {
                const customer = (sourceState.customers || []).find(item => Number(item.id) === Number(payment.customer_id));
                const invoice = (sourceState.invoices || []).find(item => Number(item.id) === Number(payment.invoice_id));
                return {
                    ...payment,
                    customer_name: payment.customer_name || customer?.company_name || 'Unknown Customer',
                    invoice_number: payment.invoice_number || invoice?.invoice_number || 'N/A'
                };
            }

            const vendor = (sourceState.vendors || []).find(item => Number(item.id) === Number(payment.vendor_id));
            return {
                ...payment,
                vendor_name: payment.vendor_name || vendor?.company_name || 'Unknown Vendor'
            };
        });
    }

    function getState() {
        return state;
    }

    function getPayablesAging() {
        const today = new Date();
        return (state.bills || []).map(bill => {
            const dueDate = new Date(bill.due_date);
            const diffDays = Number.isNaN(dueDate.getTime()) ? 0 : Math.max(0, Math.floor((today - dueDate) / 86400000));
            return { ...bill, days_past_due: diffDays, aging_bucket: currencyBucketLabel(diffDays) };
        });
    }

    function getReceivablesAging() {
        const today = new Date();
        const grouped = new Map();
        (state.invoices || []).forEach(invoice => {
            const key = String(invoice.customer_id);
            const dueDate = new Date(invoice.due_date);
            const diffDays = Number.isNaN(dueDate.getTime()) ? 0 : Math.max(0, Math.floor((today - dueDate) / 86400000));
            if (!grouped.has(key)) {
                grouped.set(key, {
                    customer_name: invoice.customer_name,
                    customer_code: invoice.customer_code,
                    current: 0,
                    days30: 0,
                    days60: 0,
                    days90: 0,
                    legacy: 0,
                    total: 0
                });
            }
            const bucket = grouped.get(key);
            const amount = Number(invoice.balance ?? invoice.total_amount ?? 0);
            if (diffDays > 90) bucket.legacy += amount;
            else if (diffDays > 60) bucket.days90 += amount;
            else if (diffDays > 30) bucket.days60 += amount;
            else if (diffDays > 0) bucket.days30 += amount;
            else bucket.current += amount;
            bucket.total += amount;
        });
        return Array.from(grouped.values());
    }

    function getBudgetTracking() {
        return (state.budget_allocations || []).map(allocation => {
            const budgetAmount = Number(allocation.total_amount || 0);
            const actualAmount = Number(allocation.utilized_amount || 0);
            return {
                id: allocation.id,
                category: allocation.category || allocation.department,
                department: allocation.department,
                budget_amount: budgetAmount,
                actual_amount: actualAmount,
                variance: actualAmount - budgetAmount,
                variance_percent: budgetAmount > 0 ? ((actualAmount - budgetAmount) / budgetAmount) * 100 : 0
            };
        });
    }

    function getBudgetSummary() {
        const tracking = getBudgetTracking();
        const totalBudget = tracking.reduce((sum, item) => sum + item.budget_amount, 0);
        const actualSpent = tracking.reduce((sum, item) => sum + item.actual_amount, 0);
        return {
            total_budget: totalBudget,
            actual_spent: actualSpent,
            variance_percent: totalBudget > 0 ? ((actualSpent - totalBudget) / totalBudget) * 100 : 0,
            remaining: totalBudget - actualSpent
        };
    }

    function getBudgetAlerts() {
        return (state.budget_allocations || []).map((allocation, index) => {
            const budgeted = Number(allocation.total_amount || 0);
            const utilized = Number(allocation.utilized_amount || 0);
            const utilization = budgeted > 0 ? (utilized / budgeted) * 100 : 0;
            if (utilization < 70) {
                return null;
            }
            let severity = 'yellow';
            let severityLabel = 'Warning';
            if (utilization >= 100) {
                severity = 'red';
                severityLabel = 'Critical';
            } else if (utilization >= 90) {
                severity = 'orange';
                severityLabel = 'High Risk';
            } else if (utilization >= 80) {
                severity = 'light_orange';
                severityLabel = 'Watchlist';
            }
            return {
                id: 9950 + index,
                department: allocation.department,
                budget_year: '2026',
                budgeted_amount: budgeted,
                utilized_amount: utilized,
                utilization_percent: utilization,
                over_amount: Math.max(0, utilized - budgeted),
                severity,
                severity_label: severityLabel,
                alert_date: formatDateOnly(new Date())
            };
        }).filter(Boolean);
    }

    function getDisbursements() {
        return (state.disbursements || []).slice().sort((left, right) => {
            return String(right.disbursement_date || '').localeCompare(String(left.disbursement_date || ''));
        });
    }

    function addDisbursement(record) {
        const response = post('create_disbursement', { record });
        return response?.state || state;
    }

    function updateDisbursement(disbursementId, patch) {
        state.disbursements = (state.disbursements || []).map(item => {
            return String(item.id) === String(disbursementId) ? { ...item, ...patch } : item;
        });
        return state;
    }

    function removeDisbursement(disbursementId) {
        state.disbursements = (state.disbursements || []).filter(item => String(item.id) !== String(disbursementId));
        return state;
    }

    function updateQueueStatus(collection, id, status) {
        post('update_queue_status', { id, status });
        state[collection] = (state[collection] || []).map(item => {
            const matches = String(item.id) === String(id)
                || String(item.claim_id) === String(id)
                || String(item.approval_id) === String(id);
            return matches ? { ...item, status, can_process: false, can_approve: false } : item;
        });
        return state;
    }

    function nextLocalId(items, minValue) {
        return (items || []).reduce((maxValue, item) => Math.max(maxValue, Number(item.id) || 0), minValue || 0) + 1;
    }

    function createBudget(payload) {
        const id = nextLocalId(state.budget_plans, 9900);
        state.budget_plans.push({
            id,
            name: payload.name || `Budget ${id}`,
            budget_name: payload.name || `Budget ${id}`,
            start_date: payload.start_date || formatDateOnly(new Date()),
            end_date: payload.end_date || '2026-12-31',
            total_amount: Number(payload.total_amount || payload.total_budgeted || 0),
            utilized_amount: Number(payload.utilized_amount || 0),
            status: payload.status || 'draft',
            created_by_name: payload.created_by_name || 'Finance Team',
            source: 'database_seed'
        });
        return state;
    }

    function updateBudget(budgetId, patch) {
        state.budget_plans = (state.budget_plans || []).map(item => String(item.id) === String(budgetId) ? { ...item, ...patch } : item);
        return state;
    }

    function addBudgetAllocation(payload) {
        const id = nextLocalId(state.budget_allocations, 9910);
        const totalAmount = Number(payload.total_amount || payload.budgeted_amount || 0);
        const utilizedAmount = Number(payload.utilized_amount || 0);
        state.budget_allocations.push({
            id,
            budget_id: payload.budget_id || null,
            department_id: payload.department_id || '',
            department: payload.department || 'Unassigned',
            category: payload.category || payload.department || 'General',
            total_amount: totalAmount,
            reserved_amount: Number(payload.reserved_amount || 0),
            utilized_amount: utilizedAmount,
            remaining: totalAmount - utilizedAmount,
            source: 'database_seed'
        });
        return state;
    }

    function updateBudgetAllocation(allocationId, patch) {
        state.budget_allocations = (state.budget_allocations || []).map(item => {
            if (String(item.id) !== String(allocationId)) return item;
            const next = { ...item, ...patch };
            next.remaining = Number(next.remaining ?? (Number(next.total_amount || 0) - Number(next.utilized_amount || 0)));
            return next;
        });
        return state;
    }

    function addBudgetAdjustment(payload) {
        const id = nextLocalId(state.budget_adjustments, 9920);
        state.budget_adjustments.push({
            id,
            adjustment_number: payload.adjustment_number || id,
            budget_id: payload.budget_id || null,
            department_id: payload.department_id || '',
            department_name: payload.department_name || payload.department || 'Unassigned',
            vendor_id: payload.vendor_id || null,
            requested_by_name: payload.requested_by_name || 'Finance Team',
            adjustment_type: payload.adjustment_type || 'increase',
            amount: Number(payload.amount || 0),
            reason: payload.reason || '',
            effective_date: payload.effective_date || formatDateOnly(new Date()),
            status: payload.status || 'pending',
            source: 'database_seed'
        });
        return state;
    }

    function updateBudgetAdjustment(adjustmentId, patch) {
        state.budget_adjustments = (state.budget_adjustments || []).map(item => String(item.id) === String(adjustmentId) ? { ...item, ...patch } : item);
        return state;
    }

    function updateBudgetAdjustmentStatus(adjustmentId, status) {
        post('budget_adjustment_status', { id: adjustmentId, status });
        return state;
    }

    function deleteBudgetAdjustment(adjustmentId) {
        post('delete_budget_adjustment', { id: adjustmentId });
        state.budget_adjustments = (state.budget_adjustments || []).filter(item => String(item.id) !== String(adjustmentId));
        return state;
    }

    const api = {
        refresh: loadState,
        getState,
        getVendors: () => state.vendors || [],
        getCustomers: () => state.customers || [],
        getBills: () => state.bills || [],
        getInvoices: () => state.invoices || [],
        getPaymentsMade: () => state.payments_made || [],
        getPaymentsReceived: () => state.payments_received || [],
        getAdjustments: type => (state.adjustments || []).filter(item => !type || item.type === type || item.adjustment_category === type),
        getPayablesAging,
        getReceivablesAging,
        getHrClaims: () => state.hr_claims || [],
        getHrPayroll: () => state.hr_payroll || [],
        getHrIncentives: () => state.hr_incentives || [],
        getDisbursements,
        addDisbursement,
        updateDisbursement,
        removeDisbursement,
        getBudgetPlans: () => state.budget_plans || [],
        getBudgetAllocations: () => state.budget_allocations || [],
        getBudgetTracking,
        getBudgetSummary,
        getBudgetAlerts,
        getBudgetAdjustments: () => state.budget_adjustments || [],
        createBudget,
        updateBudget,
        addBudgetAllocation,
        updateBudgetAllocation,
        addBudgetAdjustment,
        updateBudgetAdjustment,
        updateBudgetAdjustmentStatus,
        deleteBudgetAdjustment,
        updateBillWorkflow: (billId, decision) => post('update_bill_workflow', { id: billId, decision }),
        updateInvoiceStatus: (invoiceId, status) => post('update_invoice_status', { id: invoiceId, status }),
        updateHrClaimStatus: (claimId, status) => updateQueueStatus('hr_claims', claimId, status),
        updateHrPayrollStatus: (payrollId, status) => updateQueueStatus('hr_payroll', payrollId, status),
        updateHrIncentiveStatus: (incentiveId, status) => updateQueueStatus('hr_incentives', incentiveId, status)
    };

    loadState();
    window.FinancialHQState = api;
})();
