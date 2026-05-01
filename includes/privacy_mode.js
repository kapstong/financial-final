/**
 * PRIVACY MODE - Show/hide amounts with OTP verification before reveal
 */

(function() {
    'use strict';

    if (window.__privacyModeInitialized) {
        return;
    }
    window.__privacyModeInitialized = true;

    let isHidden = true;
    let eyeButton = null;
    let verificationModalElement = null;
    let verificationModalInstance = null;
    let verificationForm = null;
    let verificationInput = null;
    let verificationAlert = null;
    let verificationVerifyButton = null;
    let verificationResendButton = null;

    const STORAGE_KEY = 'privacyModeVisible';
    const SERVER_REFRESH_KEY = 'privacyModeRefreshPending';
    const PRIVACY_API_FILE = 'privacy_code.php';
    const MASKED_CLASS = 'privacy-mask';
    const AMOUNT_REGEX = /(?:[\u20B1$\u20AC\u00A3\u00A5]\s*-?[\d,]+\.?\d*)|(?:PHP\s*-?[\d,]+\.?\d*)|(?:P\s*-?[\d,]+\.?\d*)|(?:\(\s*(?:[\u20B1$\u20AC\u00A3\u00A5P])?\s*-?[\d,]+\.?\d*\s*\))/g;
    const originalTextMap = new WeakMap();

    function hideAmounts(force, options) {
        if (!force && isHidden === false) {
            return;
        }

        const settings = resolveVisibilityOptions(options, {
            persist: true,
            refresh: false,
            sync: true
        });

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

        if (settings.persist) {
            persistVisibility();
        }
        if (settings.sync) {
            syncPrivacyVisibility(false);
        }
    }

    function showAmounts(options) {
        const settings = resolveVisibilityOptions(options, {
            persist: true,
            refresh: true,
            sync: true
        });

        const maskedNodes = document.querySelectorAll('.' + MASKED_CLASS);
        maskedNodes.forEach(function(span) {
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
        });

        isHidden = false;
        updateEyeButton();
        setDownloadButtonsDisabled(false);

        if (settings.persist) {
            persistVisibility();
        }
        if (settings.sync) {
            syncPrivacyVisibility(true);
        }
        if (settings.refresh) {
            refreshIfServerMasked();
        }
    }

    function toggleAmounts() {
        if (isHidden) {
            requestVisibilityUnlock();
            return;
        }

        hideAmounts(true);
    }

    function requestVisibilityUnlock() {
        if (!openVerificationModal()) {
            return;
        }

        sendVerificationCode();
    }

    function createEyeButton() {
        const setupEyeButton = function(button) {
            if (!button) {
                return;
            }

            button.classList.add('btn', 'btn-link', 'me-3');
            button.style.cssText = [
                'color: #64748b !important',
                'padding: 0.5rem !important',
                'border: none !important',
                'background: none !important',
                'transition: color 0.2s ease !important'
            ].join('; ');

            if (!button.querySelector('#privacyEyeIcon')) {
                button.innerHTML = '<i class="fas fa-eye fa-lg" id="privacyEyeIcon"></i>';
            }

            button.title = 'Toggle Privacy Mode - Show/Hide Amounts';

            if (!button.dataset.privacyBound) {
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleAmounts();
                });

                button.addEventListener('mouseenter', function() {
                    this.style.color = '#1e2936';
                });

                button.addEventListener('mouseleave', function() {
                    this.style.color = '#64748b';
                });

                button.dataset.privacyBound = '1';
            }

            eyeButton = button;
            updateEyeButton();
        };

        setTimeout(function() {
            const existingButton = document.getElementById('privacyEyeButton');
            if (existingButton) {
                setupEyeButton(existingButton);
                return;
            }

            const button = document.createElement('button');
            button.id = 'privacyEyeButton';
            setupEyeButton(button);

            const userDropdown = document.querySelector('#userDropdown');
            if (userDropdown && userDropdown.parentElement) {
                const navbarContainer = userDropdown.parentElement.parentElement;
                navbarContainer.insertBefore(button, userDropdown.parentElement);
            }
        }, 300);
    }

    function createVerificationModal() {
        if (verificationModalElement || !document.body) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.innerHTML = [
            '<div class="modal fade" id="privacyOtpModal" tabindex="-1" aria-labelledby="privacyOtpModalLabel" aria-hidden="true">',
            '    <div class="modal-dialog modal-dialog-centered">',
            '        <div class="modal-content shadow border-0">',
            '            <div class="modal-header">',
            '                <h5 class="modal-title" id="privacyOtpModalLabel">Verify to View Amounts</h5>',
            '                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>',
            '            </div>',
            '            <form id="privacyOtpForm" novalidate>',
            '                <div class="modal-body">',
            '                    <p class="text-muted mb-3">A 6-digit OTP will be sent to your account email before amounts are shown.</p>',
            '                    <div id="privacyOtpAlert" class="alert d-none mb-3" role="alert"></div>',
            '                    <label for="privacyOtpCodeInput" class="form-label fw-semibold">One-Time Password</label>',
            '                    <input type="text" class="form-control form-control-lg text-center" id="privacyOtpCodeInput" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="Enter 6-digit OTP">',
            '                    <div class="form-text">The OTP expires in 2 minutes.</div>',
            '                </div>',
            '                <div class="modal-footer justify-content-between">',
            '                    <button type="button" class="btn btn-outline-secondary" id="privacyOtpResendButton">Resend OTP</button>',
            '                    <div class="d-flex gap-2">',
            '                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>',
            '                        <button type="submit" class="btn btn-primary" id="privacyOtpVerifyButton">Verify OTP</button>',
            '                    </div>',
            '                </div>',
            '            </form>',
            '        </div>',
            '    </div>',
            '</div>'
        ].join('');

        verificationModalElement = wrapper.firstElementChild;
        document.body.appendChild(verificationModalElement);

        verificationForm = document.getElementById('privacyOtpForm');
        verificationInput = document.getElementById('privacyOtpCodeInput');
        verificationAlert = document.getElementById('privacyOtpAlert');
        verificationVerifyButton = document.getElementById('privacyOtpVerifyButton');
        verificationResendButton = document.getElementById('privacyOtpResendButton');

        if (verificationForm) {
            verificationForm.addEventListener('submit', handleVerificationSubmit);
        }

        if (verificationInput) {
            verificationInput.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
            });
        }

        if (verificationResendButton) {
            verificationResendButton.addEventListener('click', function(event) {
                event.preventDefault();
                sendVerificationCode();
            });
        }

        if (verificationModalElement) {
            verificationModalElement.addEventListener('shown.bs.modal', function() {
                if (verificationInput) {
                    verificationInput.focus();
                    verificationInput.select();
                }
            });

            verificationModalElement.addEventListener('hidden.bs.modal', function() {
                resetVerificationModal();
            });
        }
    }

    function openVerificationModal() {
        createVerificationModal();

        if (!verificationModalElement || !window.bootstrap || !bootstrap.Modal) {
            window.alert('The verification dialog is unavailable on this page.');
            return false;
        }

        resetVerificationModal();
        verificationModalInstance = bootstrap.Modal.getOrCreateInstance(verificationModalElement);
        verificationModalInstance.show();
        return true;
    }

    function resetVerificationModal() {
        setVerificationRequestState('idle');
        clearVerificationAlert();

        if (verificationInput) {
            verificationInput.value = '';
            verificationInput.disabled = false;
        }
    }

    async function sendVerificationCode() {
        if (!verificationModalElement) {
            return;
        }

        setVerificationAlert('Sending OTP to your account email...', 'info');
        setVerificationRequestState('sending');

        try {
            const result = await requestPrivacyApi('send_code', {
                method: 'POST'
            });

            setVerificationAlert(result.message || 'OTP sent to your account email.', 'success');
        } catch (error) {
            setVerificationAlert(error.message || 'Failed to send OTP.', 'danger');
        } finally {
            setVerificationRequestState('idle');
            if (verificationInput) {
                verificationInput.focus();
            }
        }
    }

    async function handleVerificationSubmit(event) {
        if (event) {
            event.preventDefault();
        }

        if (!verificationInput) {
            return;
        }

        const code = verificationInput.value.replace(/\D/g, '').slice(0, 6);
        verificationInput.value = code;

        if (!/^\d{6}$/.test(code)) {
            setVerificationAlert('Enter a valid 6-digit OTP.', 'danger');
            verificationInput.focus();
            verificationInput.select();
            return;
        }

        setVerificationAlert('Verifying OTP...', 'info');
        setVerificationRequestState('verifying');

        try {
            await requestPrivacyApi('verify_code', {
                data: { code: code },
                method: 'POST'
            });

            if (verificationModalInstance) {
                verificationModalInstance.hide();
            }

            showAmounts({
                persist: true,
                refresh: true,
                sync: false
            });
        } catch (error) {
            setVerificationAlert(error.message || 'Invalid or expired OTP.', 'danger');
            verificationInput.focus();
            verificationInput.select();
        } finally {
            setVerificationRequestState('idle');
        }
    }

    function setVerificationRequestState(state) {
        const isBusy = state === 'sending' || state === 'verifying';

        if (verificationVerifyButton) {
            verificationVerifyButton.disabled = isBusy;
            verificationVerifyButton.textContent = state === 'verifying' ? 'Verifying...' : 'Verify OTP';
        }

        if (verificationResendButton) {
            verificationResendButton.disabled = isBusy;
            verificationResendButton.textContent = state === 'sending' ? 'Sending...' : 'Resend OTP';
        }

        if (verificationInput) {
            verificationInput.disabled = isBusy;
        }
    }

    function setVerificationAlert(message, type) {
        if (!verificationAlert) {
            return;
        }

        verificationAlert.className = 'alert mb-3 alert-' + type;
        verificationAlert.textContent = message;
        verificationAlert.classList.remove('d-none');
    }

    function clearVerificationAlert() {
        if (!verificationAlert) {
            return;
        }

        verificationAlert.className = 'alert d-none mb-3';
        verificationAlert.textContent = '';
    }

    async function syncWithServerStatus() {
        try {
            const status = await requestPrivacyApi('check_status', {
                method: 'GET'
            });

            if (status.visible) {
                showAmounts({
                    persist: false,
                    refresh: false,
                    sync: false
                });
                return;
            }
        } catch (error) {}

        hideAmounts(true, {
            persist: false,
            refresh: false,
            sync: false
        });
    }

    function updateEyeButton() {
        const icon = document.getElementById('privacyEyeIcon');
        if (!icon || !eyeButton) {
            return;
        }

        if (isHidden) {
            icon.className = 'fas fa-eye-slash';
            eyeButton.title = 'Amounts Hidden - Click to Request OTP';
            return;
        }

        icon.className = 'fas fa-eye';
        eyeButton.title = 'Amounts Visible - Click to Hide';
    }

    function init() {
        createEyeButton();
        createVerificationModal();
        ensureMaskStyles();
        hideAmounts(true, {
            persist: false,
            refresh: false,
            sync: false
        });
        syncWithServerStatus();

        const observer = new MutationObserver(function() {
            if (isHidden) {
                hideAmounts(true, {
                    persist: false,
                    refresh: false,
                    sync: false
                });
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
                showAmounts({
                    persist: false,
                    refresh: true,
                    sync: false
                });
            } else if (event.newValue === '0') {
                hideAmounts(true, {
                    persist: false,
                    refresh: false,
                    sync: false
                });
            }
        });
    }

    window.PrivacyMode = {
        hide: function() {
            hideAmounts(true);
        },
        isHidden: function() {
            return isHidden;
        },
        show: function() {
            requestVisibilityUnlock();
        },
        toggle: toggleAmounts
    };

    function resolveVisibilityOptions(options, defaults) {
        return Object.assign({}, defaults, options || {});
    }

    function persistVisibility() {
        setStoredVisibility(isHidden ? '0' : '1');
    }

    function setStoredVisibility(value) {
        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch (error) {
            // Ignore storage errors
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
        if (!parent) {
            return true;
        }

        const tag = parent.tagName;
        if (!tag) {
            return true;
        }

        if (parent.classList.contains(MASKED_CLASS) || parent.classList.contains('privacy-exempt') || (parent.closest && parent.closest('[data-privacy-exempt]'))) {
            return true;
        }

        return ['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT'].includes(tag);
    }

    function maskTextNode(node) {
        const text = node.nodeValue;
        if (!text) {
            return;
        }

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

        if (!masked) {
            return;
        }

        const trailing = text.slice(lastIndex);
        if (trailing) {
            fragment.appendChild(document.createTextNode(trailing));
        }

        node.parentNode.replaceChild(fragment, node);
        ensureMaskStyles();
    }

    function scrubLegacyMaskedSpans() {
        const legacyNodes = document.querySelectorAll('.' + MASKED_CLASS);
        legacyNodes.forEach(function(span) {
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
        } else if (/^P\b/.test(core)) {
            masked = 'P*********';
        } else if (core.startsWith('\u20B1')) {
            masked = '\u20B1*********';
        } else {
            const symbol = core.charAt(0);
            if ('\u20B1$\u20AC\u00A3\u00A5'.includes(symbol)) {
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
        return /(?:PHP|P|\u20B1|\$)\s*\*{5,}/.test(text);
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
        const formData = new URLSearchParams();
        formData.append('action', 'set_visibility');
        formData.append('visible', visible ? '1' : '0');

        const csrfToken = getCsrfToken();
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }

        fetch(getApiPath(PRIVACY_API_FILE), {
            body: formData.toString(),
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            keepalive: true,
            method: 'POST'
        }).catch(function() {
            // Ignore visibility sync failures
        });
    }

    async function requestPrivacyApi(action, options) {
        const settings = Object.assign({
            data: {},
            method: 'POST'
        }, options || {});

        const method = settings.method.toUpperCase();
        const fetchOptions = {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            method: method
        };

        let url = getApiPath(PRIVACY_API_FILE);

        if (method === 'GET') {
            const params = new URLSearchParams();
            params.append('action', action);

            Object.keys(settings.data || {}).forEach(function(key) {
                params.append(key, settings.data[key]);
            });

            url += (url.indexOf('?') === -1 ? '?' : '&') + params.toString();
        } else {
            const formData = new URLSearchParams();
            formData.append('action', action);

            const csrfToken = getCsrfToken();
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }

            Object.keys(settings.data || {}).forEach(function(key) {
                formData.append(key, settings.data[key]);
            });

            fetchOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            fetchOptions.body = formData.toString();
        }

        const response = await fetch(url, fetchOptions);
        let payload = null;

        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an invalid response.');
        }

        if (!response.ok || payload.error || payload.success === false) {
            throw new Error(payload.error || payload.message || 'The request could not be completed.');
        }

        return payload;
    }

    function getCsrfToken() {
        const tokenNode = document.querySelector('meta[name="csrf-token"]');
        return tokenNode ? tokenNode.getAttribute('content') : '';
    }

    function getApiPath(filename) {
        const pathname = window.location.pathname;

        if (pathname.includes('/integ-capstone/')) {
            const match = pathname.match(/^(.+?\/integ-capstone)/);
            if (match) {
                return match[1] + '/api/' + filename;
            }
        }

        const parts = pathname.split('/').filter(function(part) {
            return part.length > 0;
        });

        if (parts.length >= 2) {
            const lastFolder = parts[parts.length - 2];
            if (['superadmin', 'staff', 'admin', 'hotels', 'restaurants'].includes(lastFolder)) {
                return '../api/' + filename;
            }
        }

        return '/api/' + filename;
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
        candidates.forEach(function(element) {
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

                return;
            }

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
        });
    }

    init();
})();
