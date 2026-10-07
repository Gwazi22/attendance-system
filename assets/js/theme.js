/**
 * theme.js — shared across every AttendX page.
 * Day/night toggle (applied immediately, before DOMContentLoaded, to
 * avoid a flash of the wrong theme), password show/hide, toasts, and
 * the join-code copy helper.
 */
(function () {
    var THEME_KEY = "attendance_theme";

    function applyTheme(theme) {
        document.documentElement.setAttribute("data-theme", theme);
        document.documentElement.style.colorScheme = theme;
        var toggle = document.getElementById("themeSwitch");
        if (toggle) {
            toggle.dataset.theme = theme;
            toggle.setAttribute("aria-pressed", theme === "light");
            toggle.setAttribute("aria-label", theme === "light" ? "Switch to dark mode" : "Switch to day mode");
        }
    }

    var saved = localStorage.getItem(THEME_KEY) || "light";
    applyTheme(saved);

    document.addEventListener("DOMContentLoaded", function () {
        var toggle = document.getElementById("themeSwitch");
        if (toggle) {
            toggle.addEventListener("click", function () {
                var next = document.documentElement.getAttribute("data-theme") === "light" ? "dark" : "light";
                localStorage.setItem(THEME_KEY, next);
                applyTheme(next);
            });
        }

        // Password visibility toggles — any .eye-btn[data-target="fieldId"]
        document.querySelectorAll(".eye-btn[data-target]").forEach(function (btn) {
            var input = document.getElementById(btn.dataset.target);
            if (!input) return;
            btn.addEventListener("click", function () {
                var show = input.type === "password";
                input.type = show ? "text" : "password";
                btn.classList.toggle("is-visible", show);
                btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
            });
        });
    });

    /* ---------------------------------------------------------
       Toast notifications
    --------------------------------------------------------- */
    window.showToast = function (message, type) {
        if (!message) return;
        type = type || "info";
        var stack = document.getElementById("toastStack");
        if (!stack) return;
        var item = document.createElement("div");
        item.className = "toast-item " + type;
        item.innerHTML = '<span></span><button type="button" class="toast-close" aria-label="Dismiss">&times;</button>';
        item.querySelector("span").textContent = message;
        item.querySelector(".toast-close").addEventListener("click", function () { item.remove(); });
        stack.appendChild(item);
        setTimeout(function () { item.remove(); }, 5000);
    };

    /* ---------------------------------------------------------
       Copy-to-clipboard helper (join codes)
    --------------------------------------------------------- */
    window.copyElementText = function (elementId, successMessage) {
        var el = document.getElementById(elementId);
        if (!el) return;
        var text = el.textContent.trim();
        navigator.clipboard.writeText(text).then(function () {
            window.showToast(successMessage || "Copied to clipboard!", "success");
        }).catch(function () {
            window.showToast("Could not copy — please copy it manually.", "danger");
        });
    };
})();