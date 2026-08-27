/**
 * ui-polish.js
 * Shared across all pages: dark mode toggle, toast notifications,
 * and a copy-to-clipboard helper. Include this AFTER the Bootstrap
 * JS bundle (needed for the Toast component).
 */
(function () {
    /* ---------------------------------------------------------
       Dark mode
       Persisted in localStorage so it survives page navigation
       and reloads. Applied immediately (not waiting for
       DOMContentLoaded) to avoid a flash of the wrong theme.
    --------------------------------------------------------- */
    const THEME_KEY = "attendance_theme";

    function applyTheme(theme) {
        document.documentElement.setAttribute("data-bs-theme", theme);
        const icon = document.getElementById("themeToggleIcon");
        if (icon) {
            icon.textContent = theme === "dark" ? "☀️" : "🌙";
        }
    }

    function initTheme() {
        const saved = localStorage.getItem(THEME_KEY) || "light";
        applyTheme(saved);
    }

    function toggleTheme() {
        const current = document.documentElement.getAttribute("data-bs-theme") || "light";
        const next = current === "dark" ? "light" : "dark";
        localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
    }

    window.toggleTheme = toggleTheme;
    initTheme();

    // Re-sync the icon once the DOM (and the toggle button) actually exists.
    document.addEventListener("DOMContentLoaded", initTheme);

    /* ---------------------------------------------------------
       Toast notifications
       Replaces full-width alert banners with dismissible,
       auto-expiring corner toasts. Requires Bootstrap's JS
       bundle to be loaded first (for the Toast component).
    --------------------------------------------------------- */
    function ensureToastContainer() {
        let container = document.getElementById("toastContainer");
        if (!container) {
            container = document.createElement("div");
            container.id = "toastContainer";
            container.className = "toast-container position-fixed top-0 end-0 p-3";
            container.style.zIndex = 1080;
            document.body.appendChild(container);
        }
        return container;
    }

    function showToast(message, type) {
        if (!message) return;
        type = type || "info";

        const bgClass = {
            success: "text-bg-success",
            danger: "text-bg-danger",
            info: "text-bg-primary",
            warning: "text-bg-warning"
        }[type] || "text-bg-primary";

        const container = ensureToastContainer();

        const toastEl = document.createElement("div");
        toastEl.className = "toast align-items-center " + bgClass + " border-0";
        toastEl.setAttribute("role", "alert");
        toastEl.setAttribute("aria-live", "assertive");
        toastEl.setAttribute("aria-atomic", "true");
        toastEl.innerHTML =
            '<div class="d-flex">' +
                '<div class="toast-body"></div>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
            '</div>';
        toastEl.querySelector(".toast-body").textContent = message; // textContent avoids HTML injection

        container.appendChild(toastEl);

        const toast = new bootstrap.Toast(toastEl, { delay: 5000 });
        toast.show();
        toastEl.addEventListener("hidden.bs.toast", () => toastEl.remove());
    }

    window.showToast = showToast;

    /* ---------------------------------------------------------
       Copy-to-clipboard helper (used for join codes)
    --------------------------------------------------------- */
    function copyElementText(elementId, successMessage) {
        const el = document.getElementById(elementId);
        if (!el) return;
        const text = el.textContent.trim();

        navigator.clipboard.writeText(text).then(() => {
            showToast(successMessage || "Copied to clipboard!", "success");
        }).catch(() => {
            showToast("Could not copy — please copy it manually.", "danger");
        });
    }

    window.copyElementText = copyElementText;
})();