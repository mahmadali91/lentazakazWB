<?php foreach (take_flashes() as $f): ?>
  <div class="alert alert-<?= $f['type'] === 'success' ? 'success' : 'error' ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
