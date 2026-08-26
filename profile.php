<?php
// =====================================================
// Profile Page
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/config/database.php';
$pageTitle = 'My Profile';
requireLogin();

$db = getDB();
$user = currentUser();

// Get tenancy info for tenants
$tenancy = null;
if ($user['role'] === 'tenant') {
    $stmt = $db->prepare("SELECT t.*, r.room_number, r.room_type FROM tenancies t JOIN rooms r ON t.room_id = r.id WHERE t.tenant_id = ? AND t.status = 'active'");
    $stmt->execute([$user['id']]);
    $tenancy = $stmt->fetch();
}

include __DIR__ . '/includes/header.php';
?>

<?php if (!empty($user['must_change_password'])): ?>
<div class="alert alert-warning" style="margin-bottom:16px;">
    <strong>Action required:</strong> You are using a temporary password. Please set a new password below before continuing.
</div>
<?php endif; ?>

<div class="profile-header">
    <div class="profile-pic-wrapper">
        <img src="<?= SITE_URL ?>/uploads/profiles/<?= sanitize($user['profile_picture']) ?>" alt="Profile" id="profilePicPreview" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($user['full_name']) ?>&background=47433E&color=fff&size=100'">
    </div>
    <div>
        <h3 style="font-size:1.2rem;color:#fff;"><?= sanitize($user['full_name']) ?></h3>
        <p style="color:rgba(255,255,255,0.7);font-size:0.88rem;text-transform:capitalize;"><?= $user['role'] ?> &bull; <?= sanitize($user['username']) ?></p>
    </div>
</div>

<div class="grid-2">
    <!-- Profile Form -->
    <div class="card">
        <div class="card-header"><h3>Edit Profile</h3></div>
        <form id="profileForm" onsubmit="updateProfile(event)" enctype="multipart/form-data">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" class="form-control" value="<?= sanitize($user['full_name']) ?>" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Phone *</label>
                    <input type="tel" name="phone" class="form-control" value="<?= sanitize($user['phone']) ?>" pattern="[0-9]{10}" maxlength="10" oninput="this.value=this.value.replace(/[^0-9]/g,'')" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?= sanitize($user['email'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Date of Birth</label>
                <input type="date" name="date_of_birth" class="form-control" value="<?= sanitize($user['date_of_birth'] ?? '') ?>" max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
            </div>
            <div class="form-group">
                <label>Profile Picture</label>
                <input type="file" name="profile_picture" class="form-control" accept="image/*" onchange="previewPic(this)">
            </div>
            <div class="form-group">
                <label>Current Password (required to change your password)</label>
                <div class="password-field">
                    <input type="password" id="current_password" name="current_password" class="form-control" placeholder="Enter your current password" autocomplete="current-password">
                    <button type="button" class="password-toggle" tabindex="-1" onclick="togglePasswordVisibility('current_password')" aria-label="Show password"><i class="bx bx-show" id="current_passwordToggleIcon"></i></button>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>New Password</label>
                    <div class="password-field">
                        <input type="password" id="new_password" name="password" class="form-control" placeholder="Min. 8 characters" oninput="checkPasswordStrength(this.value, 'profStrength')" autocomplete="new-password">
                        <button type="button" class="password-toggle" tabindex="-1" onclick="togglePasswordVisibility('new_password')" aria-label="Show password"><i class="bx bx-show" id="new_passwordToggleIcon"></i></button>
                    </div>
                    <div id="profStrength" style="margin-top:6px;"></div>
                    <small style="color:var(--text-muted);font-size:0.75rem;">Must contain uppercase, lowercase, number, and special character</small>
                </div>
                <div class="form-group">
                    <label>Repeat New Password</label>
                    <div class="password-field">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Repeat new password" autocomplete="new-password">
                        <button type="button" class="password-toggle" tabindex="-1" onclick="togglePasswordVisibility('confirm_password')" aria-label="Show password"><i class="bx bx-show" id="confirm_passwordToggleIcon"></i></button>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>

    <!-- Account Info -->
    <div>
        <div class="card">
            <div class="card-header"><h3>Account Details</h3></div>
            <div style="display:flex;flex-direction:column;gap:10px;font-size:0.9rem;">
                <div><strong>Username:</strong> <?= sanitize($user['username']) ?></div>
                <div><strong>Role:</strong> <span class="badge badge-info" style="text-transform:capitalize;"><?= $user['role'] ?></span></div>
                <?php
                $age = null;
                if (!empty($user['date_of_birth'])) {
                    $dob = new DateTime($user['date_of_birth']);
                    $age = $dob->diff(new DateTime())->y;
                }
                ?>
                <?php if ($age !== null): ?>
                <div><strong>Age:</strong> <?= $age ?> years</div>
                <?php endif; ?>
                <div><strong>Member Since:</strong> <?= date('M j, Y', strtotime($user['created_at'])) ?></div>
            </div>
        </div>

        <?php if ($tenancy): ?>
        <div class="card">
            <div class="card-header"><h3>Current Tenancy</h3></div>
            <div style="display:flex;flex-direction:column;gap:10px;font-size:0.9rem;">
                <div><strong>Room:</strong> <?= sanitize($tenancy['room_number']) ?> (<?= ucfirst($tenancy['room_type']) ?>)</div>
                <div><strong>Monthly Rent:</strong> <?= formatCurrency($tenancy['monthly_rent']) ?></div>
                <div><strong>Start:</strong> <?= date('M j, Y', strtotime($tenancy['start_date'])) ?></div>
                <div><strong>End:</strong> <?= $tenancy['end_date'] ? date('M j, Y', strtotime($tenancy['end_date'])) : 'N/A' ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// On Chrome/Edge, mask text inputs (-webkit-text-security) instead of using
// type="password" so the browser's native eye icon never appears.
var pwMaskedInput = /Chrome|CriOS|Edg/i.test(navigator.userAgent);
(function() {
    if (!pwMaskedInput) return;
    var inputs = document.querySelectorAll('.password-field input[type="password"]');
    for (var i = 0; i < inputs.length; i++) {
        inputs[i].type = 'text';
        inputs[i].classList.add('masked');
    }
})();

function togglePasswordVisibility(inputId) {
    var p = document.getElementById(inputId);
    var icon = document.getElementById(inputId + 'ToggleIcon');
    if (!p || !icon) return;
    if (pwMaskedInput) {
        var hidden = p.classList.contains('masked');
        p.classList.toggle('masked', !hidden);
        icon.className = hidden ? 'bx bx-hide' : 'bx bx-show';
    } else {
        var show = p.type === 'password';
        p.type = show ? 'text' : 'password';
        icon.className = show ? 'bx bx-hide' : 'bx bx-show';
    }
    p.focus();
}

function previewPic(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => document.getElementById('profilePicPreview').src = e.target.result;
        reader.readAsDataURL(input.files[0]);
    }
}

async function updateProfile(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    const currentPass = form.get('current_password') || '';
    const newPass = form.get('password') || '';
    const confirmPass = form.get('confirm_password') || '';

    if (newPass || confirmPass) {
        if (!currentPass) {
            showToast('Enter your current password to change the password.', 'error');
            return;
        }
        if (!newPass) {
            showToast('Enter a new password.', 'error');
            return;
        }
        if (newPass !== confirmPass) {
            showToast('New password and repeat password do not match.', 'error');
            return;
        }
    }

    form.append('action', 'update_profile');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/users.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Profile updated!', 'success');
        setTimeout(() => location.reload(), 800);
    } else {
        showToast(data.error || 'Error updating profile', 'error');
    }
}

function checkPasswordStrength(password, targetId) {
    const el = document.getElementById(targetId);
    if (!el) return;
    let score = 0;
    if (password.length >= 8) score++;
    if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    const labels = ['Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];
    const colors = ['#EF4444', '#F97316', '#F59E0B', '#10B981', '#059669'];
    const widths = ['20%', '40%', '60%', '80%', '100%'];

    if (password.length === 0) {
        el.innerHTML = '';
        return;
    }

    el.innerHTML = `
        <div style="height:4px;border-radius:2px;background:var(--border);overflow:hidden;">
            <div style="height:100%;width:${widths[score]};background:${colors[score]};transition:all 0.3s;border-radius:2px;"></div>
        </div>
        <span style="font-size:0.72rem;color:${colors[score]};font-weight:600;">${labels[score]}</span>
    `;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
