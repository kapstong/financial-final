/**
 * ATIERA Financial Management System - Inactivity Timeout
 * Automatically logs out users after 2 minutes of inactivity
 */

(function() {
    'use strict';
    if (window.__atieraInactivityTimeoutInit) {
        return;
    }
    window.__atieraInactivityTimeoutInit = true;

    // Configuration
    const TIMEOUT_DURATION = 2 * 60 * 1000; // 2 minutes in milliseconds
    const WARNING_DURATION = 30 * 1000; // Show warning 30 seconds before timeout
    const CHECK_INTERVAL = 1000; // Check every second

    let lastActivity = Date.now();
    let timeoutTimer = null;
    let warningShown = false;
    let warningModal = null;
    let countdownInterval = null;

    // Events that count as user activity
    const activityEvents = [
        'mousedown',
        'mousemove',
        'keypress',
        'scroll',
        'touchstart',
        'click'
    ];

    /**
     * Update last activity timestamp
     */
    function updateActivity() {
        lastActivity = Date.now();
        warningShown = false;

        // Hide warning if shown
        if (warningModal) {
            hideWarning();
        }
    }

    /**
     * Apply blur overlay to entire screen
     */
    function applyBlur() {
        if (isBlurred) return;

        let blurOverlay = document.getElementById('inactivity-blur-overlay');

        if (!blurOverlay) {
            // Create blur overlay
            blurOverlay = document.createElement('div');
            blurOverlay.id = 'inactivity-blur-overlay';
            blurOverlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                backdrop-filter: blur(15px);
                -webkit-backdrop-filter: blur(15px);
                background: rgba(0, 0, 0, 0.3);
                /* Keep below Bootstrap modal z-index to avoid blocking modals (Bootstrap modal default ~1050) */
                z-index: 1040;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: opacity 0.3s ease;
            `;

            // Add text overlay
            const textDiv = document.createElement('div');
            textDiv.style.cssText = `
                background: white;
                padding: 30px 50px;
                border-radius: 15px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                text-align: center;
                pointer-events: none;
            `;
            textDiv.innerHTML = `
                <div style="font-size: 48px; margin-bottom: 15px;">💤</div>
                <h2 style="margin: 0 0 10px 0; color: #1b2f73; font-size: 24px;">Screen Locked</h2>
                <p style="margin: 0; color: #64748b; font-size: 16px;">Click anywhere to continue</p>
            `;

            blurOverlay.appendChild(textDiv);
            document.body.appendChild(blurOverlay);
            blurOverlayElement = blurOverlay;

            // Click anywhere to remove blur
            blurOverlay.addEventListener('click', function() {
                updateActivity();
            });
        }

        blurOverlay.style.display = 'flex';
        // Ensure overlay doesn't block Bootstrap modals if any are open
        syncOverlayWithModals();
        // Watch for modal DOM changes to keep overlay behavior in sync
        observeModalChanges();
        isBlurred = true;
    }

    /**
     * Remove blur overlay
     */
    function removeBlur() {
        const blurOverlay = document.getElementById('inactivity-blur-overlay');
        if (blurOverlay) {
            blurOverlay.style.display = 'none';
        }
        isBlurred = false;
    }

    /**
     * If a Bootstrap modal is open, make the blur overlay non-interactive so the modal receives clicks.
     */
    function syncOverlayWithModals() {
        try {
            const blurOverlay = document.getElementById('inactivity-blur-overlay');
            if (!blurOverlay) return;

            const modalOpen = document.querySelector('.modal.show');
            if (modalOpen) {
                blurOverlay.style.pointerEvents = 'none';
                // keep visual dimming but allow interaction with modal above
                blurOverlay.style.opacity = '0.6';
            } else {
                blurOverlay.style.pointerEvents = 'auto';
                blurOverlay.style.opacity = '1';
            }
        } catch (e) {
            // ignore errors
        }
    }

    let modalObserver = null;
    function observeModalChanges() {
        if (modalObserver) return;
        modalObserver = new MutationObserver(function() {
            syncOverlayWithModals();
        });
        modalObserver.observe(document.body, { childList: true, subtree: true });
    }

    /**
     * Show inactivity warning modal
     */
    function showWarning() {
        if (warningShown) return;

        warningShown = true;

        // Create modal if it doesn't exist
        if (!warningModal) {
            createWarningModal();
        }

        // Show modal
        if (warningModal) {
            warningModal.style.display = 'flex';

            // Start countdown
            startCountdown();
        }
    }

    /**
     * Hide warning modal
     */
    function hideWarning() {
        if (warningModal) {
            warningModal.style.display = 'none';
        }

        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }

        warningShown = false;
    }

    /**
     * Create warning modal
     */
    function createWarningModal() {
        // Create modal HTML
        const modalHTML = `
            <div id="inactivity-warning-modal" style="display: none; position: fixed; z-index: 1050; left: 0; right: 0; bottom: 24px; align-items: center; justify-content: center; pointer-events: none;">
                <div style="background-color: white; padding: 22px; border-radius: 10px; box-shadow: 0 12px 32px rgba(0,0,0,0.25); max-width: 400px; text-align: center; pointer-events: auto; border: 1px solid #e5e7eb;">
                    <h2 style="margin: 0 0 15px 0; color: #1b2f73; font-size: 24px;">Inactivity Warning</h2>
                    <p style="margin: 0 0 20px 0; color: #64748b; font-size: 16px;">
                        You will be logged out due to inactivity in:
                    </p>
                    <div id="inactivity-countdown" style="font-size: 48px; font-weight: bold; color: #dc2626; margin: 20px 0;">
                        30
                    </div>
                    <p style="margin: 0 0 20px 0; color: #64748b; font-size: 14px;">
                        Click "Stay Logged In" to continue your session.
                    </p>
                    <button id="stay-logged-in-btn" style="background: linear-gradient(180deg, #1b2f73, #0f1c49); color: white; border: none; padding: 12px 30px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.2);">
                        Stay Logged In
                    </button>
                </div>
            </div>
        `;

        // Insert modal into document
        const div = document.createElement('div');
        div.innerHTML = modalHTML;
        document.body.appendChild(div.firstElementChild);

        warningModal = document.getElementById('inactivity-warning-modal');

        // Add event listener to "Stay Logged In" button
        const stayBtn = document.getElementById('stay-logged-in-btn');
        if (stayBtn) {
            stayBtn.addEventListener('click', function() {
                updateActivity();
                hideWarning();

                // Send keep-alive request to server
                sendKeepAlive();
            });
        }
    }

    /**
     * Start countdown timer in warning modal
     */
    function startCountdown() {
        const countdownEl = document.getElementById('inactivity-countdown');

        if (!countdownEl) return;

        let timeLeft = WARNING_DURATION / 1000; // Convert to seconds

        // Update immediately
        countdownEl.textContent = timeLeft;

        // Update every second
        countdownInterval = setInterval(function() {
            timeLeft--;
            countdownEl.textContent = timeLeft;

            if (timeLeft <= 0) {
                clearInterval(countdownInterval);
                logout();
            }
        }, 1000);
    }

    /**
     * Send keep-alive request to server
     */
    function sendKeepAlive() {
        // Use relative path based on current location
        const apiPath = /\/(?:superadmin|admin|staff)\//.test(window.location.pathname)
            ? '../api/keep_alive.php'
            : 'api/keep_alive.php';

        fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ action: 'keep_alive' })
        }).catch(function(error) {
            // Silent fail - don't log errors
        });
    }

    /**
     * Logout user
     */
    function logout() {
        // Show logout message
        if (warningModal) {
            const modalContent = warningModal.querySelector('div > div');
            if (modalContent) {
                modalContent.innerHTML = `
                    <div style="font-size: 48px; color: #dc2626; margin-bottom: 15px;">🔒</div>
                    <h2 style="margin: 0 0 15px 0; color: #1b2f73; font-size: 24px;">Session Expired</h2>
                    <p style="margin: 0 0 20px 0; color: #64748b; font-size: 16px;">
                        You have been logged out due to inactivity.
                    </p>
                    <p style="margin: 0; color: #64748b; font-size: 14px;">
                        Redirecting to login page...
                    </p>
                `;
            }
        }

        // Redirect to logout after 2 seconds
        setTimeout(function() {
            const baseURL = getBaseURL().replace(/\/$/, '');
            window.location.href = baseURL + '/logout.php?reason=timeout';
        }, 2000);
    }

    /**
     * Get base URL
     */
    function getBaseURL() {
        const path = window.location.pathname;
        const parts = path.split('/');

        // Remove empty parts and file name
        const cleanParts = parts.filter(function(p) { return p; });
        if (cleanParts.length > 0 && cleanParts[cleanParts.length - 1].indexOf('.') !== -1) {
            cleanParts.pop();
        }

        // Find integ-capstone index
        const index = cleanParts.indexOf('integ-capstone');
        if (index !== -1) {
            return '/' + cleanParts.slice(0, index + 1).join('/');
        }

        return '/' + cleanParts.join('/');
    }

    /**
     * Check for inactivity
     */
    function checkInactivity() {
        const now = Date.now();
        const inactiveTime = now - lastActivity;

        // Show warning when close to timeout
        if (inactiveTime >= TIMEOUT_DURATION - WARNING_DURATION && !warningShown) {
            showWarning();
        }

        // Logout when timeout reached
        if (inactiveTime >= TIMEOUT_DURATION) {
            logout();
        }
    }

    /**
     * Initialize inactivity tracker
     */
    function init() {
        // Register activity event listeners
        activityEvents.forEach(function(event) {
            document.addEventListener(event, updateActivity, true);
        });

        // Start checking for inactivity
        timeoutTimer = setInterval(checkInactivity, CHECK_INTERVAL);

        // Initial activity timestamp
        updateActivity();
    }

    /**
     * Cleanup
     */
    function cleanup() {
        // Remove event listeners
        activityEvents.forEach(function(event) {
            document.removeEventListener(event, updateActivity, true);
        });

        // Clear timers
        if (timeoutTimer) {
            clearInterval(timeoutTimer);
        }
        if (countdownInterval) {
            clearInterval(countdownInterval);
        }

        // Remove modal
        if (warningModal && warningModal.parentNode) {
            warningModal.parentNode.removeChild(warningModal);
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Cleanup on page unload
    window.addEventListener('beforeunload', cleanup);

    // Expose API
    window.InactivityTimeout = {
        updateActivity: updateActivity,
        reset: function() {
            updateActivity();
            hideWarning();
        },
        disable: cleanup
    };
})();
