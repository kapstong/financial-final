<?php
$pageTitle = $pageTitle ?? null;
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$isUserArea = strpos($scriptName, '/user/') !== false;
$fallbackTitle = ucwords(str_replace('_', ' ', pathinfo($scriptName, PATHINFO_FILENAME)));
$navTitle = $pageTitle ? htmlspecialchars($pageTitle) : htmlspecialchars($fallbackTitle ?: 'Dashboard');

$firstName = $_SESSION['user']['first_name'] ?? '';
$lastName = $_SESSION['user']['last_name'] ?? '';
$fullName = $_SESSION['user']['full_name'] ?? '';
$userName = $_SESSION['user']['username'] ?? '';

if (!empty($firstName) || !empty($lastName)) {
    $displayName = trim($firstName . ' ' . $lastName);
} elseif (!empty($fullName)) {
    $displayName = $fullName;
} else {
    $displayName = $userName;
}

$isSuperAdminArea = strpos($scriptName, '/superadmin/') !== false;
$isAdminArea = strpos($scriptName, '/admin/') !== false;
$isStaffArea = strpos($scriptName, '/staff/') !== false;
$profileLink = $isUserArea ? 'profile.php' : ($isSuperAdminArea ? 'superadmin-profile-settings.php' : ($isStaffArea ? 'profile-settings.php' : 'admin-profile-settings.php'));
$settingsLink = $isUserArea ? 'settings.php' : 'settings.php';
$searchLink = $isSuperAdminArea || $isAdminArea ? '/admin/search.php' : '/staff/search.php';
$scriptDirectory = trim(str_replace('\\', '/', dirname($scriptName)), '/.');
$pathSegments = $scriptDirectory === '' ? [] : explode('/', $scriptDirectory);
$assetPrefix = str_repeat('../', count($pathSegments));

// Get user role for display
require_once '../includes/permissions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/privacy.php';
$permManager = PermissionManager::getInstance();
$permManager->loadUserPermissions($_SESSION['user']['id']);
$userRoles = $permManager->getUserRoles();
$userRoleDisplay = !empty($userRoles) ? $userRoles[0]['role_name'] : 'User';
$userRoleDisplay = ucwords(str_replace('_', ' ', $userRoleDisplay));
$roleNames = array_column($userRoles, 'role_name');
$isAdminRole = in_array('admin', $roleNames, true) || in_array('super_admin', $roleNames, true);
$canViewSettings = $isAdminRole || $permManager->hasPermission('settings.view') || $permManager->hasPermission('roles.view');
$csrfToken = csrf_token();
$privacyModeEnabled = privacyModeEnabled();
?>
<?php include_once __DIR__ . '/loading_screen.php'; ?>
<style>
    .navbar-date-time {
        font-size: 0.95rem;
        font-weight: 600;
    }
    .privacy-money {
        white-space: nowrap;
    }
    .privacy-otp-input {
        font-size: 1.4rem;
        letter-spacing: 0.35rem;
        text-align: center;
    }
    #privacyOtpModal {
        z-index: 2147483001 !important;
    }
    .modal-backdrop.privacy-otp-backdrop {
        z-index: 2147483000 !important;
    }
    .privacy-output-disabled {
        opacity: 0.55 !important;
        cursor: not-allowed !important;
    }
</style>
<nav class="navbar navbar-expand-lg navbar-light bg-white mb-4 shadow-sm">
    <div class="container-fluid">
        <button class="btn btn-outline-secondary toggle-btn" type="button" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <span class="navbar-brand mb-0 h1 me-4"><?php echo $navTitle; ?></span>
        <div class="d-flex align-items-center me-4">
            <button class="btn btn-link me-3" type="button" id="privacyEyeButton"
                    title="Toggle Privacy Mode - Show/Hide Amounts" aria-label="Toggle privacy mode"
                    data-privacy-mode="<?php echo $privacyModeEnabled ? '1' : '0'; ?>">
                <i class="fas <?php echo $privacyModeEnabled ? 'fa-eye-slash' : 'fa-eye'; ?> fa-lg" id="privacyEyeIcon"></i>
            </button>
            <div class="dropdown">
                <button class="btn btn-link text-dark dropdown-toggle d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="d-flex flex-column align-items-start">
                        <span><strong><?php echo htmlspecialchars($displayName); ?></strong></span>
                        <small class="text-muted" style="font-size: 0.75rem; line-height: 1;"><?php echo htmlspecialchars($userRoleDisplay); ?></small>
                    </div>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($profileLink); ?>"><i class="fas fa-user me-2"></i>Profile Settings</a></li>
                    <?php if ($canViewSettings): ?>
                        <li><a class="dropdown-item" href="<?php echo htmlspecialchars($settingsLink); ?>"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="d-flex align-items-center flex-grow-1">
            <form class="input-group mx-auto" style="width: 500px;" method="get" action="<?php echo htmlspecialchars($searchLink); ?>">
                <input type="text" class="form-control" name="q" placeholder="Search..." aria-label="Search">
                <button class="btn btn-outline-secondary" type="submit">
                    <i class="fas fa-search"></i>
                </button>
            </form>
        </div>
    </div>
</nav>
<div class="modal fade" id="privacyOtpModal" tabindex="-1" aria-labelledby="privacyOtpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="privacyOtpModalLabel">Verify Privacy Mode</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3" id="privacyOtpMessage">A 6-digit code will be sent to your account email.</p>
                <input type="text" class="form-control privacy-otp-input" id="privacyOtpCode"
                       inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                       placeholder="000000">
                <div class="invalid-feedback d-block mt-2" id="privacyOtpError" style="display: none !important;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="privacyOtpResendButton">Resend Code</button>
                <button type="button" class="btn btn-primary" id="privacyOtpVerifyButton">Verify</button>
            </div>
        </div>
    </div>
</div>
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
<script src="<?php echo htmlspecialchars($assetPrefix . 'includes/inactivity_timeout.js?v=4'); ?>"></script>
<script>
    (function() {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!token) return;

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('form').forEach(form => {
                const method = (form.getAttribute('method') || 'GET').toUpperCase();
                if (method === 'GET') return;
                if (form.querySelector('input[name="csrf_token"]')) return;
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf_token';
                input.value = token;
                form.appendChild(input);
            });
        });
    })();
</script>
<script>
    (function() {
        const privacyApiUrl = <?php echo json_encode($assetPrefix . 'api/privacy.php'); ?>;
        const currencyPattern = /((?:₱|\$|PHP\s*)-?\d[\d,]*(?:\.\d{1,2})?)/gi;
        let privacyMode = <?php echo $privacyModeEnabled ? 'true' : 'false'; ?>;
        let applyingPrivacy = false;
        let observer = null;
        const restrictedOutputPattern = /\b(export|download|print|generate\s+report|email\s+report)\b/i;
        const restrictedInlinePattern = /\b(export|download|print|generate[A-Za-z0-9_]*Report|window\.print|emailCurrentReport|downloadCSV)\b/i;
        const unrestrictedIds = new Set([
            'privacyEyeButton',
            'privacyOtpCode',
            'privacyOtpResendButton',
            'privacyOtpVerifyButton'
        ]);

        function maskMoney(value) {
            return String(value).replace(/[\d,.]/g, '*');
        }

        function updateButton() {
            const button = document.getElementById('privacyEyeButton');
            const icon = document.getElementById('privacyEyeIcon');
            if (!button || !icon) return;
            button.dataset.privacyMode = privacyMode ? '1' : '0';
            icon.classList.toggle('fa-eye-slash', privacyMode);
            icon.classList.toggle('fa-eye', !privacyMode);
            button.title = privacyMode ? 'Privacy Mode On - verify to show amounts' : 'Privacy Mode Off - hide amounts';
            button.setAttribute('aria-label', button.title);
        }

        function showPrivacyBlockedNotice() {
            const message = 'Privacy mode is ON. Disable privacy mode with OTP verification before exporting, downloading, printing, or generating reports.';
            if (typeof window.showAlert === 'function') {
                window.showAlert(message, 'warning');
            } else {
                alert(message);
            }
        }

        function isRestrictedUrl(value) {
            if (!value) return false;
            let url;
            try {
                url = new URL(value, window.location.href);
            } catch (error) {
                return restrictedOutputPattern.test(String(value));
            }

            const path = url.pathname.toLowerCase();
            const action = (url.searchParams.get('action') || '').toLowerCase();
            const format = (url.searchParams.get('format') || '').toLowerCase();

            return path.endsWith('/download.php')
                || path.endsWith('/api/pdf.php')
                || (path.endsWith('/api/reports.php') && (format !== '' || url.searchParams.has('type')))
                || (path.endsWith('/api/audit.php') && action === 'export')
                || (path.endsWith('/api/backups.php') && action === 'download')
                || ['export', 'download'].includes(action)
                || ['csv', 'pdf', 'excel', 'xlsx', 'xls'].includes(format)
                || /(?:^|[?&])export=/.test(url.search);
        }

        function isRestrictedOutputElement(element) {
            if (!element || unrestrictedIds.has(element.id)) return false;

            const control = element.closest('button, a, input, select, [role="button"], .btn');
            if (!control || unrestrictedIds.has(control.id)) return false;
            if (control.closest('#privacyOtpModal')) return false;

            const text = ((control.innerText || control.value || control.getAttribute('aria-label') || control.title || '') + '').trim();
            const inline = control.getAttribute('onclick') || '';
            const href = control.getAttribute('href') || '';
            const download = control.hasAttribute('download');

            return download
                || restrictedOutputPattern.test(text)
                || restrictedInlinePattern.test(inline)
                || isRestrictedUrl(href);
        }

        function enforcePrivacyOutputUi(root) {
            if (!privacyMode || !root || root.nodeType !== Node.ELEMENT_NODE) return;
            const candidates = root.matches?.('button, a, input, [role="button"], .btn')
                ? [root]
                : Array.from(root.querySelectorAll?.('button, a, input, [role="button"], .btn') || []);

            candidates.forEach(function(control) {
                if (!isRestrictedOutputElement(control)) return;
                control.classList.add('privacy-output-disabled');
                control.setAttribute('aria-disabled', 'true');
                control.title = privacyRestrictedTitle();
                if (control.tagName === 'BUTTON' || (control.tagName === 'INPUT' && ['button', 'submit'].includes((control.type || '').toLowerCase()))) {
                    control.disabled = true;
                }
            });
        }

        function privacyRestrictedTitle() {
            return 'Disable privacy mode before exporting, downloading, printing, or generating reports.';
        }

        function shouldSkipNode(node) {
            const parent = node.parentElement;
            if (!parent) return true;
            return parent.closest('script, style, textarea, input, select, option, [data-privacy-exempt], .privacy-money');
        }

        function wrapTextNode(node) {
            if (shouldSkipNode(node) || !currencyPattern.test(node.nodeValue)) {
                currencyPattern.lastIndex = 0;
                return;
            }
            currencyPattern.lastIndex = 0;

            const fragment = document.createDocumentFragment();
            let lastIndex = 0;
            String(node.nodeValue).replace(currencyPattern, function(match, value, offset) {
                if (offset > lastIndex) {
                    fragment.appendChild(document.createTextNode(node.nodeValue.slice(lastIndex, offset)));
                }
                const span = document.createElement('span');
                span.className = 'privacy-money';
                span.dataset.privacyMoney = '1';
                span.textContent = privacyMode ? maskMoney(value) : value;
                fragment.appendChild(span);
                lastIndex = offset + match.length;
                return match;
            });

            if (lastIndex < node.nodeValue.length) {
                fragment.appendChild(document.createTextNode(node.nodeValue.slice(lastIndex)));
            }
            node.parentNode.replaceChild(fragment, node);
        }

        function cleanupUnavailableText(root) {
            if (!root) return;
            const replacements = [
                { pattern: /#\s*N\/A\b/gi, value: 'record pending' },
                { pattern: /\bN\/A\b/gi, value: 'Not recorded' },
                { pattern: /\bnull\b/gi, value: 'Not recorded' },
                { pattern: /\bundefined\b/gi, value: 'Not recorded' }
            ];
            function replaceTextNode(node) {
                let value = node.nodeValue;
                replacements.forEach(function(item) {
                    item.pattern.lastIndex = 0;
                    value = value.replace(item.pattern, item.value);
                });
                node.nodeValue = value;
            }
            if (root.nodeType === Node.TEXT_NODE) {
                replaceTextNode(root);
                return;
            }
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
                acceptNode: function(node) {
                    const parent = node.parentElement;
                    if (!parent || parent.closest('script, style, textarea, input, select, option')) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    return replacements.some(function(item) {
                        item.pattern.lastIndex = 0;
                        return item.pattern.test(node.nodeValue);
                    }) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
                }
            });
            const nodes = [];
            while (walker.nextNode()) nodes.push(walker.currentNode);
            nodes.forEach(replaceTextNode);
        }

        function scanMoney(root) {
            if (!root || applyingPrivacy) return;
            applyingPrivacy = true;
            try {
                if (root.nodeType === Node.TEXT_NODE) {
                    cleanupUnavailableText(root);
                    wrapTextNode(root);
                } else if (root.nodeType === Node.ELEMENT_NODE || root.nodeType === Node.DOCUMENT_NODE) {
                    cleanupUnavailableText(root === document ? document.body : root);
                    enforcePrivacyOutputUi(root === document ? document.body : root);
                    if (privacyMode) {
                        root.querySelectorAll?.('.privacy-money').forEach(function(el) {
                            el.removeAttribute('data-privacy-value');
                            el.textContent = maskMoney(el.textContent);
                        });
                    }
                    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
                        acceptNode: function(node) {
                            return shouldSkipNode(node) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
                        }
                    });
                    const nodes = [];
                    while (walker.nextNode()) nodes.push(walker.currentNode);
                    nodes.forEach(wrapTextNode);
                }
            } finally {
                applyingPrivacy = false;
            }
        }

        function setPrivacyMode(enabled) {
            privacyMode = !!enabled;
            updateButton();
            scanMoney(document.body);
        }

        function reloadForPrivacyState() {
            window.location.reload();
        }

        async function postPrivacy(action, payload) {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const response = await fetch(privacyApiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token
                },
                body: JSON.stringify(Object.assign({ action }, payload || {}))
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success === false) {
                throw new Error(data.error || 'Privacy verification failed.');
            }
            return data;
        }

        function showOtpError(message) {
            const error = document.getElementById('privacyOtpError');
            if (!error) return;
            error.textContent = message || '';
            error.style.setProperty('display', message ? 'block' : 'none', 'important');
        }

        function prepareOtpModal() {
            const modalEl = document.getElementById('privacyOtpModal');
            if (!modalEl) return null;
            if (modalEl.parentElement !== document.body) {
                document.body.appendChild(modalEl);
            }
            return modalEl;
        }

        function markOtpBackdrop() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            const backdrop = backdrops[backdrops.length - 1];
            backdrop?.classList.add('privacy-otp-backdrop');
        }

        async function sendOtp() {
            const resendButton = document.getElementById('privacyOtpResendButton');
            if (resendButton) resendButton.disabled = true;
            showOtpError('');
            try {
                const data = await postPrivacy('send_otp');
                const message = document.getElementById('privacyOtpMessage');
                if (message) message.textContent = 'Enter the 6-digit code sent to ' + (data.email || 'your email') + '.';
                return true;
            } catch (error) {
                showOtpError(error.message);
                return false;
            } finally {
                if (resendButton) resendButton.disabled = false;
            }
        }

        async function revealWithOtp() {
            const input = document.getElementById('privacyOtpCode');
            const verifyButton = document.getElementById('privacyOtpVerifyButton');
            const code = (input?.value || '').replace(/\D/g, '');
            if (code.length !== 6) {
                showOtpError('Enter the 6-digit verification code.');
                return;
            }

            if (verifyButton) verifyButton.disabled = true;
            showOtpError('');
            try {
                await postPrivacy('verify_otp', { code });
                privacyMode = false;
                updateButton();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('privacyOtpModal')).hide();
                reloadForPrivacyState();
            } catch (error) {
                showOtpError(error.message);
            } finally {
                if (verifyButton) verifyButton.disabled = false;
            }
        }

        async function handleToggle() {
            if (!privacyMode) {
                await postPrivacy('enable');
                setPrivacyMode(true);
                reloadForPrivacyState();
                return;
            }

            const modalEl = prepareOtpModal();
            if (modalEl && window.bootstrap?.Modal) {
                document.getElementById('privacyOtpCode').value = '';
                showOtpError('');
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
                window.setTimeout(markOtpBackdrop, 0);
                if (await sendOtp()) {
                    document.getElementById('privacyOtpCode')?.focus();
                }
                return;
            }

            if (!await sendOtp()) return;
            const code = prompt('Enter the 6-digit verification code sent to your email:');
            if (code) {
                await postPrivacy('verify_otp', { code: code.replace(/\D/g, '') });
                privacyMode = false;
                updateButton();
                reloadForPrivacyState();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateButton();
            scanMoney(document.body);
            if (privacyMode) {
                document.addEventListener('click', function(event) {
                    if (!isRestrictedOutputElement(event.target)) return;
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();
                    showPrivacyBlockedNotice();
                }, true);

                document.addEventListener('submit', function(event) {
                    const form = event.target;
                    if (!(form instanceof HTMLFormElement)) return;
                    const action = form.getAttribute('action') || '';
                    const formText = form.innerText || '';
                    if (!isRestrictedUrl(action) && !restrictedOutputPattern.test(formText)) return;
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();
                    showPrivacyBlockedNotice();
                }, true);

                const originalOpen = window.open;
                window.open = function(url) {
                    if (isRestrictedUrl(url)) {
                        showPrivacyBlockedNotice();
                        return null;
                    }
                    return originalOpen.apply(window, arguments);
                };

                const originalPrint = window.print;
                window.print = function() {
                    showPrivacyBlockedNotice();
                    return undefined;
                };
            }
            observer = new MutationObserver(function(mutations) {
                if (applyingPrivacy) return;
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(scanMoney);
                    if (mutation.type === 'characterData') scanMoney(mutation.target);
                });
            });
            observer.observe(document.body, { childList: true, characterData: true, subtree: true });

            document.getElementById('privacyEyeButton')?.addEventListener('click', function() {
                handleToggle().catch(error => alert(error.message));
            });
            prepareOtpModal();
            document.getElementById('privacyOtpVerifyButton')?.addEventListener('click', revealWithOtp);
            document.getElementById('privacyOtpResendButton')?.addEventListener('click', sendOtp);
            document.getElementById('privacyOtpCode')?.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
                showOtpError('');
            });
            document.getElementById('privacyOtpCode')?.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') revealWithOtp();
            });
        });
    })();
</script>
