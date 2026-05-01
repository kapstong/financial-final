</div> <!-- End content -->
</div> <!-- End main content area -->

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<!-- Privacy Mode - Hide amounts with asterisks -->
<script src="../includes/privacy_mode.js?v=13"></script>

<!-- Inactivity Timeout - Blur screen after 10 sec, logout after 2 min -->
<script src="../includes/inactivity_timeout.js"></script>
<script src="../includes/navbar_datetime.js"></script>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('show');
}

// Initialize Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize Bootstrap popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Persist active tab per page on refresh
    (function () {
        const tabTriggers = document.querySelectorAll('[data-bs-toggle="tab"]');
        if (!tabTriggers.length) {
            return;
        }
        const storageKey = 'activeTab:' + window.location.pathname;
        tabTriggers.forEach(function (trigger) {
            trigger.addEventListener('shown.bs.tab', function (event) {
                if (event.target && event.target.id) {
                    localStorage.setItem(storageKey, event.target.id);
                }
            });
        });
        const savedTabId = localStorage.getItem(storageKey);
        if (savedTabId) {
            const savedTrigger = document.getElementById(savedTabId);
            if (savedTrigger) {
                new bootstrap.Tab(savedTrigger).show();
            }
        }
    })();
</script>
<script>
// Guard against modal triggers pointing to missing elements
window.safeModal = function(elOrId, options) {
    const el = typeof elOrId === 'string' ? document.getElementById(elOrId) : elOrId;
    if (!el || !window.bootstrap || !bootstrap.Modal) {
        return null;
    }
    return bootstrap.Modal.getOrCreateInstance(el, options);
};

document.addEventListener('click', function(event) {
    const trigger = event.target.closest('[data-bs-toggle="modal"]');
    if (!trigger) {
        return;
    }
    const target = trigger.getAttribute('data-bs-target') || trigger.getAttribute('href');
    if (!target) {
        return;
    }
    const modalEl = document.querySelector(target);
    if (!modalEl) {
        event.preventDefault();
        event.stopImmediatePropagation();
        console.warn('Modal target not found:', target);
    }
}, true);
</script>
<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>






