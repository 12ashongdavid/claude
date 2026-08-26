// =====================================================
// PK's Luxury Apartments — Main JavaScript
// Apartment Management System
// =====================================================

// ---- Theme Toggle (manipulates CSS via [data-theme]) ----
function initTheme() {
    const saved = localStorage.getItem('pk-theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = saved || (prefersDark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
    updateThemeIcon(theme);
}

function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('pk-theme', next);
    updateThemeIcon(next);
}

function updateThemeIcon(theme) {
    const btn = document.getElementById('themeToggle');
    if (btn) {
        btn.innerHTML = theme === 'dark'
            ? '<i class="bx bx-sun"></i>'
            : '<i class="bx bx-moon"></i>';
    }
}

// ---- Scroll Reveal (JS adds .visible to trigger CSS transitions) ----
function initScrollReveal() {
    const targets = document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-scale');
    if (!targets.length) return;

    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced || !('IntersectionObserver' in window)) {
        targets.forEach(el => el.classList.add('visible'));
        return;
    }

    const observer = new IntersectionObserver((entries, obs) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                obs.unobserve(entry.target);
            }
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

    targets.forEach(el => observer.observe(el));
}

// ---- Animated Counter for stat numbers ----
function initCountUp() {
    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced) return;

    const targets = document.querySelectorAll('.stat-info h4, .report-box .value');
    targets.forEach(el => {
        if (el.dataset.counted) return;
        const text = el.textContent.trim();
        const match = text.match(/^(\D*)([\d][\d,.]*)(\D*)$/);
        if (!match || text.includes('/')) return;

        const prefix = match[1];
        const raw = match[2];
        const suffix = match[3];
        const target = parseFloat(raw.replace(/,/g, ''));
        if (isNaN(target)) return;

        const isMoney = /[₵$€£]/.test(prefix + suffix);
        if (!isMoney) return;

        el.dataset.counted = '1';
        const duration = 900;
        const start = performance.now();

        function frame(now) {
            const p = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - p, 3);
            const val = target * eased;
            const formatted = Number.isInteger(target)
                ? Math.round(val).toLocaleString()
                : val.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            el.textContent = prefix + formatted + suffix;
            if (p < 1) requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    });
}

// ---- Button ripple effect ----
function initRipple() {
    document.addEventListener('pointerdown', function(e) {
        const btn = e.target.closest('.btn');
        if (!btn) return;
        const rect = btn.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        const ripple = document.createElement('span');
        ripple.className = 'ripple';
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
        ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
        btn.appendChild(ripple);
        ripple.addEventListener('animationend', () => ripple.remove());
    });
}

// ---- Topbar scrolled shadow ----
function initTopbarShadow() {
    const topbar = document.querySelector('.topbar');
    const nav = document.querySelector('.booking-nav');
    const onScroll = () => {
        const scrolled = window.scrollY > 6;
        if (topbar) topbar.classList.toggle('scrolled', scrolled);
        if (nav) nav.classList.toggle('scrolled', scrolled);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
}

// ---- Sidebar Toggle ----
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('show');
}

// ---- Notification Dropdown ----
function toggleNotifDropdown() {
    const dropdown = document.getElementById('notifDropdown');
    dropdown.classList.toggle('show');

    // Close when clicking outside
    if (dropdown.classList.contains('show')) {
        setTimeout(() => {
            document.addEventListener('click', closeNotifDropdown);
        }, 0);
    }
}

function closeNotifDropdown(e) {
    const dropdown = document.getElementById('notifDropdown');
    const btn = document.getElementById('notifBtn');
    if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
        dropdown.classList.remove('show');
        document.removeEventListener('click', closeNotifDropdown);
    }
}

// ---- Modal Functions ----
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close modal on overlay click
document.addEventListener('click', function(e) {
    if (!(e.target instanceof Element)) return;
    if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('active')) {
        if (e.target.id === 'confirmModal') {
            closeConfirmModal();
            return;
        }
        e.target.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            if (modal.id === 'confirmModal') {
                closeConfirmModal();
                return;
            }
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
});

// ---- Centered Confirm Dialog ----
let confirmModalCallback = null;

function showConfirm(message, onConfirm, okLabel = 'Yes, Continue') {
    document.getElementById('confirmModalMessage').textContent = message;
    const ok = document.getElementById('confirmModalOk');
    ok.textContent = okLabel;
    confirmModalCallback = onConfirm || null;
    openModal('confirmModal');
}

function closeConfirmModal() {
    confirmModalCallback = null;
    closeModal('confirmModal');
}

var confirmOkBtn = document.getElementById('confirmModalOk');
if (confirmOkBtn) {
    confirmOkBtn.addEventListener('click', function() {
        const cb = confirmModalCallback;
        closeConfirmModal();
        if (typeof cb === 'function') cb();
    });
}

// ---- Toast Notifications ----
function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = 'toast ' + type;

    const icons = {
        success: '&#10003;',
        warning: '&#9888;',
        error: '&#10007;',
        info: '&#8505;'
    };

    toast.innerHTML = `
        <span style="font-size:1.2rem;">${icons[type] || icons.info}</span>
        <span class="toast-msg">${esc(message)}</span>
        <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
    `;

    container.appendChild(toast);

    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (toast.parentElement) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(40px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }
    }, 5000);
}

// ---- Tabs ----
function switchTab(tabGroupId, tabId) {
    // Hide all tab contents in group
    const group = document.getElementById(tabGroupId);
    if (group) {
        group.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
        group.querySelectorAll('.tab-btn').forEach(tb => tb.classList.remove('active'));
    }

    // Show selected tab
    const tabContent = document.getElementById(tabId);
    if (tabContent) tabContent.classList.add('active');

    // Highlight button
    const btn = event.target.closest('.tab-btn');
    if (btn) btn.classList.add('active');
}

// ---- Fetch Helper ----
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

async function apiRequest(url, data = null) {
    try {
        const options = {};
        if (data) {
            options.method = 'POST';
            if (data instanceof FormData) {
                data.append('csrf_token', getCsrfToken());
                options.body = data;
            } else {
                data.csrf_token = getCsrfToken();
                options.body = JSON.stringify(data);
                options.headers = { 'Content-Type': 'application/json' };
            }
        }
        const res = await fetch(url, options);
        return await res.json();
    } catch (err) {
        console.error('API Error:', err);
        showToast('An error occurred. Please try again.', 'error');
        return null;
    }
}

// ---- Form Serialization Helper ----
function serializeForm(form) {
    const formData = new FormData(form);
    const obj = {};
    formData.forEach((value, key) => {
        obj[key] = value;
    });
    return obj;
}

// ---- HTML-escaping helper (used when injecting strings into the DOM) ----
function esc(s) {
    const d = document.createElement('div');
    d.textContent = (s == null ? '' : s);
    return d.innerHTML;
}

// ---- Real-time Notification Polling ----
setInterval(async function() {
    if (!document.getElementById('notifDropdown')) return;
    try {
        const res = await fetch('api/notifications.php', { cache: 'no-store' });
        const data = await res.json();
        const badges = document.querySelectorAll('.notif-badge');
        badges.forEach(badge => {
            if (data.unread > 0) {
                badge.textContent = data.unread;
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        });
    } catch (e) { /* silent — keep the app responsive */ }
}, 20000);

// ---- Confirm Actions ----
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// ---- Auto-hide alerts after 5 seconds ----
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            alert.style.transition = 'all 0.3s ease';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });
});

// ---- Responsive: close sidebar on navigation ----
document.querySelectorAll('.sidebar-nav a').forEach(link => {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
            toggleSidebar();
        }
    });
});

// ---- Styled Form Validation ----
// Replaces the plain browser "Please fill out this field" bubble with a
// styled inline message (red border + animated error text below the field).
(function() {
    function validationMessage(el) {
        if (el.validity.valid) return '';
        if (el.validity.valueMissing) return el.dataset.requiredMsg || 'Please fill out this field.';
        if (el.validity.typeMismatch) {
            if (el.type === 'email') return 'Please enter a valid email address.';
            if (el.type === 'tel') return 'Please enter a valid phone number.';
            return 'Please enter a valid value.';
        }
        if (el.validity.patternMismatch) return el.title || 'Please match the requested format.';
        if (el.validity.tooShort) return 'This value is too short.';
        if (el.validity.tooLong) return 'This value is too long.';
        if (el.validity.rangeUnderflow) return el.validationMessage || 'Value is too small.';
        if (el.validity.rangeOverflow) return el.validationMessage || 'Value is too large.';
        if (el.validity.stepMismatch) return 'Please use a valid step value.';
        return el.validationMessage || 'This field is invalid.';
    }

    function showFieldError(el) {
        if (el.__fieldError) return;
        clearFieldError(el);
        el.classList.add('is-invalid');
        const msg = validationMessage(el);
        if (!msg) return;
        const div = document.createElement('div');
        div.className = 'field-error';
        div.textContent = msg;
        el.__fieldError = div;
        if (el.parentNode) el.parentNode.insertBefore(div, el.nextSibling);
    }

    function clearFieldError(el) {
        el.classList.remove('is-invalid');
        if (el.__fieldError) {
            el.__fieldError.remove();
            el.__fieldError = null;
        }
    }

    // Suppress the native bubble and show our styled message instead
    document.addEventListener('invalid', function(e) {
        const el = e.target;
        if (!(el instanceof Element)) return;
        e.preventDefault();
        showFieldError(el);
        if (window.__validationBatch) window.__validationBatch.push(el);
    }, true);

    // Remember which submit button triggered the batch, so we can focus the first error
    document.addEventListener('pointerdown', function(e) {
        if (!(e.target instanceof Element)) return;
        if (e.target.closest('button[type="submit"], input[type="submit"]')) {
            window.__validationBatch = [];
        }
    }, true);

    // After the batch completes, focus the first invalid field
    document.addEventListener('click', function(e) {
        if (!window.__validationBatch || !window.__validationBatch.length) {
            window.__validationBatch = null;
            return;
        }
        const first = window.__validationBatch[0];
        window.__validationBatch = null;
        if (first && first.focus) first.focus();
    }, true);

    // Live-clear the error as the user fixes the field
    document.addEventListener('input', function(e) {
        const el = e.target;
        if (el instanceof Element && el.__fieldError) clearFieldError(el);
    });

    document.addEventListener('change', function(e) {
        const el = e.target;
        if (el instanceof Element && el.__fieldError && el.validity && el.validity.valid) clearFieldError(el);
    });
})();

// ---- Boot: theme, reveal, ripple, shadows ----
initTheme();
initRipple();
initTopbarShadow();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initScrollReveal();
        initCountUp();
    });
} else {
    initScrollReveal();
    initCountUp();
}
