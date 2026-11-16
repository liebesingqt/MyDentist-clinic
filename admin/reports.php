<?php
/** Reports: daily, weekly, monthly or custom-range summaries; completed, cancellation and no-show reports. */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_staff();

$period = input('period', 'monthly');
$ref = input('date') ?: date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) $ref = date('Y-m-d');
$type = input('type', 'all');   // all | completed | cancelled | no_show
switch ($period) {
    case 'daily':
        $from = $ref; $to = $ref; $label = fmt_date($ref, 'l, F j, Y'); break;
    case 'weekly':
        $from = date('Y-m-d', strtotime('monday this week', strtotime($ref)));
        $to = date('Y-m-d', strtotime("$from +6 days"));
        $label = 'Week of ' . fmt_date($from, 'M j') . ' – ' . fmt_date($to, 'M j, Y'); break;
    case 'custom':
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('from')) ? input('from') : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('to')) ? input('to') : date('Y-m-d');
        if ($to < $from) [$from, $to] = [$to, $from];
        $label = fmt_date($from, 'M j, Y') . ' – ' . fmt_date($to, 'M j, Y'); break;
    default:
        $period = 'monthly';
        $from = date('Y-m-01', strtotime($ref)); $to = date('Y-m-t', strtotime($ref));
        $label = fmt_date($from, 'F Y');
}
$rows = db_all(
    "SELECT a.*, s.name AS service_name, s.price, d.name AS dentist_name FROM appointments a
     JOIN services s ON s.id = a.service_id JOIN dentists d ON d.id = a.dentist_id
     WHERE a.start_at >= ? AND a.start_at < ? ORDER BY a.start_at",
    ["$from 00:00:00", date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00']
);

$sum = ['total' => count($rows), 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0, 'emergency' => 0, 'revenue' => 0];
$byService = [];
$byDentist = [];
$bySource = [];
foreach ($rows as $r) {
    $sum[$r['status']]++;
    if ($r['is_emergency']) $sum['emergency']++;
    $svc = &$byService[$r['service_name']];
    $svc = $svc ?? ['total' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0, 'revenue' => 0];
    $svc['total']++;
    if (isset($svc[$r['status']])) $svc[$r['status']]++;
    if ($r['status'] === 'completed') { $svc['revenue'] += $r['price']; $sum['revenue'] += $r['price']; }
    unset($svc);
    $byDentist[$r['dentist_name']][$r['status']] = ($byDentist[$r['dentist_name']][$r['status']] ?? 0) + 1;
    $bySource[source_label($r['source'])] = ($bySource[source_label($r['source'])] ?? 0) + 1;
}
uasort($byService, fn($a, $b) => $b['total'] <=> $a['total']);
$finished = $sum['completed'] + $sum['no_show'];
$rate = fn($n, $d) => $d ? round($n / $d * 100, 1) . '%' : '—';
$list = $type === 'all' ? $rows : array_values(array_filter($rows, fn($r) => $r['status'] === $type));
$typeLabels = ['all' => 'All Appointments', 'completed' => 'Completed Appointments', 'cancelled' => 'Cancellations', 'no_show' => 'No-Shows'];
if (!isset($typeLabels[$type])) $type = 'all';

if (input('export') === 'csv') {
    audit('report_exported', $typeLabels[$type] . ' report, ' . $label);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $period . '-' . $from . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [APP_NAME . ' – ' . $typeLabels[$type] . ' Report', $label]);
    fputcsv($out, ['Total', $sum['total'], 'Completed', $sum['completed'], 'Cancelled', $sum['cancelled'], 'No-show', $sum['no_show']]);
    fputcsv($out, []);
    fputcsv($out, ['Reference', 'Date', 'Time', 'Patient', 'Phone', 'Service', 'Dentist', 'Status', 'Source', 'Emergency']);
    foreach ($list as $r) {
        fputcsv($out, [$r['code'], fmt_date($r['start_at'], 'Y-m-d'), fmt_time($r['start_at']), $r['contact_name'], $r['contact_phone'], $r['service_name'], $r['dentist_name'], status_label($r['status']), source_label($r['source']), $r['is_emergency'] ? 'Yes' : 'No']);
    }
    exit;
}

$periodLabel = $label;   // header.php reuses $label for its menu, so keep our own copy
$title = 'Reports';
$subtitle = $periodLabel;
$layout = 'admin';
$active = 'reports';
include __DIR__ . '/../includes/header.php';
$link = fn(array $o) => '?' . e(http_build_query(array_merge(['period' => $period, 'date' => $ref, 'from' => input('from'), 'to' => input('to'), 'type' => $type], $o)));
?>
<?php
// ---------------------------------------------------------------- Formal printed report (Print / PDF)
// Two versions of the printed report:
//   Staff  -> "Front Desk Report": prepared by the staff member, noted and approved by the dentist.
//   Admin  -> "Management Report": adds revenue and observations, issued by the administrator.
$isAdminReport = is_admin();
$roleTitle = $isAdminReport ? 'Clinic Administrator' : 'Front Desk Staff';
// A signature needs a person's name: account names that are only a job title print as a blank line
$hasRealName = !preg_match('/^(front desk staff|clinic staff|staff|admin|administrator|receptionist|user)$/i', trim($user['full_name']));
$signName = $hasRealName ? $user['full_name'] : '';
$byDay = [];
foreach ($rows as $r0) {
    $d0 = substr($r0['start_at'], 0, 10);
    $byDay[$d0] = ($byDay[$d0] ?? 0) + 1;
}
arsort($byDay);
$php = fn($amount) => 'PHP ' . number_format((float) $amount, 2);            // formal currency format
$pct = fn($n, $d) => $d ? number_format($n / $d * 100, 1) . '%' : 'N/A';     // N/A when there is nothing to compute
// Summary shown as two aligned columns: counts on the left, rates on the right
$sumLeft = [
    'Total appointments'   => $sum['total'],
    'Completed'            => $sum['completed'],
    'Confirmed (upcoming)' => $sum['confirmed'],
    'Pending approval'     => $sum['pending'],
    'Cancelled'            => $sum['cancelled'],
    'No-show'              => $sum['no_show'],
];
$sumRight = [
    'Emergency cases'   => $sum['emergency'],
    'Attendance rate'   => $pct($sum['completed'], $finished),
    'Cancellation rate' => $pct($sum['cancelled'], $sum['total']),
    'No-show rate'      => $pct($sum['no_show'], $finished),
];
if ($isAdminReport) {
    $sumRight['Estimated revenue'] = $php($sum['revenue']);
}
$sumRight = array_pad($sumRight, count($sumLeft), '');
$sumRightKeys = array_keys($sumRight);
$periodWord = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom-Range'][$period];
$reportNo = ($isAdminReport ? 'MGT-' : 'FDR-') . date('Ymd', strtotime($from)) . '-' . date('Ymd', strtotime($to)) . '-' . strtoupper(substr($type, 0, 3));
$docKind = $isAdminReport ? 'MANAGEMENT REPORT' : 'FRONT DESK REPORT';
$coverage = $from === $to ? fmt_date($from, 'F j, Y') : fmt_date($from, 'F j, Y') . ' to ' . fmt_date($to, 'F j, Y');
$plural = fn(int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
$generated = fmt_date(now_str(), 'F j, Y') . ', ' . fmt_time(now_str());
$svcCols = $isAdminReport ? 6 : 5;
?>
<div class="print-only report-formal">
<table class="rf-page"><thead><tr><td><div class="rf-space-top"></div></td></tr></thead>
<tfoot><tr><td><div class="rf-space-bottom"><span><?= e(APP_NAME) ?> &nbsp;|&nbsp; Official <?= e(ucwords(strtolower($docKind))) ?></span><span>Report No. <?= e($reportNo) ?></span></div></td></tr></tfoot>
<tbody><tr><td>

  <div class="rf-head">
    <img src="<?= url('assets/img/logo.svg') ?>" alt="" width="62" height="62">
    <div>
      <div class="rf-clinic"><?= e(APP_NAME) ?></div>
      <div class="rf-sub">Web-Based Dental Appointment System</div>
      <div><?= e(CLINIC_ADDRESS) ?></div>
      <div>Contact No.: <?= e(CLINIC_PHONE) ?> &nbsp;&bull;&nbsp; Email: <?= e(CLINIC_EMAIL) ?></div>
    </div>
  </div>

  <div class="rf-kind"><?= e($docKind) ?></div>
  <div class="rf-title"><?= e(strtoupper($periodWord)) ?> <?= e(strtoupper($typeLabels[$type])) ?> REPORT</div>
  <div class="rf-subtitle">For the period <?= e($coverage) ?></div>

  <table class="rf-meta">
    <tr><td>Report No.</td><td>:</td><td><?= e($reportNo) ?></td></tr>
    <tr><td>Period Covered</td><td>:</td><td><?= e($coverage) ?></td></tr>
    <tr><td>Report Type</td><td>:</td><td><?= e($periodWord . ' – ' . $typeLabels[$type]) ?></td></tr>
    <tr><td>Date Generated</td><td>:</td><td><?= e($generated) ?></td></tr>
    <tr><td><?= $isAdminReport ? 'Issued By' : 'Prepared By' ?></td><td>:</td><td><?= $signName !== '' ? e($signName) . ', ' . e($roleTitle) : '______________________________, ' . e($roleTitle) ?></td></tr>
  </table>

  <p class="rf-para">This <?= $isAdminReport ? 'management report, issued by the Clinic Administrator,' : 'report, prepared by the front desk,' ?> presents the appointment records of <?= e(APP_NAME) ?> for the period <?= e($coverage) ?>, as recorded in the clinic&rsquo;s Web-Based Dental Appointment System.
  <?php if ($sum['total']): ?>During this period, the clinic recorded a total of <strong><?= e($plural($sum['total'], 'appointment', 'appointments')) ?></strong>, of which <?= (int) $sum['completed'] ?> <?= $sum['completed'] === 1 ? 'was' : 'were' ?> completed, <?= (int) $sum['cancelled'] ?> <?= $sum['cancelled'] === 1 ? 'was' : 'were' ?> cancelled, and <?= (int) $sum['no_show'] ?> <?= $sum['no_show'] === 1 ? 'was a no-show' : 'were no-shows' ?>. A further <?= (int) ($sum['pending'] + $sum['confirmed']) ?> <?= ($sum['pending'] + $sum['confirmed']) === 1 ? 'appointment remains' : 'appointments remain' ?> upcoming (<?= (int) $sum['confirmed'] ?> confirmed and <?= (int) $sum['pending'] ?> pending approval).
  <?php else: ?>No appointments were recorded during this period.<?php endif; ?></p>

  <h3>I. SUMMARY OF APPOINTMENTS</h3>
  <table class="rf-table">
    <colgroup><col style="width:32%"><col style="width:18%"><col style="width:32%"><col style="width:18%"></colgroup>
    <thead><tr><th>Particulars</th><th>Figure</th><th>Particulars</th><th>Figure</th></tr></thead>
    <tbody>
      <?php $li = 0; foreach ($sumLeft as $k => $v): $rk = $sumRightKeys[$li]; $rv = $sumRight[$rk]; ?>
        <tr><td><?= e($k) ?></td><td class="num"><?= e((string) $v) ?></td><td><?= is_int($rk) ? '' : e($rk) ?></td><td class="num"><?= e((string) $rv) ?></td></tr>
      <?php $li++; endforeach; ?>
    </tbody>
  </table>

  <h3>II. APPOINTMENTS BY SERVICE</h3>
  <table class="rf-table">
    <?php if ($isAdminReport): ?><colgroup><col style="width:28%"><col style="width:10%"><col style="width:16%"><col style="width:16%"><col style="width:13%"><col style="width:17%"></colgroup>
    <?php else: ?><colgroup><col style="width:40%"><col style="width:12%"><col style="width:16%"><col style="width:16%"><col style="width:16%"></colgroup><?php endif; ?>
    <thead><tr><th>Service</th><th>Total</th><th>Completed</th><th>Cancelled</th><th>No-Show</th><?php if ($isAdminReport): ?><th>Est. Revenue</th><?php endif; ?></tr></thead>
    <tbody>
      <?php foreach ($byService as $n => $sv): ?><tr><td><?= e($n) ?></td><td class="num"><?= $sv['total'] ?></td><td class="num"><?= $sv['completed'] ?></td><td class="num"><?= $sv['cancelled'] ?></td><td class="num"><?= $sv['no_show'] ?></td><?php if ($isAdminReport): ?><td class="num"><?= e($php($sv['revenue'])) ?></td><?php endif; ?></tr><?php endforeach; ?>
      <?php if (!$byService): ?><tr><td colspan="<?= $svcCols ?>" class="none">No appointments were recorded for this period.</td></tr><?php endif; ?>
      <tr class="total"><td>TOTAL</td><td class="num"><?= $sum['total'] ?></td><td class="num"><?= $sum['completed'] ?></td><td class="num"><?= $sum['cancelled'] ?></td><td class="num"><?= $sum['no_show'] ?></td><?php if ($isAdminReport): ?><td class="num"><?= e($php($sum['revenue'])) ?></td><?php endif; ?></tr>
    </tbody>
  </table>

  <h3>III. APPOINTMENTS BY BOOKING CHANNEL</h3>
  <table class="rf-table">
    <colgroup><col style="width:50%"><col style="width:25%"><col style="width:25%"></colgroup>
    <thead><tr><th>Booking Channel</th><th>Appointments</th><th>Percentage</th></tr></thead>
    <tbody>
      <?php foreach ($bySource as $n => $c): ?><tr><td><?= e($n) ?></td><td class="num"><?= $c ?></td><td class="num"><?= $pct($c, $sum['total']) ?></td></tr><?php endforeach; ?>
      <?php if (!$bySource): ?><tr><td colspan="3" class="none">No appointments were recorded for this period.</td></tr><?php endif; ?>
      <tr class="total"><td>TOTAL</td><td class="num"><?= $sum['total'] ?></td><td class="num"><?= $sum['total'] ? '100.0%' : 'N/A' ?></td></tr>
    </tbody>
  </table>

  <h3>IV. DETAILED LIST OF <?= e(strtoupper($typeLabels[$type])) ?></h3>
  <table class="rf-table rf-list">
    <colgroup><col style="width:5%"><col style="width:14%"><col style="width:11%"><col style="width:20%"><col style="width:20%"><col style="width:16%"><col style="width:14%"></colgroup>
    <thead><tr><th>No.</th><th>Date</th><th>Time</th><th>Name of Patient</th><th>Service Availed</th><th>Dentist</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($list as $i => $r): ?><tr><td class="ctr"><?= $i + 1 ?></td><td class="ctr nw"><?= e(fmt_date($r['start_at'], 'M j, Y')) ?></td><td class="ctr nw"><?= e(fmt_time($r['start_at'])) ?></td><td><?= e($r['contact_name']) ?><?= is_guest_booking($r) ? '<br><span class="ref nw">Guest (' . e($r['code']) . ')</span>' : '' ?></td><td><?= e($r['service_name']) ?></td><td class="nw"><?= e(preg_replace('/^(Dr\.?\s+\S)\S*\s+(?:\S+\s+)*?(\S+)$/', '$1. $2', $r['dentist_name'])) ?></td><td class="ctr"><?= e(status_label($r['status'])) ?><?= $r['is_emergency'] ? '<br><span class="ref">Emergency</span>' : '' ?></td></tr><?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="7" class="none">No records were found for this report.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="rf-end">*** Nothing follows ***</div>

  <?php if ($isAdminReport): ?>
    <h3>V. MANAGEMENT OBSERVATIONS</h3>
    <?php if ($sum['total']): $topSvc = array_key_first($byService); $topDay = array_key_first($byDay); ?>
      <ol class="rf-obs">
        <li>The most availed service for the period was <strong><?= e($topSvc) ?></strong>, with <?= e($plural($byService[$topSvc]['total'], 'appointment', 'appointments')) ?> (<?= $pct($byService[$topSvc]['total'], $sum['total']) ?> of the total).</li>
        <li>The busiest day was <strong><?= e(fmt_date($topDay, 'l, F j, Y')) ?></strong>, with <?= e($plural($byDay[$topDay], 'appointment', 'appointments')) ?>.</li>
        <li>Of the appointments already due, the attendance rate was <strong><?= $pct($sum['completed'], $finished) ?></strong> and the no-show rate was <strong><?= $pct($sum['no_show'], $finished) ?></strong>. The cancellation rate for all appointments was <strong><?= $pct($sum['cancelled'], $sum['total']) ?></strong>.</li>
        <li>Estimated revenue from completed appointments, based on starting service rates, amounted to <strong><?= e($php($sum['revenue'])) ?></strong>.</li>
        <?php if ($sum['pending']): ?><li><?= e($plural($sum['pending'], 'appointment request is', 'appointment requests are')) ?> still pending approval and should be acted upon by the front desk.</li><?php endif; ?>
      </ol>
    <?php else: ?>
      <p class="rf-para">No appointments were recorded for this period; hence, there are no observations to report.</p>
    <?php endif; ?>

    <table class="rf-issued"><tr><td></td><td>
      <div class="lbl">Issued by:</div>
      <div class="name"><?= $signName !== '' ? e($signName) : '______________________________' ?></div>
      <div><?= e($roleTitle) ?> / Clinic Owner</div>
      <div class="nw"><?= e($generated) ?></div>
    </td></tr></table>
  <?php else: ?>
    <div class="rf-cert">
      <p class="rf-para"><strong>CERTIFICATION.</strong> I hereby certify that the foregoing information is true and correct, and was prepared from the official records of the <?= e(APP_NAME) ?> Web-Based Dental Appointment System as of <?= e(fmt_date(now_str(), 'F j, Y')) ?>.</p>
      <table class="rf-sign">
        <tr><td class="lbl">Prepared by:</td><td class="gap"></td><td class="lbl">Noted and approved by:</td></tr>
        <tr><td class="line"><?= e($signName) ?>&nbsp;</td><td class="gap"></td><td class="line"><?= e(DENTIST_DEFAULT_NAME) ?></td></tr>
        <tr><td class="cap"><?= $signName === '' ? 'Signature over printed name' : e($roleTitle) ?></td><td class="gap"></td><td class="cap">Dentist / Clinic Owner</td></tr>
        <tr><td class="cap"><?= $signName === '' ? e($roleTitle) : 'Date: ____________________' ?></td><td class="gap"></td><td class="cap">Date: ____________________</td></tr>
        <?php if ($signName === ''): ?><tr><td class="cap">Date: ____________________</td><td class="gap"></td><td class="cap"></td></tr><?php endif; ?>
      </table>
    </div>
  <?php endif; ?>

  <div class="rf-foot">CONFIDENTIAL &ndash; This document contains patient information and is intended for the official use of <?= e(APP_NAME) ?> only, in accordance with the Data Privacy Act of 2012 (R.A. 10173).<?= $isAdminReport ? ' Revenue figures are estimates based on the starting rates of completed services.' : '' ?></div>

</td></tr></tbody></table>
</div>

<?php if (!$hasRealName): ?>
  <div class="alert alert-warning small no-print"><i class="bi bi-person-exclamation"></i> Your account name is &ldquo;<?= e($user['full_name']) ?>&rdquo;, which is a job title, so printed reports leave the name on the signature line blank. <a href="<?= url('admin/settings.php') ?>" class="fw-semibold">Set your real name in Settings</a> so reports show who prepared them.</div>
<?php endif; ?>
<form method="get" class="card-md card-body-md mb-3 no-print">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">Report period</label>
      <div class="btn-group w-100" role="group">
        <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom'] as $k => $l): ?>
          <input type="radio" class="btn-check" name="period" id="p_<?= $k ?>" value="<?= $k ?>" <?= $period === $k ? 'checked' : '' ?> onchange="this.form.submit()"><label class="btn btn-sm btn-outline-primary" for="p_<?= $k ?>"><?= $l ?></label>
        <?php endforeach; ?>
      </div></div>
    <?php if ($period === 'custom'): ?>
      <div class="col-md-2 col-6"><label class="form-label small" for="from">From</label><input type="date" class="form-control form-control-sm" name="from" id="from" value="<?= e($from) ?>"></div>
      <div class="col-md-2 col-6"><label class="form-label small" for="to">To</label><input type="date" class="form-control form-control-sm" name="to" id="to" value="<?= e($to) ?>"></div>
    <?php else: ?>
      <div class="col-md-3"><label class="form-label small" for="date"><?= $period === 'monthly' ? 'Any day in the month' : ($period === 'weekly' ? 'Any day in the week' : 'Date') ?></label><input type="date" class="form-control form-control-sm" name="date" id="date" value="<?= e($ref) ?>"></div>
    <?php endif; ?>
    <div class="col-md-2"><label class="form-label small" for="type">Report type</label>
      <select class="form-select form-select-sm" name="type" id="type"><?php foreach ($typeLabels as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md d-flex gap-2 flex-wrap">
      <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Generate</button>
      <a class="btn btn-sm btn-light border" href="<?= $link(['export' => 'csv']) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
      <button type="button" class="btn btn-sm btn-light border" onclick="window.print()"><i class="bi bi-printer"></i> Print / PDF</button>
    </div>
  </div>
</form>

<div class="row g-3 mb-3 no-print">
  <?php foreach ([
      ['Total', $sum['total'], 'calendar3', 'tint-blue', $periodLabel],
      ['Completed', $sum['completed'], 'check2-all', 'tint-green', 'Attendance ' . $rate($sum['completed'], $finished)],
      ['Cancelled', $sum['cancelled'], 'x-circle', 'tint-amber', 'Cancellation rate ' . $rate($sum['cancelled'], $sum['total'])],
      ['No-Shows', $sum['no_show'], 'person-x', 'tint-red', 'No-show rate ' . $rate($sum['no_show'], $finished)],
      ['Upcoming', $sum['pending'] + $sum['confirmed'], 'hourglass-split', 'tint-violet', $sum['pending'] . ' pending · ' . $sum['confirmed'] . ' confirmed'],
      ['Est. Revenue', peso($sum['revenue']), 'cash-coin', 'tint-teal', 'Starting rates of completed'],
  ] as [$l, $v, $i, $t, $h]): ?>
    <div class="col-6 col-md-4 col-xl-2"><div class="card-md stat-card"><div class="stat-icon <?= $t ?>"><i class="bi bi-<?= $i ?>"></i></div><div class="stat-value"><?= e((string) $v) ?></div><div class="stat-label"><?= e($l) ?></div><div class="small text-muted"><?= e($h) ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3 no-print">
  <div class="col-xl-7">
    <div class="card-md h-100"><div class="card-head"><h2>By Service</h2></div>
      <div class="table-responsive"><table class="table table-md">
        <thead><tr><th>Service</th><th class="text-end">Total</th><th class="text-end">Completed</th><th class="text-end">Cancelled</th><th class="text-end">No-show</th><th class="text-end">Est. revenue</th></tr></thead>
        <tbody>
          <?php foreach ($byService as $n => $s): ?><tr><td class="fw-semibold"><?= e($n) ?></td><td class="text-end"><?= $s['total'] ?></td><td class="text-end"><?= $s['completed'] ?></td><td class="text-end"><?= $s['cancelled'] ?></td><td class="text-end"><?= $s['no_show'] ?></td><td class="text-end"><?= peso($s['revenue']) ?></td></tr><?php endforeach; ?>
          <?php if (!$byService): ?><tr><td colspan="6" class="text-center text-muted py-4">No appointments in this period.</td></tr><?php endif; ?>
        </tbody></table></div></div>
  </div>
  <div class="col-xl-5">
    <div class="card-md mb-3"><div class="card-head"><h2>By Dentist</h2></div>
      <div class="table-responsive"><table class="table table-md"><thead><tr><th>Dentist</th><th class="text-end">Total</th><th class="text-end">Completed</th><th class="text-end">No-show</th></tr></thead><tbody>
        <?php foreach ($byDentist as $n => $s): ?><tr><td><?= e($n) ?></td><td class="text-end"><?= array_sum($s) ?></td><td class="text-end"><?= $s['completed'] ?? 0 ?></td><td class="text-end"><?= $s['no_show'] ?? 0 ?></td></tr><?php endforeach; ?>
        <?php if (!$byDentist): ?><tr><td colspan="4" class="text-center text-muted py-3">—</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <div class="card-md"><div class="card-head"><h2>By Booking Channel</h2></div>
      <div class="card-body-md pt-1">
        <?php foreach ($bySource as $n => $c): ?><div class="d-flex justify-content-between py-1 small border-bottom"><span><?= e($n) ?></span><strong><?= $c ?> <span class="text-muted fw-normal">(<?= $rate($c, $sum['total']) ?>)</span></strong></div><?php endforeach; ?>
        <?php if ($sum['emergency']): ?><div class="d-flex justify-content-between py-1 small"><span>Emergency cases</span><strong><?= $sum['emergency'] ?></strong></div><?php endif; ?>
        <?php if (!$bySource): ?><div class="small text-muted">—</div><?php endif; ?>
      </div></div>
  </div>
</div>

<div class="card-md no-print">
  <div class="card-head"><h2><?= e($typeLabels[$type]) ?></h2>
    <div class="filter-tabs ms-auto no-print"><?php foreach ($typeLabels as $k => $l): ?><a href="<?= $link(['type' => $k]) ?>" class="<?= $type === $k ? 'active' : '' ?>"><?= $k === 'all' ? 'All' : $l ?></a><?php endforeach; ?></div></div>
  <div class="table-responsive"><table class="table table-md">
    <thead><tr><th>Date</th><th>Time</th><th>Reference</th><th>Patient</th><th>Service</th><th>Dentist</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($list as $r): ?><tr><td class="text-nowrap"><?= e(fmt_date($r['start_at'], 'D, M j')) ?></td><td class="text-nowrap"><?= e(fmt_time($r['start_at'])) ?></td><td><a class="code-ref small" href="<?= url('admin/appointment.php?id=' . $r['id']) ?>"><?= e($r['code']) ?></a></td><td><?= e($r['contact_name']) ?></td><td><?= e($r['service_name']) ?></td><td class="small"><?= e($r['dentist_name']) ?></td><td><?= status_badge($r['status']) ?></td></tr><?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="7" class="text-center text-muted py-4">No records for this report.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
