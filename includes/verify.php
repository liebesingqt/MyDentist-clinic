<?php
/**
 * Email verification codes (6 digits) for changing or resetting a password.
 *
 * The code is emailed to the address on the account, is valid for 10 minutes, allows
 * 5 attempts, and only its hash is kept (in the visitor's session), never the code itself.
 */

const VERIFY_MINUTES = 10;
const VERIFY_MAX_ATTEMPTS = 5;
const VERIFY_RESEND_SECONDS = 60;

/** j***@gmail.com – enough to recognise the address without showing it in full. */
function mask_email(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($name, 0, 1) . str_repeat('*', max(3, mb_strlen($name) - 1)) . '@' . $domain;
}

function verify_pending(string $purpose): ?array
{
    $v = $_SESSION['verify'][$purpose] ?? null;
    if (!$v) {
        return null;
    }
    if ($v['expires'] < time()) {
        unset($_SESSION['verify'][$purpose]);
        return null;
    }
    return $v;
}

function verify_clear(string $purpose): void
{
    unset($_SESSION['verify'][$purpose]);
}

/** Creates a new code and emails it. Returns true when the email was handed over for sending. */
function verify_start(string $purpose, array $user, array $payload = []): bool
{
    $code = (string) random_int(100000, 999999);
    $_SESSION['verify'][$purpose] = [
        'user_id'  => (int) $user['id'],
        'email'    => $user['email'],
        'hash'     => password_hash($code, PASSWORD_DEFAULT),
        'expires'  => time() + VERIFY_MINUTES * 60,
        'attempts' => 0,
        'sent_at'  => time(),
        'payload'  => $payload,
    ];
    $first = e(explode(' ', trim($user['full_name']))[0]);
    $what = $purpose === 'reset' ? 'reset your password' : 'change your password';
    $intro = $purpose === 'register'
        ? "<p>Hi $first,</p><p>Thank you for registering with " . e(APP_NAME) . '. To <strong>verify your email address</strong> and finish creating your account, enter this code:</p>'
        : "<p>Hi $first,</p><p>We received a request to <strong>$what</strong> for your " . e(APP_NAME) . ' account. Enter this code to continue:</p>';
    $html = email_layout(
        'Your verification code',
        $intro,
        '<div style="text-align:center;margin:8px 0 18px"><span data-secret="1" style="display:inline-block;font-family:monospace;font-size:32px;font-weight:bold;letter-spacing:8px;padding:12px 22px;border:1px solid #d3e5ee;border-radius:10px;background:#f2f8fb;color:#1e3a4c">' . $code . '</span></div>'
        . '<p style="font-size:14px;color:#3b5566">This code is valid for ' . VERIFY_MINUTES . ' minutes. Do not share it with anyone; clinic staff will never ask for it.</p>'
        . '<p style="font-size:14px;color:#3b5566">' . ($purpose === 'register' ? 'If you did not try to create an account, you can ignore this email. No account will be created.' : 'If you did not make this request, you can ignore this email. Your password stays the same.') . '</p>'
    );
    return send_mail($user['email'], $user['full_name'], 'Your ' . APP_NAME . ' verification code', $html);
}

/** 'ok' | 'wrong' | 'locked' | 'none' (no code waiting, or it expired) */
function verify_check(string $purpose, string $code): string
{
    $v = verify_pending($purpose);
    if (!$v) {
        return 'none';
    }
    if ($v['attempts'] >= VERIFY_MAX_ATTEMPTS) {
        verify_clear($purpose);
        return 'locked';
    }
    $code = preg_replace('/\D+/', '', $code);
    if (strlen($code) === 6 && password_verify($code, $v['hash'])) {
        return 'ok';
    }
    $_SESSION['verify'][$purpose]['attempts']++;
    if ($_SESSION['verify'][$purpose]['attempts'] >= VERIFY_MAX_ATTEMPTS) {
        verify_clear($purpose);
        return 'locked';
    }
    return 'wrong';
}

/** Seconds the visitor still has to wait before another code can be sent (0 = can resend). */
function verify_resend_wait(string $purpose): int
{
    $v = verify_pending($purpose);
    return $v ? max(0, VERIFY_RESEND_SECONDS - (time() - $v['sent_at'])) : 0;
}
