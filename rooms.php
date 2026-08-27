<?php
// Lets admin/staff add, edit, and delete residences and their photos, and manage residence types.
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Residence';
requireRole(['admin', 'staff']);

$db = getDB();
$staffList = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','staff') AND is_active=1 ORDER BY full_name")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="filter-bar">
    <div class="search-box">
        <span class="search-icon"><i class='bx bx-search'></i></span>
        <input type="text" id="searchRooms" placeholder="Search residences..." oninput="filterRooms()">
    </div>
    <select id="filterType" onchange="filterRooms()">
        <option value="">All Types</option>
    </select>
    <select id="filterStatus" onchange="filterRooms()">
        <option value="">All Status</option>
        <option value="available">Available</option>
        <option value="occupied">Occupied</option>
        <option value="maintenance">Maintenance</option>
    </select>
    <button class="btn btn-primary" onclick="openModal('addRoomModal')">+ Add Residence</button>
    <?php if ($user && $user['role'] === 'admin'): ?>
    <button class="btn btn-outline" onclick="openModal('manageTypesModal')">+ Manage Types</button>
    <?php endif; ?>
</div>

<div class="room-grid" id="roomGrid"></div>

<!-- Add Room Modal -->
<div class="modal-overlay" id="addRoomModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h3>Add New Residence</h3>
            <button class="modal-close" onclick="closeModal('addRoomModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addRoomForm" onsubmit="submitRoom(event)" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Residence Images (multiple)</label>
                    <div class="image-upload-area" id="addImageArea" onclick="document.getElementById('addImageInput').click()">
                        <input type="file" name="image[]" id="addImageInput" accept="image/*" multiple style="display:none;" onchange="previewImages(this, 'addImagePreview')">
                        <div id="addImagePreview" class="image-upload-placeholder">
                            <span class="image-upload-icon"><i class='bx bx-image-add'></i></span>
                            <span>Click to upload residence images</span>
                            <small>JPG, PNG, WebP (max 5MB each)</small>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Residence Number *</label>
                        <input type="text" name="room_number" class="form-control" placeholder="e.g. A101" required>
                    </div>
                    <div class="form-group">
                        <label>Residence Type *</label>
                        <select name="room_type" class="form-control" required>
                            <option value="">Select type...</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Floor</label>
                        <input type="number" name="floor" class="form-control" value="1" min="1" max="10">
                    </div>
                </div>
                <div class="form-group">
                    <label id="addPriceLabel">Monthly Rental Price (GH&#8373;) *</label>
                    <input type="number" name="rental_price" class="form-control" step="0.01" min="0" required placeholder="e.g. 2500.00">
                </div>
                <div class="form-group">
                    <label>Amenities</label>
                    <input type="text" name="amenities" class="form-control" placeholder="WiFi, Private Bathroom, Kitchenette...">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Brief description..."></textarea>
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addRoomModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Residence</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Room Modal -->
<div class="modal-overlay" id="editRoomModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h3>Edit Residence</h3>
            <button class="modal-close" onclick="closeModal('editRoomModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editRoomForm" onsubmit="updateRoom(event)" enctype="multipart/form-data">
                <input type="hidden" name="id" id="editRoomId">
                <div class="form-group">
                    <label>Residence Name *</label>
                    <input type="text" name="room_number" id="editRoomName" class="form-control" placeholder="e.g. A101" required>
                </div>
                <div class="form-group">
                    <label>Residence Images</label>
                    <div id="editGallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px;margin-bottom:10px;"></div>
                    <div class="image-upload-area" id="editImageArea" onclick="document.getElementById('editImageInput').click()">
                        <input type="file" name="image[]" id="editImageInput" accept="image/*" multiple style="display:none;" onchange="previewImages(this, 'editImagePreview')">
                        <div id="editImagePreview" class="image-upload-placeholder">
                            <span class="image-upload-icon"><i class='bx bx-image-add'></i></span>
                            <span>Click to add more images</span>
                            <small>JPG, PNG, WebP (max 5MB each)</small>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Residence Type</label>
                        <select name="room_type" id="editRoomType" class="form-control">
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="editRoomStatus" class="form-control">
                            <option value="available">Available</option>
                            <option value="occupied">Occupied</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Floor</label>
                        <input type="number" name="floor" id="editRoomFloor" class="form-control" min="1">
                    </div>
                </div>
                <div class="form-group">
                    <label id="editPriceLabel">Monthly Rental Price (GH&#8373;)</label>
                    <input type="number" name="rental_price" id="editRoomPrice" class="form-control" step="0.01">
                </div>
                <div class="form-group">
                    <label>Amenities</label>
                    <input type="text" name="amenities" id="editRoomAmenities" class="form-control">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="editRoomDesc" class="form-control" rows="2"></textarea>
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteRoom(document.getElementById('editRoomId').value)">Delete</button>
                    <div style="flex:1"></div>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editRoomModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Residence Types Modal (Admin) -->
<div class="modal-overlay" id="manageTypesModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h3>Manage Residence Types</h3>
            <button class="modal-close" onclick="closeModal('manageTypesModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group" style="margin-bottom:4px;">
                <label>Add New Type</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input type="text" id="newTypeName" class="form-control" placeholder="e.g. Suite" style="flex:1;min-width:140px;">
                    <select id="newTypePeriod" class="form-control" style="width:130px;">
                        <option value="monthly">Monthly</option>
                        <option value="daily">Daily</option>
                    </select>
                    <button class="btn btn-primary" id="saveTypeBtn" onclick="saveType()">Add</button>
                </div>
                <button type="button" class="btn btn-sm btn-outline" id="cancelEditTypeBtn" style="display:none;margin-top:8px;" onclick="cancelEditType()">&larr; Cancel Edit</button>
                <small style="display:block;color:var(--text-muted);font-size:0.72rem;margin-top:6px;">Choose how this residence type is charged — monthly or daily.</small>
            </div>
            <div id="typeList" style="margin-top:16px;"></div>
        </div>
    </div>
</div>

<script>
let allRooms = [];
let roomTypes = [];

const ROOM_IMG = '<?= SITE_URL ?>/uploads/rooms/';

async function loadTypes() {
    try {
        const res = await fetch('api/rooms.php?types=1');
        roomTypes = JSON.parse(await res.text());
        populateTypeSelects();
    } catch (err) {
        console.error('Error loading residence types:', err);
    }
}

function typePeriodLabel(period) {
    return (period === 'daily') ? 'Daily' : 'Monthly';
}

function typeOptions(selected) {
    return roomTypes.map(t => {
        const label = t.name.charAt(0).toUpperCase() + t.name.slice(1);
        const period = typePeriodLabel(t.charge_period);
        return `<option value="${esc(t.name)}" ${t.name === selected ? 'selected' : ''}>${esc(label)} (${period})</option>`;
    }).join('');
}

function populateTypeSelects() {
    const addSel = document.querySelector('#addRoomForm select[name="room_type"]');
    if (addSel) addSel.innerHTML = '<option value="">Select type...</option>' + typeOptions();
    const editSel = document.getElementById('editRoomType');
    if (editSel) editSel.innerHTML = typeOptions();
    const filterSel = document.getElementById('filterType');
    if (filterSel) filterSel.innerHTML = '<option value="">All Types</option>' + typeOptions();
    wirePriceLabels();
    renderTypeList();
}

// Keep the "Rental Price" label in sync with the selected type's charge period
function wirePriceLabels() {
    const addSel = document.querySelector('#addRoomForm select[name="room_type"]');
    const editSel = document.getElementById('editRoomType');
    const addLbl = document.getElementById('addPriceLabel');
    const editLbl = document.getElementById('editPriceLabel');
    const apply = (sel, lbl, suffix) => {
        if (!sel || !lbl) return;
        const t = roomTypes.find(x => x.name === sel.value);
        lbl.textContent = (t && t.charge_period === 'daily' ? 'Daily' : 'Monthly') + ' Rental Price (GH₵)' + suffix;
    };
    if (addSel) addSel.addEventListener('change', () => apply(addSel, addLbl, ' *'));
    if (editSel) editSel.addEventListener('change', () => apply(editSel, editLbl, ''));
    apply(addSel, addLbl, ' *');
    apply(editSel, editLbl, '');
}

function renderTypeList() {
    const list = document.getElementById('typeList');
    if (!list) return;
    if (!roomTypes.length) {
        list.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:16px;">No types yet. Add one above.</p>';
        return;
    }
    list.innerHTML = roomTypes.map(t => {
        const period = typePeriodLabel(t.charge_period);
        const periodStyle = t.charge_period === 'daily'
            ? 'background:rgba(231,76,60,0.12);color:#e74c3c;'
            : 'background:var(--accent-light);color:var(--accent);';
        return `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;background:var(--surface);">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-weight:700;color:var(--text);">${esc(t.name.charAt(0).toUpperCase() + t.name.slice(1))}</span>
                <span style="font-size:0.7rem;padding:2px 9px;border-radius:999px;font-weight:600;${periodStyle}">${period} Charge</span>
            </div>
            <div style="display:flex;gap:6px;">
                <button class="btn btn-sm btn-outline" onclick="editType(${t.id})">Edit</button>
                <button class="btn btn-sm btn-danger" onclick="deleteType(${t.id})">Delete</button>
            </div>
        </div>`;
    }).join('');
}

let editingTypeId = null;

async function saveType() {
    const nameInput = document.getElementById('newTypeName');
    const periodInput = document.getElementById('newTypePeriod');
    const name = nameInput.value.trim();
    if (!name) { showToast('Enter a type name.', 'warning'); return; }
    const form = new FormData();
    form.append('action', editingTypeId ? 'edit_type' : 'add_type');
    if (editingTypeId) form.append('id', editingTypeId);
    form.append('name', name);
    form.append('charge_period', periodInput.value);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/rooms.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast(editingTypeId ? 'Type updated.' : 'Type added.', 'success');
        cancelEditType();
        loadTypes();
        loadRooms();
    } else {
        showToast(data.error || 'Error saving type', 'error');
    }
}

function editType(id) {
    const t = roomTypes.find(x => x.id == id);
    if (!t) return;
    editingTypeId = id;
    document.getElementById('newTypeName').value = t.name;
    document.getElementById('newTypePeriod').value = t.charge_period;
    document.getElementById('saveTypeBtn').textContent = 'Save';
    document.getElementById('cancelEditTypeBtn').style.display = '';
    document.getElementById('newTypeName').focus();
}

function cancelEditType() {
    editingTypeId = null;
    document.getElementById('newTypeName').value = '';
    document.getElementById('newTypePeriod').value = 'monthly';
    document.getElementById('saveTypeBtn').textContent = 'Add';
    document.getElementById('cancelEditTypeBtn').style.display = 'none';
}

async function deleteType(id) {
    const t = roomTypes.find(x => x.id == id);
    if (!t) return;
    if (!confirm(`Delete type "${t.name.charAt(0).toUpperCase() + t.name.slice(1)}"?`)) return;
    const form = new FormData();
    form.append('action', 'delete_type');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/rooms.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Type deleted.', 'success');
        loadTypes();
        loadRooms();
    } else {
        showToast(data.error || 'Error deleting type', 'error');
    }
}

async function loadRooms() {
    try {
        const res = await fetch('api/rooms.php');
        const text = await res.text();
        allRooms = JSON.parse(text);
        filterRooms();
    } catch (err) {
        console.error('Error loading rooms:', err);
        document.getElementById('roomGrid').innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><div class="empty-icon"><i class="bx bx-error" style="font-size:3rem;"></i></div><h4>Failed to load residences</h4><p>Please refresh the page or check your connection.</p></div>';
    }
}

function filterRooms() {
    const search = document.getElementById('searchRooms').value.toLowerCase();
    const type = document.getElementById('filterType').value;
    const status = document.getElementById('filterStatus').value;

    let filtered = allRooms.filter(r => {
        if (search && !r.room_number.toLowerCase().includes(search) && !(r.description || '').toLowerCase().includes(search)) return false;
        if (type && r.room_type !== type) return false;
        if (status && r.status !== status) return false;
        return true;
    });

    renderRooms(filtered);
}

function renderRooms(rooms) {
    const grid = document.getElementById('roomGrid');
    if (rooms.length === 0) {
        grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><div class="empty-icon"><i class="bx bx-door-open" style="font-size:3rem;"></i></div><h4>No residences found</h4><p>Adjust filters or add a new residence.</p></div>';
        return;
    }

    grid.innerHTML = rooms.map(r => {
        const hasImg = r.image && r.image !== 'null';
        const imgSrc = hasImg ? ROOM_IMG + esc(r.image) : '';
        return `
        <div class="room-card" onclick="editRoom(${r.id})">
            <div class="room-card-image" ${hasImg ? `style="background:url('${imgSrc}') center/cover no-repeat;"` : ''}>
                ${hasImg ? '' : '<i class="bx bx-home" style="font-size:3rem;"></i>'}
                <span class="room-status-badge badge badge-${r.status === 'available' ? 'success' : (r.status === 'occupied' ? 'warning' : 'danger')}">${r.status.charAt(0).toUpperCase() + r.status.slice(1)}</span>
            </div>
            <div class="room-card-body">
                <h4>Residence ${esc(r.room_number)}</h4>
                <div class="room-meta">${esc(r.room_type.charAt(0).toUpperCase() + r.room_type.slice(1))} &bull; Floor ${r.floor}</div>
                <div class="room-price">GH&#8373; ${parseFloat(r.rental_price).toLocaleString('en',{minimumFractionDigits:2})} <small>/${r.charge_period === 'daily' ? 'day' : 'month'}</small></div>
            </div>
            <div class="room-card-footer">
                <button class="btn btn-sm btn-outline" onclick="event.stopPropagation();editRoom(${r.id})">Edit</button>
            </div>
        </div>`;
    }).join('');
}

function editRoom(id) {
    const r = allRooms.find(x => x.id == id);
    if (!r) return;
    document.getElementById('editRoomId').value = r.id;
    document.getElementById('editRoomName').value = r.room_number;
    document.getElementById('editRoomType').value = r.room_type;
    document.getElementById('editRoomStatus').value = r.status;
    document.getElementById('editRoomFloor').value = r.floor;

    // Update price label to match the room type's charge period
    document.getElementById('editRoomType').dispatchEvent(new Event('change'));
    document.getElementById('editRoomPrice').value = r.rental_price;
    document.getElementById('editRoomAmenities').value = r.amenities || '';
    document.getElementById('editRoomDesc').value = r.description || '';

    renderEditGallery(r.images || [], r.id);

    // Reset file input preview
    document.getElementById('editImagePreview').innerHTML = '<span class="image-upload-icon"><i class="bx bx-image-add"></i></span><span>Click to add more images</span><small>JPG, PNG, WebP (max 5MB each)</small>';
    document.getElementById('editImageInput').value = '';

    openModal('editRoomModal');
}

function renderEditGallery(images, roomId) {
    const gal = document.getElementById('editGallery');
    if (!images || !images.length) {
        gal.innerHTML = '<p class="text-muted" style="font-size:0.85rem;grid-column:1/-1;">No images uploaded yet.</p>';
        return;
    }
    gal.innerHTML = images.map(im => `
        <div style="position:relative;border-radius:6px;overflow:hidden;border:1px solid var(--border);">
            <img src="${ROOM_IMG + esc(im.image)}" alt="Residence image" style="width:100%;height:80px;object-fit:cover;display:block;">
            <button type="button" title="Remove image" onclick="deleteRoomImage(${im.id}, ${roomId})" style="position:absolute;top:4px;right:4px;background:rgba(0,0,0,0.65);color:#fff;border:none;border-radius:50%;width:22px;height:22px;line-height:1;cursor:pointer;font-size:14px;">&times;</button>
        </div>`).join('');
}

async function deleteRoomImage(imgId, roomId) {
    if (!confirm('Remove this image?')) return;
    const form = new FormData();
    form.append('action', 'delete_image');
    form.append('id', imgId);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/rooms.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Image removed.', 'success');
        await loadRooms();
        const updated = allRooms.find(x => x.id == roomId);
        if (updated) renderEditGallery(updated.images || [], roomId);
    } else {
        showToast(data.error || 'Error removing image', 'error');
    }
}

async function submitRoom(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'add');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/rooms.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Residence added successfully!', 'success');
        closeModal('addRoomModal');
        e.target.reset();
        resetImagePreview('addImagePreview', 'addImageArea');
        loadRooms();
    } else {
        showToast(data.error || 'Error adding room', 'error');
    }
}

async function updateRoom(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'update');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/rooms.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Residence updated!', 'success');
        closeModal('editRoomModal');
        loadRooms();
    } else {
        showToast(data.error || 'Error updating room', 'error');
    }
}

async function deleteRoom(id) {
    if (!confirm('Delete this residence? This cannot be undone.')) return;
    const form = new FormData();
    form.append('action', 'delete');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/rooms.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Residence deleted.', 'success');
        closeModal('editRoomModal');
        loadRooms();
    }
}

function previewImages(input, previewId) {
    if (!input.files || !input.files.length) return;
    const files = [...input.files];
    const slots = new Array(files.length);
    let pending = files.length;
    files.forEach((file, i) => {
        if (file.size > 5 * 1024 * 1024) {
            showToast('Image "' + file.name + '" must be under 5MB.', 'warning');
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            slots[i] = `<img src="${e.target.result}" alt="Preview ${i + 1}" style="width:100%;height:90px;object-fit:cover;border-radius:6px;">`;
            if (!--pending) {
                const preview = document.getElementById(previewId);
                preview.innerHTML = slots.join('');
                preview.style.padding = '0';
            }
        };
        reader.readAsDataURL(file);
    });
}

function resetImagePreview(previewId, areaId) {
    const preview = document.getElementById(previewId);
    preview.innerHTML = '<span class="image-upload-icon"><i class="bx bx-image-add"></i></span><span>Click to upload residence images</span><small>JPG, PNG, WebP (max 5MB each)</small>';
    preview.style.padding = '';
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
loadRooms();
loadTypes();

</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
