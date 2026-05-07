<?php
require __DIR__ . '/init_db.php';
checkSecret();
header('Content-Type: application/json');

$db     = getDB();
$action = $_GET['action'] ?? '';

if ($action === 'poll') {
    // Return unprocessed commands and mark them done
    $stmt = $db->query("SELECT id, type, payload FROM commands WHERE done = 0 ORDER BY id ASC LIMIT 10");
    $cmds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($cmds)) {
        $ids  = implode(',', array_column($cmds, 'id'));
        $db->exec("UPDATE commands SET done = 1 WHERE id IN ($ids)");
    }

    echo json_encode($cmds ?: []);

} elseif ($action === 'push') {
    // Dashboard pushes a command
    $type    = $_POST['type']    ?? '';
    $payload = $_POST['payload'] ?? '';
    if (!$type) { http_response_code(400); die('Missing type'); }

    $stmt = $db->prepare("INSERT INTO commands (type, payload) VALUES (?, ?)");
    $stmt->execute([$type, $payload]);
    echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
}