    </main>
</div>

<!-- CSRF Token for AJAX -->
<meta name="csrf-token" content="<?= generateCSRFToken() ?>">

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Centered Confirm Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <h3 id="confirmModalTitle">Please Confirm</h3>
            <button class="modal-close" onclick="closeConfirmModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p id="confirmModalMessage" style="color:var(--text-secondary);font-size:0.92rem;line-height:1.5;"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeConfirmModal()">Cancel</button>
            <button class="btn btn-danger" id="confirmModalOk">Yes, Continue</button>
        </div>
    </div>
</div>

<script src="<?= SITE_URL ?>/js/app.js?v=18"></script>
<script src="<?= SITE_URL ?>/js/email-validator.js?v=1"></script>
<?php if (isset($extraScripts)): ?>
    <?= $extraScripts ?>
<?php endif; ?>
</body>
</html>
