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
    let verificationModal = null;
    let activeVerificationRequest = 0;

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

    function createPasswordModal() {
        if (verificationModal) {
            return verificationModal;
        }

        ensureMaskStyles();

        const wrapper = document.createElement('div');
        wrapper.id = 'privacyPasswordModal';
        wrapper.className = 'privacy-otp-modal';
        wrapper.setAttribute('data-privacy-exempt', 'true');
        wrapper.setAttribute('aria-hidden', 'true');
        wrapper.innerHTML = `
            <div class="privacy-otp-backdrop" data-privacy-close="1"></div>
            <div class="privacy-otp-dialog" role="dialog" aria-modal="true" aria-labelledby="privacyOtpTitle" data-privacy-exempt="true">
                <div class="privacy-otp-card" data-privacy-exempt="true">
                    <div class="privacy-otp-header">
                        <div class="privacy-otp-icon"><i class="fas fa-shield-alt"></i></div>
                        <div>
                            <h2 id="privacyOtpTitle">Verification Required</h2>
                            <p>We will send a 6-digit code to your email before amounts can be shown.</p>
                        </div>
                    </div>
                    <div class="privacy-otp-body">
                        <div id="privacyOtpStatus" class="privacy-otp-banner privacy-otp-banner-info">
                            <span class="privacy-otp-spinner" aria-hidden="true"></span>
                            <span id="privacyOtpStatusText">Sending your verification code...</span>
                        </div>
                        <div id="privacyOtpSuccess" class="privacy-otp-banner privacy-otp-banner-success" hidden>
                            <i class="fas fa-check-circle"></i>
                            <span>Code sent. Check the user email and enter it below.</span>
                        </div>
                        <div id="privacyCodeError" class="privacy-otp-banner privacy-otp-banner-error" hidden></div>
                        <form id="privacyCodeForm" novalidate>
                            <label class="privacy-otp-label" for="privacyCode">Enter 6-Digit Code</label>
                            <input
                                type="text"
                                id="privacyCode"
                                class="privacy-otp-input"
                                placeholder="000000"
                                maxlength="6"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                aria-describedby="privacyOtpHint"
                                disabled
                            >
                            <p id="privacyOtpHint" class="privacy-otp-hint">Only digits are accepted. The code expires in 2 minutes.</p>
                            <div class="privacy-otp-actions-row">
                                <button type="button" id="privacyResendBtn" class="privacy-otp-link">Resend code</button>
                            </div>
                        </form>
                    </div>
                    <div class="privacy-otp-footer">
                        <button type="button" id="privacyCancelBtn" class="privacy-otp-button privacy-otp-button-secondary">Cancel</button>
                        <button type="button" id="privacyVerifyBtn" class="privacy-otp-button privacy-otp-button-primary" disabled>Verify & Show Amounts</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(wrapper);
        verificationModal = wrapper;

        const form = document.getElementById('privacyCodeForm');
        const codeInput = document.getElementById('privacyCode');
        const verifyBtn = document.getElementById('privacyVerifyBtn');
        const resendBtn = document.getElementById('privacyResendBtn');
        const cancelBtn = document.getElementById('privacyCancelBtn');
        const errorDiv = document.getElementById('privacyCodeError');

        if (form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                verifyCodeAndShow();
            });
        }

        if (verifyBtn) {
            verifyBtn.addEventListener('click', verifyCodeAndShow);
        }

        if (resendBtn) {
            resendBtn.addEventListener('click', function() {
                prepareVerification();
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', closePasswordModal);
        }

        wrapper.addEventListener('click', function(event) {
            if (event.target && event.target.getAttribute('data-privacy-close') === '1') {
                closePasswordModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && verificationModal && verificationModal.classList.contains('is-open')) {
                closePasswordModal();
            }
        });

        if (codeInput) {
            codeInput.addEventListener('input', function(event) {
                const normalized = event.target.value.replace(/\D/g, '').slice(0, 6);
                if (event.target.value !== normalized) {
                    event.target.value = normalized;
                }
                event.target.classList.remove('is-invalid');
                hideVerificationError();
                toggleVerifyButtonState();
            });

            codeInput.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    verifyCodeAndShow();
                }
            });
        }

        toggleVerifyButtonState();
        return verificationModal;
    }

    /**
     * Verify OTP code and show amounts - Clean, bulletproof
     */
    function verifyCodeAndShow() {
        const elements = getVerificationElements();
        const codeInput = elements.codeInput;
        const verifyBtn = elements.verifyBtn;
        const code = codeInput ? codeInput.value.trim() : '';

        if (!codeInput || !verifyBtn) {
            return;
        }

        if (!verificationReady) {
            showVerificationError('Wait for the code to be sent before verifying.');
            return;
        }

        if (!code || code.length !== 6 || !/^\d{6}$/.test(code)) {
            codeInput.classList.add('is-invalid');
            showVerificationError('Please enter a valid 6-digit code.');
            return;
        }

        hideVerificationError();
        const originalBtnState = verifyBtn.disabled;
        const originalBtnText = verifyBtn.textContent;
        verifyBtn.textContent = 'Verifying...';
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
                showAmounts();
                codeInput.value = '';
                codeInput.classList.remove('is-invalid');
                closePasswordModal();
            } else {
                throw new Error(data.error || 'Invalid code');
            }
        })
        .catch(error => {
            codeInput.classList.add('is-invalid');
            showVerificationError(error.message || 'Verification failed. Please try again.');
            verifyBtn.textContent = originalBtnText;
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

    function getVerificationElements() {
        return {
            modal: verificationModal || document.getElementById('privacyPasswordModal'),
            codeInput: document.getElementById('privacyCode'),
            verifyBtn: document.getElementById('privacyVerifyBtn'),
            resendBtn: document.getElementById('privacyResendBtn'),
            cancelBtn: document.getElementById('privacyCancelBtn'),
            errorDiv: document.getElementById('privacyCodeError'),
            statusDiv: document.getElementById('privacyOtpStatus'),
            statusText: document.getElementById('privacyOtpStatusText'),
            successDiv: document.getElementById('privacyOtpSuccess')
        };
    }

    function hideVerificationError() {
        const elements = getVerificationElements();
        if (elements.errorDiv) {
            elements.errorDiv.hidden = true;
            elements.errorDiv.textContent = '';
        }
    }

    function showVerificationError(message) {
        const elements = getVerificationElements();
        if (elements.errorDiv) {
            elements.errorDiv.textContent = message;
            elements.errorDiv.hidden = false;
        }
    }

    function setVerificationStatus(message, variant) {
        const elements = getVerificationElements();
        if (!elements.statusDiv || !elements.statusText) {
            return;
        }

        elements.statusText.textContent = message;
        elements.statusDiv.hidden = false;
        elements.statusDiv.classList.toggle('is-error', variant === 'error');
        elements.statusDiv.classList.toggle('is-success', variant === 'success');
    }

    function hideVerificationStatus() {
        const elements = getVerificationElements();
        if (elements.statusDiv) {
            elements.statusDiv.hidden = true;
            elements.statusDiv.classList.remove('is-error', 'is-success');
        }
    }

    function showVerificationSuccess(message) {
        const elements = getVerificationElements();
        if (elements.successDiv) {
            const span = elements.successDiv.querySelector('span');
            if (span) {
                span.textContent = message;
            }
            elements.successDiv.hidden = false;
        }
    }

    function hideVerificationSuccess() {
        const elements = getVerificationElements();
        if (elements.successDiv) {
            elements.successDiv.hidden = true;
        }
    }

    function toggleVerifyButtonState() {
        const elements = getVerificationElements();
        if (!elements.verifyBtn || !elements.codeInput) {
            return;
        }

        const ready = verificationReady && /^\d{6}$/.test(elements.codeInput.value.trim());
        elements.verifyBtn.disabled = !ready;
    }

    function resetVerificationModal() {
        const elements = getVerificationElements();
        verificationReady = false;
        hideVerificationError();
        hideVerificationSuccess();
        setVerificationStatus('Sending your verification code...', 'info');

        if (elements.codeInput) {
            elements.codeInput.value = '';
            elements.codeInput.disabled = true;
            elements.codeInput.classList.remove('is-invalid');
        }
        if (elements.verifyBtn) {
            elements.verifyBtn.disabled = true;
            elements.verifyBtn.textContent = 'Verify & Show Amounts';
        }
        if (elements.resendBtn) {
            elements.resendBtn.disabled = true;
        }
    }

    function openPasswordModal() {
        const modal = createPasswordModal();
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('privacy-otp-open');

        const elements = getVerificationElements();
        if (elements.codeInput) {
            setTimeout(function() {
                elements.codeInput.focus();
            }, 50);
        }
    }

    function closePasswordModal() {
        const elements = getVerificationElements();
        if (!elements.modal) {
            return;
        }

        elements.modal.classList.remove('is-open');
        elements.modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('privacy-otp-open');
        hideVerificationError();
    }

    function showPasswordModal() {
        openPasswordModal();
        prepareVerification();
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
            body.privacy-otp-open {
                overflow: hidden;
            }
            .privacy-otp-modal {
                position: fixed;
                inset: 0;
                display: none;
                align-items: center;
                justify-content: center;
                z-index: 2000;
                padding: 24px;
            }
            .privacy-otp-modal.is-open {
                display: flex;
            }
            .privacy-otp-backdrop {
                position: absolute;
                inset: 0;
                background: rgba(15, 23, 42, 0.56);
                backdrop-filter: blur(3px);
            }
            .privacy-otp-dialog {
                position: relative;
                width: min(100%, 520px);
                z-index: 1;
            }
            .privacy-otp-card {
                background: #ffffff;
                border-radius: 20px;
                box-shadow: 0 28px 80px rgba(15, 23, 42, 0.28);
                overflow: hidden;
                color: #1f2937;
            }
            .privacy-otp-header {
                display: flex;
                gap: 16px;
                align-items: flex-start;
                padding: 24px 24px 18px;
                background: linear-gradient(135deg, #0f2f57 0%, #184a7a 100%);
                color: #ffffff;
            }
            .privacy-otp-header h2 {
                margin: 0 0 6px;
                font-size: 28px;
                font-weight: 800;
                line-height: 1.1;
            }
            .privacy-otp-header p {
                margin: 0;
                color: rgba(255, 255, 255, 0.84);
                font-size: 14px;
                line-height: 1.45;
            }
            .privacy-otp-icon {
                width: 48px;
                height: 48px;
                border-radius: 14px;
                background: rgba(255, 255, 255, 0.14);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                font-size: 20px;
            }
            .privacy-otp-body {
                padding: 24px;
            }
            .privacy-otp-label {
                display: block;
                margin-bottom: 10px;
                font-size: 14px;
                font-weight: 700;
                color: #334155;
            }
            .privacy-otp-input {
                width: 100%;
                border: 2px solid #d8e1ec;
                border-radius: 16px;
                background: #f8fafc;
                color: #0f172a;
                font-size: 36px;
                font-weight: 800;
                line-height: 1;
                text-align: center;
                letter-spacing: 0.45em;
                padding: 18px 20px;
                outline: none;
                transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
            }
            .privacy-otp-input:focus {
                border-color: #0f766e;
                box-shadow: 0 0 0 4px rgba(15, 118, 110, 0.12);
                background: #ffffff;
            }
            .privacy-otp-input:disabled {
                opacity: 0.72;
                cursor: not-allowed;
            }
            .privacy-otp-input.is-invalid {
                border-color: #dc2626;
                box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.08);
            }
            .privacy-otp-hint {
                margin: 10px 0 0;
                font-size: 13px;
                color: #64748b;
            }
            .privacy-otp-actions-row {
                display: flex;
                justify-content: flex-end;
                margin-top: 12px;
            }
            .privacy-otp-link {
                border: 0;
                background: transparent;
                color: #0f766e;
                font-weight: 700;
                padding: 0;
            }
            .privacy-otp-link:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            .privacy-otp-banner {
                display: flex;
                align-items: center;
                gap: 10px;
                border-radius: 14px;
                padding: 14px 16px;
                margin-bottom: 16px;
                font-size: 14px;
                line-height: 1.4;
            }
            .privacy-otp-banner-info {
                background: #ecfeff;
                color: #155e75;
                border: 1px solid #a5f3fc;
            }
            .privacy-otp-banner-success {
                background: #ecfdf5;
                color: #166534;
                border: 1px solid #86efac;
            }
            .privacy-otp-banner-error,
            .privacy-otp-banner.is-error {
                background: #fef2f2;
                color: #991b1b;
                border: 1px solid #fecaca;
            }
            .privacy-otp-spinner {
                width: 16px;
                height: 16px;
                border-radius: 999px;
                border: 2px solid rgba(21, 94, 117, 0.22);
                border-top-color: #155e75;
                animation: privacy-otp-spin 0.8s linear infinite;
                flex-shrink: 0;
            }
            .privacy-otp-footer {
                display: flex;
                justify-content: flex-end;
                gap: 12px;
                padding: 0 24px 24px;
            }
            .privacy-otp-button {
                border: 0;
                border-radius: 14px;
                padding: 14px 18px;
                font-size: 15px;
                font-weight: 800;
                line-height: 1;
                transition: transform 0.15s ease, opacity 0.15s ease;
            }
            .privacy-otp-button:hover:not(:disabled) {
                transform: translateY(-1px);
            }
            .privacy-otp-button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .privacy-otp-button-secondary {
                background: #e2e8f0;
                color: #334155;
            }
            .privacy-otp-button-primary {
                background: linear-gradient(135deg, #0f766e 0%, #22c55e 100%);
                color: #ffffff;
            }
            @keyframes privacy-otp-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            @media (max-width: 640px) {
                .privacy-otp-modal {
                    padding: 16px;
                }
                .privacy-otp-header,
                .privacy-otp-body,
                .privacy-otp-footer {
                    padding-left: 18px;
                    padding-right: 18px;
                }
                .privacy-otp-header h2 {
                    font-size: 24px;
                }
                .privacy-otp-input {
                    font-size: 28px;
                    letter-spacing: 0.35em;
                    padding: 16px;
                }
                .privacy-otp-footer {
                    flex-direction: column-reverse;
                }
                .privacy-otp-button {
                    width: 100%;
                }
            }
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
        const requestId = ++activeVerificationRequest;
        const apiPath = getApiPath('otp.php');
        const elements = getVerificationElements();
        const codeInput = elements.codeInput;
        const verifyBtn = elements.verifyBtn;
        const resendBtn = elements.resendBtn;

        if (!codeInput || !verifyBtn) {
            return;
        }

        resetVerificationModal();

        fetch(apiPath + '?action=send', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            }
        })
        .then(parseApiResponse)
        .then(data => {
            if (requestId !== activeVerificationRequest) {
                return;
            }

            if (data.success) {
                verificationReady = true;
                hideVerificationStatus();
                showVerificationSuccess('Code sent. Check the user email and enter it below.');
                codeInput.disabled = false;
                if (resendBtn) {
                    resendBtn.disabled = false;
                }
                toggleVerifyButtonState();
                setTimeout(() => {
                    codeInput.focus();
                }, 200);
            } else {
                throw new Error(data.error || 'Failed to send OTP');
            }
        })
        .catch(error => {
            if (requestId !== activeVerificationRequest) {
                return;
            }

            verificationReady = false;
            hideVerificationStatus();
            hideVerificationSuccess();
            showVerificationError(error.message || 'Failed to send OTP.');
            codeInput.disabled = false;
            if (resendBtn) {
                resendBtn.disabled = false;
            }
            toggleVerifyButtonState();
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
