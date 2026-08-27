<?php
// Tenant management: register tenants, track rent arrears, and manage tenancy agreements.
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Tenants';
requireRole(['admin', 'staff']);

$db = getDB();
$rooms = $db->query("SELECT r.id, r.room_number, r.rental_price, rt.charge_period FROM rooms r LEFT JOIN room_types rt ON rt.name = r.room_type WHERE r.status='available' ORDER BY r.room_number")->fetchAll();

// Arrears summary for active tenants
$totalOwing = 0.0;
$tenantsInArrears = 0;
$activeTenantIds = $db->query("SELECT id FROM users WHERE role='tenant' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
foreach ($activeTenantIds as $tid) {
    $a = computeTenantArrears($tid);
    $totalOwing += $a['owing'];
    if ($a['owing'] > 0) $tenantsInArrears++;
}

include __DIR__ . '/includes/header.php';
?>

<div class="report-summary" style="margin-bottom:20px;">
    <div class="report-box">
        <h5>Total Outstanding</h5>
        <div class="value" style="color:var(--danger);"><?= formatCurrency($totalOwing) ?></div>
    </div>
    <div class="report-box">
        <h5>Tenants in Arrears</h5>
        <div class="value"><?= $tenantsInArrears ?></div>
    </div>
    <div class="report-box">
        <h5>Active Tenants</h5>
        <div class="value"><?= count($activeTenantIds) ?></div>
    </div>
</div>

<div class="filter-bar">
    <div class="search-box">
        <span class="search-icon"><i class='bx bx-search'></i></span>
        <input type="text" id="searchTenants" placeholder="Search tenants..." oninput="filterTenants()">
    </div>
    <button class="btn btn-primary" onclick="openModal('addTenantModal')">+ Register Tenant</button>
</div>

<div class="card">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Balance Owing</th>
                    <th>Status</th>
                    <th>Age</th>
                    <th>Registered</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="tenantsBody"></tbody>
        </table>
    </div>
</div>

<!-- Add Tenant Modal -->
<div class="modal-overlay" id="addTenantModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Register New Tenant</h3>
            <button class="modal-close" onclick="closeModal('addTenantModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addTenantForm" onsubmit="submitTenant(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone *</label>
                        <input type="tel" name="phone" class="form-control" pattern="[0-9]{10}" maxlength="10" oninput="this.value=this.value.replace(/[^0-9]/g,'')" placeholder="10 digits only" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" class="form-control" placeholder="e.g. name@example.com" pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Date of Birth *</label>
                        <input type="date" name="date_of_birth" class="form-control" required max="<?= date('Y-m-d', strtotime('-18 years')) ?>" onchange="checkTenantAge(this)">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--accent-light);border-radius:var(--radius-sm);font-size:0.78rem;color:var(--text-secondary);">
                            <i class="bx bx-message-square-dots" style="font-size:1.1rem;"></i>
                            A temporary password will be sent to the tenant's phone via SMS. They'll change it on first login.
                        </div>
                    </div>
                </div>
                <hr style="border-color:var(--border);margin:16px 0;">
                <h4 style="font-size:0.95rem;margin-bottom:12px;color:var(--text);">Room Allocation</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Room *</label>
                        <select name="room_id" class="form-control" id="addTenantRoom" required onchange="document.getElementById('addTenantRent').value=this.options[this.selectedIndex].dataset.price||''">
                            <option value="" disabled selected>Select a room</option>
                            <?php foreach ($rooms as $r): ?>
                            <option value="<?= $r['id'] ?>" data-price="<?= $r['rental_price'] ?>"><?= sanitize($r['room_number']) ?> — <?= formatCurrency($r['rental_price']) ?>/<?= ($r['charge_period'] ?? 'monthly') === 'daily' ? 'day' : 'mo' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Monthly Rent (GH&#8373;) *</label>
                        <input type="number" name="monthly_rent" id="addTenantRent" class="form-control" step="0.01" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d', strtotime('-1 month')) ?>">
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addTenantModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Register Tenant</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Tenant Agreements Modal -->
<div class="modal-overlay" id="agreementModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="agreementModalTitle">Tenancy Agreements</h3>
            <button class="modal-close" onclick="closeModal('agreementModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="agreementList"></div>
            <hr style="border-color:var(--border);margin:16px 0;">
            <h4 style="font-size:0.95rem;margin-bottom:12px;color:var(--text);">Upload New Agreement</h4>
            <form id="agreementUploadForm" onsubmit="uploadAgreement(event)">
                <input type="hidden" name="tenant_id" id="agreementTenantId">
                <div class="form-group">
                    <label>Document (PDF, DOC, DOCX, or image)</label>
                    <input type="file" name="agreement_file" class="form-control" accept=".pdf,.doc,.docx,image/*" required>
                </div>
                <div class="form-group">
                    <label>Notes (optional)</label>
                    <input type="text" name="notes" class="form-control" maxlength="255" placeholder="e.g. Signed 1-year lease agreement">
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:12px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('agreementModal')">Close</button>
                    <button type="submit" class="btn btn-primary">Upload Agreement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let allTenants = [];
const isAdmin = <?= ($user['role'] === 'admin') ? 'true' : 'false' ?>;

async function loadTenants() {
    const res = await fetch('api/users.php?role=tenant');
    allTenants = await res.json();
    renderTenants(allTenants);
}

function filterTenants() {
    const search = document.getElementById('searchTenants').value.toLowerCase();
    const filtered = allTenants.filter(t => {
        return t.full_name.toLowerCase().includes(search) || t.username.toLowerCase().includes(search) || (t.phone || '').includes(search) || (t.email || '').toLowerCase().includes(search);
    });
    renderTenants(filtered);
}

function renderTenants(tenants) {
    const tbody = document.getElementById('tenantsBody');
    if (!tenants.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted" style="padding:30px;">No tenants found</td></tr>';
        return;
    }
    tbody.innerHTML = tenants.map(t => `
        <tr>
            <td style="font-weight:600;">${esc(t.full_name)}</td>
            <td>${esc(t.username)}</td>
            <td>${esc(t.phone || '—')}</td>
            <td>${esc(t.email || '—')}</td>
            <td>${t.owing > 0
                ? '<span style="color:var(--danger);font-weight:700;">GH\u20B5 ' + Number(t.owing).toFixed(2) + '</span>'
                : '<span class="text-muted" style="font-weight:600;color:var(--success);">Paid up</span>'}</td>
            <td><span class="badge badge-${t.is_active ? 'success' : 'danger'}">${t.is_active ? 'Active' : 'Inactive'}</span></td>
            <td class="text-muted">${t.date_of_birth ? (() => { const d = new Date(t.date_of_birth); const age = Math.floor((Date.now() - d.getTime()) / 31557600000); return age + ' yrs'; })() : '—'}</td>
            <td class="text-muted">${new Date(t.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    ${isAdmin ? `<button class="btn btn-sm btn-outline" onclick="resetPassword(${t.id})"><i class='bx bx-key'></i> Reset PW</button>` : ''}
                    ${t.is_active
                        ? `<button class="btn btn-sm btn-danger" onclick="deactivateTenant(${t.id})">Deactivate</button>`
                        : `<button class="btn btn-sm btn-success" onclick="activateTenant(${t.id})">Activate</button>`}
                    <button class="btn btn-sm btn-outline" onclick="openAgreements(${t.id})"><i class="bx bx-file"></i> Agreement</button>
                </div>
            </td>
        </tr>
    `).join('');
}

async function submitTenant(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    const dob = form.get('date_of_birth');
    if (!dob) {
        showToast('Date of birth is required — the tenant must be at least 18 years old.', 'error');
        return;
    }
    if (calculateAge(dob) < 18) {
        showToast('Tenant must be at least 18 years old to register.', 'error');
        return;
    }
    if (!form.get('room_id')) {
        showToast('Please select a room for the tenant.', 'error');
        return;
    }
    const phone = form.get('phone') || '';
    if (!/^[0-9]{10}$/.test(phone)) {
        showToast('Phone number must contain exactly 10 digits (numbers only).', 'error');
        return;
    }
    const email = form.get('email') || '';
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showToast('Please enter a valid email address.', 'error');
        return;
    }
    const startDate = form.get('start_date') || '';
    if (startDate) {
        const oneMonthAgo = new Date();
        oneMonthAgo.setMonth(oneMonthAgo.getMonth() - 1);
        if (new Date(startDate) < oneMonthAgo) {
            showToast('Start date cannot be more than 1 month in the past.', 'error');
            return;
        }
    }
    form.append('action', 'add_tenant');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/users.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Tenant registered successfully!', 'success');
        closeModal('addTenantModal');
        e.target.reset();
        loadTenants();
    } else {
        showToast(data.error || 'Error registering tenant', 'error');
    }
}

function calculateAge(dob) {
    const d = new Date(dob);
    return Math.floor((Date.now() - d.getTime()) / 31557600000);
}

function checkTenantAge(input) {
    if (input.value && calculateAge(input.value) < 18) {
        showToast('Tenant must be at least 18 years old to register.', 'error');
        input.setCustomValidity('Tenant must be at least 18 years old.');
    } else {
        input.setCustomValidity('');
    }
}

function formatBytes(b) {
    b = Number(b) || 0;
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(2) + ' MB';
}

async function openAgreements(id) {
    const t = allTenants.find(x => x.id === id);
    document.getElementById('agreementTenantId').value = id;
    document.getElementById('agreementModalTitle').textContent = 'Tenancy Agreements — ' + (t ? t.full_name : 'Tenant #' + id);
    document.getElementById('agreementUploadForm').reset();
    await loadAgreements(id);
    openModal('agreementModal');
}

async function loadAgreements(id) {
    const res = await fetch('api/agreements.php?action=list&tenant_id=' + id);
    const data = await res.json();
    const box = document.getElementById('agreementList');
    if (!data.length) {
        box.innerHTML = '<div class="empty-state" style="padding:20px;"><p>No agreement uploaded for this tenant yet.</p></div>';
        return;
    }
    box.innerHTML = data.map(a => `
        <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border);">
            <i class="bx bx-file" style="font-size:1.3rem;color:var(--primary);flex-shrink:0;"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:0.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(a.original_name)}</div>
                <div style="font-size:0.74rem;color:var(--text-muted);">${formatBytes(a.file_size)} &bull; ${new Date(a.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}${a.notes ? ' &bull; ' + esc(a.notes) : ''}</div>
            </div>
            <a class="btn btn-sm btn-outline" href="api/agreements.php?action=view&id=${a.id}" target="_blank">View</a>
            <a class="btn btn-sm btn-outline" href="api/agreements.php?action=view&id=${a.id}&download=1">Download</a>
            <button class="btn btn-sm btn-danger" onclick="deleteAgreement(${a.id}, ${a.tenant_id})">Delete</button>
        </div>
    `).join('');
}

async function uploadAgreement(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'add');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/agreements.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Agreement uploaded!', 'success');
        document.getElementById('agreementUploadForm').reset();
        await loadAgreements(Number(document.getElementById('agreementTenantId').value));
    } else {
        showToast(data.error || 'Upload failed', 'error');
    }
}

async function deleteAgreement(id, tenantId) {
    if (!confirm('Delete this agreement?')) return;
    const form = new FormData();
    form.append('action', 'delete');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/agreements.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Agreement deleted.', 'success');
        await loadAgreements(tenantId);
    } else {
        showToast(data.error || 'Delete failed', 'error');
    }
}

async function deactivateTenant(id) {
    showConfirm('Deactivate this tenant? They will no longer be able to log in.', () => doDeactivate(id), 'Deactivate');
}

async function doDeactivate(id) {
    const form = new FormData();
    form.append('action', 'deactivate_user');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/users.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Tenant deactivated.', 'success');
        loadTenants();
    } else {
        showToast(data.error || 'Error deactivating tenant', 'error');
    }
}

async function activateTenant(id) {
    showConfirm('Activate this tenant? They will be able to log in again.', () => doActivate(id), 'Activate');
}

async function doActivate(id) {
    const form = new FormData();
    form.append('action', 'activate_user');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/users.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Tenant activated.', 'success');
        loadTenants();
    } else {
        showToast(data.error || 'Error activating tenant', 'error');
    }
}

async function resetPassword(id) {
    showConfirm('Reset this tenant\'s password? A new temporary password will be sent to their phone via SMS.', () => doResetPassword(id), 'Reset Password');
}

async function doResetPassword(id) {
    const form = new FormData();
    form.append('action', 'reset_password');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/users.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Password reset. Temporary password sent via SMS.', 'success');
    } else {
        showToast(data.error || 'Error resetting password', 'error');
    }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

loadTenants();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
