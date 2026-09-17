(function () {
    const forms = Array.from(document.querySelectorAll("form[data-unsaved-guard]"));
    if (!forms.length) {
        return;
    }

    const dialog = document.getElementById("leave-dialog");
    const stayBtn = document.getElementById("leave-stay");
    const leaveBtn = document.getElementById("leave-confirm");
    const initials = new Map();
    let allowLeave = false;
    let pendingHref = null;
    let pendingForm = null;

    function snapshot(form) {
        const parts = [];
        form.querySelectorAll("input, textarea, select").forEach(function (el) {
            const type = (el.getAttribute("type") || "").toLowerCase();
            const name = el.name;
            if (!name || type === "submit" || type === "button" || type === "file" || type === "hidden" && name === "csrf_token") {
                return;
            }
            if (type === "checkbox" || type === "radio") {
                if (!el.checked) {
                    return;
                }
                parts.push(name + "=" + String(el.value));
                return;
            }
            parts.push(name + "=" + String(el.value));
        });
        parts.sort();
        const fileInput = form.querySelector('input[type="file"]');
        const files = fileInput
            ? Array.from(fileInput.files || []).map(function (file) {
                return file.name + ":" + file.size;
            }).join("|")
            : "";
        return parts.join("&") + "#files=" + files;
    }

    function isDirty() {
        return forms.some(function (form) {
            return snapshot(form) !== initials.get(form);
        });
    }

    function showLeaveDialog(href, formEl) {
        pendingHref = href || null;
        pendingForm = formEl || null;
        if (dialog && typeof dialog.showModal === "function") {
            dialog.showModal();
            return;
        }
        if (window.confirm("You have unsaved changes. Leave this page anyway?")) {
            proceedLeave();
        }
    }

    function proceedLeave() {
        allowLeave = true;
        if (dialog && dialog.open) {
            dialog.close();
        }
        if (pendingForm) {
            pendingForm.submit();
            return;
        }
        if (pendingHref) {
            window.location.href = pendingHref;
        }
    }

    function stay() {
        pendingHref = null;
        pendingForm = null;
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    forms.forEach(function (form) {
        initials.set(form, snapshot(form));
        form.addEventListener("submit", function (event) {
            if (event.defaultPrevented) {
                allowLeave = false;
                return;
            }
            allowLeave = true;
        });
    });

    document.addEventListener("click", function (event) {
        const submitter = event.target.closest("form[data-unsaved-guard] button[type='submit'], form[data-unsaved-guard] input[type='submit']");
        if (submitter) {
            allowLeave = true;
            return;
        }

        const link = event.target.closest("a[href]");
        if (!link || allowLeave || link.target === "_blank") {
            return;
        }
        const href = link.getAttribute("href");
        if (!href || href.charAt(0) === "#") {
            return;
        }
        if (link.closest("#leave-dialog")) {
            return;
        }
        if (!isDirty()) {
            return;
        }
        event.preventDefault();
        showLeaveDialog(link.href);
    }, true);

    document.querySelectorAll('form[action*="logout"]').forEach(function (logoutForm) {
        logoutForm.addEventListener("submit", function (event) {
            if (allowLeave || !isDirty()) {
                return;
            }
            event.preventDefault();
            showLeaveDialog(null, logoutForm);
        });
    });

    window.addEventListener("beforeunload", function (event) {
        if (allowLeave || !isDirty()) {
            return;
        }
        event.preventDefault();
        event.returnValue = "";
    });

    if (stayBtn) {
        stayBtn.addEventListener("click", stay);
    }
    if (leaveBtn) {
        leaveBtn.addEventListener("click", proceedLeave);
    }
    if (dialog) {
        dialog.addEventListener("cancel", function (event) {
            event.preventDefault();
            stay();
        });
    }
})();
