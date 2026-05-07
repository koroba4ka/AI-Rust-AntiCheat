<?php
require __DIR__ . '/init_db.php';
require __DIR__ . '/inference.php';
checkAdmin();           // localhost free / иначе требует X-AC-Admin
header('Content-Type: application/json');

$db     = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {

    case 'get_sessions': {
        $limit  = max(1, min((int)($_GET['limit'] ?? 50), 200));
        $filter = $_GET['filter'] ?? 'all';
        $search = $_GET['search'] ?? '';

        $where  = '1=1';
        $params = [];
        if ($filter === 'suspicious') $where .= ' AND suspicion_score > 30';
        if ($filter === 'clean')      $where .= ' AND suspicion_score <= 30';
        if ($search !== '')          { $where .= ' AND steam_id LIKE :sid'; $params[':sid'] = '%' . $search . '%'; }

        $stmt = $db->prepare(
            "SELECT id, steam_id, mode, suspicion_score, tick_count, has_anomaly, created_at
             FROM sessions WHERE $where
             ORDER BY id DESC LIMIT $limit"
        );
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    }

    case 'get_session_data': {
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT raw_json FROM sessions WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo $row ? decompressJson($row['raw_json']) : '[]';
        break;
    }

    case 'get_session_scored': {
        // Возвращает тики + per-tick risk (0..100). Для цветной визуализации.
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT raw_json FROM sessions WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error' => 'not found']); break; }

        $ticks = json_decode(decompressJson($row['raw_json']), true);
        if (!is_array($ticks)) { echo json_encode(['error' => 'bad payload']); break; }

        $model = ac_load_model();
        $risks = $model ? ac_per_tick_risk($ticks, $model) : array_fill(0, count($ticks), 0.0);

        $out = [
            'threshold' => $model ? round((float)$model['threshold'], 4) : null,
            'ticks'     => [],
        ];
        foreach ($ticks as $i => $t) {
            if (!is_array($t)) continue;
            $t['Risk'] = round((float)($risks[$i] ?? 0), 1);
            $out['ticks'][] = $t;
        }
        echo json_encode($out);
        break;
    }

    case 'get_player_history': {
        $sid  = $_GET['steam_id'] ?? '';
        $stmt = $db->prepare(
            "SELECT id, mode, suspicion_score, tick_count, has_anomaly, created_at
             FROM sessions WHERE steam_id = ? ORDER BY id DESC LIMIT 100"
        );
        $stmt->execute([$sid]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    }

    case 'get_player_baseline': {
        $sid  = $_GET['steam_id'] ?? '';
        $stmt = $db->prepare(
            "SELECT steam_id, baseline_risk, baseline_peak, sessions_count, last_seen
             FROM player_baselines WHERE steam_id = ?"
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo $row ? json_encode($row) : json_encode(null);
        break;
    }

    case 'get_stats': {
        $totalSessions   = (int)$db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
        $totalSuspicious = (int)$db->query("SELECT COUNT(*) FROM sessions WHERE suspicion_score > 30")->fetchColumn();
        $totalTicks      = (int)$db->query("SELECT COALESCE(SUM(tick_count),0) FROM sessions")->fetchColumn();
        $uniquePlayers   = (int)$db->query("SELECT COUNT(DISTINCT steam_id) FROM sessions")->fetchColumn();
        $dbSize          = file_exists(DB_FILE) ? round(filesize(DB_FILE) / 1048576, 2) : 0;
        $avgRisk         = (float)$db->query("SELECT COALESCE(AVG(suspicion_score),0) FROM sessions WHERE mode=1")->fetchColumn();
        $recentSuspect   = $db->query(
            "SELECT steam_id, suspicion_score FROM sessions
             WHERE suspicion_score > 30 ORDER BY id DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'total_sessions'   => $totalSessions,
            'total_suspicious' => $totalSuspicious,
            'total_ticks'      => $totalTicks,
            'unique_players'   => $uniquePlayers,
            'db_size_mb'       => $dbSize,
            'avg_risk'         => round($avgRisk, 2),
            'recent_suspects'  => $recentSuspect,
        ]);
        break;
    }

    case 'push_command': {
        $type    = $_POST['type']    ?? '';
        $payload = $_POST['payload'] ?? '';
        if (!is_string($type) || $type === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing type']);
            break;
        }
        $allowed = ['set_collect_mode','set_threshold','set_interval','whitelist_add','whitelist_remove','reload'];
        if (!in_array($type, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown command type']);
            break;
        }
        $stmt = $db->prepare("INSERT INTO commands (type, payload) VALUES (?, ?)");
        $stmt->execute([$type, (string)$payload]);
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        break;
    }

    case 'get_leaderboard': {
        $stmt = $db->query(
            "SELECT steam_id,
                    COUNT(*)              as sessions,
                    AVG(suspicion_score)  as avg_risk,
                    MAX(suspicion_score)  as max_risk,
                    SUM(tick_count)       as total_ticks
             FROM sessions WHERE mode = 1
             GROUP BY steam_id
             ORDER BY avg_risk DESC
             LIMIT 20"
        );
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    }

    case 'set_label': {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $label     = $_POST['label']  ?? '';
        $note      = $_POST['note']   ?? '';
        if ($sessionId <= 0 || !in_array($label, ['cheater','legit','unsure'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad input']);
            break;
        }
        $stmt = $db->prepare(
            "INSERT INTO labels (session_id, label, source, note) VALUES (?, ?, 'admin', ?)"
        );
        $stmt->execute([$sessionId, $label, (string)$note]);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
