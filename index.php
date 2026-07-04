<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$pdo  = db();
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$home = $base === '' ? '/' : $base . '/';

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

$code = isset($_GET['p']) ? preg_replace('/[^a-z0-9]/', '', (string) $_GET['p']) : '';

/* ---- JSON results endpoint (used for live updates) ---- */
if ($code !== '' && isset($_GET['json'])) {
    header('Content-Type: application/json');
    $data = load_poll($pdo, $code);
    if (!$data) { http_response_code(404); echo '{"error":"not found"}'; exit; }
    $total = array_sum(array_column($data['options'], 'votes'));
    echo json_encode([
        'total'    => (int) $total,
        'locked'   => poll_locked($data['poll']),
        'reason'   => poll_lock_reason($data['poll']),
        'options'  => array_map(fn($o) => ['id' => (int) $o['id'], 'label' => $o['label'], 'votes' => (int) $o['votes']], $data['options']),
    ]);
    exit;
}

/* ---- create a poll ---- */
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $question = trim((string) ($_POST['question'] ?? ''));
    $options  = array_values(array_filter(array_map('trim', (array) ($_POST['options'] ?? [])), fn($o) => $o !== ''));
    if ($question === '') {
        $error = 'Please enter a question.';
    } elseif (count($options) < 2) {
        $error = 'Add at least two options.';
    } elseif (count($options) > 8) {
        $error = 'Eight options max.';
    } else {
        $expiryMap = ['1h' => 3600, '1d' => 86400, 'never' => 0];
        $expiryKey = (string) ($_POST['expiry'] ?? 'never');
        $expSecs   = $expiryMap[$expiryKey] ?? 0;
        $expiresAt = $expSecs > 0 ? date('c', time() + $expSecs) : null;

        $newCode = make_code($pdo);
        $token   = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO polls (code, question, created_at, token, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$newCode, mb_substr($question, 0, 200), date('c'), $token, $expiresAt]);
        $pid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO options (poll_id, label) VALUES (?, ?)');
        foreach (array_slice($options, 0, 8) as $label) {
            $ins->execute([$pid, mb_substr($label, 0, 120)]);
        }
        // Secret creator token: presence of this cookie unlocks the "Close poll" action.
        setcookie('qpadm_' . $newCode, $token, time() + 60 * 60 * 24 * 365, $home);
        header('Location: ' . $home . '?p=' . $newCode);
        exit;
    }
}

/* ---- close a poll (creator only, via secret token cookie) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close' && $code !== '') {
    $data  = load_poll($pdo, $code);
    $token = (string) ($_COOKIE['qpadm_' . $code] ?? '');
    if ($data && $token !== '' && hash_equals((string) $data['poll']['token'], $token) && empty($data['poll']['closed'])) {
        $pdo->prepare('UPDATE polls SET closed = 1, closed_at = ? WHERE id = ?')
            ->execute([date('c'), $data['poll']['id']]);
    }
    header('Location: ' . $home . '?p=' . $code);
    exit;
}

/* ---- cast a vote ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote' && $code !== '') {
    $data = load_poll($pdo, $code);
    $optionId = (int) ($_POST['option'] ?? 0);
    $valid = $data && in_array($optionId, array_map(fn($o) => (int) $o['id'], $data['options']), true);
    $already = isset($_COOKIE['qp_' . $code]);
    $locked  = $data && poll_locked($data['poll']);
    if ($valid && !$already && !$locked) {
        $pdo->prepare('UPDATE options SET votes = votes + 1 WHERE id = ? AND poll_id = ?')
            ->execute([$optionId, $data['poll']['id']]);
        setcookie('qp_' . $code, '1', time() + 60 * 60 * 24 * 365, $home);
    }
    header('Location: ' . $home . '?p=' . $code . '&voted=1');
    exit;
}

$view = $code !== '' ? load_poll($pdo, $code) : null;
$notFound = $code !== '' && $view === null;
$locked   = $view && poll_locked($view['poll']);
$lockReason = $view ? poll_lock_reason($view['poll']) : null;
$isOwner  = $view && ($_COOKIE['qpadm_' . $code] ?? '') !== ''
            && hash_equals((string) $view['poll']['token'], (string) ($_COOKIE['qpadm_' . $code] ?? ''));
// Show results when the voter has voted, asked to peek, or the poll is locked.
$voted = $view && (isset($_COOKIE['qp_' . $code]) || isset($_GET['voted']) || $locked);
$scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
$shareUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $home . '?p=' . $code;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $view ? h($view['poll']['question']) : 'Quickpoll' ?></title>
<link rel="stylesheet" href="<?= h($base) ?>/style.css">
<script>
// Apply saved theme before paint to avoid a flash of the wrong theme.
(function () {
  try {
    var t = localStorage.getItem('qp-theme');
    if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
  } catch (e) {}
})();
</script>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <a class="brand" href="<?= h($home) ?>">Quickpoll</a>
    <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Toggle light and dark theme" title="Toggle theme">Theme</button>
  </div>

<?php if ($notFound): ?>
  <div class="card"><p class="muted">That poll doesn't exist. <a href="<?= h($home) ?>">Create one</a>.</p></div>

<?php elseif ($view): /* ---------- POLL PAGE ---------- */ ?>
  <?php $total = array_sum(array_column($view['options'], 'votes')); ?>
  <div class="card">
    <h1><?= h($view['poll']['question']) ?></h1>

    <?php if ($locked): ?>
      <div class="flash lock">
        <?= $lockReason === 'closed'
            ? 'This poll is closed. Final results below.'
            : 'This poll has expired. Final results below.' ?>
      </div>
    <?php elseif (!empty($view['poll']['expires_at'])): ?>
      <p class="muted small">Voting closes <?= h(date('M j, Y, g:i a', strtotime((string) $view['poll']['expires_at']))) ?>.</p>
    <?php endif; ?>

    <?php if (!$voted && !$locked): ?>
      <form method="post" action="<?= h($home) ?>?p=<?= h($code) ?>" class="vote">
        <input type="hidden" name="action" value="vote">
        <?php foreach ($view['options'] as $o): ?>
          <button class="opt" name="option" value="<?= (int) $o['id'] ?>"><?= h($o['label']) ?></button>
        <?php endforeach; ?>
      </form>
      <p class="muted small"><a href="<?= h($home) ?>?p=<?= h($code) ?>&voted=1">View results without voting</a></p>
    <?php else: ?>
      <div class="results<?= $locked ? ' is-locked' : '' ?>" id="results" data-code="<?= h($code) ?>" data-base="<?= h($home) ?>" data-locked="<?= $locked ? '1' : '0' ?>">
        <?php foreach ($view['options'] as $o):
          $pct = $total ? round(100 * $o['votes'] / $total) : 0; ?>
          <div class="row" data-id="<?= (int) $o['id'] ?>">
            <div class="row-head"><span class="label"><?= h($o['label']) ?></span><span class="pct"><?= $pct ?>%</span></div>
            <div class="track"><div class="fill" style="width: <?= $pct ?>%"></div></div>
            <div class="count"><?= (int) $o['votes'] ?> vote<?= $o['votes'] == 1 ? '' : 's' ?></div>
          </div>
        <?php endforeach; ?>
        <p class="total"><b id="total"><?= (int) $total ?></b> total votes
          <?php if ($locked): ?><span class="final">final</span><?php else: ?><span class="live">live</span><?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <?php if ($isOwner && !$locked): ?>
      <form method="post" action="<?= h($home) ?>?p=<?= h($code) ?>" class="close-form"
            onsubmit="return confirm('Close this poll? Voting will stop and results become final.');">
        <input type="hidden" name="action" value="close">
        <button type="submit" class="danger">Close poll</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card share">
    <span class="muted small">Share this poll</span>
    <div class="share-row">
      <input id="share" value="<?= h($shareUrl) ?>" readonly>
      <button class="copy" data-copy="<?= h($shareUrl) ?>">Copy</button>
    </div>
  </div>

<?php else: /* ---------- CREATE PAGE ---------- */ ?>
  <div class="card">
    <h1>Create a poll</h1>
    <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" action="<?= h($home) ?>" id="create">
      <input type="hidden" name="action" value="create">
      <label class="field">
        <span>Question</span>
        <input type="text" name="question" maxlength="200" placeholder="What should we build next?" required autofocus
               value="<?= h($_POST['question'] ?? '') ?>">
      </label>
      <div class="field">
        <span>Options</span>
        <div id="options">
          <input type="text" name="options[]" maxlength="120" placeholder="Option 1" required>
          <input type="text" name="options[]" maxlength="120" placeholder="Option 2" required>
        </div>
        <button type="button" class="add" id="add">+ Add option</button>
      </div>
      <label class="field">
        <span>Voting closes</span>
        <select name="expiry" class="select">
          <option value="never"<?= (($_POST['expiry'] ?? 'never') === 'never') ? ' selected' : '' ?>>Never (until you close it)</option>
          <option value="1h"<?= (($_POST['expiry'] ?? '') === '1h') ? ' selected' : '' ?>>In 1 hour</option>
          <option value="1d"<?= (($_POST['expiry'] ?? '') === '1d') ? ' selected' : '' ?>>In 1 day</option>
        </select>
      </label>
      <button type="submit" class="primary">Create poll</button>
    </form>
  </div>
  <p class="muted small center">Polls are stored locally in SQLite. No account needed.</p>
<?php endif; ?>

  <footer>PHP <?= PHP_VERSION ?> + SQLite</footer>
</div>

<script>
// add-option button (create page)
document.getElementById('add')?.addEventListener('click', () => {
  const box = document.getElementById('options');
  if (box.children.length >= 8) return;
  const i = box.children.length + 1;
  const inp = document.createElement('input');
  inp.type = 'text'; inp.name = 'options[]'; inp.maxLength = 120; inp.placeholder = 'Option ' + i;
  box.appendChild(inp); inp.focus();
});

// copy buttons
document.addEventListener('click', async (e) => {
  const b = e.target.closest('.copy'); if (!b) return;
  try { await navigator.clipboard.writeText(b.dataset.copy); const t = b.textContent; b.textContent = 'Copied'; setTimeout(() => b.textContent = t, 1200); } catch (_) {}
});

// theme toggle, persisted in localStorage
(function () {
  const root = document.documentElement;
  const btn = document.getElementById('theme-toggle');
  const current = () => {
    const set = root.getAttribute('data-theme');
    if (set) return set;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  };
  btn?.addEventListener('click', () => {
    const next = current() === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('qp-theme', next); } catch (e) {}
  });
})();

// live results polling (results view)
const res = document.getElementById('results');
if (res) {
  const url = res.dataset.base + '?p=' + res.dataset.code + '&json=1';
  let timer = null;
  const counts = new Map();
  res.querySelectorAll('.row').forEach(row => counts.set(row.dataset.id, null));

  async function refresh() {
    try {
      const r = await fetch(url); const d = await r.json();
      const totalEl = document.getElementById('total');
      if (totalEl) totalEl.textContent = d.total;
      let changed = false;
      for (const o of d.options) {
        const row = res.querySelector('.row[data-id="' + o.id + '"]'); if (!row) continue;
        const pct = d.total ? Math.round(100 * o.votes / d.total) : 0;
        row.querySelector('.fill').style.width = pct + '%';
        row.querySelector('.pct').textContent = pct + '%';
        row.querySelector('.count').textContent = o.votes + (o.votes === 1 ? ' vote' : ' votes');
        const key = String(o.id);
        if (counts.get(key) !== null && counts.get(key) !== o.votes) {
          changed = true;
          row.classList.remove('bump');
          void row.offsetWidth; // restart animation
          row.classList.add('bump');
        }
        counts.set(key, o.votes);
      }
      if (changed) {
        res.classList.remove('pulsing');
        void res.offsetWidth;
        res.classList.add('pulsing');
      }
      if (d.locked) {
        // Poll became locked while watching: stop polling and reload for the final state.
        if (timer) clearInterval(timer);
        if (res.dataset.locked !== '1') location.reload();
      }
    } catch (_) {}
  }
  if (res.dataset.locked !== '1') {
    timer = setInterval(refresh, 2500);
  }
}
</script>
</body>
</html>
