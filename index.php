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
        'total'   => (int) $total,
        'options' => array_map(fn($o) => ['id' => (int) $o['id'], 'label' => $o['label'], 'votes' => (int) $o['votes']], $data['options']),
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
        $newCode = make_code($pdo);
        $pdo->prepare('INSERT INTO polls (code, question, created_at) VALUES (?, ?, ?)')
            ->execute([$newCode, mb_substr($question, 0, 200), date('c')]);
        $pid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO options (poll_id, label) VALUES (?, ?)');
        foreach (array_slice($options, 0, 8) as $label) {
            $ins->execute([$pid, mb_substr($label, 0, 120)]);
        }
        header('Location: ' . $home . '?p=' . $newCode);
        exit;
    }
}

/* ---- cast a vote ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote' && $code !== '') {
    $data = load_poll($pdo, $code);
    $optionId = (int) ($_POST['option'] ?? 0);
    $valid = $data && in_array($optionId, array_map(fn($o) => (int) $o['id'], $data['options']), true);
    $already = isset($_COOKIE['qp_' . $code]);
    if ($valid && !$already) {
        $pdo->prepare('UPDATE options SET votes = votes + 1 WHERE id = ? AND poll_id = ?')
            ->execute([$optionId, $data['poll']['id']]);
        setcookie('qp_' . $code, '1', time() + 60 * 60 * 24 * 365, $home);
    }
    header('Location: ' . $home . '?p=' . $code . '&voted=1');
    exit;
}

$view = $code !== '' ? load_poll($pdo, $code) : null;
$notFound = $code !== '' && $view === null;
$voted = $view && (isset($_COOKIE['qp_' . $code]) || isset($_GET['voted']));
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
</head>
<body>
<div class="wrap">
  <a class="brand" href="<?= h($home) ?>">Quickpoll</a>

<?php if ($notFound): ?>
  <div class="card"><p class="muted">That poll doesn't exist. <a href="<?= h($home) ?>">Create one</a>.</p></div>

<?php elseif ($view): /* ---------- POLL PAGE ---------- */ ?>
  <?php $total = array_sum(array_column($view['options'], 'votes')); ?>
  <div class="card">
    <h1><?= h($view['poll']['question']) ?></h1>

    <?php if (!$voted): ?>
      <form method="post" action="<?= h($home) ?>?p=<?= h($code) ?>" class="vote">
        <input type="hidden" name="action" value="vote">
        <?php foreach ($view['options'] as $o): ?>
          <button class="opt" name="option" value="<?= (int) $o['id'] ?>"><?= h($o['label']) ?></button>
        <?php endforeach; ?>
      </form>
      <p class="muted small"><a href="<?= h($home) ?>?p=<?= h($code) ?>&voted=1">View results without voting</a></p>
    <?php else: ?>
      <div class="results" id="results" data-code="<?= h($code) ?>" data-base="<?= h($home) ?>">
        <?php foreach ($view['options'] as $o):
          $pct = $total ? round(100 * $o['votes'] / $total) : 0; ?>
          <div class="row" data-id="<?= (int) $o['id'] ?>">
            <div class="row-head"><span class="label"><?= h($o['label']) ?></span><span class="pct"><?= $pct ?>%</span></div>
            <div class="track"><div class="fill" style="width: <?= $pct ?>%"></div></div>
            <div class="count"><?= (int) $o['votes'] ?> vote<?= $o['votes'] == 1 ? '' : 's' ?></div>
          </div>
        <?php endforeach; ?>
        <p class="total"><b id="total"><?= (int) $total ?></b> total votes <span class="live">live</span></p>
      </div>
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

// live results polling (results view)
const res = document.getElementById('results');
if (res) {
  const url = res.dataset.base + '?p=' + res.dataset.code + '&json=1';
  async function refresh() {
    try {
      const r = await fetch(url); const d = await r.json();
      document.getElementById('total').textContent = d.total;
      for (const o of d.options) {
        const row = res.querySelector('.row[data-id="' + o.id + '"]'); if (!row) continue;
        const pct = d.total ? Math.round(100 * o.votes / d.total) : 0;
        row.querySelector('.fill').style.width = pct + '%';
        row.querySelector('.pct').textContent = pct + '%';
        row.querySelector('.count').textContent = o.votes + (o.votes === 1 ? ' vote' : ' votes');
      }
    } catch (_) {}
  }
  setInterval(refresh, 2500);
}
</script>
</body>
</html>
