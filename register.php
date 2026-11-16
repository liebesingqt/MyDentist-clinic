<?php
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) {
    redirect(home_for($u));
}

$f = ['full_name' => '', 'phone' => '', 'email' => '', 'username' => ''];
$errors = [];
// The email address is verified with a 6-digit code whenever real emails are switched on
$verifyOn = (bool) mail_cfg('enabled');
$pending = verify_pending('register');
$codeError = null;

/** Checks that still matter at the moment the account is created (someone else may have taken the name). */
function register_taken(array $d): ?string
{
    if (db_val('SELECT COUNT(*) FROM users WHERE email = ?', [$d['email']])) return t('An account with this email already exists.');
    if (db_val('SELECT COUNT(*) FROM users WHERE username = ?', [$d['username']])) return t('This username is already taken.');
    return null;
}

function register_create(array $d): void
{
    db_insert('users', [
        'role' => 'patient', 'full_name' => $d['full_name'], 'username' => $d['username'],
        'email' => $d['email'], 'phone' => $d['phone'],
        'password_hash' => $d['password_hash'],   // bcrypt; never stored in plain text
        'is_active' => 1, 'created_at' => now_str(),
    ]);
    // Privacy: a new account starts with NO appointments. Earlier Guest Mode bookings are
    // never attached by email (an email address alone does not prove who owns a booking).
    // Not signed in automatically: the new patient logs in with their new username and password
    flash('success', te('Your account has been created, {name}! Please log in with your username and password.', ['name' => $d['full_name']]));
    redirect('login.php?show=1');
}

if (is_post() && in_array(input('do'), ['verify', 'resend', 'back'], true)) {
    csrf_check();
    if (input('do') === 'back') {
        if ($pending) {
            $_SESSION['register_back'] = array_intersect_key($pending['payload'], $f);   // refill the form (never the password)
        }
        verify_clear('register');
        redirect('register.php');
    }
    if (!$pending) {
        flash('danger', te('The verification code has expired. Please start again.'));
        redirect('register.php');
    }
    if (input('do') === 'resend') {
        if ($wait = verify_resend_wait('register')) {
            flash('warning', te('Please wait {n} seconds before requesting another code.', ['n' => $wait]));
        } elseif (verify_start('register', ['id' => 0, 'email' => $pending['payload']['email'], 'full_name' => $pending['payload']['full_name']], $pending['payload'])) {
            flash('success', te('A new verification code was sent to {email}.', ['email' => $pending['payload']['email']]));
        } else {
            flash('danger', te('The verification email could not be sent. Please try again later.'));
        }
        redirect('register.php');
    }
    $res = verify_check('register', input('code'));
    if ($res === 'ok') {
        $data = $pending['payload'];
        verify_clear('register');
        if ($taken = register_taken($data)) {
            flash('danger', e($taken));
            redirect('register.php');
        }
        register_create($data);
    } elseif ($res === 'locked') {
        flash('danger', te('Too many incorrect codes. Please start again.'));
        redirect('register.php');
    } elseif ($res === 'none') {
        flash('danger', te('The verification code has expired. Please start again.'));
        redirect('register.php');
    }
    $codeError = t('That code is not correct. Please check the email and try again.');
    $pending = verify_pending('register');
} elseif (is_post()) {
    csrf_check();
    foreach ($f as $k => $_) {
        $f[$k] = input($k);
    }
    $f['email'] = strtolower($f['email']);
    $pass = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password_confirm'] ?? '');

    if (mb_strlen($f['full_name']) < 3) $errors['full_name'] = t('Please enter your full name.');
    if (!valid_phone($f['phone'])) $errors['phone'] = t('Please enter a valid contact number.');
    if (!valid_email($f['email'])) $errors['email'] = t('Please enter a valid email address.');
    elseif (db_val('SELECT COUNT(*) FROM users WHERE email = ?', [$f['email']])) $errors['email'] = t('An account with this email already exists.');
    if (!preg_match('/^[A-Za-z0-9._]{4,30}$/', $f['username'])) $errors['username'] = t('Use 4–30 letters, numbers, dots or underscores.');
    elseif (db_val('SELECT COUNT(*) FROM users WHERE username = ?', [$f['username']])) $errors['username'] = t('This username is already taken.');
    if ($p = password_problem($pass)) $errors['password'] = $p;
    if ($pass !== $pass2) $errors['password_confirm'] = t('Passwords do not match.');
    if (empty($_POST['privacy'])) $errors['privacy'] = t('Please agree to the data privacy notice.');

    if (!$errors) {
        $data = $f + ['password_hash' => password_hash($pass, PASSWORD_DEFAULT)];
        if (!$verifyOn) {
            register_create($data);   // email sending is off: create the account straight away
        }
        if (login_throttled()) {
            $errors['email'] = t('Too many attempts. Please wait 10 minutes and try again.');
        } else {
            login_failed();   // counts requests so the form cannot be used to flood an inbox
            // The account is created only after the code is confirmed. Until then the details wait
            // in this visitor's session (the password only as a hash).
            if (verify_start('register', ['id' => 0, 'email' => $f['email'], 'full_name' => $f['full_name']], $data)) {
                redirect('register.php');
            }
            verify_clear('register');
            $errors['email'] = t('The verification email could not be sent. Please check the address and try again.');
        }
    }
} elseif (!empty($_SESSION['register_back'])) {
    $f = array_merge($f, $_SESSION['register_back']);
    unset($_SESSION['register_back']);
}

function field_error(array $errors, string $k): string
{
    return isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
}

$title = t('Create Account');
$layout = 'auth';
include __DIR__ . '/includes/header.php';
?>
<?php if ($pending): ?>
<h1><?= te('Verify Your Email') ?></h1>
<p class="sub"><?= te('We sent a 6-digit verification code to {email}.', ['email' => $pending['payload']['email']]) ?></p>
<?= flash_render() ?>
<?php if ($codeError): ?><div class="alert alert-danger small"><?= e($codeError) ?></div><?php endif; ?>
<form method="post" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="do" value="verify">
  <div class="mb-3">
    <label class="form-label" for="vcode"><?= te('Verification code') ?></label>
    <input class="form-control verify-code mx-auto" id="vcode" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" required autofocus autocomplete="one-time-code">
  </div>
  <button class="btn btn-primary w-100 py-2"><i class="bi bi-check2"></i> <?= te('Verify and Create Account') ?></button>
</form>
<p class="small text-muted mt-3 mb-2"><?= te('The code is valid for {n} minutes.', ['n' => VERIFY_MINUTES]) ?> <?= te("Can't find our email? Check your Spam or Promotions folder and mark it as 'Not spam'.") ?></p>
<div class="d-flex justify-content-center gap-2">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="resend"><button class="btn btn-sm btn-light border"><i class="bi bi-arrow-repeat"></i> <?= te('Send a new code') ?></button></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="back"><button class="btn btn-sm btn-light border"><i class="bi bi-pencil"></i> <?= te('Change my details') ?></button></form>
</div>
<?php include __DIR__ . '/includes/footer.php'; return; endif; ?>
<h1><?= te('Create Your Account') ?></h1>
<p class="sub"><?= te('Join us and start booking appointments online') ?></p>
<?= flash_render() ?>
<form method="post" class="needs-validation" novalidate data-register-form>
  <?= csrf_field() ?>
  <div class="mb-3">
    <label class="form-label" for="full_name"><?= te('Full name') ?></label>
    <div class="input-icon"><i class="bi bi-person"></i><input class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>" id="full_name" name="full_name" value="<?= e($f['full_name']) ?>" placeholder="e.g. Maria Santos" required minlength="3" autocomplete="name"></div>
    <?= field_error($errors, 'full_name') ?>
  </div>
  <div class="mb-3">
    <label class="form-label" for="phone"><?= te('Contact number') ?></label>
    <div class="input-icon"><i class="bi bi-telephone"></i><input class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" id="phone" name="phone" value="<?= e($f['phone']) ?>" placeholder="+63 9XX XXX XXXX" required autocomplete="tel"></div>
    <?= field_error($errors, 'phone') ?>
  </div>
  <div class="mb-3">
    <label class="form-label" for="email"><?= te('Email address') ?></label>
    <div class="input-icon"><i class="bi bi-envelope"></i><input class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" id="email" type="email" name="email" readonly data-noautofill autocomplete="off" value="<?= e($f['email']) ?>" placeholder="you@example.com" required autocomplete="email"></div>
    <?= field_error($errors, 'email') ?>
  </div>
  <div class="mb-3">
    <label class="form-label" for="username"><?= te('Username') ?></label>
    <div class="input-icon"><i class="bi bi-at"></i><input class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>" id="username" name="username" readonly data-noautofill autocomplete="off" value="<?= e($f['username']) ?>" placeholder="e.g. maria_santos" required pattern="[A-Za-z0-9._]{4,30}" autocomplete="username"></div>
    <?= field_error($errors, 'username') ?>
  </div>
  <div class="mb-2">
    <label class="form-label" for="password"><?= te('Password') ?></label>
    <div class="input-icon"><i class="bi bi-lock"></i><input class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" id="password" type="password" name="password" required data-password readonly data-noautofill autocomplete="new-password"><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div>
    <?= password_rules_html() ?>
    <?= field_error($errors, 'password') ?>
  </div>
  <div class="mb-3">
    <label class="form-label" for="password_confirm"><?= te('Confirm Password') ?></label>
    <div class="input-icon"><i class="bi bi-lock-fill"></i><input class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>" id="password_confirm" type="password" name="password_confirm" required data-password-confirm readonly data-noautofill autocomplete="new-password"><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div>
    <div class="pw-match small mt-1" data-pw-match data-ok="<?= te('Passwords match.') ?>" data-bad="<?= te('Passwords do not match.') ?>" aria-live="polite"></div>
    <?= field_error($errors, 'password_confirm') ?>
  </div>
  <div class="form-check mb-3 small">
    <input class="form-check-input <?= isset($errors['privacy']) ? 'is-invalid' : '' ?>" type="checkbox" name="privacy" value="1" id="privacy" required <?= !empty($_POST['privacy']) ? 'checked' : '' ?>>
    <label class="form-check-label text-muted" for="privacy"><?= te('I agree that MyDentist Dental Clinic may store my personal information for appointment purposes, in line with the Data Privacy Act of 2012.') ?></label>
    <?= field_error($errors, 'privacy') ?>
  </div>
  <button class="btn btn-primary w-100 py-2"><?= te('Create Account') ?></button>
</form>
<p class="text-center small mt-3 mb-0"><?= te('Already have an account?') ?> <a href="<?= url('login.php?show=1') ?>" class="fw-semibold"><?= te('Log in') ?></a></p>
<?php include __DIR__ . '/includes/footer.php'; ?>
