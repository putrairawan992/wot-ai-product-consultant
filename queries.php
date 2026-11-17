<?php
/**
 * WOT AI Chat — Query Log Viewer
 * Access: /queries.php?key=YOUR_ADMIN_KEY
 * Export CSV: /queries.php?key=YOUR_ADMIN_KEY&export=csv
 */

$ADMIN_KEY = getenv('WOT_ADMIN_KEY') ?: 'wot-admin-2026';
if (($_GET['key'] ?? '') !== $ADMIN_KEY) {
    http_response_code(403);
    exit('Forbidden');
}

$DB_PATH = getenv('WOT_DB_PATH') ?: __DIR__ . '/data/queries.db';
if (!file_exists($DB_PATH)) {
    exit('No queries yet.');
}

$db = new PDO('sqlite:' . $DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── EXPORT CSV ────────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="wot-queries-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'session_id', 'message', 'created_at']);
    foreach ($db->query('SELECT * FROM queries ORDER BY created_at DESC') as $row) {
        fputcsv($out, $row);
    }
    exit;
}

// ── STATS ─────────────────────────────────────────────────────────────────────
$total    = $db->query('SELECT COUNT(*) FROM queries')->fetchColumn();
$sessions = $db->query('SELECT COUNT(DISTINCT session_id) FROM queries')->fetchColumn();
$today    = $db->query("SELECT COUNT(*) FROM queries WHERE date(created_at) = date('now')")->fetchColumn();
$rows     = $db->query('SELECT * FROM queries ORDER BY created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WOT Query Log</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f5f7fa; color: #1a1a1a; padding: 24px; }
h1 { font-size: 20px; margin-bottom: 16px; }
.stats { display: flex; gap: 16px; margin-bottom: 20px; }
.stat { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 20px; }
.stat strong { display: block; font-size: 24px; color: #1a3a5c; }
.stat span { font-size: 12px; color: #888; }
.actions { margin-bottom: 16px; }
.actions a { background: #1a3a5c; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; }
table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
th { background: #1a3a5c; color: #fff; padding: 10px 14px; text-align: left; font-size: 13px; }
td { padding: 9px 14px; border-bottom: 1px solid #f0f0f0; font-size: 13px; vertical-align: top; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8f9fb; }
.session { font-size: 11px; color: #999; font-family: monospace; }
.time { font-size: 11px; color: #999; white-space: nowrap; }
</style>
</head>
<body>
<h1>WOT AI Chat — Query Log</h1>
<div class="stats">
  <div class="stat"><strong><?= $total ?></strong><span>Total queries</span></div>
  <div class="stat"><strong><?= $sessions ?></strong><span>Unique sessions</span></div>
  <div class="stat"><strong><?= $today ?></strong><span>Today</span></div>
</div>
<div class="actions">
  <a href="?key=<?= htmlspecialchars($_GET['key']) ?>&export=csv">Export CSV</a>
</div>
<table>
  <thead><tr><th>#</th><th>Message</th><th>Session</th><th>Time</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= $r['id'] ?></td>
      <td><?= htmlspecialchars($r['message']) ?></td>
      <td class="session"><?= substr($r['session_id'], 0, 8) ?>...</td>
      <td class="time"><?= $r['created_at'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
