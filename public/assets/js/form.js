(function () {
    const form = document.getElementById("inspect-form");
    if (!form) {
        return;
    }

    const canvas = document.getElementById("signature-pad");
    const dataInput = document.getElementById("signature-data");
    const clearBtn = document.getElementById("signature-clear");
    const status = document.getElementById("signature-status");
    const submitBtn = document.getElementById("submit-inspection");
    const photoInput = document.getElementById("photo-input");
    const preview = document.getElementById("photo-previews");

    if (canvas && dataInput) {
        const ctx = canvas.getContext("2d");
        const ratio = window.devicePixelRatio || 1;
        const cssWidth = canvas.clientWidth || 640;
        const cssHeight = 180;
        canvas.width = Math.floor(cssWidth * ratio);
        canvas.height = Math.floor(cssHeight * ratio);
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2;
        ctx.lineCap = "round";
        ctx.strokeStyle = "#1c2b24";

        if (dataInput.value) {
            const img = new Image();
            img.onload = function () {
                ctx.drawImage(img, 0, 0, cssWidth, cssHeight);
            };
            img.src = dataInput.value;
        }

        let drawing = false;
        let signatureDirty = false;

        function pos(event) {
            const rect = canvas.getBoundingClientRect();
            const point = event.touches ? event.touches[0] : event;
            return {
                x: point.clientX - rect.left,
                y: point.clientY - rect.top
            };
        }

        function start(event) {
            drawing = true;
            signatureDirty = true;
            const p = pos(event);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            event.preventDefault();
        }

        function move(event) {
            if (!drawing) {
                return;
            }
            const p = pos(event);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            event.preventDefault();
        }

        function end() {
            if (!drawing) {
                return;
            }
            drawing = false;
            dataInput.value = canvas.toDataURL("image/png");
            if (status) {
                status.textContent = "Signature captured.";
            }
        }

        canvas.addEventListener("mousedown", start);
        canvas.addEventListener("mousemove", move);
        window.addEventListener("mouseup", end);
        canvas.addEventListener("touchstart", start, { passive: false });
        canvas.addEventListener("touchmove", move, { passive: false });
        canvas.addEventListener("touchend", end);

        if (clearBtn) {
            clearBtn.addEventListener("click", function () {
                ctx.clearRect(0, 0, cssWidth, cssHeight);
                dataInput.value = "";
                signatureDirty = false;
                if (status) {
                    status.textContent = "Signature cleared.";
                }
                const previewImg = form.querySelector(".signature-preview");
                if (previewImg) {
                    previewImg.remove();
                }
            });
        }

        form.addEventListener("submit", function () {
            if (signatureDirty) {
                dataInput.value = canvas.toDataURL("image/png");
            }
        });
    }

    if (photoInput && preview) {
        photoInput.addEventListener("change", function () {
            preview.innerHTML = "";
            const max = parseInt(photoInput.getAttribute("data-max") || "3", 10);
            const existing = parseInt(photoInput.getAttribute("data-existing") || "0", 10);
            const files = Array.from(photoInput.files || []);
            if (existing + files.length > max) {
                alert("You can attach up to " + max + " photos in total.");
            }
            files.slice(0, Math.max(0, max - existing)).forEach(function (file) {
                const li = document.createElement("li");
                const img = document.createElement("img");
                img.alt = file.name;
                img.src = URL.createObjectURL(file);
                const cap = document.createElement("span");
                cap.textContent = file.name;
                li.appendChild(img);
                li.appendChild(cap);
                preview.appendChild(li);
            });
        });
    }

    form.addEventListener("submit", function (event) {
        const submitter = event.submitter || document.activeElement;
        if (!submitBtn || !submitter || submitter.getAttribute("value") !== "submit") {
            return;
        }
        const missing = [];
        form.querySelectorAll("[data-required]").forEach(function (el) {
            if (!String(el.value || "").trim()) {
                const field = el.closest(".field");
                const label = field ? field.querySelector(".label") : null;
                missing.push(label ? label.textContent.replace("*", "").trim() : "A required field");
            }
        });
        form.querySelectorAll("[data-required-group]").forEach(function (group) {
            const name = group.getAttribute("data-required-group");
            const checked = form.querySelector('input[name="' + name + '"]:checked');
            if (!checked) {
                const field = group.closest(".field");
                const label = field ? field.querySelector(".label") : null;
                missing.push(label ? label.textContent.replace("*", "").trim() : "A required checklist item");
            }
        });
        if (missing.length) {
            event.preventDefault();
            alert("Please complete: " + missing.join(", "));
        }
    });
})();
