<?php $me = db_one('SELECT * FROM users WHERE id = ?', [$user['id']]); ?>
<?php if (!empty($settingsErrors)): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $settingsErrors)) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card-md mb-3" id="avatarForm">
  <div class="card-head"><h2><i class="bi bi-person-circle text-primary"></i> <?= te('Profile Picture') ?></h2></div>
  <div class="card-body-md d-flex flex-wrap align-items-center gap-4">
    <?= csrf_field() ?><input type="hidden" name="form" value="avatar"><input type="hidden" name="avatar_action" value="upload" id="avatarAction">
    <div class="text-center">
      <img id="avatarPreview" class="avatar-preview" alt="<?= te('Preview') ?>" hidden>
      <div id="avatarCurrent"><?= avatar_html($me, 'avatar-lg') ?></div>
    </div>
    <div class="flex-grow-1" style="min-width:240px">
      <div class="fw-bold mb-1"><?= e($me['full_name']) ?></div>
      <div class="small text-muted mb-2"><?= te('JPG, JPEG, PNG or WEBP, up to 2 MB.') ?></div>
      <input class="form-control form-control-sm mb-2" type="file" name="avatar" id="avatarInput" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-label="<?= te('Choose a profile picture') ?>">
      <div class="small text-danger mb-2" id="avatarError" hidden></div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-sm btn-primary" id="avatarSave" disabled><i class="bi bi-upload"></i> <?= empty($me['avatar']) ? te('Upload Profile Picture') : te('Change Profile Picture') ?></button>
        <?php if (!empty($me['avatar'])): ?>
          <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="<?= te('Remove your profile picture?') ?>" onclick="document.getElementById('avatarAction').value='remove'"><i class="bi bi-trash"></i> <?= te('Remove') ?></button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>
<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" class="card-md h-100">
      <div class="card-head"><h2><i class="bi bi-person-vcard text-primary"></i> <?= te('Profile') ?></h2></div>
      <div class="card-body-md">
        <?= csrf_field() ?><input type="hidden" name="form" value="profile">
        <div class="mb-2"><label class="form-label"><?= te('Username') ?></label><input class="form-control" value="<?= e($me['username']) ?>" disabled></div>
        <div class="mb-2"><label class="form-label" for="full_name"><?= te('Full name') ?></label><input class="form-control" id="full_name" name="full_name" value="<?= e($me['full_name']) ?>" required></div>
        <div class="mb-2"><label class="form-label" for="email"><?= te('Email address') ?></label><input class="form-control" type="email" id="email" name="email" value="<?= e($me['email']) ?>" required></div>
        <div class="mb-3"><label class="form-label" for="phone"><?= te('Contact number') ?></label><input class="form-control" id="phone" name="phone" value="<?= e($me['phone']) ?>"></div>
        <button class="btn btn-primary"><?= te('Save Profile') ?></button>
      </div>
    </form>
  </div>
  <div class="col-lg-6">
    <?php $pwPendingNow = verify_pending('pwchange'); if ($pwPendingNow && (int) $pwPendingNow['user_id'] === (int) $me['id']): ?>
    <div class="card-md h-100" id="change-password">
      <div class="card-head"><h2><i class="bi bi-envelope-check text-primary"></i> <?= te('Enter Verification Code') ?></h2></div>
      <div class="card-body-md">
        <p class="small"><?= te('We sent a 6-digit verification code to {email}. Enter it below to finish changing your password.', ['email' => mask_email($pwPendingNow['email'])]) ?></p>
        <form method="post" class="mb-3">
          <?= csrf_field() ?><input type="hidden" name="form" value="password_code">
          <label class="form-label" for="vcode"><?= te('Verification code') ?></label>
          <input class="form-control verify-code mb-3" id="vcode" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" required autofocus autocomplete="one-time-code">
          <button class="btn btn-primary"><i class="bi bi-check2"></i> <?= te('Confirm and Change Password') ?></button>
        </form>
        <p class="small text-muted mb-2"><?= te('The code is valid for {n} minutes.', ['n' => VERIFY_MINUTES]) ?> <?= te("Can't find our email? Check your Spam or Promotions folder and mark it as 'Not spam'.") ?></p>
        <div class="d-flex gap-2">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="form" value="password_resend"><button class="btn btn-sm btn-light border"><i class="bi bi-arrow-repeat"></i> <?= te('Send a new code') ?></button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="form" value="password_cancel"><button class="btn btn-sm btn-light border"><?= te('Cancel') ?></button></form>
        </div>
      </div>
    </div>
    <?php else: ?>
    <form method="post" class="card-md h-100 needs-validation" novalidate id="change-password">
      <div class="card-head"><h2><i class="bi bi-shield-lock text-primary"></i> <?= te('Change Password') ?></h2></div>
      <div class="card-body-md">
        <?= csrf_field() ?><input type="hidden" name="form" value="password">
        <div class="mb-2"><label class="form-label" for="cp"><?= te('Current password') ?></label><div class="input-icon"><i class="bi bi-key"></i><input class="form-control" type="password" id="cp" name="current_password" required readonly data-noautofill autocomplete="new-password"><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div></div>
        <div class="mb-2"><label class="form-label" for="np"><?= te('New password') ?></label><div class="input-icon"><i class="bi bi-lock"></i><input class="form-control" type="password" id="np" name="new_password" required readonly data-noautofill autocomplete="new-password" data-password><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div>
          <?= password_rules_html() ?></div>
        <div class="mb-3"><label class="form-label" for="np2"><?= te('Confirm new password') ?></label><div class="input-icon"><i class="bi bi-lock-fill"></i><input class="form-control" type="password" id="np2" name="confirm_password" required readonly data-noautofill autocomplete="new-password" data-password-confirm><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div>
          <div class="pw-match small mt-1" data-pw-match data-ok="<?= te('Passwords match.') ?>" data-bad="<?= te('Passwords do not match.') ?>" aria-live="polite"></div></div>
        <p class="small text-muted"><?= te('Passwords are stored as secure bcrypt hashes, never as plain text.') ?>
          <?php if (!empty($pwVerifyOn)): ?><br><i class="bi bi-envelope-check"></i> <?= te('To confirm it is really you, we will email a verification code to {email}.', ['email' => mask_email($me['email'])]) ?><?php endif; ?></p>
        <button class="btn btn-primary"><?= te('Update Password') ?></button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var input = document.getElementById('avatarInput'), prev = document.getElementById('avatarPreview'),
      cur = document.getElementById('avatarCurrent'), save = document.getElementById('avatarSave'), err = document.getElementById('avatarError');
  var MSG = <?= json_encode(['type' => t('Unsupported file type. Please upload a JPG, JPEG, PNG or WEBP image.'), 'size' => t('The image is too large. Maximum size is 2 MB.')], JSON_UNESCAPED_UNICODE) ?>;
  input.addEventListener('change', function () {
    var f = input.files[0]; err.hidden = true; save.disabled = true;
    if (!f) { prev.hidden = true; cur.hidden = false; return; }
    if (!/^image\/(jpeg|png|webp)$/.test(f.type) || !/\.(jpe?g|png|webp)$/i.test(f.name)) { err.textContent = MSG.type; err.hidden = false; input.value = ''; return; }
    if (f.size > 2 * 1024 * 1024) { err.textContent = MSG.size; err.hidden = false; input.value = ''; return; }
    var r = new FileReader();
    r.onload = function (e) { prev.src = e.target.result; prev.hidden = false; cur.hidden = true; save.disabled = false; };
    r.readAsDataURL(f);
  });
})();
</script>
