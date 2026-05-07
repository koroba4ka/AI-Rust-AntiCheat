<?php
// Shared scoring helpers — используются validate.php (live inference)
// и api.php (post-hoc per-tick scoring для дашборда).

require_once __DIR__ . '/features.php';

function ac_load_model(): ?array {
    $f = __DIR__ . '/neural_model.json';
    if (!file_exists($f)) return null;
    $m = json_decode(file_get_contents($f), true);
    if (!is_array($m) || ($m['version'] ?? '') !== 'autoencoder_v1') return null;
    if ((int)($m['feature_count'] ?? 0) !== FEATURE_COUNT) return null;
    return $m;
}

function ac_forward(array $x, array $layers): array {
    foreach ($layers as $layer) {
        $W   = $layer['weight'];
        $b   = $layer['bias'];
        $act = $layer['activation'];
        $rows = count($W);
        $cols = count($x);
        $out  = [];
        for ($j = 0; $j < $rows; $j++) {
            $sum = (float)$b[$j];
            $row = $W[$j];
            for ($i = 0; $i < $cols; $i++) {
                $sum += (float)$row[$i] * (float)$x[$i];
            }
            if ($act === 'relu' && $sum < 0) $sum = 0.0;
            $out[$j] = $sum;
        }
        $x = $out;
    }
    return $x;
}

function ac_tick_mse(array $tick, ?array $prev, array &$state, array $model): float {
    $feat   = ac_extract_features($tick, $prev, $state);
    $mean   = $model['mean'];
    $scale  = $model['scale'];
    $layers = $model['layers'];

    $norm = [];
    for ($i = 0; $i < FEATURE_COUNT; $i++) {
        $s = (float)$scale[$i];
        if ($s < 1e-8) $s = 1.0;
        $norm[$i] = ((float)$feat[$i] - (float)$mean[$i]) / $s;
    }
    $recon = ac_forward($norm, $layers);

    $mse = 0.0;
    for ($i = 0; $i < FEATURE_COUNT; $i++) {
        $d = $norm[$i] - $recon[$i];
        $mse += $d * $d;
    }
    return $mse / FEATURE_COUNT;
}

/**
 * Per-tick risk percentage (0..100), сглаженный окном.
 * Возвращает массив той же длины что $ticks; для skipped тиков — 0 после smoothing.
 */
function ac_per_tick_risk(array $ticks, array $model, int $window = 8): array {
    $threshold = max(1e-6, (float)$model['threshold']);
    $errs = [];
    $prev  = null;
    $state = ac_new_session_state();
    foreach ($ticks as $i => $t) {
        if (!is_array($t) || ac_should_skip_tick($t)) {
            $errs[$i] = null;
            $prev = $t;
            continue;
        }
        $errs[$i] = ac_tick_mse($t, $prev, $state, $model);
        $prev = $t;
    }

    $half = (int)floor($window / 2);
    $n    = count($ticks);
    $out  = [];
    for ($i = 0; $i < $n; $i++) {
        $sum = 0.0; $cnt = 0;
        $lo = max(0, $i - $half);
        $hi = min($n - 1, $i + $half);
        for ($j = $lo; $j <= $hi; $j++) {
            if ($errs[$j] !== null) { $sum += $errs[$j]; $cnt++; }
        }
        if ($cnt === 0) {
            $out[$i] = 0.0;
        } else {
            $r = 100.0 * ($sum / $cnt) / $threshold;
            if ($r < 0) $r = 0.0;
            if ($r > 100) $r = 100.0;
            $out[$i] = $r;
        }
    }
    return $out;
}
