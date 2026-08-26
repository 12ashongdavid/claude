<?php
// =====================================================
// Bookings Page (Admin/Staff) — with payment management
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Booking Requests';
requireRole(['admin', 'staff']);

include __DIR__ . '/includes/header.php';
?>

<div class="filter-bar">
    <select id="filterBookingStatus" onchange="loadBookings()" class="form-control" style="max-width:160px;">
        <option value="">All Status</option>
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
        <option value="rejected">Rejected</option>
    </select>
</div>

<div class="card">
    <div class="card-header">
        <h3>Booking Requests</h3>
    </div>
    <div id="bookingsContainer"></div>
</div>

<div class="modal-overlay" id="confirmPaymentModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <h3>Confirm Payment</h3>
            <button class="modal-close" onclick="closeModal('confirmPaymentModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="confirmPaymentForm" onsubmit="submitConfirmPayment(event)">
                <input type="hidden" name="id" id="confirmPaymentBookingId">
                <div class="form-group">
                    <label>Reference Number</label>
                    <input type="text" name="payment_reference" class="form-control" placeholder="e.g. Bank slip number or ADMIN-XXXX">
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:12px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('confirmPaymentModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let allBookings = [];

async function loadBookings() {
    const status = document.getElementById('filterBookingStatus').value;
    let url = 'api/bookings.php';
    if (status) url += '?status=' + status;
    const res = await fetch(url);
    allBookings = await res.json();
    renderBookings(allBookings);
}

function renderBookings(bookings) {
    const container = document.getElementById('bookingsContainer');
    if (!bookings.length) {
        container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="bx bx-calendar" style="font-size:3rem;"></i></div><h4>No booking requests</h4></div>';
        return;
    }

    const statusColors = { pending: 'warning', approved: 'success', rejected: 'danger' };
    const paymentStatusColors = { pending: 'warning', completed: 'success', failed: 'danger', '': 'secondary' };
    const paymentLabels = { none: 'No Deposit', down_payment: '50% Deposit', full_payment: 'Full Amount' };
    const paymentStatusLabels = { pending: 'Pending', completed: 'Paid', failed: 'Failed', '': 'N/A' };

    container.innerHTML = bookings.map(b => {
        const hasPayment = b.payment_type && b.payment_type !== 'none';
        return `
        <div class="booking-card ${esc(b.status)}" style="border-left-color:var(--${statusColors[b.status] || 'info'});">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
                <div style="flex:1;min-width:200px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                        <h4 style="font-size:1rem;margin:0;">${esc(b.full_name)}</h4>
                        <span class="badge badge-${statusColors[b.status] || 'info'}">${esc(b.status)}</span>
                        ${hasPayment ? `<span class="badge badge-${paymentStatusColors[b.payment_status] || 'secondary'}">${esc(paymentLabels[b.payment_type])} &mdash; ${esc(paymentStatusLabels[b.payment_status] || b.payment_status)}</span>` : '<span class="badge badge-secondary">No Payment</span>'}
                    </div>
                    <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:2px;">
                        <i class="bx bx-phone"></i> ${esc(b.phone)} ${b.email ? '&bull; <i class="bx bx-envelope"></i> ' + esc(b.email) : ''}
                    </p>
                    ${b.room_number ? `<p style="font-size:0.85rem;margin-top:4px;"><strong>Residence:</strong> ${esc(b.room_number)}</p>` : ''}
                    ${b.preferred_date ? `<p style="font-size:0.85rem;"><strong>Preferred Date:</strong> ${new Date(b.preferred_date).toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'})}</p>` : ''}
                    ${b.message ? `<p style="font-size:0.85rem;margin-top:4px;color:var(--text-secondary);">${esc(b.message)}</p>` : ''}
                    ${hasPayment ? `
                    <div style="margin-top:8px;padding:10px 14px;background:var(--surface);border-radius:8px;font-size:0.82rem;">
                        <strong>Payment:</strong> ${fmtCcy(b.payment_amount)} via ${esc((b.payment_method || 'none').replace('_', ' '))}
                        ${b.payment_reference ? '&bull; Ref: <code>' + esc(b.payment_reference) + '</code>' : ''}
                    </div>` : ''}
                </div>
            </div>
            <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                ${b.status === 'pending' ? `
                    <button class="btn btn-sm btn-success" onclick="updateBooking(${b.id}, 'approved')"><i class="bx bx-check"></i> Approve</button>
                    <button class="btn btn-sm btn-danger" onclick="updateBooking(${b.id}, 'rejected')"><i class="bx bx-x"></i> Reject</button>
                ` : ''}
                ${hasPayment && b.payment_status !== 'completed' && b.payment_status !== 'failed' ? `
                    <button class="btn btn-sm btn-success" onclick="openConfirmPayment(${b.id})"><i class="bx bx-check-circle"></i> Confirm Payment</button>
                    <button class="btn btn-sm btn-danger" onclick="rejectPayment(${b.id})"><i class="bx bx-x-circle"></i> Reject Payment</button>
                ` : ''}
                ${hasPayment && b.payment_status === 'completed' ? `
                    <span class="badge badge-success" style="padding:6px 12px;font-size:0.8rem;"><i class="bx bx-check-circle"></i> Payment Confirmed</span>
                ` : ''}
            </div>
            <div style="font-size:0.72rem;color:var(--text-muted);margin-top:8px;">Submitted: ${new Date(b.created_at).toLocaleString('en-GB')}</div>
        </div>`;
    }).join('');
}

async function updateBooking(id, status) {
    const form = new FormData();
    form.append('action', 'update_status');
    form.append('id', id);
    form.append('status', status);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/bookings.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast(`Booking ${status}!`, 'success');
        loadBookings();
    }
}

function openConfirmPayment(id) {
    document.getElementById('confirmPaymentBookingId').value = id;
    document.querySelector('input[name="payment_reference"]').value = '';
    openModal('confirmPaymentModal');
}

async function submitConfirmPayment(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'confirm_payment');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/bookings.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Payment confirmed!', 'success');
        closeModal('confirmPaymentModal');
        loadBookings();
    } else {
        showToast(data.error || 'Error confirming payment', 'error');
    }
}

async function rejectPayment(id) {
    if (!confirm('Reject this payment?')) return;
    const form = new FormData();
    form.append('action', 'reject_payment');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/bookings.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Payment rejected.', 'success');
        loadBookings();
    }
}

function fmtCcy(n) { return 'GH\u20B5 ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

loadBookings();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
