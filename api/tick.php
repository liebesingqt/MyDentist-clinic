<?php
/**
 * Background "tick": sends the reminder emails that are due (24, 12 and 6 hours before an
 * appointment) and makes the daily backup. Every page calls this quietly (includes/footer.php),
 * so reminders go out automatically on hosting that has no scheduled tasks.
 * It shows nothing and does its work at most once every 5 minutes, so calling it is harmless.
 */
define('SKIP_INSTALL_CHECK', true);
require __DIR__ . '/../includes/bootstrap.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();   // do not hold up the visitor's other pages
}
http_response_code(204);
header('Cache-Control: no-store');
if (is_installed()) {
    ignore_user_abort(true);
    maybe_run_scheduled_tasks();
}
