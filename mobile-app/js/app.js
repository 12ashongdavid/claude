// Tiny hand-rolled router/renderer — no framework, so there's nothing to
// build or install to run this app; open index.html and it works.

const $app = document.getElementById('app');
let currentTab = 'home';
let cache = {}; // last-loaded data per screen, so switching tabs feels instant

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
function money(n) {
    return 'GH₵ ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtDate(d) {
    if (!d) return 'N/A';
    return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}
function fmtDateTime(d) {
    if (!d) return '';
    return new Date(d.replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
function monthLabel(ym) {
    if (!ym) return '';
    return new Date(ym + '-01T00:00:00').toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

function spinner() {
    return '<div class="spinner"></div>';
}
function emptyState(icon, text) {
    return `<div class="empty-state"><i class='bx ${icon}'></i><p>${esc(text)}</p></div>`;
}
function alertBox(type, msg) {
    return `<div class="alert alert-${type}">${esc(msg)}</div>`;
}

// ---------------- Auth screens ----------------

function renderLogin(errorMsg) {
    $app.innerHTML = `
    <div class="login-screen">
        <div class="login-logo">
            <img src="icons/icon-192.png" alt="PK's Luxury Apartments">
            <h1>PK's Luxury Apartments</h1>
            <p>Tenant portal</p>
        </div>
        <div class="login-card">
            ${errorMsg ? alertBox('error', errorMsg) : ''}
            <form id="loginForm">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" placeholder="Your username" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Your password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary">Sign In</button>
            </form>
        </div>
    </div>`;

    document.getElementById('loginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = new FormData(e.target);
        form.append('action', 'login');
        form.append('device_label', navigator.userAgent.slice(0, 90));
        const btn = e.target.querySelector('button');
        const origText = btn.textContent;
        btn.textContent = 'Signing in…';
        btn.disabled = true;
        try {
            const data = await Api.post('auth.php', form);
            Api.setToken(data.token);
            Api.setUser(data.user);
            cache = {};
            renderApp();
        } catch (err) {
            renderLogin(err.message);
        }
    });
}

// ---------------- App shell ----------------

function renderApp() {
    $app.innerHTML = `
    <div class="topbar">
        <div>
            <h1>Hi, ${esc((Api.user() || {}).full_name || 'there').split(' ')[0]}</h1>
            <div class="subtitle">PK's Luxury Apartments</div>
        </div>
        <button class="topbar-bell" id="bellBtn" aria-label="Notifications">
            <i class='bx bx-bell'></i>
            <span class="dot hidden" id="bellDot"></span>
        </button>
    </div>
    <div id="screenBody" class="screen"></div>
    <nav class="bottom-nav">
        <button data-tab="home"><i class='bx bx-home'></i>Home</button>
        <button data-tab="payments"><i class='bx bx-money'></i>Rent</button>
        <button data-tab="utilities"><i class='bx bx-bulb'></i>Utilities</button>
        <button data-tab="maintenance"><i class='bx bx-wrench'></i>Repairs</button>
        <button data-tab="profile"><i class='bx bx-user'></i>Profile</button>
    </nav>`;

    document.getElementById('bellBtn').addEventListener('click', () => goTab('notifications'));
    document.querySelectorAll('.bottom-nav button').forEach((b) => {
        b.addEventListener('click', () => goTab(b.dataset.tab));
    });

    refreshUnreadDot();
    // Wait for the home screen's own data fetch to finish before showing
    // the payment-outcome banner — it's injected into #screenBody, which
    // the screen render below would otherwise wipe out from under it.
    goTab('home').then(handlePaystackReturn);
}

function goTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.bottom-nav button').forEach((b) => {
        b.classList.toggle('active', b.dataset.tab === tab);
    });
    const renderers = {
        home: renderHome, payments: renderPayments, utilities: renderUtilities,
        maintenance: renderMaintenance, notifications: renderNotifications, profile: renderProfile,
    };
    return (renderers[tab] || renderers.home)();
}

async function refreshUnreadDot() {
    try {
        const data = await Api.get('notifications.php');
        const unread = (data.notifications || []).filter((n) => !n.is_read).length;
        document.getElementById('bellDot').classList.toggle('hidden', unread === 0);
    } catch (e) { /* not critical */ }
}

function handlePaystackReturn() {
    const params = new URLSearchParams(location.search);
    if (!params.has('paid')) return;
    const ok = params.get('paid') === '1';
    const body = document.getElementById('screenBody');
    const banner = document.createElement('div');
    banner.innerHTML = ok
        ? alertBox('success', 'Payment received! Thank you. Your records have been updated.')
        : alertBox('error', "Payment wasn't completed. No charge was made, so you can try again.");
    body.prepend(banner.firstChild);
    history.replaceState({}, '', location.pathname);
}

// ---------------- Home ----------------

async function renderHome() {
    const body = document.getElementById('screenBody');
    body.innerHTML = spinner();
    try {
        const d = await Api.get('dashboard.php');
        cache.home = d;
        const room = d.room;
        body.innerHTML = `
        <div class="stat-grid">
            <div class="stat-card ${d.owing > 0 ? 'owing' : 'ok'}">
                <div class="value">${money(d.owing)}</div>
                <div class="label">${d.owing > 0 ? 'Rent Owing' : 'Rent Up To Date'}</div>
            </div>
            <div class="stat-card">
                <div class="value">${d.unpaidBills}</div>
                <div class="label">Unpaid Bills</div>
            </div>
            <div class="stat-card">
                <div class="value">${d.openMaintenance}</div>
                <div class="label">Open Repairs</div>
            </div>
            <div class="stat-card">
                <div class="value">${d.unreadNotifications}</div>
                <div class="label">Unread Alerts</div>
            </div>
        </div>
        ${room ? `
        <div class="card">
            <h3>Your Residence</h3>
            <div class="list-item">
                <div class="main">
                    <div class="title">${esc(room.room_number)} · ${esc((room.room_type || '').replace(/^./, c => c.toUpperCase()))}</div>
                    <div class="meta">Paid through: ${d.paidThrough ? esc(d.paidThrough) : 'No payments yet'}</div>
                </div>
                <div class="amount">${money(room.monthly_rent)}/mo</div>
            </div>
        </div>` : `<div class="card">${emptyState('bx-home-smile', "You don't have an active residence on file yet. Contact management if this looks wrong.")}</div>`}
        <div class="card">
            <h3>Recent Activity</h3>
            ${(d.recentPayments && d.recentPayments.length) ? d.recentPayments.map((p) => `
                <div class="list-item">
                    <div class="main">
                        <div class="title">${esc(p.kind)}</div>
                        <div class="meta">${fmtDate(p.payment_date)} · ${esc((p.payment_method || '').replace('_', ' '))}</div>
                    </div>
                    <div class="amount">${money(p.amount)}</div>
                </div>`).join('') : emptyState('bx-receipt', 'No payments yet.')}
        </div>`;
    } catch (err) {
        body.innerHTML = alertBox('error', err.message);
    }
}

// ---------------- Payments (rent) ----------------

async function renderPayments() {
    const body = document.getElementById('screenBody');
    body.innerHTML = spinner();
    try {
        const d = await Api.get('payments.php');
        cache.payments = d;
        body.innerHTML = `
        <button class="btn btn-primary" id="payRentBtn" style="margin-bottom:14px;"><i class='bx bx-credit-card'></i> Pay Rent Online</button>
        <div class="card">
            <h3>Payment History</h3>
            ${(d.payments && d.payments.length) ? d.payments.map((p) => `
                <div class="list-item">
                    <div class="main">
                        <div class="title">${esc(monthLabel(p.month_covered))}</div>
                        <div class="meta">${fmtDate(p.payment_date)} · Ref ${esc(p.reference_number || 'N/A')}</div>
                    </div>
                    <div class="amount">${money(p.amount)}</div>
                </div>`).join('') : emptyState('bx-money', 'No rent payments recorded yet.')}
        </div>`;
        document.getElementById('payRentBtn').addEventListener('click', openPayRentModal);
    } catch (err) {
        body.innerHTML = alertBox('error', err.message);
    }
}

function openPayRentModal() {
    const today = new Date();
    const defaultMonth = today.toISOString().slice(0, 7);
    openModal(`
        <h3 style="margin-bottom:14px;">Pay Rent Online</h3>
        <div id="payRentError"></div>
        <form id="payRentForm">
            <div class="form-group">
                <label>Starting Month</label>
                <input type="month" name="month_covered" class="form-control" value="${defaultMonth}" min="${defaultMonth}" required>
            </div>
            <div class="form-group">
                <label>Number of Months</label>
                <input type="number" name="months" class="form-control" value="1" min="1" max="12" required>
            </div>
            <button type="submit" class="btn btn-primary">Continue to Payment</button>
        </form>
    `);
    document.getElementById('payRentForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = new FormData(e.target);
        form.append('action', 'initialize');
        const btn = e.target.querySelector('button');
        btn.disabled = true;
        btn.textContent = 'Please wait…';
        try {
            const data = await Api.post('payments.php', form);
            location.href = data.authorization_url;
        } catch (err) {
            document.getElementById('payRentError').innerHTML = alertBox('error', err.message);
            btn.disabled = false;
            btn.textContent = 'Continue to Payment';
        }
    });
}

// ---------------- Utilities ----------------

async function renderUtilities() {
    const body = document.getElementById('screenBody');
    body.innerHTML = spinner();
    try {
        const d = await Api.get('utilities.php');
        cache.utilities = d;
        body.innerHTML = `<div class="card"><h3>Utility Bills</h3>
            ${(d.bills && d.bills.length) ? d.bills.map((b) => `
                <div class="list-item">
                    <div class="main">
                        <div class="title">${esc((b.bill_type || '').replace(/^./, c => c.toUpperCase()))} · ${esc(monthLabel(b.billing_month))}</div>
                        <div class="meta">
                            ${b.status === 'paid' ? `<span class="badge badge-success">Paid</span>` : `<span class="badge badge-danger">Unpaid</span>`}
                        </div>
                    </div>
                    <div class="main" style="flex:0;text-align:right;">
                        <div class="amount">${money(b.amount)}</div>
                        ${b.status !== 'paid' ? `<button class="btn btn-primary" style="width:auto;padding:8px 14px;font-size:0.8rem;margin-top:6px;" data-bill="${b.id}">Pay Now</button>` : ''}
                    </div>
                </div>`).join('') : emptyState('bx-bulb', 'No utility bills yet.')}
        </div>`;
        body.querySelectorAll('[data-bill]').forEach((btn) => {
            btn.addEventListener('click', () => payUtilityBill(btn.dataset.bill, btn));
        });
    } catch (err) {
        body.innerHTML = alertBox('error', err.message);
    }
}

async function payUtilityBill(billId, btn) {
    btn.disabled = true;
    btn.textContent = '…';
    try {
        const data = await Api.post('utilities.php', { action: 'initialize', bill_id: billId });
        location.href = data.authorization_url;
    } catch (err) {
        alert(err.message);
        btn.disabled = false;
        btn.textContent = 'Pay Now';
    }
}

// ---------------- Maintenance ----------------

async function renderMaintenance() {
    const body = document.getElementById('screenBody');
    body.innerHTML = spinner();
    try {
        const d = await Api.get('maintenance.php');
        cache.maintenance = d;
        const statusBadge = { submitted: 'warning', in_progress: 'warning', resolved: 'success', closed: 'muted' };
        body.innerHTML = `
        <button class="btn btn-primary" id="newRequestBtn" style="margin-bottom:14px;"><i class='bx bx-plus'></i> New Repair Request</button>
        <div class="card">
            <h3>Your Requests</h3>
            ${(d.requests && d.requests.length) ? d.requests.map((r) => `
                <div class="list-item">
                    <div class="main">
                        <div class="title">${esc(r.subject)}</div>
                        <div class="meta">${esc((r.category || '').replace('_', ' '))} · ${fmtDateTime(r.created_at)}</div>
                    </div>
                    <span class="badge badge-${statusBadge[r.status] || 'muted'}">${esc((r.status || '').replace('_', ' '))}</span>
                </div>`).join('') : emptyState('bx-wrench', "You haven't submitted any repair requests.")}
        </div>`;
        document.getElementById('newRequestBtn').addEventListener('click', () => openNewMaintenanceModal(d.rooms || []));
    } catch (err) {
        body.innerHTML = alertBox('error', err.message);
    }
}

function openNewMaintenanceModal(rooms) {
    const roomOptions = rooms.map((r) => `<option value="${r.id}">${esc(r.room_number)}</option>`).join('');
    openModal(`
        <h3 style="margin-bottom:14px;">New Repair Request</h3>
        <div id="maintError"></div>
        <form id="maintForm">
            <div class="form-group">
                <label>Room</label>
                <select name="room_id" class="form-control" required>${roomOptions}</select>
            </div>
            <div class="form-group">
                <label>Category</label>
                <select name="category" class="form-control" required>
                    <option value="plumbing">Plumbing</option>
                    <option value="electrical">Electrical</option>
                    <option value="structural">Structural</option>
                    <option value="pest_control">Pest Control</option>
                    <option value="appliance">Appliance</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label>Priority</label>
                <select name="priority" class="form-control" required>
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div class="form-group">
                <label>Subject</label>
                <input type="text" name="subject" class="form-control" placeholder="e.g. Leaking bathroom tap" required maxlength="150">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" placeholder="Describe the issue in detail…" required maxlength="1000"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit Request</button>
        </form>
    `);
    document.getElementById('maintForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = new FormData(e.target);
        form.append('action', 'submit');
        const btn = e.target.querySelector('button');
        btn.disabled = true;
        btn.textContent = 'Submitting…';
        try {
            await Api.post('maintenance.php', form);
            closeModal();
            renderMaintenance();
        } catch (err) {
            document.getElementById('maintError').innerHTML = alertBox('error', err.message);
            btn.disabled = false;
            btn.textContent = 'Submit Request';
        }
    });
}

// ---------------- Notifications ----------------

async function renderNotifications() {
    const body = document.getElementById('screenBody');
    body.innerHTML = spinner();
    try {
        const d = await Api.get('notifications.php');
        cache.notifications = d;
        body.innerHTML = `<div class="card"><h3>Notifications</h3>
            ${(d.notifications && d.notifications.length) ? d.notifications.map((n) => `
                <div class="list-item" data-notif="${n.id}" style="cursor:pointer;${n.is_read ? '' : 'background:var(--accent-soft);border-radius:8px;padding-left:8px;padding-right:8px;'}">
                    <div class="main">
                        <div class="title">${esc(n.title)}</div>
                        <div class="meta">${esc(n.message)}</div>
                        <div class="meta">${fmtDateTime(n.created_at)}</div>
                    </div>
                </div>`).join('') : emptyState('bx-bell-off', "You're all caught up. No notifications right now.")}
        </div>`;
        body.querySelectorAll('[data-notif]').forEach((el) => {
            el.addEventListener('click', async () => {
                await Api.post('notifications.php', { action: 'mark_read', id: el.dataset.notif });
                renderNotifications();
                refreshUnreadDot();
            });
        });
    } catch (err) {
        body.innerHTML = alertBox('error', err.message);
    }
}

// ---------------- Profile ----------------

async function renderProfile() {
    const body = document.getElementById('screenBody');
    body.innerHTML = spinner();
    try {
        const d = await Api.get('profile.php');
        const u = d.user;
        cache.profile = d;
        body.innerHTML = `
        ${u.must_change_password ? alertBox('error', 'You are using a temporary password. Please set a new one below.') : ''}
        <div class="card">
            <h3>My Details</h3>
            <form id="profileForm">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" class="form-control" value="${esc(u.full_name)}" required></div>
                <div class="form-group"><label>Phone</label><input type="tel" name="phone" class="form-control" value="${esc(u.phone)}" pattern="[0-9]{10}" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="${esc(u.email || '')}" required></div>
                <div id="profileMsg"></div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
        </div>
        <div class="card">
            <h3>Change Password</h3>
            <form id="pwForm">
                <div class="form-group"><label>Current Password</label><input type="password" name="current_password" class="form-control" required></div>
                <div class="form-group"><label>New Password</label><input type="password" name="password" class="form-control" required></div>
                <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div>
                <div id="pwMsg"></div>
                <button type="submit" class="btn btn-outline">Update Password</button>
            </form>
        </div>
        <button class="btn btn-outline" id="logoutBtn" style="color:var(--danger);border-color:var(--danger-bg);">Sign Out</button>`;

        document.getElementById('profileForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = new FormData(e.target);
            form.append('action', 'update');
            try {
                await Api.post('profile.php', form);
                document.getElementById('profileMsg').innerHTML = alertBox('success', 'Saved.');
            } catch (err) {
                document.getElementById('profileMsg').innerHTML = alertBox('error', err.message);
            }
        });
        document.getElementById('pwForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = new FormData(e.target);
            form.append('action', 'change_password');
            try {
                await Api.post('profile.php', form);
                document.getElementById('pwMsg').innerHTML = alertBox('success', 'Password updated.');
                e.target.reset();
            } catch (err) {
                document.getElementById('pwMsg').innerHTML = alertBox('error', err.message);
            }
        });
        document.getElementById('logoutBtn').addEventListener('click', async () => {
            try { await Api.post('auth.php', { action: 'logout' }); } catch (e) {}
            Api.setToken(null);
            Api.setUser(null);
            init();
        });
    } catch (err) {
        body.innerHTML = alertBox('error', err.message);
    }
}

// ---------------- Modal helper ----------------

function openModal(innerHtml) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'modalOverlay';
    overlay.innerHTML = `<div class="modal-sheet">
        <div class="modal-header"><span></span><button class="modal-close" aria-label="Close">&times;</button></div>
        <div class="modal-body">${innerHtml}</div>
    </div>`;
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });
    overlay.querySelector('.modal-close').addEventListener('click', closeModal);
    document.body.appendChild(overlay);
}
function closeModal() {
    const el = document.getElementById('modalOverlay');
    if (el) el.remove();
}

// ---------------- Boot ----------------

function init() {
    if (Api.isLoggedIn()) {
        renderApp();
    } else {
        renderLogin();
    }
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('service-worker.js').catch(() => {});
    });
}

init();
