<?php
/**
 * Forgot password: a 6-digit code is emailed to the account's address; entering it
 * lets the owner set a new password. Works for patients, staff and administrators.
 */
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) {
    redirect(home_for($u));
}

$mailOn = (bool) mail_cfg('enabled');
$pending = verify_pending('reset');
$errors = [];

if (is_post()) {
    csrf_check();
    $do = input('do');
    if ($do === 'cancel') {
        verify_clear('reset');
        redirect('login.php?show=1');
    }
    if ($do === 'request' && $mailOn) {
        if (login_throttled()) {
            flash('danger', te('Too many attempts. Please wait 10 minutes and try again.'));
            redirect('forgot.php');
        }
        login_failed();   // counts requests, so this form cannot be used to flood someone with emails
        $login = input('login');
        $acct = db_one('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1', [$login, strtolower($login)]);
        if ($acct && valid_email((string) $acct['email'])) {
            verify_start('reset', $acct);
        } else {
            // Same screen whether or not the account exists, so accounts cannot be guessed here
            $_SESSION['verify']['reset'] = ['user_id' => 0, 'email' => '', 'hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
                'expires' => time() + VERIFY_MINUTES * 60, 'attempts' => 0, 'sent_at' => time(), 'payload' => []];
        }
        flash('info', te('If that account exists, we sent a 6-digit verification code to its email address.'));
        redirect('forgot.php');
    }
    if ($do === 'reset') {
        if (!$pending) {
            flash('danger', te('The verification code has expired. Please start again.'));
            redirect('forgot.php');
        }
        $new = (string) ($_POST['new_password'] ?? '');
        if ($p = password_problem($new)) {
            $errors[] = $p;
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            $errors[] = t('Passwords do not match.');
        } else {
            $res = verify_check('reset', input('code'));
            if ($res === 'ok' && $pending['user_id']) {
                q('UPDATE users SET password_hash = ? WHERE id = ? AND is_active = 1', [password_hash($new, PASSWORD_DEFAULT), $pending['user_id']]);
                verify_clear('reset');
                db_insert('audit_logs', ['user_id' => $pending['user_id'], 'action' => 'password_reset', 'details' => 'Password reset by the account owner with an email verification code', 'appointment_id' => null, 'ip_address' => client_ip(), 'created_at' => now_str()]);
                flash('success', te('Your password has been changed. Please log in with your new password.'));
                redirect('login.php?show=1');
            } elseif ($res === 'locked') {
                flash('danger', te('Too many incorrect codes. Please start again.'));
                redirect('forgot.php');
            } elseif ($res === 'none') {
                flash('danger', te('The verification code has expired. Please start again.'));
                redirect('forgot.php');
            } else {
                $errors[] = t('That code is not correct. Please check the email and try again.');
            }
        }
        $pending = verify_pending('reset');
    }
}

$title = t('Forgot Password');
$layout = 'auth';
include __DIR__ . '/includes/header.php';
?>
<h1><?= te('Forgot Password') ?></h1>
<?php if (!$mailOn): ?>
  <p class="sub"><?= te('Password reset by email is not available right now.') ?></p>
  <?= flash_render() ?>
  <div class="alert alert-info small"><?= te('Please contact the clinic to reset your password:') ?> <strong><?= e(CLINIC_PHONE) ?></strong></div>
  <a class="btn btn-primary w-100" href="<?= url('login.php?show=1') ?>"><?= te('Back to Login') ?></a>
<?php elseif (!$pending): ?>
  <p class="sub"><?= te('Enter your username or email and we will email you a verification code.') ?></p>
  <?= flash_render() ?>
  <form method="post" class="needs-validation" novalidate autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="request">
    <div class="mb-3">
      <label class="form-label" for="login"><?= te('Username or email') ?></label>
      <div class="input-icon"><i class="bi bi-person"></i>
        <input class="form-control" id="login" name="login" value="<?= e(mb_substr((string) ($_GET['login'] ?? ''), 0, 120)) ?>" required autofocus readonly data-noautofill autocomplete="off"></div>
    </div>
    <button class="btn btn-primary w-100 py-2"><i class="bi bi-envelope"></i> <?= te('Send Verification Code') ?></button>
  </form>
  <p class="text-center small mt-3 mb-0"><a href="<?= url('login.php?show=1') ?>" class="fw-semibold"><?= te('Back to Login') ?></a></p>
<?php else: ?>
  <p class="sub"><?= te('Enter the 6-digit code from the email, then choose a new password.') ?></p>
  <?= flash_render() ?>
  <?php if ($errors): ?><div class="alert alert-danger small"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
  <form method="post" class="needs-validation" novalidate autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <div class="mb-3">
      <label class="form-label" for="vcode"><?= te('Verification code') ?></label>
      <input class="form-control verify-code mx-auto" id="vcode" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" required autofocus autocomplete="one-time-code" value="<?= e(preg_replace('/\D+/', '', input('code'))) ?>">
    </div>
    <div class="mb-2"><label class="form-label" for="np"><?= te('New password') ?></label>
      <div class="input-icon"><i class="bi bi-lock"></i><input class="form-control" type="password" id="np" name="new_password" required readonly data-noautofill autocomplete="new-password" data-password><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div>
      <?= password_rules_html() ?></div>
    <div class="mb-3"><label class="form-label" for="np2"><?= te('Confirm new password') ?></label>
      <div class="input-icon"><i class="bi bi-lock-fill"></i><input class="form-control" type="password" id="np2" name="confirm_password" required readonly data-noautofill autocomplete="new-password" data-password-confirm><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div>
      <div class="pw-match small mt-1" data-pw-match data-ok="<?= te('Passwords match.') ?>" data-bad="<?= te('Passwords do not match.') ?>" aria-live="polite"></div></div>
    <button class="btn btn-primary w-100 py-2"><i class="bi bi-check2"></i> <?= te('Change Password') ?></button>
  </form>
  <p class="small text-muted mt-3 mb-2"><?= te('The code is valid for {n} minutes.', ['n' => VERIFY_MINUTES]) ?> <?= te("Can't find our email? Check your Spam or Promotions folder and mark it as 'Not spam'.") ?></p>
  <form method="post" class="text-center"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><button class="btn btn-link btn-sm"><?= te('Cancel and go back to Login') ?></button></form>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
