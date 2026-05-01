/**
 * PRIVACY MODE - Simple show/hide amount visibility
 */

(function() {
    'use strict';

    let isHidden = true;
    let eyeButton = null;
    const STORAGE_KEY = 'privacyModeVisible';
    const SERVER_REFRESH_KEY = 'privacyModeRefreshPending';

    const AMOUNT_REGEX = /(?:[â‚±$â‚¬Â£Â¥]\s*-?[\d,]+\.?\d*)|(?:PHP\s*-?[\d,]+\.?\d*)|(?:P\s*-?[\d,]+\.?\d*)|(?:\(\s*(?:[â‚±$â‚¬Â£Â¥P])?\s*-?[\d,]+\.?\d*\s*\))/g;
    const MASKED_CLASS = 'privacy-mask';
    const originalTextMap = new WeakMap();

    function hideAmounts(force) {
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

    function showAmounts() {
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
        persistVisibility();
        setDownloadButtonsDisabled(false);
        syncPrivacyVisibility(true);
        refreshIfServerMasked();
    }

    function toggleAmounts() {
        if (isHidden) {
            showAmounts();
            return;
        }
        hideAmounts(true);
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

    function updateEyeButton() {
        const icon = document.getElementById('privacyEyeIcon');
        if (!icon || !eyeButton) {
            return;
        }

        if (isHidden) {
            icon.className = 'fas fa-eye-slash';
            eyeButton.title = 'Amounts Hidden - Click to Show';
            return;
        }

        icon.className = 'fas fa-eye';
        eyeButton.title = 'Amounts Visible - Click to Hide';
    }

    function init() {
        createEyeButton();
        ensureMaskStyles();

        const storedVisibility = getStoredVisibility();
        if (storedVisibility === '1') {
            showAmounts();
        } else {
            hideAmounts(true);
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
        isHidden: function() {
            return isHidden;
        }
    };

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
        } else if (/^P\\b/.test(core)) {
            masked = 'P*********';
        } else if (core.startsWith('â‚±')) {
            masked = 'â‚±*********';
        } else {
            const symbol = core.charAt(0);
            if ('â‚±$â‚¬Â£Â¥'.includes(symbol)) {
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
        }).catch(function() {
            // Ignore visibility sync failures
        });
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
