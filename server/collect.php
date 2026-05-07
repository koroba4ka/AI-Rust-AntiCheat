<?php
require __DIR__ . '/init_db.php';
checkSecret();

$json_data = ac_get_body();
if (!$json_data) { http_response_code(400); die("Bad Request"); }

$data = json_decode($json_data, true);
if (!$data) { http_response_code(400); die("Invalid JSON"); }

$db   = getDB();
$stmt = $db->prepare("INSERT INTO sessions (steam_id, mode, tick_count, raw_json) VALUES (?, 0, ?, ?)");

foreach ($data as $steamId => $ticks) {
    if (!is_array($ticks) || count($ticks) === 0) continue;
    $compressed = compressJson(json_encode($ticks));
    $stmt->execute([$steamId, count($ticks), $compressed]);
}

// Обновить счётчик сессий
$db->exec("INSERT OR REPLACE INTO stats (key, value) VALUES ('total_sessions',
    COALESCE((SELECT value FROM stats WHERE key='total_sessions'), 0) + 1)");

http_response_code(200);
echo "OK";