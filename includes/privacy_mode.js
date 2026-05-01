/**
 * PRIVACY MODE - Password-Protected Amount Visibility
 * - Hides all amounts with asterisks by default
 * - Requires admin password to view amounts
 * - Big red eye button to toggle
 */

(function() {
    'use strict';

    let isHidden = true;
    let eyeButton = null;
    const STORAGE_KEY = 'privacyModeVisible';
    const SERVER_REFRESH_KEY = 'privacyModeRefreshPending';
    let verificationReady = false;

    const AMOUNT_REGEX = /(?:[₱$€£¥]\s*-?[\d,]+\.?\d*)|(?:PHP\s*-?[\d,]+\.?\d*)|(?:P\s*-?[\d,]+\.?\d*)|(?:\(\s*(?:[₱$€£¥P])?\s*-?[\d,]+\.?\d*\s*\))/g;
    const MASKED_CLASS = 'privacy-mask';
    const originalTextMap = new WeakMap();

    /**
     * Hide all amounts with asterisks
     */
    function hideAmounts(force = false) {
        // If user unlocked and no force flag, skip re-hiding newly loaded data
        if (!force && isHidden === false) {
            return;
        }

        ensureMaskStyles();
        scrubLegacyMaskedSpans();
        setDownloadButtonsDisabled(true);

        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node = walker.currentNode;
            if (!node || !node.nodeValue || shouldSkipNode(node)) {
                continue;
            }

            maskTextNode(node);
        }

        isHidden = true;
        updateEyeButton();
        persistVisibility();
        syncPrivacyVisibility(false);
    }

    /**
     * Show all amounts (restore original)
     */
    function showAmounts() {
        let restoredCount = 0;

        const maskedNodes = document.querySelectorAll('.' + MASKED_CLASS);
        maskedNodes.forEach(span => {
            const original = originalTextMap.get(span);
            span.classList.remove(MASKED_CLASS);
            span.style.removeProperty('--privacy-mask-color');
            span.style.removeProperty('position');
            span.style.removeProperty('display');
            span.style.removeProperty('color');
            span.style.removeProperty('white-space');
            const textNode = document.createTextNode(original || span.textContent || '');
            span.replaceWith(textNode);
            originalTextMap.delete(span);
            restoredCount++;
        });

        isHidden = false;
        updateEyeButton();
        persistVisibility();
        setDownloadButtonsDisabled(false);
        syncPrivacyVisibility(true);
        refreshIfServerMasked();
    }

    /**
     * Create verification code modal
     */
    function createPasswordModal() {
        const modalHTML = `
            <div id="privacyPasswordModal" class="modal fade" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-shield-alt me-2"></i>Verification Required
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info" id="privacyVerificationMessage">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Protected Information</strong><br>
                                Enter the 6-digit code sent to your email.
                            </div>

                            <form id="privacyCodeForm">
                                <div class="mb-3">
                                    <label for="privacyCode" class="form-label">Enter 6-Digit Code</label>
                                    <input type="text" class="form-control form-control-lg text-center"
                                           id="privacyCode" placeholder="000000" maxlength="6"
                                           pattern="[0-9]{6}" required autofocus
                                           autocomplete="one-time-code"
                                           style="font-size: 24px; letter-spacing: 8px;">
                                    <div class="invalid-feedback" id="privacyCodeError"></div>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-success" id="privacyVerifyBtn">
                                <i class="fas fa-unlock me-2"></i>Verify & Show Amounts
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Event listeners
        document.getElementById('privacyCodeForm').addEventListener('submit', (e) => {
            e.preventDefault();
            verifyCodeAndShow();
        });
        document.getElementById('privacyVerifyBtn').addEventListener('click', verifyCodeAndShow);

        // Auto-verify when 6 digits entered
        document.getElementById('privacyCode').addEventListener('input', (e) => {
            const code = e.target.value;
            e.target.classList.remove('is-invalid');
            document.getElementById('privacyCodeError').textContent = '';
            if (code.length === 6 && /^\d{6}$/.test(code)) {
                verifyCodeAndShow();
            }
        });
    }

    /**
     * Verify code and show amounts
     */
    function verifyCodeAndShow() {
        const codeInput = document.getElementById('privacyCode');
        const errorDiv = document.getElementById('privacyCodeError');
        const verifyBtn = document.getElementById('privacyVerifyBtn');
        const code = codeInput.value;

        if (!verificationReady) {
            codeInput.classList.add('is-invalid');
            errorDiv.textContent = 'Verification is not ready for this account.';
            return;
        }

        if (!code || code.length !== 6) {
            codeInput.classList.add('is-invalid');
            errorDiv.textContent = 'Please enter a 6-digit code';
            return;
        }

        const originalBtnText = verifyBtn.innerHTML;
        verifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';
        verifyBtn.disabled = true;
        codeInput.disabled = true;

        const apiPath = getApiPath('privacy_code.php');

        fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=verify_code&code=' + encodeURIComponent(code)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAmounts();

                const modal = bootstrap.Modal.getInstance(document.getElementById('privacyPasswordModal'));
                modal.hide();

                codeInput.value = '';
                codeInput.classList.remove('is-invalid');
            } else {
                codeInput.classList.add('is-invalid');
                errorDiv.textContent = data.error || 'Incorrect code';

                verifyBtn.innerHTML = originalBtnText;
                verifyBtn.disabled = false;
                codeInput.disabled = false;
                codeInput.focus();
            }
        })
        .catch(error => {
            codeInput.classList.add('is-invalid');
            errorDiv.textContent = 'Network error. Please try again.';

            verifyBtn.innerHTML = originalBtnText;
            verifyBtn.disabled = false;
            codeInput.disabled = false;
            codeInput.focus();
        });
    }

    /**
     * Check if privacy mode is already unlocked in session
     */
    function checkSessionStatus() {
        const apiPath = getApiPath('privacy_code.php');
        const storedVisibility = getStoredVisibility();

        fetch(apiPath + '?action=check_status', {
            method: 'GET'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.unlocked && data.visible) {
                showAmounts();
                return;
            }
            hideAmounts(true);
            if (storedVisibility === '1') {
                setStoredVisibility('0');
            }
        })
        .catch(error => {
            // On error, default to hiding amounts
            hideAmounts(true);
        });
    }

    /**
     * Get API path based on current location
     */
    function getApiPath(filename) {
        // Determine the correct API path based on current page location
        const pathname = window.location.pathname;

        // Check if integ-capstone is in the path
        if (pathname.includes('/integ-capstone/')) {
            // Extract path up to and including integ-capstone
            const match = pathname.match(/^(.+?\/integ-capstone)/);
            if (match) {
                return match[1] + '/api/' + filename;
            }
        }

        // For pages in subdirectories like /superadmin/, /staff/, /admin/
        const parts = pathname.split('/').filter(p => p.length > 0);

        // If we're in a folder structure (superadmin, staff, admin, etc.)
        if (parts.length >= 2) {
            const lastFolder = parts[parts.length - 2];
            if (['superadmin', 'staff', 'admin', 'hotels', 'restaurants'].includes(lastFolder)) {
                return '../api/' + filename;
            }
        }

        // Default: assume app is at domain root
        return '/api/' + filename;
    }

    /**
     * Show password modal
     */
    function showPasswordModal() {
        prepareVerification();
        const modal = new bootstrap.Modal(document.getElementById('privacyPasswordModal'));
        modal.show();

        document.getElementById('privacyPasswordModal').addEventListener('shown.bs.modal', function() {
            document.getElementById('privacyCode').focus();
        });
    }

    /**
     * Toggle amounts visibility
     */
    function toggleAmounts() {
        if (isHidden) {
            // Check if already verified in this session
            checkIfUnlockedThenToggle();
        } else {
            hideAmounts(true);
        }
    }

    /**
     * Check if privacy mode is unlocked, then toggle or show modal
     */
    function checkIfUnlockedThenToggle() {
        const apiPath = getApiPath('privacy_code.php');

        fetch(apiPath + '?action=check_status', {
            method: 'GET'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.unlocked) {
                // Already verified in this session, just show amounts
                showAmounts();
            } else {
                // Not verified, show modal to enter code
                showPasswordModal();
            }
        })
        .catch(error => {
            // On error, show modal
            showPasswordModal();
        });
    }

    /**
     * Create or wire up eye icon button in navbar
     */
    function createEyeButton() {
        const setupEyeButton = (button) => {
            if (!button) {
                return;
            }

            button.classList.add('btn', 'btn-link', 'me-3');
            button.style.cssText = `
                color: #64748b !important;
                padding: 0.5rem !important;
                border: none !important;
                background: none !important;
                transition: color 0.2s ease !important;
            `;
            if (!button.querySelector('#privacyEyeIcon')) {
                button.innerHTML = `<i class="fas fa-eye fa-lg" id="privacyEyeIcon"></i>`;
            }
            button.title = 'Toggle Privacy Mode - Show/Hide Amounts';

            if (!button.dataset.privacyBound) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleAmounts();
                });

                button.addEventListener('mouseenter', function() {
                    this.style.color = '#1e2936 !important';
                });

                button.addEventListener('mouseleave', function() {
                    this.style.color = '#64748b !important';
                });

                button.dataset.privacyBound = '1';
            }

            eyeButton = button;
            updateEyeButton();
        };

        // Wait a bit for DOM to be fully ready
        setTimeout(function() {
            const existingButton = document.getElementById('privacyEyeButton');
            if (existingButton) {
                setupEyeButton(existingButton);
                return;
            }

            const button = document.createElement('button');
            button.id = 'privacyEyeButton';
            setupEyeButton(button);

            // Find the top navbar container with notification bell and user dropdown
            // Look for the user dropdown first, then get its parent container
            const userDropdown = document.querySelector('#userDropdown');

            if (userDropdown && userDropdown.parentElement) {
                // Get the parent container (the d-flex div)
                const navbarContainer = userDropdown.parentElement.parentElement;
                // Insert button before the dropdown (between notification bell and user dropdown)
                navbarContainer.insertBefore(button, userDropdown.parentElement);
            }
        }, 300); // Wait 300ms for DOM to be ready
    }

    /**
     * Update eye button appearance
     */
    function updateEyeButton() {
        const icon = document.getElementById('privacyEyeIcon');
        if (!icon || !eyeButton) return;

        if (isHidden) {
            icon.className = 'fas fa-eye-slash';
            eyeButton.title = 'Amounts Hidden - Click to Show (Email Verification Required)';
        } else {
            icon.className = 'fas fa-eye';
            eyeButton.title = 'Amounts Visible - Click to Hide';
        }
    }

    /**
     * Initialize privacy mode
     */
    function init() {
        createPasswordModal();
        createEyeButton();

        // Default to hidden on init to avoid revealing amounts due to stale localStorage.
        // The server/session status (checked shortly after) will restore visibility if the session is unlocked.
        hideAmounts(true);
        const storedVisibility = getStoredVisibility();

        // Check if password was already entered in this session
        setTimeout(function() {
            checkSessionStatus();
        }, 200);

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(checkSessionStatus, 500);
            });
        }

        const observer = new MutationObserver(function() {
            if (isHidden) {
                hideAmounts(true);
                setDownloadButtonsDisabled(true);
            } else {
                setDownloadButtonsDisabled(false);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        document.addEventListener('click', function(event) {
            if (!isHidden) {
                return;
            }
            const target = event.target.closest('a, button, input[type="button"], input[type="submit"]');
            if (!target || !isDownloadElement(target)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
        }, true);

        window.addEventListener('storage', function(event) {
            if (event.key !== STORAGE_KEY) {
                return;
            }
            if (event.newValue === '1') {
                showAmounts();
            } else if (event.newValue === '0') {
                hideAmounts(true);
            }
        });
    }

    window.PrivacyMode = {
        hide: hideAmounts,
        show: showAmounts,
        toggle: toggleAmounts,
        isHidden: function() { return isHidden; }
    };

    function persistVisibility() {
        setStoredVisibility(isHidden ? '0' : '1');
    }

    function setStoredVisibility(value) {
        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch (error) {
            // Ignore storage errors (private mode, disabled storage)
        }
    }

    function getStoredVisibility() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (error) {
            return null;
        }
    }

    function shouldSkipNode(node) {
        const parent = node.parentElement;
        if (!parent) return true;
        const tag = parent.tagName;
        if (!tag) return true;
        // Skip nodes inside elements explicitly marked to bypass privacy masking
        if (parent.classList.contains(MASKED_CLASS) || parent.classList.contains('privacy-exempt') || (parent.closest && parent.closest('[data-privacy-exempt]'))) return true;
        const blockedTags = ['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT'];
        if (blockedTags.includes(tag)) return true;
        return false;
    }

    function maskTextNode(node) {
        const text = node.nodeValue;
        if (!text) return;
        AMOUNT_REGEX.lastIndex = 0;

        let match;
        let lastIndex = 0;
        let masked = false;
        const fragment = document.createDocumentFragment();

        while ((match = AMOUNT_REGEX.exec(text)) !== null) {
            masked = true;
            const preceding = text.slice(lastIndex, match.index);
            if (preceding) {
                fragment.appendChild(document.createTextNode(preceding));
            }

            const span = document.createElement('span');
            span.className = MASKED_CLASS;
            originalTextMap.set(span, match[0]);
            span.textContent = formatMaskedAmount(match[0]);
            const parent = node.parentElement;
            if (parent) {
                const color = window.getComputedStyle(parent).color;
                span.style.setProperty('--privacy-mask-color', color);
            }
            fragment.appendChild(span);

            lastIndex = match.index + match[0].length;
        }

        if (!masked) return;

        const trailing = text.slice(lastIndex);
        if (trailing) {
            fragment.appendChild(document.createTextNode(trailing));
        }

        node.parentNode.replaceChild(fragment, node);
        ensureMaskStyles();
    }

    function scrubLegacyMaskedSpans() {
        const legacyNodes = document.querySelectorAll('.' + MASKED_CLASS);
        legacyNodes.forEach(span => {
            if (originalTextMap.has(span)) {
                return;
            }
            const original = span.getAttribute('data-privacy-original') || span.textContent || '';
            originalTextMap.set(span, original);
            span.removeAttribute('data-privacy-original');
            span.removeAttribute('data-privacy-mask');
            span.textContent = formatMaskedAmount(original);
            const parent = span.parentElement;
            if (parent) {
                const color = window.getComputedStyle(parent).color;
                span.style.setProperty('--privacy-mask-color', color);
            }
        });
    }

    function formatMaskedAmount(amount) {
        const leading = amount.match(/^\s*/)[0];
        const trailing = amount.match(/\s*$/)[0];
        let core = amount.trim();

        let prefix = '';
        let suffix = '';

        if (core.startsWith('(') && core.endsWith(')')) {
            prefix = '(';
            suffix = ')';
            core = core.slice(1, -1).trim();
        }

        let masked = '*********';
        if (/^PHP/i.test(core)) {
            masked = 'PHP *********';
        } else if (/^P\\b/.test(core)) {
            masked = 'P*********';
        } else if (core.startsWith('₱')) {
            masked = '₱*********';
        } else {
            const symbol = core.charAt(0);
            if ('₱$€£¥'.includes(symbol)) {
                masked = symbol + '*********';
            }
        }

        return leading + prefix + masked + suffix + trailing;
    }

    function ensureMaskStyles() {
        if (document.getElementById('privacy-mask-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'privacy-mask-styles';
        style.textContent = `
            .${MASKED_CLASS} {
                white-space: pre;
                color: var(--privacy-mask-color, #1f2937);
            }
            .privacy-download-disabled {
                opacity: 0.55 !important;
                pointer-events: none !important;
                cursor: not-allowed !important;
            }
        `;
        document.head.appendChild(style);
    }

    function refreshIfServerMasked() {
        if (isHidden) {
            return;
        }
        if (!isServerMasked()) {
            clearRefreshFlag();
            return;
        }
        if (getRefreshFlag() === '1') {
            return;
        }
        setRefreshFlag('1');
        window.location.reload();
    }

    function isServerMasked() {
        if (document.querySelectorAll('.' + MASKED_CLASS).length > 0) {
            return true;
        }
        const text = document.body ? document.body.textContent || '' : '';
        return /(?:PHP|P|\?|\$)\s*\*{5,}/.test(text);
    }

    function setRefreshFlag(value) {
        try {
            localStorage.setItem(SERVER_REFRESH_KEY, value);
        } catch (error) {
            // Ignore storage errors
        }
    }

    function getRefreshFlag() {
        try {
            return localStorage.getItem(SERVER_REFRESH_KEY);
        } catch (error) {
            return null;
        }
    }

    function clearRefreshFlag() {
        setRefreshFlag('0');
    }

    function syncPrivacyVisibility(visible) {
        const apiPath = getApiPath('privacy_code.php');
        const formData = new URLSearchParams();
        formData.append('action', 'set_visibility');
        formData.append('visible', visible ? '1' : '0');

        fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData.toString()
        }).catch(() => {
            // Ignore visibility sync failures
        });
    }

    function prepareVerification() {
        verificationReady = false;

        const apiPath = getApiPath('privacy_code.php');
        const messageEl = document.getElementById('privacyVerificationMessage');
        const codeInput = document.getElementById('privacyCode');
        const verifyBtn = document.getElementById('privacyVerifyBtn');
        const errorDiv = document.getElementById('privacyCodeError');

        if (!messageEl || !codeInput || !verifyBtn) {
            return;
        }

        messageEl.className = 'alert alert-info';
        messageEl.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Checking verification method...';
        codeInput.disabled = true;
        verifyBtn.disabled = true;
        codeInput.value = '';
        codeInput.classList.remove('is-invalid');
        errorDiv.textContent = '';

        // First check if 2FA is configured for this user
        fetch(apiPath + '?action=check_method', {
            method: 'GET'
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    throw new Error(`HTTP ${response.status}: ${text || 'Unknown error'}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (!data.success) {
                throw new Error(data.error || 'Verification is not available for this account.');
            }

            // 2FA is configured, now request the code to be sent
            return fetch(apiPath + '?action=send_code', {
                method: 'GET'
            }).then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        throw new Error(`HTTP ${response.status}: ${text || 'Failed to send code'}`);
                    });
                }
                return response.json();
            });
        })
        .then(data => {
            if (!data.success) {
                throw new Error(data.error || 'Failed to send verification code.');
            }

            verificationReady = true;
            messageEl.className = 'alert alert-info';
            messageEl.innerHTML = '<i class="fas fa-envelope me-2"></i><strong>Protected Information</strong><br>A 6-digit code has been sent to your email. Enter it below.';
            codeInput.disabled = false;
            verifyBtn.disabled = false;
            setTimeout(() => codeInput.focus(), 100);
        })
        .catch(error => {
            verificationReady = false;
            messageEl.className = 'alert alert-warning';
            messageEl.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>' + (error.message || 'Verification is not configured for this account.');
            codeInput.disabled = true;
            verifyBtn.disabled = true;
        });
    }

    function isDownloadElement(element) {
        if (!element) {
            return false;
        }
        if (element.matches('a[download]')) {
            return true;
        }
        const text = (element.textContent || '').toLowerCase();
        const value = (element.value || '').toLowerCase();
        const attrs = [
            element.id || '',
            element.name || '',
            element.className || '',
            element.getAttribute('aria-label') || '',
            element.getAttribute('title') || ''
        ].join(' ').toLowerCase();

        if (text.includes('export') || text.includes('download') || value.includes('export') || value.includes('download')) {
            return true;
        }
        if (attrs.includes('export') || attrs.includes('download')) {
            return true;
        }

        if (element.matches('a[href]')) {
            const href = (element.getAttribute('href') || '').toLowerCase();
            if (href.includes('export') || href.includes('download')) {
                return true;
            }
            if (/\.(csv|xlsx|xls|pdf|zip|txt|json)(\?|#|$)/i.test(href)) {
                return true;
            }
        }
        return false;
    }

    function setDownloadButtonsDisabled(disabled) {
        ensureMaskStyles();
        const candidates = document.querySelectorAll('a, button, input[type="button"], input[type="submit"]');
        candidates.forEach(element => {
            if (!isDownloadElement(element)) {
                return;
            }

            if (disabled) {
                if (!element.hasAttribute('data-privacy-prev-disabled')) {
                    element.setAttribute('data-privacy-prev-disabled', element.disabled ? '1' : '0');
                }
                if (!element.hasAttribute('data-privacy-prev-tabindex')) {
                    const prevTabindex = element.getAttribute('tabindex');
                    element.setAttribute('data-privacy-prev-tabindex', prevTabindex === null ? '' : prevTabindex);
                }
                element.classList.add('privacy-download-disabled');
                element.setAttribute('aria-disabled', 'true');
                if (element.matches('button, input[type="button"], input[type="submit"]')) {
                    element.disabled = true;
                } else {
                    element.setAttribute('tabindex', '-1');
                }
            } else {
                const wasDisabled = element.getAttribute('data-privacy-prev-disabled');
                const prevTabindex = element.getAttribute('data-privacy-prev-tabindex');
                element.classList.remove('privacy-download-disabled');
                element.removeAttribute('aria-disabled');
                if (element.matches('button, input[type="button"], input[type="submit"]')) {
                    element.disabled = wasDisabled === '1';
                } else if (prevTabindex === '') {
                    element.removeAttribute('tabindex');
                } else if (prevTabindex !== null) {
                    element.setAttribute('tabindex', prevTabindex);
                }
                element.removeAttribute('data-privacy-prev-disabled');
                element.removeAttribute('data-privacy-prev-tabindex');
            }
        });
    }

    init();

})();
