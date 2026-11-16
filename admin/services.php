<?php
/** Services & Dentists (admin): manage service list, durations, prices and dentists. */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_admin();

$icons = ['bi-clipboard2-pulse', 'bi-stars', 'bi-emoji-smile', 'bi-bandaid', 'bi-scissors', 'bi-brightness-high', 'bi-activity', 'bi-sliders', 'bi-gem', 'bi-magic', 'bi-heart-pulse', 'bi-shield-plus', 'bi-capsule', 'bi-droplet'];
$edit = null;
$hasTariffCols = schema_version() >= 5;   // price range and add-on columns (database upgrade 5)
// sections of the price list, plus any other category already used by a service
$categories = service_categories();
foreach (db_all('SELECT DISTINCT category FROM services') as $c) {
    if (!in_array($c['category'], $categories, true) && !in_array($c['category'], ['routine', 'advanced'], true)) $categories[] = $c['category'];
}

if (is_post()) {
    csrf_check();
    $do = input('do');
    if ($do === 'save_service') {
        $data = [
            'name' => mb_substr(input('name'), 0, 120), 'description' => mb_substr(input('description'), 0, 255),
            'category' => in_array(input('category'), $categories, true) ? input('category') : $categories[0],
            'duration_min' => max(0, min(480, input_int('duration_min'))),
            'price' => max(0, (float) input('price')), 'icon' => in_array(input('icon'), $icons, true) ? input('icon') : 'bi-heart-pulse',
            'is_active' => empty($_POST['is_active']) ? 0 : 1,
        ];
        if ($hasTariffCols) {
            $max = (float) input('price_max');
            $data['price_max'] = $max > $data['price'] ? $max : null;          // only kept when it is a real range
            $data['bookable'] = empty($_POST['bookable']) ? 0 : 1;
            if ($data['bookable'] && $data['duration_min'] < 5) {
                $data['duration_min'] = 30;                                     // a bookable service must take some time
            }
        } else {
            $data['duration_min'] = max(5, $data['duration_min']);
        }
        if ($data['name'] === '') {
            flash('danger', 'Service name is required.');
        } elseif ($id = input_int('id')) {
            db_update('services', $data, 'id = :id', ['id' => $id]);
            audit('service_updated', $data['name'] . " ({$data['duration_min']} min, ₱{$data['price']})");
            flash('success', 'Service updated.');
        } else {
            db_insert('services', $data);
            audit('service_added', $data['name']);
            flash('success', 'Service added.');
        }
    } elseif ($do === 'save_dentist') {
        $data = ['name' => mb_substr(input('name'), 0, 120), 'specialty' => mb_substr(input('specialty'), 0, 120), 'is_active' => empty($_POST['is_active']) ? 0 : 1];
        if ($data['name'] === '') {
            flash('danger', 'Dentist name is required.');
        } elseif (($id = input_int('id')) && $id !== sole_dentist_id() && $data['is_active']) {
            // Dr. Warren is the only bookable dentist: other records stay inactive (history only)
            $data['is_active'] = 0;
            db_update('dentists', $data, 'id = :id', ['id' => $id]);
            flash('warning', 'Saved, but this dentist stays inactive: Dr. Warren is the only dentist who can be booked.');
        } elseif ($id) {
            if ($id === sole_dentist_id()) {
                $data['is_active'] = 1;   // Dr. Warren can never be deactivated
            }
            $future = (int) db_val("SELECT COUNT(*) FROM appointments WHERE dentist_id = ? AND status IN ('pending','confirmed') AND start_at >= ?", [$id, now_str()]);
            db_update('dentists', $data, 'id = :id', ['id' => $id]);
            audit('dentist_updated', $data['name'] . ($data['is_active'] ? '' : ' (deactivated)'));
            flash($future && !$data['is_active'] ? 'warning' : 'success', 'Dentist updated.' . ($future && !$data['is_active'] ? " This dentist still has $future upcoming appointment(s); please reassign them." : ''));
        } else {
            $data['is_active'] = 0;
            db_insert('dentists', $data);
            audit('dentist_added', $data['name'] . ' (inactive: Dr. Warren is the only bookable dentist)');
            flash('warning', 'Dentist record added as inactive. Dr. Warren is the only dentist who can be booked.');
        }
    }
    redirect('admin/services.php');
}
if ($id = input_int('edit_service')) $edit = ['type' => 'service', 'row' => find_service($id)];
if ($id = input_int('edit_dentist')) $edit = ['type' => 'dentist', 'row' => find_dentist($id)];

$likeId = ['sqlsrv' => "'%,' + CAST(s.id AS VARCHAR(10)) + ',%'", 'mysql' => "CONCAT('%,', s.id, ',%')", 'sqlite' => "'%,' || s.id || ',%'"][DB_DRIVER];
$services = sort_services(db_all("SELECT s.*, (SELECT COUNT(*) FROM appointments a WHERE a.service_id = s.id OR a.extra_services LIKE $likeId) AS uses FROM services s"));
// The old sample services that are still attached to past appointments stay in the database for those records only: never listed
$services = array_values(array_filter($services, fn($x) => $x['is_active'] || !in_array($x['category'], ['routine', 'advanced'], true)));
usort($services, fn($a, $b) => (int) $b['is_active'] <=> (int) $a['is_active']);   // stable: inactive ones go last, order kept
$activeCount = count(array_filter($services, fn($x) => $x['is_active']));
$upgradeError = (string) setting_get('migration_5_error', '');
$dentists = db_all("SELECT d.*, (SELECT COUNT(*) FROM appointments a WHERE a.dentist_id = d.id AND a.status IN ('pending','confirmed') AND a.start_at >= ?) AS upcoming FROM dentists d ORDER BY d.is_active DESC, d.name", [now_str()]);
$es = ($edit['type'] ?? '') === 'service' ? $edit['row'] : null;
$ed = ($edit['type'] ?? '') === 'dentist' ? $edit['row'] : null;

$title = 'Services & Dentists';
$layout = 'admin';
$active = 'services';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-xl-8">
    <?php if ($upgradeError !== ''): ?><div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> The new price list could not be loaded into the database automatically, so the old services are still shown. Reason: <?= e($upgradeError) ?></div><?php endif; ?>
    <div class="card-md mb-3">
      <div class="card-head"><h2>Dental Services</h2><span class="small text-muted ms-auto"><?= $activeCount ?> active &middot; rates from the PDA Bukidnon Chapter tariff</span></div>
      <div class="card-body-md pb-0">
        <input type="search" class="form-control form-control-sm" id="svcFilter" placeholder="Search a service or category..." aria-label="Search a service" autocomplete="off">
        <p class="small text-muted mt-2 mb-2"><i class="bi bi-info-circle"></i> <strong>Duration</strong> controls how long a booking blocks the schedule. The tariff does not give durations, so they are estimates: please review them. <strong>Add-on</strong> charges are in the price list but cannot be booked on their own.</p>
      </div>
      <div class="table-responsive" style="max-height: 640px; overflow-y: auto;"><table class="table table-md" id="svcTable">
        <thead><tr><th>Service</th><th>Category</th><th class="text-end">Duration</th><th class="text-end">Rate</th><th class="text-end">Bookings</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($services as $s): $addon = !service_is_bookable($s); ?>
          <tr class="<?= $s['is_active'] ? '' : 'opacity-50' ?>" data-svc="<?= e(mb_strtolower($s['name'] . ' ' . $s['category'])) ?>">
            <td><div class="person"><div class="avatar light sm"><i class="bi <?= e($s['icon']) ?>"></i></div><div><div class="n"><?= e($s['name']) ?><?= $s['is_active'] ? '' : ' <span class="badge text-bg-light">inactive</span>' ?><?= $addon ? ' <span class="badge tint-violet">add-on</span>' : '' ?></div><div class="s"><?= e($s['description']) ?></div></div></div></td>
            <td class="small"><?= e($s['category']) ?></td>
            <td class="text-end text-nowrap"><?= $addon ? '&ndash;' : (int) $s['duration_min'] . ' min' ?></td>
            <td class="text-end text-nowrap"><?= price_label($s) ?></td>
            <td class="text-end"><?= (int) $s['uses'] ?></td>
            <td class="text-end"><a class="btn btn-sm btn-light border" href="?edit_service=<?= $s['id'] ?>#serviceForm" aria-label="Edit <?= e($s['name']) ?>"><i class="bi bi-pencil"></i></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <div class="card-md">
      <div class="card-head"><h2>Dentists</h2></div>
      <div class="table-responsive"><table class="table table-md">
        <thead><tr><th>Dentist</th><th>Specialty</th><th class="text-end">Upcoming</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($dentists as $d): ?>
          <tr class="<?= $d['is_active'] ? '' : 'opacity-50' ?>"><td><div class="person"><div class="avatar sm"><?= e(initials($d['name'])) ?></div><span class="n"><?= e($d['name']) ?></span><?= $d['is_active'] ? '' : ' <span class="badge text-bg-light">inactive</span>' ?></div></td>
            <td class="small"><?= e($d['specialty']) ?></td><td class="text-end"><?= (int) $d['upcoming'] ?></td>
            <td class="text-end"><a class="btn btn-sm btn-light border" href="?edit_dentist=<?= $d['id'] ?>#dentistForm"><i class="bi bi-pencil"></i></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>
  <div class="col-xl-4">
    <form method="post" class="card-md mb-3" id="serviceForm">
      <div class="card-head"><h2><?= $es ? 'Edit Service' : 'Add Service' ?></h2><?php if ($es): ?><a class="small ms-auto" href="<?= url('admin/services.php') ?>">Cancel</a><?php endif; ?></div>
      <div class="card-body-md">
        <?= csrf_field() ?><input type="hidden" name="do" value="save_service"><input type="hidden" name="id" value="<?= (int) ($es['id'] ?? 0) ?>">
        <div class="mb-2"><label class="form-label" for="s_name">Name</label><input class="form-control form-control-sm" id="s_name" name="name" value="<?= e($es['name'] ?? '') ?>" required></div>
        <div class="mb-2"><label class="form-label" for="s_desc">Description</label><input class="form-control form-control-sm" id="s_desc" name="description" value="<?= e($es['description'] ?? '') ?>"></div>
        <div class="row g-2 mb-2">
          <div class="col-12"><label class="form-label" for="s_cat">Category</label><select class="form-select form-select-sm" id="s_cat" name="category"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>" <?= ($es['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label" for="s_icon">Icon</label><select class="form-select form-select-sm" id="s_icon" name="icon"><?php foreach ($icons as $ic): ?><option value="<?= $ic ?>" <?= ($es['icon'] ?? '') === $ic ? 'selected' : '' ?>><?= str_replace('bi-', '', $ic) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label" for="s_dur">Duration (min)</label><input class="form-control form-control-sm" type="number" id="s_dur" name="duration_min" min="0" max="480" step="5" value="<?= (int) ($es['duration_min'] ?? 30) ?>" required></div>
          <div class="col-6"><label class="form-label" for="s_price">Rate (₱)</label><input class="form-control form-control-sm" type="number" id="s_price" name="price" min="0" step="50" value="<?= e((string) (float) ($es['price'] ?? 0)) ?>"></div>
          <?php if ($hasTariffCols): ?><div class="col-6"><label class="form-label" for="s_pmax">Up to (₱) <span class="text-muted fw-normal small">optional</span></label><input class="form-control form-control-sm" type="number" id="s_pmax" name="price_max" min="0" step="50" value="<?= !empty($es['price_max']) ? e((string) (float) $es['price_max']) : '' ?>" placeholder="for a price range"></div><?php endif; ?>
        </div>
        <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="is_active" id="s_active" value="1" <?= !$es || $es['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="s_active">Active (shown in the price list)</label></div>
        <?php if ($hasTariffCols): ?><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="bookable" id="s_bookable" value="1" <?= !$es || ($es['bookable'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="s_bookable">Can be booked on its own <span class="text-muted small">(untick for add-on charges)</span></label></div><?php else: ?><div class="mb-3"></div><?php endif; ?>
        <button class="btn btn-sm btn-primary w-100"><?= $es ? 'Save Changes' : 'Add Service' ?></button>
      </div>
    </form>
    <form method="post" class="card-md" id="dentistForm">
      <div class="card-head"><h2><?= $ed ? 'Edit Dentist' : 'Add Dentist' ?></h2><?php if ($ed): ?><a class="small ms-auto" href="<?= url('admin/services.php') ?>">Cancel</a><?php endif; ?></div>
      <div class="card-body-md">
        <?= csrf_field() ?><input type="hidden" name="do" value="save_dentist"><input type="hidden" name="id" value="<?= (int) ($ed['id'] ?? 0) ?>">
        <div class="mb-2"><label class="form-label" for="d_name">Name</label><input class="form-control form-control-sm" id="d_name" name="name" value="<?= e($ed['name'] ?? '') ?>" placeholder="Dr. Juan Dela Cruz" required></div>
        <div class="mb-2"><label class="form-label" for="d_spec">Specialty</label><input class="form-control form-control-sm" id="d_spec" name="specialty" value="<?= e($ed['specialty'] ?? '') ?>"></div>
        <input type="hidden" name="is_active" value="<?= $ed && (int) $ed['id'] === sole_dentist_id() ? 1 : 0 ?>">
        <p class="small text-muted"><i class="bi bi-info-circle"></i> Dr. Warren is the only dentist patients and staff can book. Other dentist records are kept inactive so past appointments keep their history.</p>
        <button class="btn btn-sm btn-primary w-100"><?= $ed ? 'Save Changes' : 'Add Dentist' ?></button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('svcFilter').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('#svcTable tbody tr').forEach(function (tr) { tr.hidden = q && tr.dataset.svc.indexOf(q) === -1; });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
