<?php
require __DIR__ . '/init_db.php';
require __DIR__ . '/features.php';
require __DIR__ . '/inference.php';
checkSecret();
header('Content-Type: application/json');

const ANOMALY_FLAG_RATIO = 0.85;
const SMOOTH_WINDOW      = 8;

$model = ac_load_model();
if ($model === null) {
    http_response_code(503);
    die(json_encode([['Error' => 'neural_model.json missing or invalid. Re-run train_autoencoder.py.']]));
}
$threshold = max(1e-6, (float)$model['threshold']);

$input = json_decode(ac_get_body(), true);

// Per-player baseline: deviation from a player's own EMA risk catches
// "good player who started cheating yesterday" that the global model misses.
const BASELINE_EMA_ALPHA       = 0.15;
const BASELINE_TRUST_AFTER     = 5;
const BASELINE_DEVIATION_BOOST = 1.5;
const BASELINE_FREEZE_RISK     = 60.0;  // suspicious sessions don't update baseline

function ac_get_baseline(PDO $db, string $sid): ?array {
    $st = $db->prepare("SELECT baseline_risk, baseline_peak, sessions_count
                        FROM player_baselines WHERE steam_id = ?");
    $st->execute([$sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ac_update_baseline(PDO $db, string $sid, float $risk): void {
    $cur = ac_get_baseline($db, $sid);
    if ($risk > BASELINE_FREEZE_RISK) {
        // Don't move baseline on suspicious sessions, otherwise a cheater raises their own baseline.
        if ($cur === null) {
            $db->prepare("INSERT INTO player_baselines
                          (steam_id, baseline_risk, baseline_peak, sessions_count)
                          VALUES (?, 0, ?, 1)")->execute([$sid, $risk]);
        } else {
            $db->prepare("UPDATE player_baselines
                          SET sessions_count = sessions_count + 1,
                              baseline_peak  = MAX(baseline_peak, ?),
                              last_seen      = CURRENT_TIMESTAMP
                          WHERE steam_id = ?")->execute([$risk, $sid]);
        }
        return;
    }
    if ($cur === null) {
        $db->prepare("INSERT INTO player_baselines
                      (steam_id, baseline_risk, baseline_peak, sessions_count)
                      VALUES (?, ?, ?, 1)")->execute([$sid, $risk, $risk]);
    } else {
        $newBase = (1 - BASELINE_EMA_ALPHA) * (float)$cur['baseline_risk']
                   + BASELINE_EMA_ALPHA * $risk;
        $newPeak = max((float)$cur['baseline_peak'], $risk);
        $db->prepare("UPDATE player_baselines
                      SET baseline_risk  = ?,
                          baseline_peak  = ?,
                          sessions_count = sessions_count + 1,
                          last_seen      = CURRENT_TIMESTAMP
                      WHERE steam_id = ?")->execute([$newBase, $newPeak, $sid]);
    }
}

$response   = [];
$db         = getDB();
$insertStmt = $db->prepare(
    "INSERT INTO sessions (steam_id, mode, suspicion_score, tick_count, has_anomaly, raw_json)
     VALUES (?, 1, ?, ?, ?, ?)"
);

if ($input && is_array($input)) {
    foreach ($input as $sid => $ticks) {
        if (!is_array($ticks) || count($ticks) === 0) continue;

        // Hard rule: явные телепорты ловятся без модели
        [$hardFlag, $maxResidual] = ac_has_hard_teleport($ticks);

        // Per-tick risk той же длины что входной массив (для ddraw в плагине)
        $perTickRisks = ac_per_tick_risk($ticks, $model);

        $prev          = null;
        $state         = ac_new_session_state();
        $tickErrors    = [];
        $suspPositions = [];

        foreach ($ticks as $t) {
            if (!is_array($t)) continue;
            if (ac_should_skip_tick($t)) { $prev = $t; continue; }

            $mse  = ac_tick_mse($t, $prev, $state, $model);
            $prev = $t;
            $tickErrors[] = $mse;

            if (($mse / $threshold) >= ANOMALY_FLAG_RATIO &&
                isset($t['Position']) &&
                count($suspPositions) < 25)
            {
                $suspPositions[] = $t['Position'];
            }
        }

        $count     = count($tickErrors);
        $smoothMax = 0.0;
        $smoothAvg = 0.0;
        if ($count > 0) {
            $win    = min(SMOOTH_WINDOW, $count);
            $sumWin = 0.0;
            for ($i = 0; $i < $win; $i++) $sumWin += $tickErrors[$i];
            $smoothMax = $sumWin / $win;
            for ($i = $win; $i < $count; $i++) {
                $sumWin += $tickErrors[$i] - $tickErrors[$i - $win];
                $avg = $sumWin / $win;
                if ($avg > $smoothMax) $smoothMax = $avg;
            }
            $smoothAvg = array_sum($tickErrors) / $count;
        }

        $riskRatio = $smoothMax / $threshold;
        if ($riskRatio > 1.5) $riskRatio = 1.5;
        $risk = min(100.0, $riskRatio * 100.0);

        // Hard teleport overrides модельный score — если был явный телепорт,
        // выставляем минимум 95% (минус стрим из лаговых пакетов всё ещё могут проскочить).
        if ($hardFlag) {
            $risk = max($risk, 95.0);
        }

        // ── Per-player baseline adjustment ──
        $rawRisk      = $risk;
        $baseline     = ac_get_baseline($db, (string)$sid);
        $baselineRisk = null;
        $sessionsSeen = 0;
        if ($baseline !== null) {
            $baselineRisk = (float)$baseline['baseline_risk'];
            $sessionsSeen = (int)$baseline['sessions_count'];
            // Если у нас достаточно профайла — boost'им отклонение от baseline.
            // Игрок 5%-baseline с сессией 25% получает ~35% (значимое отклонение).
            // Игрок 30%-baseline с сессией 35% получает ~37% (его норма).
            if ($sessionsSeen >= BASELINE_TRUST_AFTER) {
                $deviation = max(0.0, $rawRisk - $baselineRisk);
                $risk = min(100.0, $baselineRisk + $deviation * BASELINE_DEVIATION_BOOST);
            }
        }
        ac_update_baseline($db, (string)$sid, $rawRisk);

        $anomalyTicks = 0;
        foreach ($tickErrors as $e) {
            if (($e / $threshold) >= ANOMALY_FLAG_RATIO) $anomalyTicks++;
        }

        $compressed = compressJson(json_encode($ticks));
        $insertStmt->execute([(string)$sid, round($risk, 2), $count, $anomalyTicks > 0 ? 1 : 0, $compressed]);

        // Округляем до int чтобы payload был компактным
        $perTickRisksInt = array_map(fn($r) => (int)round($r), $perTickRisks);

        $response[] = [
            'SteamID'         => (string)$sid,
            'Risk'            => round($risk, 2),
            'RawRisk'         => round($rawRisk, 2),
            'Baseline'        => $baselineRisk !== null ? round($baselineRisk, 2) : null,
            'SessionsSeen'    => $sessionsSeen,
            'Ticks'           => $count,
            'Anomalies'       => $anomalyTicks,
            'AvgError'        => round($smoothAvg, 6),
            'PeakError'       => round($smoothMax, 6),
            'Threshold'       => round($threshold, 6),
            'HardFlag'        => $hardFlag,
            'MaxResidual'     => round($maxResidual, 3),
            'SuspiciousTicks' => $suspPositions,
            'TickRisks'       => $perTickRisksInt,
        ];
    }
}

echo json_encode($response);
