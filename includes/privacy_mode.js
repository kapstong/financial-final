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
     * Create verification OTP modal - Professional, clean design
     */
    function createPasswordModal() {
        const modalHTML = `
            <div id="privacyPasswordModal" class="modal fade" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content shadow-lg" style="border-radius: 12px; border: none;">
                        <div class="modal-header bg-gradient text-white border-0" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px 12px 0 0; padding: 2rem;">
                            <div>
                                <h5 class="modal-title mb-0 fw-bold" style="font-size: 1.5rem;">
                                    <i class="fas fa-lock me-2"></i>Security Verification
                                </h5>
                                <small class="text-white-50 mt-1">One-Time Password</small>
                            </div>
                        </div>
                        <div class="modal-body" style="padding: 2.5rem 2rem;">
                            <div class="text-center mb-4">
                                <div class="mb-3">
                                    <i class="fas fa-shield-alt" style="font-size: 3rem; color: #667eea; opacity: 0.3;"></i>
                                </div>
                                <p class="text-dark fw-bold mb-1" style="font-size: 1.1rem;">Verify Your Identity</p>
                                <p class="text-muted">We've sent a 6-digit code to your email</p>
                            </div>

                            <form id="privacyCodeForm">
                                <div class="mb-3">
                                    <label for="privacyCode" class="form-label fw-bold text-dark">Enter Code</label>
                                    <div class="position-relative">
                                        <input type="text" class="form-control form-control-lg text-center"
                                               id="privacyCode" placeholder="000000" maxlength="6"
                                               pattern="[0-9]{6}" required autofocus
                                               autocomplete="one-time-code"
                                               inputmode="numeric"
                                               style="font-size: 2.5rem; letter-spacing: 12px; font-weight: 700; border: 2px solid #e0e0e0; border-radius: 10px; transition: all 0.3s ease; padding: 1rem;">
                                        <small class="form-text text-muted d-block mt-2 text-center">Enter the 6 digits from your email</small>
                                    </div>
                                    <div class="invalid-feedback d-block mt-2" id="privacyCodeError" style="display: none; color: #dc3545; font-weight: 500;"></div>
                                </div>

                                <div id="privacyOtpStatus" class="alert alert-info d-none" style="border-radius: 8px; margin-top: 1rem;">
                                    <span class="spinner-border spinner-border-sm me-2"></span>
                                    <span id="privacyOtpStatusText">Sending code to your email...</span>
                                </div>
                            </form>

                            <div id="privacyOtpSuccess" class="alert alert-success d-none" style="border-radius: 8px; margin-top: 1rem;">
                                <i class="fas fa-check-circle me-2"></i>
                                <span>OTP sent successfully!</span>
                            </div>
                        </div>
                        <div class="modal-footer border-0" style="padding: 1.5rem 2rem;">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 500;">Cancel</button>
                            <button type="button" class="btn btn-primary" id="privacyVerifyBtn" style="padding: 0.75rem 2rem; border-radius: 8px; font-weight: 500; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                                <i class="fas fa-check me-2"></i>Verify
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Event listeners
        const form = document.getElementById('privacyCodeForm');
        const codeInput = document.getElementById('privacyCode');
        const verifyBtn = document.getElementById('privacyVerifyBtn');
        const errorDiv = document.getElementById('privacyCodeError');

        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                verifyCodeAndShow();
            });
        }

        if (verifyBtn) {
            verifyBtn.addEventListener('click', verifyCodeAndShow);
        }

        // Auto-verify when 6 digits entered
        if (codeInput) {
            codeInput.addEventListener('input', (e) => {
                const code = e.target.value;
                e.target.classList.remove('is-invalid');
                if (errorDiv) errorDiv.style.display = 'none';
                
                // Add visual feedback while typing
                if (code.length === 6 && /^\d{6}$/.test(code)) {
                    setTimeout(() => verifyCodeAndShow(), 300);
                }
            });

            // Visual feedback on focus
            codeInput.addEventListener('focus', (e) => {
                e.target.style.borderColor = '#667eea';
                e.target.style.boxShadow = '0 0 0 3px rgba(102, 126, 234, 0.1)';
            });

            codeInput.addEventListener('blur', (e) => {
                e.target.style.borderColor = '#e0e0e0';
                e.target.style.boxShadow = 'none';
            });
        }
    }

    /**
     * Verify OTP code and show amounts - Clean, bulletproof
     */
    function verifyCodeAndShow() {
        const codeInput = document.getElementById('privacyCode');
        const errorDiv = document.getElementById('privacyCodeError');
        const verifyBtn = document.getElementById('privacyVerifyBtn');
        const code = codeInput.value.trim();

        if (!code || code.length !== 6 || !/^\d{6}$/.test(code)) {
            codeInput.classList.add('is-invalid');
            if (errorDiv) {
                errorDiv.textContent = 'Please enter a valid 6-digit code';
                errorDiv.style.display = 'block';
            }
            return;
        }

        const originalBtnText = verifyBtn.innerHTML;
        const originalBtnState = verifyBtn.disabled;
        verifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';
        verifyBtn.disabled = true;
        codeInput.disabled = true;

        const apiPath = getApiPath('otp.php');

        fetch(apiPath + '?action=verify', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'code=' + encodeURIComponent(code)
        })
        .then(parseApiResponse)
        .then(data => {
            if (data.success) {
                // Success - show amounts
                showAmounts();
                codeInput.value = '';
                codeInput.classList.remove('is-invalid');
                if (errorDiv) errorDiv.style.display = 'none';
                
                // Close modal
                const modalEl = document.getElementById('privacyPasswordModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }
            } else {
                throw new Error(data.error || 'Invalid code');
            }
        })
        .catch(error => {
            // Error - show message and reset
            codeInput.classList.add('is-invalid');
            if (errorDiv) {
                errorDiv.textContent = error.message || 'Verification failed. Please try again.';
                errorDiv.style.display = 'block';
            }
            verifyBtn.innerHTML = originalBtnText;
            verifyBtn.disabled = originalBtnState;
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

    function parseApiResponse(response) {
        return response.text().then(text => {
            let data = null;

            if (text) {
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    console.error('API JSON parse error:', error, 'Text:', text);
                    throw new Error('Server returned an invalid response');
                }
            }

            if (!response.ok) {
                const message = data && (data.error || data.message);
                throw new Error(message || ('HTTP ' + response.status));
            }

            return data || {};
        });
    }

    function prepareVerification() {
        verificationReady = false;

        const apiPath = getApiPath('otp.php');
        const codeInput = document.getElementById('privacyCode');
        const verifyBtn = document.getElementById('privacyVerifyBtn');
        const errorDiv = document.getElementById('privacyCodeError');
        const statusDiv = document.getElementById('privacyOtpStatus');
        const successDiv = document.getElementById('privacyOtpSuccess');

        if (!codeInput || !verifyBtn) {
            return;
        }

        // Reset form
        codeInput.disabled = true;
        verifyBtn.disabled = true;
        codeInput.value = '';
        codeInput.classList.remove('is-invalid');
        if (errorDiv) errorDiv.style.display = 'none';
        if (successDiv) successDiv.classList.add('d-none');
        if (statusDiv) statusDiv.classList.remove('d-none');

        // Send OTP to user's email
        fetch(apiPath + '?action=send', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            }
        })
        .then(parseApiResponse)
        .then(data => {
            if (data.success) {
                verificationReady = true;
                if (statusDiv) statusDiv.classList.add('d-none');
                if (successDiv) successDiv.classList.remove('d-none');
                codeInput.disabled = false;
                verifyBtn.disabled = false;
                setTimeout(() => {
                    codeInput.focus();
                }, 200);
            } else {
                throw new Error(data.error || 'Failed to send OTP');
            }
        })
        .catch(error => {
            verificationReady = false;
            if (statusDiv) statusDiv.classList.add('d-none');
            if (errorDiv) {
                errorDiv.textContent = 'Error: ' + (error.message || 'Failed to send OTP');
                errorDiv.style.display = 'block';
            }
            codeInput.disabled = false;
            verifyBtn.disabled = false;
            console.error('prepareVerification error:', error);
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
