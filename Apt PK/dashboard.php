<?php
// =====================================================
// Dashboard Page
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Dashboard';
requireRole(['admin', 'staff', 'tenant']);

$db = getDB();
$user = currentUser();
$role = $user['role'];

// Stats
if ($role === 'admin' || $role === 'staff') {
    $totalRooms = $db->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    $occupiedRooms = $db->query("SELECT COUNT(*) FROM rooms WHERE status='occupied'")->fetchColumn();
    $availableRooms = $db->query("SELECT COUNT(*) FROM rooms WHERE status='available'")->fetchColumn();
    $maintenanceRooms = $db->query("SELECT COUNT(*) FROM rooms WHERE status='maintenance'")->fetchColumn();
    $totalTenants = $db->query("SELECT COUNT(*) FROM users WHERE role='tenant' AND is_active=1")->fetchColumn();
    $totalPayments = $db->query("SELECT
        (SELECT COALESCE(SUM(amount),0) FROM rent_payments WHERE MONTH(payment_date)=MONTH(CURRENT_DATE()) AND YEAR(payment_date)=YEAR(CURRENT_DATE()))
        + (SELECT COALESCE(SUM(amount),0) FROM utility_bills WHERE status='paid' AND payment_date IS NOT NULL AND MONTH(payment_date)=MONTH(CURRENT_DATE()) AND YEAR(payment_date)=YEAR(CURRENT_DATE()))
        ")->fetchColumn();
    $pendingMaintenance = $db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status IN ('submitted','in_progress')")->fetchColumn();
    $overdueBills = $db->query("SELECT COUNT(*) FROM utility_bills WHERE status='unpaid'")->fetchColumn();
    $pendingBookings = $db->query("SELECT COUNT(*) FROM booking_requests WHERE status='pending'")->fetchColumn();
} else {
    // Tenant stats
    $myRoom = $db->prepare("SELECT r.*, t.id as tenancy_id, t.monthly_rent, t.start_date, t.end_date FROM tenancies t JOIN rooms r ON t.room_id = r.id WHERE t.tenant_id = ? AND t.status = 'active'");
    $myRoom->execute([$user['id']]);
    $myRoom = $myRoom->fetch();

    $myPayments = $db->prepare("SELECT COUNT(*) FROM rent_payments WHERE tenant_id = ?");
    $myPayments->execute([$user['id']]);
    $totalPaid = $myPayments->fetchColumn();

    $totalPaidAmount = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM rent_payments WHERE tenant_id = ?");
    $totalPaidAmount->execute([$user['id']]);
    $totalPaidAmount = $totalPaidAmount->fetchColumn();

    $myMaintenance = $db->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE tenant_id = ?");
    $myMaintenance->execute([$user['id']]);
    $myMaintenanceCount = $myMaintenance->fetchColumn();

    $myUnpaidBills = $db->prepare("SELECT COUNT(*) FROM utility_bills WHERE tenant_id = ? AND status = 'unpaid'");
    $myUnpaidBills->execute([$user['id']]);
    $myUnpaidBills = $myUnpaidBills->fetchColumn();
}

// Recent payments (rent + paid utility bills)
$recentPayments = $db->query("
    SELECT payment_date, amount, payment_method, tenant_name, room_number, kind
    FROM (
        SELECT rp.payment_date AS payment_date, rp.amount AS amount, rp.payment_method AS payment_method,
               u.full_name AS tenant_name, r.room_number AS room_number, 'Rent' AS kind, rp.created_at AS created_at
        FROM rent_payments rp
        JOIN users u ON rp.tenant_id = u.id
        JOIN rooms r ON rp.room_id = r.id
        UNION ALL
        SELECT ub.payment_date, ub.amount, ub.payment_method,
               u.full_name, r.room_number, CONCAT('Utility (', UCASE(ub.bill_type), ')'), ub.created_at
        FROM utility_bills ub
        JOIN users u ON ub.tenant_id = u.id
        JOIN rooms r ON ub.room_id = r.id
        WHERE ub.status = 'paid' AND ub.payment_date IS NOT NULL
    ) t
    ORDER BY payment_date DESC, created_at DESC
    LIMIT 5
")->fetchAll();

// Recent maintenance
$recentMaintenance = $db->query("SELECT mr.*, u.full_name as tenant_name, r.room_number FROM maintenance_requests mr JOIN users u ON mr.tenant_id = u.id JOIN rooms r ON mr.room_id = r.id ORDER BY mr.created_at DESC LIMIT 5")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<?php if ($role === 'admin' || $role === 'staff'): ?>
<!-- Admin/Staff Dashboard -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class='bx bx-door-open'></i></div>
        <div class="stat-info">
            <h4><?= $totalRooms ?></h4>
            <p>Total Residences</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon available"><i class='bx bx-check-circle'></i></div>
        <div class="stat-info">
            <h4><?= $availableRooms ?></h4>
            <p>Residences Available</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class='bx bx-home'></i></div>
        <div class="stat-info">
            <h4><?= $occupiedRooms ?>/<?= $totalRooms ?></h4>
            <p>Occupied</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class='bx bx-group'></i></div>
        <div class="stat-info">
            <h4><?= $totalTenants ?></h4>
            <p>Active Tenants</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon teal"><i class='bx bx-money'></i></div>
        <div class="stat-info">
            <h4 id="statRevenue"><?= formatCurrency($totalPayments) ?></h4>
            <p>Revenue This Month</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class='bx bx-wrench'></i></div>
        <div class="stat-info">
            <h4 id="statPendingMaintenance"><?= $pendingMaintenance ?></h4>
            <p>Pending Maintenance</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class='bx bx-bolt-circle'></i></div>
        <div class="stat-info">
            <h4 id="statUnpaidBills"><?= $overdueBills ?></h4>
            <p>Unpaid Utility Bills</p>
        </div>
    </div>
</div>

<!-- Occupancy Progress -->
<div class="card mb-3">
    <div class="card-header">
        <h3>Residence Occupancy</h3>
        <a href="rooms.php" class="btn btn-sm btn-outline">View All</a>
    </div>
    <div style="display:flex;align-items:center;gap:16px;">
        <div class="progress-bar" style="flex:1;">
            <div class="progress-bar-fill green" style="width:<?= $totalRooms > 0 ? round($occupiedRooms/$totalRooms*100) : 0 ?>%"></div>
        </div>
        <span style="font-weight:700;color:var(--text);"><?= $totalRooms > 0 ? round($occupiedRooms/$totalRooms*100) : 0 ?>%</span>
    </div>
    <div style="display:flex;gap:20px;margin-top:12px;font-size:0.82rem;">
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--success);margin-right:4px;"></span> Occupied (<?= $occupiedRooms ?>)</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--text-muted);margin-right:4px;"></span> Available (<?= $availableRooms ?>)</span>
        <?php if ($maintenanceRooms > 0): ?>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--danger);margin-right:4px;"></span> Maintenance (<?= $maintenanceRooms ?>)</span>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <!-- Recent Payments -->
    <div class="card">
        <div class="card-header">
            <h3>Recent Payments</h3>
            <a href="payments.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th>Type</th><th>Tenant</th><th>Residence</th><th>Amount</th><th>Date</th></tr>
                </thead>
                <tbody id="recentPaymentsBody">
                    <?php if ($recentPayments): foreach ($recentPayments as $p): ?>
                    <tr>
                        <td><span class="badge badge-<?= $p['kind'] === 'Rent' ? 'success' : 'info' ?>"><?= sanitize($p['kind']) ?></span></td>
                        <td><?= sanitize($p['tenant_name']) ?></td>
                        <td><?= sanitize($p['room_number']) ?></td>
                        <td style="font-weight:600;color:var(--success);"><?= formatCurrency($p['amount']) ?></td>
                        <td class="text-muted"><?= date('M j', strtotime($p['payment_date'])) ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-center text-muted">No recent payments</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Maintenance -->
    <div class="card">
        <div class="card-header">
            <h3>Maintenance Requests</h3>
            <a href="maintenance.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th>Issue</th><th>Residence</th><th>Priority</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if ($recentMaintenance): foreach ($recentMaintenance as $m): ?>
                    <tr>
                        <td><?= sanitize($m['subject']) ?></td>
                        <td><?= sanitize($m['room_number']) ?></td>
                        <td>
                            <span class="badge badge-<?= $m['priority'] === 'urgent' ? 'danger' : ($m['priority'] === 'high' ? 'warning' : ($m['priority'] === 'medium' ? 'info' : 'secondary')) ?>">
                                <?= ucfirst($m['priority']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-<?= $m['status'] === 'resolved' ? 'success' : ($m['status'] === 'in_progress' ? 'warning' : 'info') ?>">
                                <?= ucfirst(str_replace('_', ' ', $m['status'])) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-center text-muted">No requests</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Tenancy Agreements (admin/staff) -->
<div class="card mt-3">
    <div class="card-header">
        <h3>Tenancy Agreements</h3>
        <div style="display:flex;gap:8px;">
            <a href="tenants.php" class="btn btn-sm btn-outline">Manage</a>
            <button class="btn btn-sm btn-primary" onclick="openModal('uploadAgreementModal')"><i class="bx bx-upload"></i> Upload</button>
        </div>
    </div>
    <div id="recentAgreements"></div>
</div>

<!-- Upload Agreement Modal -->
<div class="modal-overlay" id="uploadAgreementModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Upload Tenancy Agreement</h3>
            <button class="modal-close" onclick="closeModal('uploadAgreementModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="dashAgreementForm" onsubmit="dashUploadAgreement(event)">
                <div class="form-group">
                    <label>Tenant *</label>
                    <select name="tenant_id" id="dashAgreementTenant" class="form-control" required></select>
                </div>
                <div class="form-group">
                    <label>Document (PDF, DOC, DOCX, or image)</label>
                    <input type="file" name="agreement_file" class="form-control" accept=".pdf,.doc,.docx,image/*" required>
                </div>
                <div class="form-group">
                    <label>Notes (optional)</label>
                    <input type="text" name="notes" class="form-control" maxlength="255" placeholder="e.g. Signed 1-year lease agreement">
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:12px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('uploadAgreementModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Tenant Dashboard -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class='bx bx-door-open'></i></div>
        <div class="stat-info">
            <h4><?= $myRoom ? sanitize($myRoom['room_number']) : 'N/A' ?></h4>
            <p>My Residence</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class='bx bx-check-circle'></i></div>
        <div class="stat-info">
            <h4><?= $totalPaid ?></h4>
            <p>Total Payments Made</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class='bx bx-wrench'></i></div>
        <div class="stat-info">
            <h4><?= $myMaintenanceCount ?></h4>
            <p>Maintenance Requests</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class='bx bx-bolt-circle'></i></div>
        <div class="stat-info">
            <h4><?= $myUnpaidBills ?></h4>
            <p>Unpaid Utility Bills</p>
        </div>
    </div>
</div>

<?php if ($myRoom): ?>
<!-- My Room Info -->
<div class="card mb-3">
    <div class="card-header">
        <h3>My Residence — <?= sanitize($myRoom['room_number']) ?></h3>
        <span class="badge badge-success">Active Tenancy</span>
    </div>
    <div class="grid-2">
        <div>
            <p style="margin-bottom:6px;"><strong>Type:</strong> <?= ucfirst($myRoom['room_type']) ?></p>
            <p style="margin-bottom:6px;"><strong>Floor:</strong> <?= $myRoom['floor'] ?></p>
            <p><strong>Amenities:</strong> <?= sanitize($myRoom['amenities']) ?></p>
        </div>
        <div>
            <p style="margin-bottom:6px;"><strong>Monthly Rent:</strong> <?= formatCurrency($myRoom['monthly_rent']) ?></p>
            <p style="margin-bottom:6px;"><strong>Start Date:</strong> <?= date('M j, Y', strtotime($myRoom['start_date'])) ?></p>
            <p><strong>End Date:</strong> <?= $myRoom['end_date'] ? date('M j, Y', strtotime($myRoom['end_date'])) : 'N/A' ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tenancy Agreement (tenant) -->
<div class="card mb-3">
    <div class="card-header">
        <h3>Tenancy Agreement</h3>
        <span class="badge badge-info">Documents</span>
    </div>
    <div id="tenantAgreements"></div>
</div>

<div class="grid-2">
    <!-- Quick Actions -->
    <div class="card">
        <div class="card-header"><h3>Quick Actions</h3></div>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <a href="payments.php" class="btn btn-primary btn-block">View Payment History</a>
            <a href="maintenance.php" class="btn btn-secondary btn-block">Submit Maintenance Request</a>
            <a href="utilities.php" class="btn btn-outline btn-block">Check Utility Bills</a>
            <a href="profile.php" class="btn btn-outline btn-block">Update Profile</a>
        </div>
    </div>

    <!-- Recent Notifications -->
    <div class="card">
        <div class="card-header">
            <h3>Recent Notifications</h3>
            <a href="notifications.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <?php
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$user['id']]);
        $notifs = $stmt->fetchAll();
        if ($notifs):
            foreach ($notifs as $n): ?>
            <div style="padding:10px 0;border-bottom:1px solid var(--border);<?= !$n['is_read'] ? 'background:rgba(33,150,243,0.04);padding-left:8px;border-left:3px solid var(--info);' : '' ?>">
                <div style="font-weight:600;font-size:0.88rem;"><?= sanitize($n['title']) ?></div>
                <div style="font-size:0.8rem;color:var(--text-muted);margin-top:2px;"><?= sanitize($n['message']) ?></div>
                <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;"><?= date('M j, g:i A', strtotime($n['created_at'])) ?></div>
            </div>
            <?php endforeach; else: ?>
            <div class="empty-state" style="padding:30px;">
                <p>No notifications yet</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- My Recent Payments -->
<div class="card mt-3">
    <div class="card-header">
        <h3>My Recent Payments</h3>
        <a href="payments.php" class="btn btn-sm btn-outline">View All</a>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr><th>Residence</th><th>Amount</th><th>Date</th><th>Method</th><th>Reference</th><th>Receipt</th></tr>
            </thead>
            <tbody>
                <?php
                $stmt = $db->prepare("SELECT rp.*, r.room_number FROM rent_payments rp JOIN rooms r ON rp.room_id = r.id WHERE rp.tenant_id = ? ORDER BY rp.payment_date DESC LIMIT 5");
                $stmt->execute([$user['id']]);
                $myPays = $stmt->fetchAll();
                if ($myPays):
                    foreach ($myPays as $p): ?>
                    <tr>
                        <td><?= sanitize($p['room_number']) ?></td>
                        <td style="font-weight:600;"><?= formatCurrency($p['amount']) ?></td>
                        <td><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
                        <td><?= ucfirst(str_replace('_', ' ', $p['payment_method'])) ?></td>
                        <td><code><?= sanitize($p['reference_number']) ?></code></td>
                        <td><a href="receipt.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline" target="_blank">Receipt</a></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center text-muted">No payments recorded</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function formatBytes(b) { b = Number(b) || 0; if (b < 1024) return b + ' B'; if (b < 1048576) return (b / 1024).toFixed(1) + ' KB'; return (b / 1048576).toFixed(2) + ' MB'; }

<?php if ($role === 'tenant'): ?>
(function loadMyAgreements() {
    fetch('api/agreements.php?action=mine')
        .then(r => r.json())
        .then(items => {
            const box = document.getElementById('tenantAgreements');
            if (!items || !items.length) {
                box.innerHTML = '<div class="empty-state" style="padding:24px;"><i class="bx bx-file" style="font-size:1.6rem;color:var(--text-muted);"></i><p>No agreement uploaded yet. Your agreement document will appear here.</p></div>';
                return;
            }
            box.innerHTML = items.map(a => `
                <div style="display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid var(--border);">
                    <i class="bx bx-file" style="font-size:1.4rem;color:var(--primary);flex-shrink:0;"></i>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;font-size:0.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(a.original_name)}</div>
                        <div style="font-size:0.75rem;color:var(--text-muted);">${formatBytes(a.file_size)} &bull; Uploaded ${new Date(a.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}${a.notes ? ' &bull; ' + esc(a.notes) : ''}</div>
                    </div>
                    <a class="btn btn-sm btn-primary" href="api/agreements.php?action=view&id=${a.id}" target="_blank">View</a>
                    <a class="btn btn-sm btn-outline" href="api/agreements.php?action=view&id=${a.id}&download=1">Download</a>
                </div>
            `).join('');
        });
})();
<?php else: ?>
function loadTenantOptions() {
    fetch('api/users.php?role=tenant')
        .then(r => r.json())
        .then(tenants => {
            const sel = document.getElementById('dashAgreementTenant');
            sel.innerHTML = '<option value="">Select tenant...</option>' + (tenants || []).map(t => `<option value="${t.id}">${esc(t.full_name)} (${esc(t.username)})</option>`).join('');
        });
}
function loadAgreements() {
    fetch('api/agreements.php?action=list')
        .then(r => r.json())
        .then(items => {
            const box = document.getElementById('recentAgreements');
            if (!items || !items.length) {
                box.innerHTML = '<div class="empty-state" style="padding:24px;"><i class="bx bx-file" style="font-size:1.6rem;color:var(--text-muted);"></i><p>No agreements uploaded yet.</p></div>';
                return;
            }
            box.innerHTML = items.slice(0, 5).map(a => `
                <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border);">
                    <i class="bx bx-file" style="font-size:1.3rem;color:var(--primary);flex-shrink:0;"></i>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;font-size:0.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(a.original_name)}</div>
                        <div style="font-size:0.74rem;color:var(--text-muted);">${esc(a.tenant_name)}${a.room_number ? ' &bull; ' + esc(a.room_number) : ''} &bull; ${formatBytes(a.file_size)} &bull; ${new Date(a.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</div>
                    </div>
                    <a class="btn btn-sm btn-outline" href="api/agreements.php?action=view&id=${a.id}" target="_blank">View</a>
                    <a class="btn btn-sm btn-outline" href="api/agreements.php?action=view&id=${a.id}&download=1">Download</a>
                </div>
            `).join('');
        });
}
async function dashUploadAgreement(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'add');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/agreements.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Agreement uploaded!', 'success');
        closeModal('uploadAgreementModal');
        e.target.reset();
        loadAgreements();
    } else {
        showToast(data.error || 'Upload failed', 'error');
    }
}
loadAgreements();
loadTenantOptions();

const fmtCcy = n => 'GH\u20B5 ' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function renderRecentPayments(rows) {
    const body = document.getElementById('recentPaymentsBody');
    if (!rows || !rows.length) {
        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No recent payments</td></tr>';
        return;
    }
    body.innerHTML = rows.map(p => `
        <tr>
            <td><span class="badge badge-${p.kind === 'Rent' ? 'success' : 'info'}">${esc(p.kind)}</span></td>
            <td>${esc(p.tenant_name)}</td>
            <td>${esc(p.room_number)}</td>
            <td style="font-weight:600;color:var(--success);">${fmtCcy(p.amount)}</td>
            <td class="text-muted">${new Date(p.payment_date).toLocaleDateString('en-GB', { month: 'short', day: 'numeric' })}</td>
        </tr>`).join('');
}

async function refreshDashboardStats() {
    try {
        const res = await fetch('api/dashboard_stats.php', { cache: 'no-store' });
        const data = await res.json();
        if (data.error) return;
        if (document.getElementById('statRevenue')) {
            document.getElementById('statRevenue').textContent = fmtCcy(data.revenueThisMonth);
            document.getElementById('statPendingMaintenance').textContent = data.pendingMaintenance;
            document.getElementById('statUnpaidBills').textContent = data.unpaidBills;
        }
        renderRecentPayments(data.recentPayments);
    } catch (err) { /* keep last known values */ }
}

refreshDashboardStats();
setInterval(refreshDashboardStats, 10000);
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
