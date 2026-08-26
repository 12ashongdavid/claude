<?php
// =====================================================
// Feedback Reports Page (Admin/Staff) — polished UI
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Feedback Reports';
requireRole(['admin', 'staff']);
$db = getDB();

$newCount = $db->query("SELECT COUNT(*) FROM feedback_reports WHERE status='new'")->fetchColumn();
$progressCount = $db->query("SELECT COUNT(*) FROM feedback_reports WHERE status='in_progress'")->fetchColumn();
$resolvedCount = $db->query("SELECT COUNT(*) FROM feedback_reports WHERE status='resolved'")->fetchColumn();
$totalCount = $db->query("SELECT COUNT(*) FROM feedback_reports")->fetchColumn();

include __DIR__ . '/includes/header.php';
?>

<div class="grid-4" style="margin-bottom:24px;">
    <div class="stat-card" style="cursor:pointer;" onclick="document.getElementById('filterStatus').value='';loadFeedback();">
        <div class="stat-icon blue"><i class='bx bx-message-detail'></i></div>
        <div class="stat-info"><h4 id="statTotal"><?= $totalCount ?></h4><p>Total Reports</p></div>
    </div>
    <div class="stat-card" style="cursor:pointer;" onclick="document.getElementById('filterStatus').value='new';loadFeedback();">
        <div class="stat-icon orange"><i class='bx bx-envelope'></i></div>
        <div class="stat-info"><h4 id="statNew"><?= $newCount ?></h4><p>New</p></div>
    </div>
    <div class="stat-card" style="cursor:pointer;" onclick="document.getElementById('filterStatus').value='in_progress';loadFeedback();">
        <div class="stat-icon purple"><i class='bx bx-loader-alt'></i></div>
        <div class="stat-info"><h4 id="statProgress"><?= $progressCount ?></h4><p>In Progress</p></div>
    </div>
    <div class="stat-card" style="cursor:pointer;" onclick="document.getElementById('filterStatus').value='resolved';loadFeedback();">
        <div class="stat-icon green"><i class='bx bx-check-circle'></i></div>
        <div class="stat-info"><h4 id="statResolved"><?= $resolvedCount ?></h4><p>Resolved</p></div>
    </div>
</div>

<div class="filter-bar" style="margin-bottom:20px;">
    <select id="filterStatus" onchange="loadFeedback()" class="form-control" style="max-width:150px;">
        <option value="">All Status</option>
        <option value="new">New</option>
        <option value="in_progress">In Progress</option>
        <option value="resolved">Resolved</option>
        <option value="closed">Closed</option>
    </select>
    <select id="filterCategory" onchange="loadFeedback()" class="form-control" style="max-width:150px;">
        <option value="">All Categories</option>
        <option value="general">General</option>
        <option value="complaint">Complaint</option>
        <option value="suggestion">Suggestion</option>
        <option value="maintenance">Maintenance</option>
        <option value="feedback">Feedback</option>
        <option value="other">Other</option>
    </select>
</div>

<div class="card">
    <div class="card-header">
        <h3>Reports</h3>
        <span class="text-muted" id="feedbackCount" style="font-size:0.82rem;"></span>
    </div>
    <div id="feedbackContainer"></div>
</div>

<!-- Reply Modal -->
<div class="modal-overlay" id="replyModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header" style="background:linear-gradient(135deg, var(--primary), #2D4A7A);color:#fff;">
            <div style="display:flex;align-items:center;gap:10px;">
                <i class='bx bx-reply' style="font-size:1.3rem;"></i>
                <h3 style="margin:0;">Reply to Report</h3>
            </div>
            <button class="modal-close" onclick="closeModal('replyModal')" style="color:rgba(255,255,255,0.8);">&times;</button>
        </div>
        <div class="modal-body" style="padding:24px;">
            <div id="replyReportInfo" style="margin-bottom:16px;"></div>
            <form id="replyForm" onsubmit="submitReply(event)">
                <input type="hidden" name="report_id" id="replyReportId">
                <div class="form-group">
                    <label style="font-weight:700;font-size:0.85rem;">Update Status</label>
                    <select name="status" id="replyStatus" class="form-control">
                        <option value="in_progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-weight:700;font-size:0.85rem;">Your Reply <span style="color:var(--danger);">*</span></label>
                    <textarea name="admin_reply" id="replyMessage" class="form-control" rows="4" placeholder="Write your reply to the reporter..." required></textarea>
                </div>
                <div class="modal-footer" style="padding:20px 24px;border-top:1px solid var(--border);margin:16px -24px -24px;display:flex;gap:12px;justify-content:flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('replyModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="display:flex;align-items:center;gap:6px;"><i class='bx bx-send'></i> Send Reply</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let allFeedback = [];

async function loadFeedback() {
    const status = document.getElementById('filterStatus').value;
    const category = document.getElementById('filterCategory').value;
    let url = 'api/feedback.php?';
    if (status) url += 'status=' + status + '&';
    if (category) url += 'category=' + category;
    const res = await fetch(url);
    allFeedback = await res.json();
    renderFeedback(allFeedback);
    document.getElementById('feedbackCount').textContent = allFeedback.length + ' report(s)';
}

function renderFeedback(reports) {
    const container = document.getElementById('feedbackContainer');
    if (!reports.length) {
        container.innerHTML = '<div class="empty-state" style="padding:48px 20px;"><div class="empty-icon"><i class="bx bx-message-detail" style="font-size:3rem;color:var(--text-muted);"></i></div><h4 style="margin-top:12px;">No reports found</h4><p style="color:var(--text-muted);font-size:0.9rem;">Reports from tenants and website visitors will appear here.</p></div>';
        return;
    }
    const catIcons = { general: 'info-circle', complaint: 'error', suggestion: 'bulb', maintenance: 'wrench', feedback: 'star', other: 'dots-horizontal-rounded' };
    const catColors = { general: 'info', complaint: 'danger', suggestion: 'success', maintenance: 'warning', feedback: 'purple', other: 'secondary' };
    const statusColors = { new: 'info', in_progress: 'warning', resolved: 'success', closed: 'secondary' };

    container.innerHTML = reports.map(r => `
        <div class="booking-card ${r.status === 'new' ? 'pending' : (r.status === 'resolved' ? 'approved' : '')}" style="border-left:4px solid var(--${statusColors[r.status] || 'info'});">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                <div style="flex:1;min-width:240px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                        <span class="badge badge-${catColors[r.category] || 'info'}" style="display:inline-flex;align-items:center;gap:4px;"><i class="bx bx-${catIcons[r.category] || 'info-circle'}" style="font-size:0.75rem;"></i>${esc(r.category)}</span>
                        <span class="badge badge-${statusColors[r.status] || 'info'}">${esc(r.status.replace('_', ' '))}</span>
                    </div>
                    <p style="font-size:0.92rem;color:var(--text);margin-bottom:8px;white-space:pre-wrap;line-height:1.5;">${esc(r.message)}</p>
                    <div style="display:flex;align-items:center;gap:12px;font-size:0.78rem;color:var(--text-muted);flex-wrap:wrap;">
                        <span style="display:flex;align-items:center;gap:4px;"><i class="bx bx-user" style="font-size:0.85rem;"></i> ${esc(r.reporter_name)}</span>
                        ${r.reporter_email ? '<span style="display:flex;align-items:center;gap:4px;"><i class="bx bx-envelope" style="font-size:0.85rem;"></i> ' + esc(r.reporter_email) + '</span>' : ''}
                        ${r.reporter_phone ? '<span style="display:flex;align-items:center;gap:4px;"><i class="bx bx-phone" style="font-size:0.85rem;"></i> ' + esc(r.reporter_phone) + '</span>' : ''}
                        <span>${r.user_id ? '<span class="badge badge-info" style="font-size:0.65rem;padding:2px 8px;">Tenant</span>' : '<span class="badge badge-secondary" style="font-size:0.65rem;padding:2px 8px;">Public</span>'}</span>
                        <span>${new Date(r.created_at).toLocaleString('en-GB')}</span>
                    </div>
                    ${r.admin_reply ? `
                    <div style="margin-top:12px;padding:12px 16px;background:var(--surface);border-radius:var(--radius);border-left:3px solid var(--accent);">
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                            <i class="bx bx-reply" style="color:var(--accent);font-size:0.85rem;"></i>
                            <span style="font-size:0.78rem;font-weight:700;color:var(--accent);">${esc(r.admin_reply_by_name || 'Admin')}</span>
                            <span style="font-size:0.72rem;color:var(--text-muted);">${r.admin_reply_at ? new Date(r.admin_reply_at).toLocaleString('en-GB') : ''}</span>
                        </div>
                        <div style="font-size:0.88rem;color:var(--text-secondary);white-space:pre-wrap;line-height:1.5;">${esc(r.admin_reply)}</div>
                    </div>` : ''}
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;min-width:140px;">
                    <button class="btn btn-sm btn-primary" onclick="openReply(${r.id}, '${esc((r.category || 'Report') + ': ' + r.message.substring(0, 60)).replace(/'/g, "\\'")}', '${esc(r.status)}')" style="display:flex;align-items:center;justify-content:center;gap:4px;"><i class="bx bx-reply"></i> Reply</button>
                    <select onchange="updateStatus(${r.id}, this.value)" class="form-control" style="padding:5px 8px;font-size:0.78rem;">
                        <option value="new" ${r.status === 'new' ? 'selected' : ''}>New</option>
                        <option value="in_progress" ${r.status === 'in_progress' ? 'selected' : ''}>In Progress</option>
                        <option value="resolved" ${r.status === 'resolved' ? 'selected' : ''}>Resolved</option>
                        <option value="closed" ${r.status === 'closed' ? 'selected' : ''}>Closed</option>
                    </select>
                    ${isAdmin ? `<button class="btn btn-sm btn-outline" onclick="deleteFeedback(${r.id})" style="color:var(--danger);border-color:var(--danger);display:flex;align-items:center;justify-content:center;gap:4px;"><i class="bx bx-trash"></i> Delete</button>` : ''}
                </div>
            </div>
        </div>
    `).join('');
}

const isAdmin = <?= ($user['role'] === 'admin') ? 'true' : 'false' ?>;

function openReply(id, subject, status) {
    document.getElementById('replyReportId').value = id;
    document.getElementById('replyReportInfo').innerHTML = `<div style="padding:12px 16px;background:var(--surface);border-radius:var(--radius);font-size:0.88rem;display:flex;align-items:center;gap:8px;"><i class="bx bx-message-detail" style="color:var(--primary);font-size:1.1rem;"></i><span>${esc(subject)}</span></div>`;
    document.getElementById('replyStatus').value = status === 'new' ? 'in_progress' : status;
    document.getElementById('replyMessage').value = '';
    openModal('replyModal');
}

async function submitReply(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'reply');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/feedback.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Reply sent!', 'success');
        closeModal('replyModal');
        loadFeedback();
    } else {
        showToast(data.error || 'Error sending reply', 'error');
    }
}

async function updateStatus(id, status) {
    const form = new FormData();
    form.append('action', 'update_status');
    form.append('id', id);
    form.append('status', status);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/feedback.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Status updated.', 'success');
        loadFeedback();
    }
}

async function deleteFeedback(id) {
    if (!confirm('Delete this report? This cannot be undone.')) return;
    const form = new FormData();
    form.append('action', 'delete');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/feedback.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Report deleted.', 'success');
        loadFeedback();
    }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

loadFeedback();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
