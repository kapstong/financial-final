(function() {
    'use strict';

    const STORAGE_KEY = 'atieraFinancialHQStateV2';
    const LEGACY_STORAGE_KEY = 'atieraFinancialHQStateV1';
    const CHANGE_KEY = 'atieraFinancialHQStateChangedAt';
    const TODAY = new Date();

    const SEED_STATE = {
        vendors: [
            { id: 9101, vendor_code: 'VEND-9101', company_name: 'Sterling Food Solutions', status: 'active', source: 'seed' },
            { id: 9102, vendor_code: 'VEND-9102', company_name: 'Harborline Utilities Group', status: 'active', source: 'seed' },
            { id: 9103, vendor_code: 'VEND-9103', company_name: 'Northpoint Facilities Supply', status: 'active', source: 'seed' }
        ],
        customers: [
            { id: 9201, customer_code: 'CUST-9201', company_name: 'Silver Crest Events', contact_person: 'Leah Flores', email: 'finance@silvercrest-events.com', phone: '+63 917 200 4481', credit_limit: 850000, status: 'active', source: 'seed' },
            { id: 9202, customer_code: 'CUST-9202', company_name: 'Blue Harbor Suites', contact_person: 'Marco Lim', email: 'ap@blueharborsuites.com', phone: '+63 917 200 5521', credit_limit: 640000, status: 'active', source: 'seed' },
            { id: 9203, customer_code: 'CUST-9203', company_name: 'Summit Dining Concepts', contact_person: 'Ariana Cruz', email: 'payments@summitdining.ph', phone: '+63 917 200 6188', credit_limit: 720000, status: 'active', source: 'seed' }
        ],
        bills: [
            { id: 9301, bill_number: 'BILL-2026-301', vendor_id: 9101, bill_date: '2026-04-16', due_date: '2026-05-12', amount: 182450, total_amount: 182450, balance: 182450, status: 'approved', workflow_state: 'pending_approval', record_mode: 'process', source: 'seed' },
            { id: 9302, bill_number: 'BILL-2026-302', vendor_id: 9102, bill_date: '2026-04-09', due_date: '2026-04-28', amount: 96320, total_amount: 96320, balance: 96320, status: 'overdue', workflow_state: 'approved', record_mode: 'view_only', source: 'seed' },
            { id: 9303, bill_number: 'BILL-2026-303', vendor_id: 9103, bill_date: '2026-04-23', due_date: '2026-05-18', amount: 75480, total_amount: 75480, balance: 75480, status: 'draft', workflow_state: 'pending_approval', record_mode: 'process', source: 'seed' }
        ],
        invoices: [
            { id: 9401, invoice_number: 'INV-2026-401', customer_id: 9201, invoice_date: '2026-04-08', due_date: '2026-05-08', total_amount: 248000, balance: 248000, status: 'sent', record_mode: 'view_only', source: 'seed' },
            { id: 9402, invoice_number: 'INV-2026-402', customer_id: 9202, invoice_date: '2026-04-15', due_date: '2026-05-05', total_amount: 186500, balance: 96500, status: 'partial', record_mode: 'process', source: 'seed' },
            { id: 9403, invoice_number: 'INV-2026-403', customer_id: 9203, invoice_date: '2026-03-20', due_date: '2026-04-20', total_amount: 129900, balance: 129900, status: 'overdue', record_mode: 'view_only', source: 'seed' }
        ],
        payments_made: [
            { id: 9501, payment_number: 'PMT-2026-601', vendor_id: 9102, amount: 48250, payment_method: 'bank_transfer', payment_date: '2026-04-21', reference_number: 'HBG-484920', notes: 'Partial settlement for utility servicing', source: 'seed' }
        ],
        payments_received: [
            { id: 9601, payment_number: 'RCV-2026-701', customer_id: 9202, invoice_id: 9402, amount: 90000, payment_method: 'bank_transfer', payment_date: '2026-04-27', reference_number: 'BHS-220711', source: 'seed' }
        ],
        adjustments: [
            { id: 9701, adjustment_number: 'ADJ-P-2026-101', vendor_id: 9101, adjustment_type: 'credit_memo', adjustment_date: '2026-04-25', amount: 12450, reason: 'Quality claim for returned produce batch', type: 'payable', source: 'seed' },
            { id: 9702, adjustment_number: 'ADJ-R-2026-201', customer_id: 9203, invoice_id: 9403, adjustment_type: 'debit_memo', adjustment_date: '2026-04-29', amount: 6400, reason: 'Rebilling for additional banquet service hours', type: 'receivable', source: 'seed' }
        ],
        hr_claims: [
            { id: 'CLM-301', claim_id: 'CLM-301', employee_name: 'Jules Navarro', department: 'HR 3', claim_type: 'Travel Reimbursement', amount: 12880, submitted_at: '2026-04-28T09:15:00', status: 'approved', can_process: true, source: 'seed' },
            { id: 'CLM-302', claim_id: 'CLM-302', employee_name: 'Mira Santos', department: 'HR 3', claim_type: 'Medical Reimbursement', amount: 9650, submitted_at: '2026-04-29T13:42:00', status: 'approved', can_process: true, source: 'seed' }
        ],
        hr_payroll: [
            { id: 'PAY-APR-01', approval_id: 'PAY-APR-01', payroll_period: 'Apr 16-30, 2026', period_display: 'Apr 16-30, 2026', total_amount: 542880, employee_count: 48, submitted_by: 'Compensation Team', submitted_at: '2026-04-30T17:20:00', status: 'pending', can_approve: true, source: 'seed' }
        ],
        hr_incentives: [
            { id: 'INC-801', employee_name: 'Janelle Cruz', department: 'Sales', position: 'Account Executive', period: 'April 2026', amount: 18500, status: 'pending', can_process: true, source: 'seed' },
            { id: 'INC-802', employee_name: 'Rafael Ong', department: 'Operations', position: 'Shift Lead', period: 'April 2026', amount: 12400, status: 'pending', can_process: true, source: 'seed' }
        ],
        disbursements: [
            { id: 9801, disbursement_number: 'DISB-20260429-301', payee: 'Jules Navarro', disbursement_date: '2026-04-29', amount: 12880, payment_method: 'bank_transfer', reference_number: 'HR3-CLAIM-CLM-301', status: 'processed', source_module: 'claims', source: 'seed' },
            { id: 9802, disbursement_number: 'DISB-20260430-302', payee: 'Harborline Utilities Group', disbursement_date: '2026-04-30', amount: 48250, payment_method: 'bank_transfer', reference_number: 'HBG-484920', status: 'pending', source_module: 'ap', source: 'seed' },
            { id: 9803, disbursement_number: 'DISB-20260501-303', payee: 'Payroll Apr 16-30, 2026', disbursement_date: '2026-05-01', amount: 542880, payment_method: 'bank_transfer', reference_number: 'PAYROLL-PAY-APR-01', status: 'pending', source_module: 'payroll', source: 'seed' }
        ],
        budget_plans: [
            { id: 9901, name: 'FY2026 Core Operations Plan', start_date: '2026-01-01', end_date: '2026-12-31', total_amount: 4200000, utilized_amount: 2785000, status: 'active', created_by_name: 'Finance Strategy Office', source: 'seed', is_external: false },
            { id: 9902, name: 'Guest Experience Acceleration', start_date: '2026-04-01', end_date: '2026-09-30', total_amount: 1325000, utilized_amount: 864200, status: 'active', created_by_name: 'Commercial Planning', source: 'seed', is_external: false },
            { id: 9903, name: 'Facilities Reliability Program', start_date: '2026-02-01', end_date: '2026-11-30', total_amount: 1680000, utilized_amount: 1195000, status: 'active', created_by_name: 'Operations Finance', source: 'seed', is_external: false }
        ],
        budget_allocations: [
            { id: 9911, budget_id: 9901, department_id: 'OPS', department: 'Operations', category: 'Operations', total_amount: 1320000, reserved_amount: 118000, utilized_amount: 978500, remaining: 341500, source: 'seed' },
            { id: 9912, budget_id: 9901, department_id: 'HR3', department: 'HR 3', category: 'Claims and Benefits', total_amount: 860000, reserved_amount: 92000, utilized_amount: 704000, remaining: 156000, source: 'seed' },
            { id: 9913, budget_id: 9902, department_id: 'SALES', department: 'Sales', category: 'Revenue Programs', total_amount: 725000, reserved_amount: 84000, utilized_amount: 612400, remaining: 112600, source: 'seed' },
            { id: 9914, budget_id: 9903, department_id: 'FAC', department: 'Facilities', category: 'Maintenance', total_amount: 980000, reserved_amount: 140000, utilized_amount: 882300, remaining: 97700, source: 'seed' }
        ],
        budget_adjustments: [
            { id: 9921, adjustment_number: 'BGT-ADJ-2026-021', budget_id: 9901, department_id: 'HR3', department_name: 'HR 3', vendor_id: 9102, requested_by_name: 'Rosa Valdez', adjustment_type: 'supplemental', amount: 85000, reason: 'Higher-than-expected medical reimbursements for April cycle', effective_date: '2026-05-06', status: 'pending', source: 'seed' },
            { id: 9922, adjustment_number: 'BGT-ADJ-2026-022', budget_id: 9903, department_id: 'FAC', department_name: 'Facilities', vendor_id: 9103, requested_by_name: 'Miguel Ramos', adjustment_type: 'reallocation', amount: 64000, reason: 'Critical refrigeration preventive maintenance package', effective_date: '2026-05-10', status: 'approved', source: 'seed' }
        ]
    };

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function loadState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY) || localStorage.getItem(LEGACY_STORAGE_KEY);
            if (!raw) {
                return null;
            }
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (error) {
            return null;
        }
    }

    function touch() {
        try {
            localStorage.setItem(CHANGE_KEY, String(Date.now()));
        } catch (error) {
            // Ignore storage errors.
        }
    }

    function saveState(state) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        touch();
        return state;
    }

    function mergeById(primary, secondary) {
        const map = new Map();
        (secondary || []).forEach(item => map.set(String(item.id), item));
        (primary || []).forEach(item => map.set(String(item.id), item));
        return Array.from(map.values());
    }

    function ensureState() {
        const existing = loadState();
        if (!existing) {
            return saveState(clone(SEED_STATE));
        }

        const nextState = clone(existing);
        Object.keys(SEED_STATE).forEach(key => {
            nextState[key] = mergeById(nextState[key] || [], SEED_STATE[key] || []);
        });

        return saveState(nextState);
    }

    function getState() {
        return ensureState();
    }

    function updateState(updater) {
        const current = clone(getState());
        const next = updater(current) || current;
        return saveState(next);
    }

    function nextNumericId(items, minValue) {
        return (items || []).reduce((maxValue, item) => {
            const numericId = Number(item.id) || 0;
            return Math.max(maxValue, numericId);
        }, minValue || 0) + 1;
    }

    function formatDateOnly(value) {
        if (!value) {
            return '';
        }
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        return date.toISOString().slice(0, 10);
    }

    function currencyBucketLabel(daysPastDue) {
        if (daysPastDue > 90) return '90+';
        if (daysPastDue > 60) return '61-90';
        if (daysPastDue > 30) return '31-60';
        if (daysPastDue > 0) return '1-30';
        return 'current';
    }

    function enrichBills(state) {
        return (state.bills || []).map(bill => {
            const vendor = (state.vendors || []).find(item => Number(item.id) === Number(bill.vendor_id));
            return {
                ...bill,
                vendor_name: bill.vendor_name || vendor?.company_name || 'Unknown Vendor',
                vendor_code: bill.vendor_code || vendor?.vendor_code || ''
            };
        });
    }

    function enrichInvoices(state) {
        return (state.invoices || []).map(invoice => {
            const customer = (state.customers || []).find(item => Number(item.id) === Number(invoice.customer_id));
            return {
                ...invoice,
                customer_name: invoice.customer_name || customer?.company_name || 'Unknown Customer',
                customer_code: invoice.customer_code || customer?.customer_code || ''
            };
        });
    }

    function enrichPayments(state, type) {
        const sourceKey = type === 'received' ? 'payments_received' : 'payments_made';
        return (state[sourceKey] || []).map(payment => {
            if (type === 'received') {
                const customer = (state.customers || []).find(item => Number(item.id) === Number(payment.customer_id));
                const invoice = (state.invoices || []).find(item => Number(item.id) === Number(payment.invoice_id));
                return {
                    ...payment,
                    customer_name: payment.customer_name || customer?.company_name || 'Unknown Customer',
                    invoice_number: payment.invoice_number || invoice?.invoice_number || 'N/A'
                };
            }

            const vendor = (state.vendors || []).find(item => Number(item.id) === Number(payment.vendor_id));
            return {
                ...payment,
                vendor_name: payment.vendor_name || vendor?.company_name || 'Unknown Vendor'
            };
        });
    }

    function getPayablesAging() {
        const bills = enrichBills(getState());
        return bills.map(bill => {
            const dueDate = new Date(bill.due_date);
            const diffDays = Math.max(0, Math.floor((TODAY - dueDate) / 86400000));
            return {
                ...bill,
                days_past_due: diffDays,
                aging_bucket: currencyBucketLabel(diffDays)
            };
        });
    }

    function getReceivablesAging() {
        const invoices = enrichInvoices(getState());
        const grouped = new Map();

        invoices.forEach(invoice => {
            const key = String(invoice.customer_id);
            const dueDate = new Date(invoice.due_date);
            const diffDays = Math.max(0, Math.floor((TODAY - dueDate) / 86400000));
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
        return getBudgetAllocations().map(allocation => {
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

    function getBudgetAlerts() {
        return getBudgetAllocations()
            .map((allocation, index) => {
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
                    severity: severity,
                    severity_label: severityLabel,
                    alert_date: '2026-05-01'
                };
            })
            .filter(Boolean);
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

    function getDisbursements() {
        return (getState().disbursements || []).slice().sort((left, right) => {
            return String(right.disbursement_date || '').localeCompare(String(left.disbursement_date || ''));
        });
    }

    function addDisbursement(record) {
        return updateState(state => {
            const items = state.disbursements || [];
            const id = record.id || nextNumericId(items, 9800);
            const dateOnly = formatDateOnly(record.disbursement_date || record.payment_date) || new Date().toISOString().slice(0, 10);
            const suffix = String(id).slice(-3).padStart(3, '0');
            const disbursementNumber = record.disbursement_number || `DISB-${dateOnly.replace(/-/g, '')}-${suffix}`;
            state.disbursements = items.filter(item => String(item.id) !== String(id));
            state.disbursements.push({
                id: id,
                disbursement_number: disbursementNumber,
                payee: record.payee || 'Unassigned Payee',
                disbursement_date: dateOnly,
                amount: Number(record.amount || 0),
                payment_method: record.payment_method || 'bank_transfer',
                reference_number: record.reference_number || '',
                status: record.status || 'processed',
                purpose: record.purpose || record.description || '',
                notes: record.notes || record.description || '',
                source_module: record.source_module || 'manual',
                source: record.source || 'seed'
            });
            return state;
        });
    }

    function updateDisbursement(disbursementId, patch) {
        return updateState(state => {
            state.disbursements = (state.disbursements || []).map(item => {
                if (String(item.id) !== String(disbursementId)) {
                    return item;
                }
                return { ...item, ...patch };
            });
            return state;
        });
    }

    function removeDisbursement(disbursementId) {
        return updateState(state => {
            state.disbursements = (state.disbursements || []).filter(item => String(item.id) !== String(disbursementId));
            return state;
        });
    }

    function getBudgetPlans() {
        return (getState().budget_plans || []).slice();
    }

    function getBudgetAllocations() {
        return (getState().budget_allocations || []).map(item => ({
            ...item,
            remaining: Number(item.remaining ?? (Number(item.total_amount || 0) - Number(item.utilized_amount || 0)))
        }));
    }

    function getBudgetAdjustments() {
        return (getState().budget_adjustments || []).slice();
    }

    function createBudget(payload) {
        return updateState(state => {
            const id = nextNumericId(state.budget_plans || [], 9900);
            state.budget_plans = state.budget_plans || [];
            state.budget_plans.push({
                id: id,
                name: payload.name || `Budget ${id}`,
                start_date: payload.start_date || '2026-05-01',
                end_date: payload.end_date || '2026-12-31',
                total_amount: Number(payload.total_amount || 0),
                utilized_amount: Number(payload.utilized_amount || 0),
                status: payload.status || 'draft',
                created_by_name: payload.created_by_name || 'Finance Team',
                source: 'seed',
                is_external: false
            });
            return state;
        });
    }

    function updateBudget(budgetId, patch) {
        return updateState(state => {
            state.budget_plans = (state.budget_plans || []).map(item => {
                if (String(item.id) !== String(budgetId)) {
                    return item;
                }
                return { ...item, ...patch };
            });
            return state;
        });
    }

    function addBudgetAllocation(payload) {
        return updateState(state => {
            const id = nextNumericId(state.budget_allocations || [], 9910);
            const totalAmount = Number(payload.total_amount || 0);
            const utilizedAmount = Number(payload.utilized_amount || 0);
            const reservedAmount = Number(payload.reserved_amount || 0);
            state.budget_allocations = state.budget_allocations || [];
            state.budget_allocations.push({
                id: id,
                budget_id: payload.budget_id || null,
                department_id: payload.department_id || '',
                department: payload.department || 'Unassigned',
                category: payload.category || payload.department || 'General',
                total_amount: totalAmount,
                reserved_amount: reservedAmount,
                utilized_amount: utilizedAmount,
                remaining: totalAmount - utilizedAmount,
                source: 'seed'
            });
            return state;
        });
    }

    function updateBudgetAllocation(allocationId, patch) {
        return updateState(state => {
            state.budget_allocations = (state.budget_allocations || []).map(item => {
                if (String(item.id) !== String(allocationId)) {
                    return item;
                }
                const next = { ...item, ...patch };
                const totalAmount = Number(next.total_amount || 0);
                const utilizedAmount = Number(next.utilized_amount || 0);
                next.remaining = Number(next.remaining ?? (totalAmount - utilizedAmount));
                return next;
            });
            return state;
        });
    }

    function addBudgetAdjustment(payload) {
        return updateState(state => {
            const items = state.budget_adjustments || [];
            const id = nextNumericId(items, 9920);
            const code = String(id).slice(-3).padStart(3, '0');
            state.budget_adjustments = items.concat([{
                id: id,
                adjustment_number: payload.adjustment_number || `BGT-ADJ-2026-${code}`,
                budget_id: payload.budget_id || null,
                department_id: payload.department_id || '',
                department_name: payload.department_name || payload.department || 'Unassigned',
                vendor_id: payload.vendor_id || null,
                requested_by_name: payload.requested_by_name || 'Finance Team',
                adjustment_type: payload.adjustment_type || 'supplemental',
                amount: Number(payload.amount || 0),
                reason: payload.reason || '',
                effective_date: payload.effective_date || formatDateOnly(new Date()),
                status: payload.status || 'pending',
                source: 'seed'
            }]);
            return state;
        });
    }

    function updateBudgetAdjustment(adjustmentId, patch) {
        return updateState(state => {
            state.budget_adjustments = (state.budget_adjustments || []).map(item => {
                if (String(item.id) !== String(adjustmentId)) {
                    return item;
                }
                return { ...item, ...patch };
            });
            return state;
        });
    }

    function updateBudgetAdjustmentStatus(adjustmentId, status) {
        return updateState(state => {
            const items = state.budget_adjustments || [];
            const target = items.find(item => String(item.id) === String(adjustmentId));
            state.budget_adjustments = items.map(item => {
                if (String(item.id) !== String(adjustmentId)) {
                    return item;
                }
                return { ...item, status: status };
            });

            if (target && status === 'approved') {
                state.budget_allocations = (state.budget_allocations || []).map(allocation => {
                    if (String(allocation.department_id) !== String(target.department_id)) {
                        return allocation;
                    }
                    const nextTotal = Number(allocation.total_amount || 0) + Number(target.amount || 0);
                    const utilizedAmount = Number(allocation.utilized_amount || 0);
                    return {
                        ...allocation,
                        total_amount: nextTotal,
                        remaining: nextTotal - utilizedAmount
                    };
                });
            }

            return state;
        });
    }

    function deleteBudgetAdjustment(adjustmentId) {
        return updateState(state => {
            state.budget_adjustments = (state.budget_adjustments || []).filter(item => String(item.id) !== String(adjustmentId));
            return state;
        });
    }

    const api = {
        getState: getState,
        getVendors: function() { return getState().vendors || []; },
        getCustomers: function() { return getState().customers || []; },
        getBills: function() { return enrichBills(getState()); },
        getInvoices: function() { return enrichInvoices(getState()); },
        getPaymentsMade: function() { return enrichPayments(getState(), 'made'); },
        getPaymentsReceived: function() { return enrichPayments(getState(), 'received'); },
        getAdjustments: function(type) {
            const state = getState();
            return (state.adjustments || []).filter(item => !type || item.type === type);
        },
        getPayablesAging: getPayablesAging,
        getReceivablesAging: getReceivablesAging,
        getHrClaims: function() { return getState().hr_claims || []; },
        getHrPayroll: function() { return getState().hr_payroll || []; },
        getHrIncentives: function() { return getState().hr_incentives || []; },
        getDisbursements: getDisbursements,
        addDisbursement: addDisbursement,
        updateDisbursement: updateDisbursement,
        removeDisbursement: removeDisbursement,
        getBudgetPlans: getBudgetPlans,
        getBudgetAllocations: getBudgetAllocations,
        getBudgetTracking: getBudgetTracking,
        getBudgetSummary: getBudgetSummary,
        getBudgetAlerts: getBudgetAlerts,
        getBudgetAdjustments: getBudgetAdjustments,
        createBudget: createBudget,
        updateBudget: updateBudget,
        addBudgetAllocation: addBudgetAllocation,
        updateBudgetAllocation: updateBudgetAllocation,
        addBudgetAdjustment: addBudgetAdjustment,
        updateBudgetAdjustment: updateBudgetAdjustment,
        updateBudgetAdjustmentStatus: updateBudgetAdjustmentStatus,
        deleteBudgetAdjustment: deleteBudgetAdjustment,
        updateBillWorkflow: function(billId, decision) {
            return updateState(state => {
                state.bills = (state.bills || []).map(item => {
                    if (String(item.id) !== String(billId)) {
                        return item;
                    }
                    if (decision === 'approve') {
                        return { ...item, status: 'approved', workflow_state: 'approved' };
                    }
                    return { ...item, status: 'rejected', workflow_state: 'rejected' };
                });
                return state;
            });
        },
        updateInvoiceStatus: function(invoiceId, status) {
            return updateState(state => {
                state.invoices = (state.invoices || []).map(item => {
                    if (String(item.id) !== String(invoiceId)) {
                        return item;
                    }
                    const next = { ...item, status: status };
                    if (status === 'paid') {
                        next.balance = 0;
                    }
                    return next;
                });
                return state;
            });
        },
        updateHrClaimStatus: function(claimId, status) {
            return updateState(state => {
                state.hr_claims = (state.hr_claims || []).map(item => {
                    if (String(item.id) !== String(claimId) && String(item.claim_id) !== String(claimId)) {
                        return item;
                    }
                    return { ...item, status: status, can_process: false };
                });
                return state;
            });
        },
        updateHrPayrollStatus: function(payrollId, status) {
            return updateState(state => {
                state.hr_payroll = (state.hr_payroll || []).map(item => {
                    if (String(item.id) !== String(payrollId) && String(item.approval_id) !== String(payrollId)) {
                        return item;
                    }
                    return { ...item, status: status, can_approve: false };
                });
                return state;
            });
        },
        updateHrIncentiveStatus: function(incentiveId, status) {
            return updateState(state => {
                state.hr_incentives = (state.hr_incentives || []).map(item => {
                    if (String(item.id) !== String(incentiveId)) {
                        return item;
                    }
                    return { ...item, status: status, can_process: false };
                });
                return state;
            });
        }
    };

    ensureState();
    window.FinancialHQState = api;
})();
