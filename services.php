<?php
/** Public price list: every active service, grouped by section of the PDA tariff. */
require __DIR__ . '/includes/bootstrap.php';

$title = 'Services & Rates';
$layout = 'public';
$active = 'services';
$groups = group_services(active_services(false));
include __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container" style="max-width: 980px;">
    <div class="text-center mb-4">
      <div class="small-caps">What we offer</div>
      <h1 class="section-title">Services &amp; Rates</h1>
      <p class="text-muted mb-2">Based on the Philippine Dental Association, Bukidnon Chapter Approved Dental Tariff (effective 2023).</p>
      <p class="small text-muted">The dentist confirms the final cost after examination. Payment is made at the clinic.</p>
    </div>
    <div class="svc-search"><i class="bi bi-search"></i><input type="search" class="form-control" id="svcSearch" placeholder="Search a service, e.g. cleaning, extraction, braces" aria-label="Search a service" autocomplete="off"></div>
    <?php foreach ($groups as $cat => $list): ?>
      <div class="card-md mb-3" id="<?= e(cat_anchor($cat)) ?>" data-cat>
        <div class="card-head"><h2><i class="bi <?= e($list[0]['icon']) ?> text-primary"></i> <?= e($cat) ?></h2></div>
        <div class="table-responsive"><table class="table table-md price-table mb-0">
          <tbody>
          <?php foreach ($list as $s): $addon = !service_is_bookable($s); ?>
            <tr class="<?= $addon ? 'addon' : '' ?>" data-svc="<?= e(mb_strtolower($s['name'] . ' ' . $s['description'] . ' ' . $cat)) ?>">
              <td><?= $addon ? '<i class="bi bi-plus-lg"></i> ' : '' ?><?= e($s['name']) ?></td>
              <td class="rate"><?= $addon ? '+ ' : '' ?><?= price_label($s) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
      </div>
    <?php endforeach; ?>
    <p class="small text-muted" id="svcNone" hidden>No service matches your search.</p>
    <p class="small text-muted"><i class="bi bi-plus-lg"></i> marks an additional charge that is added to a treatment when needed.</p>
    <div class="text-center mt-4">
      <a class="btn btn-primary" href="<?= url(current_user() && !is_staff() ? 'patient/book.php' : 'guest/book.php') ?>"><i class="bi bi-calendar-plus"></i> Book an Appointment</a>
    </div>
  </div>
</section>
<script>
document.getElementById('svcSearch').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase(), any = false;
  document.querySelectorAll('[data-cat]').forEach(function (c) {
    var n = 0;
    c.querySelectorAll('[data-svc]').forEach(function (r) { var hit = !q || r.dataset.svc.indexOf(q) !== -1; r.hidden = !hit; if (hit) n++; });
    c.hidden = !n; if (n) any = true;
  });
  document.getElementById('svcNone').hidden = any;
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
