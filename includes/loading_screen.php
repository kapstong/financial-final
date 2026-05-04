<?php
if (defined('ATIERA_LOADING_SCREEN_INCLUDED')) {
    return;
}
define('ATIERA_LOADING_SCREEN_INCLUDED', true);
?>
<style>
.atiera-transition-loader {
    --atiera-navy-900: #0f1c49;
    --atiera-navy-800: #162439;
    --atiera-navy-700: #1e2936;
    --atiera-gold-500: #d4af37;
    --atiera-gold-300: #f3d67f;
    --atiera-text: #edf2fb;
    position: fixed;
    inset: 0;
    z-index: 2147483640;
    display: grid;
    place-items: center;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 220ms ease, visibility 220ms ease;
    background:
        radial-gradient(circle at 22% 18%, rgba(212, 175, 55, 0.18), transparent 42%),
        radial-gradient(circle at 84% 80%, rgba(35, 66, 166, 0.22), transparent 38%),
        linear-gradient(135deg, var(--atiera-navy-900), var(--atiera-navy-800) 48%, var(--atiera-navy-700));
}

.atiera-transition-loader.active {
    opacity: 1;
    visibility: visible;
    pointer-events: none;
}

html.atiera-loading-active .sidebar,
body.atiera-loading-active .sidebar,
html.atiera-loading-active .sidebar-toggle,
body.atiera-loading-active .sidebar-toggle {
    opacity: 0 !important;
    visibility: hidden !important;
    pointer-events: none !important;
}

.atiera-transition-loader * {
    box-sizing: border-box;
}

.atiera-loader-shell {
    width: min(92vw, 380px);
    border-radius: 18px;
    background: linear-gradient(155deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.03));
    border: 1px solid rgba(243, 214, 127, 0.35);
    box-shadow:
        0 24px 50px rgba(5, 8, 20, 0.55),
        inset 0 1px 0 rgba(255, 255, 255, 0.12);
    padding: 24px 22px 18px;
    text-align: center;
    color: var(--atiera-text);
    position: relative;
    overflow: hidden;
}

.atiera-loader-shell::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(112deg, transparent 0%, rgba(243, 214, 127, 0.18) 50%, transparent 100%);
    transform: translateX(-120%);
    animation: atieraLoaderSweep 1.6s linear infinite;
    pointer-events: none;
}

.atiera-loader-brand {
    margin: 0;
    letter-spacing: 0.24em;
    font-weight: 700;
    font-size: 0.85rem;
    color: var(--atiera-gold-300);
    text-shadow: 0 0 14px rgba(212, 175, 55, 0.38);
}

.atiera-loader-title {
    margin: 6px 0 16px;
    font-size: 1rem;
    font-weight: 600;
    color: #f8fbff;
}

.atiera-loader-orbit {
    width: 92px;
    height: 92px;
    border-radius: 999px;
    margin: 0 auto;
    position: relative;
    display: grid;
    place-items: center;
    border: 2px solid rgba(243, 214, 127, 0.24);
    box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.3);
}

.atiera-loader-orbit::before,
.atiera-loader-orbit::after {
    content: "";
    position: absolute;
    border-radius: inherit;
}

.atiera-loader-orbit::before {
    inset: -8px;
    border: 2px solid transparent;
    border-top-color: rgba(243, 214, 127, 0.95);
    border-right-color: rgba(243, 214, 127, 0.4);
    animation: atieraLoaderSpin 900ms linear infinite;
}

.atiera-loader-orbit::after {
    inset: 8px;
    border: 2px solid transparent;
    border-bottom-color: rgba(115, 156, 255, 0.78);
    border-left-color: rgba(115, 156, 255, 0.35);
    animation: atieraLoaderSpinReverse 1200ms linear infinite;
}

.atiera-loader-core {
    width: 42px;
    height: 42px;
    border-radius: 999px;
    background:
        radial-gradient(circle at 34% 28%, #fff1c3 0%, #e7c763 40%, #d4af37 75%, #a37719 100%);
    box-shadow:
        0 0 20px rgba(212, 175, 55, 0.44),
        inset 0 2px 5px rgba(255, 255, 255, 0.4),
        inset 0 -7px 8px rgba(93, 66, 16, 0.38);
    animation: atieraLoaderPulse 1200ms ease-in-out infinite;
}

.atiera-loader-status {
    margin: 14px 0 10px;
    min-height: 1.3em;
    font-size: 0.92rem;
    color: rgba(237, 242, 251, 0.95);
}

.atiera-loader-progress-track {
    width: 100%;
    height: 7px;
    border-radius: 999px;
    background: rgba(10, 17, 36, 0.55);
    border: 1px solid rgba(243, 214, 127, 0.24);
    overflow: hidden;
}

.atiera-loader-progress-fill {
    width: 12%;
    height: 100%;
    border-radius: inherit;
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.34), transparent 65%),
        linear-gradient(90deg, #9f7a1f, #d4af37 55%, #f3d67f);
    transition: width 220ms linear;
}

.atiera-loader-progress-label {
    margin-top: 8px;
    font-size: 0.8rem;
    letter-spacing: 0.09em;
    color: rgba(243, 214, 127, 0.96);
    font-variant-numeric: tabular-nums;
}

@keyframes atieraLoaderSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@keyframes atieraLoaderSpinReverse {
    from { transform: rotate(360deg); }
    to { transform: rotate(0deg); }
}

@keyframes atieraLoaderPulse {
    0%, 100% { transform: scale(0.96); }
    50% { transform: scale(1.05); }
}

@keyframes atieraLoaderSweep {
    from { transform: translateX(-120%); }
    to { transform: translateX(120%); }
}

@media (prefers-reduced-motion: reduce) {
    .atiera-transition-loader,
    .atiera-transition-loader * {
        animation: none !important;
        transition: none !important;
    }
}
</style>

<div class="atiera-transition-loader" id="atieraTransitionLoader" role="status" aria-live="polite" aria-hidden="true">
    <section class="atiera-loader-shell">
        <p class="atiera-loader-brand">ATIERA</p>
        <p class="atiera-loader-title">Financial Management Hub</p>
        <div class="atiera-loader-orbit" aria-hidden="true">
            <div class="atiera-loader-core"></div>
        </div>
        <p class="atiera-loader-status" data-loader-status>Preparing workspace...</p>
        <div class="atiera-loader-progress-track" aria-hidden="true">
            <div class="atiera-loader-progress-fill" data-loader-progress></div>
        </div>
        <div class="atiera-loader-progress-label" data-loader-percent>12%</div>
    </section>
</div>

<script>
(function () {
    "use strict";

    const loader = document.getElementById("atieraTransitionLoader");
    if (!loader) {
        return;
    }

    const progressFill = loader.querySelector("[data-loader-progress]");
    const percentLabel = loader.querySelector("[data-loader-percent]");
    const statusLabel = loader.querySelector("[data-loader-status]");

    const statusSteps = [
        "Preparing workspace...",
        "Reconciling financial records...",
        "Opening selected module..."
    ];
    const LINK_NAVIGATION_DELAY_MS = 1800;
    const LOADER_FALLBACK_HIDE_MS = 6200;

    let progressTimer = null;
    let statusTimer = null;
    let fallbackTimer = null;
    let progress = 12;
    let visible = false;
    let phase = 0;
    let navigationLocked = false;

    function setLoadingUiState(isActive) {
        const root = document.documentElement;
        const body = document.body;
        root.classList.toggle("atiera-loading-active", isActive);
        if (body) {
            body.classList.toggle("atiera-loading-active", isActive);
        }
    }

    function clearTimers() {
        if (progressTimer) {
            window.clearInterval(progressTimer);
            progressTimer = null;
        }
        if (statusTimer) {
            window.clearInterval(statusTimer);
            statusTimer = null;
        }
        if (fallbackTimer) {
            window.clearTimeout(fallbackTimer);
            fallbackTimer = null;
        }
    }

    function renderProgress(value) {
        const bounded = Math.max(0, Math.min(100, Math.round(value)));
        progressFill.style.width = bounded + "%";
        percentLabel.textContent = bounded + "%";
    }

    function runProgressAnimation() {
        progressTimer = window.setInterval(function () {
            const step = progress < 55 ? 8 : (progress < 82 ? 4 : 2);
            progress = Math.min(96, progress + step);
            renderProgress(progress);
        }, 210);

        statusTimer = window.setInterval(function () {
            phase = (phase + 1) % statusSteps.length;
            statusLabel.textContent = statusSteps[phase];
        }, 760);
    }

    function showLoader(customStatus) {
        if (visible) {
            return;
        }
        visible = true;
        clearTimers();
        progress = 12;
        phase = 0;
        statusLabel.textContent = customStatus || statusSteps[phase];
        renderProgress(progress);
        setLoadingUiState(true);
        loader.classList.add("active");
        loader.setAttribute("aria-hidden", "false");
        runProgressAnimation();

        fallbackTimer = window.setTimeout(function () {
            if (document.visibilityState === "visible") {
                hideLoader();
            }
        }, LOADER_FALLBACK_HIDE_MS);
    }

    function hideLoader() {
        visible = false;
        clearTimers();
        setLoadingUiState(false);
        loader.classList.remove("active");
        loader.setAttribute("aria-hidden", "true");
    }

    function isLogoutUrl(urlLike) {
        let candidateUrl;
        try {
            candidateUrl = urlLike instanceof URL ? urlLike : new URL(urlLike, window.location.href);
        } catch (error) {
            return false;
        }

        const normalizedPath = candidateUrl.pathname.replace(/\/+$/, "").toLowerCase();
        return /(?:^|\/)logout\.php$/.test(normalizedPath);
    }

    function isNavigableLink(link, event) {
        if (!link || !link.href) {
            return false;
        }
        if (event.defaultPrevented || event.button !== 0) {
            return false;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return false;
        }
        if (link.classList.contains("no-loading") || link.dataset.noLoading === "true") {
            return false;
        }
        if (link.hasAttribute("download")) {
            return false;
        }
        const target = (link.getAttribute("target") || "").toLowerCase();
        if (target && target !== "_self") {
            return false;
        }
        const href = (link.getAttribute("href") || "").trim();
        if (!href || href.charAt(0) === "#") {
            return false;
        }

        let nextUrl;
        try {
            nextUrl = new URL(link.href, window.location.href);
        } catch (error) {
            return false;
        }

        if (nextUrl.origin !== window.location.origin) {
            return false;
        }
        if (nextUrl.protocol !== "http:" && nextUrl.protocol !== "https:") {
            return false;
        }
        if (isLogoutUrl(nextUrl)) {
            return false;
        }

        const currentUrl = new URL(window.location.href);
        const isInPageAnchor = nextUrl.pathname === currentUrl.pathname
            && nextUrl.search === currentUrl.search
            && nextUrl.hash !== ""
            && nextUrl.hash !== currentUrl.hash;

        return !isInPageAnchor;
    }

    function initNavigationHooks() {
        document.addEventListener("click", function (event) {
            const link = event.target.closest("a[href]");
            if (!isNavigableLink(link, event)) {
                return;
            }
            event.preventDefault();
            if (navigationLocked) {
                return;
            }
            navigationLocked = true;
            const status = (link.dataset.loadingText || "").trim();
            showLoader(status || "Opening next page...");
            window.setTimeout(function () {
                window.location.assign(link.href);
            }, LINK_NAVIGATION_DELAY_MS);
        }, true);

        document.addEventListener("submit", function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (form.classList.contains("no-loading") || form.dataset.noLoading === "true") {
                return;
            }
            const target = (form.getAttribute("target") || "").toLowerCase();
            if (target && target !== "_self") {
                return;
            }
            const action = (form.getAttribute("action") || window.location.href).trim();
            if (isLogoutUrl(action)) {
                return;
            }
            const status = (form.dataset.loadingText || "").trim();
            showLoader(status || "Submitting request...");
        }, true);
    }

    window.AtieraLoader = {
        show: showLoader,
        hide: hideLoader
    };

    hideLoader();
    window.setTimeout(hideLoader, 250);
    window.setTimeout(hideLoader, 1200);

    if (loader.dataset.autoHooks !== "false") {
        initNavigationHooks();
    }

    window.addEventListener("DOMContentLoaded", hideLoader);
    window.addEventListener("load", function () {
        window.setTimeout(hideLoader, 120);
    });

    window.addEventListener("pageshow", function () {
        hideLoader();
    });

    window.addEventListener("focus", hideLoader);
})();
</script>
