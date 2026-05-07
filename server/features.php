<?php
// Mirrors training/features.py — keep them in sync.

const FEATURE_NAMES = [
    'speed_adj','vy','height','abs_vy','delta_rotation',
    'delta_speed','delta_vy','water_level',
    'is_grounded','is_swimming','is_ducked','is_sprinting','is_flying','has_parent',
    'position_residual','rot_accel','h2v_ratio',
    'gravity_residual','air_time','vy_persist','ground_to_air_anomaly','physics_pos_residual',
    'win_speed_std','win_vy_max','win_vy_min','win_air_fraction','win_grav_residual_mean',
];
const FEATURE_COUNT = 27;

const HEIGHT_CAP    = 50.0;
const SPEED_CAP     = 100.0;
const ROT_CAP       = 720.0;
const RESIDUAL_CAP  = 50.0;
const ROT_ACC_CAP   = 5000.0;
const PING_LIMIT_MS = 250;
const GRAVITY       = 9.81;
const AIR_TIME_CAP  = 5.0;
const WINDOW_SIZE   = 16;

const HARD_TELEPORT_RESIDUAL   = 100.0;
const HARD_TELEPORT_MIN_SPIKES = 2;

const PARENT_WHITELIST = [
    'cargoship','minicopter','scraptransporthelicopter',
    'rhib','rowboat','motorrowboat','tugboat',
    'ch47scientists.entity','hotairballoon',
];

function ac_f(array $d, string $key, float $default = 0.0): float {
    if (!isset($d[$key])) return $default;
    $v = $d[$key];
    if (!is_numeric($v)) return $default;
    return (float)$v;
}
function ac_b(array $d, string $key): float {
    return !empty($d[$key]) ? 1.0 : 0.0;
}
function ac_pos(array $t): array {
    $p = (isset($t['Position']) && is_array($t['Position'])) ? $t['Position'] : [];
    return [ac_f($p, 'X'), ac_f($p, 'Y'), ac_f($p, 'Z')];
}

function ac_should_skip_tick(array $t): bool {
    $dt = ac_f($t, 'DeltaTime', 0.05);
    if ($dt < 0.005 || $dt > 2.0) return true;
    $ping = ac_f($t, 'Ping');
    if ($ping > PING_LIMIT_MS) return true;
    return false;
}

function ac_new_session_state(): array {
    return ['air_time' => 0.0, 'vy_streak' => 0.0, 'window' => []];
}

function ac_physics_applicable(array $t): bool {
    if (!empty($t['IsGrounded']))  return false;
    if (!empty($t['IsSwimming']))  return false;
    if (!empty($t['HasParent']))   return false;
    if (ac_f($t, 'WaterLevel') > 0.3) return false;
    return true;
}

function ac_extract_features(array $t, ?array $prev, array &$state): array {
    $dt = max(ac_f($t, 'DeltaTime', 0.05), 0.005);

    $hasParent  = !empty($t['HasParent']);
    $parentName = strtolower((string)($t['ParentName'] ?? 'none'));
    $onKnown    = $hasParent && in_array($parentName, PARENT_WHITELIST, true);

    $parentVel = ($hasParent && isset($t['ParentVelocity']) && is_array($t['ParentVelocity']))
        ? $t['ParentVelocity'] : [];
    $pvx = ac_f($parentVel, 'X');
    $pvz = ac_f($parentVel, 'Z');

    $vel = (isset($t['Velocity']) && is_array($t['Velocity'])) ? $t['Velocity'] : [];
    $vx = ac_f($vel, 'X') - $pvx;
    $vz = ac_f($vel, 'Z') - $pvz;
    $vy = ac_f($vel, 'Y');
    $speed = sqrt($vx * $vx + $vz * $vz);

    $cs = ac_f($t, 'ClothingSpeed', 1.0);
    if ($cs <= 0) $cs = 1.0;
    $cs = max(0.5, min(2.0, $cs));
    $speedAdj = min($speed / $cs, SPEED_CAP);
    $vyCapped = max(-SPEED_CAP, min(SPEED_CAP, $vy));

    $height = ac_f($t, 'RaycastDistance', 0);
    if ($height < 0) $height = HEIGHT_CAP;
    $height = min($height, HEIGHT_CAP);

    $deltaRot = abs(ac_f($t, 'DeltaRotation'));
    $rot      = min($deltaRot, ROT_CAP) / $dt;

    // ── derived 14..16 ─────────────────────────────────────
    $deltaSpeed = 0.0; $deltaVy = 0.0;
    $positionResidual = 0.0; $physicsPosResidual = 0.0;
    $rotAccel = 0.0;
    if ($prev !== null) {
        $pVel = (isset($prev['Velocity']) && is_array($prev['Velocity'])) ? $prev['Velocity'] : [];
        $p_vx = ac_f($pVel, 'X'); $p_vz = ac_f($pVel, 'Z'); $p_vy = ac_f($pVel, 'Y');
        $prevSpeed  = sqrt($p_vx * $p_vx + $p_vz * $p_vz);
        $deltaSpeed = ($speed - $prevSpeed) / $dt;
        $deltaVy    = ($vy    - $p_vy)    / $dt;

        if (!$onKnown && !$hasParent) {
            [$cx, $cy, $cz] = ac_pos($t);
            [$px, $py, $pz] = ac_pos($prev);
            $ex = $px + $p_vx * $dt;
            $ey = $py + $p_vy * $dt;
            $ez = $pz + $p_vz * $dt;
            $positionResidual = sqrt(($cx-$ex)**2 + ($cy-$ey)**2 + ($cz-$ez)**2) / $dt;
            if (empty($prev['IsGrounded']) && empty($prev['IsSwimming'])) {
                $eyPhys = $py + $p_vy * $dt - 0.5 * GRAVITY * $dt * $dt;
            } else {
                $eyPhys = $py + $p_vy * $dt;
            }
            $physicsPosResidual = sqrt(($cx-$ex)**2 + ($cy-$eyPhys)**2 + ($cz-$ez)**2) / $dt;
        }
        $prevRot  = abs(ac_f($prev, 'DeltaRotation'));
        $rotAccel = ($deltaRot - $prevRot) / $dt;
    }

    $water = max(0.0, min(1.0, ac_f($t, 'WaterLevel')));
    $h2v   = min($speedAdj / (abs($vyCapped) + 0.5), 50.0);
    $hasParentScore = $onKnown ? 1.0 : ($hasParent ? 0.5 : 0.0);

    // ── PHYSICS 17..21 ─────────────────────────────────────
    $physOk = ac_physics_applicable($t);
    $gravityResidual = 0.0;
    if ($physOk && $prev !== null) {
        $prevVy = ac_f((isset($prev['Velocity']) && is_array($prev['Velocity'])) ? $prev['Velocity'] : [], 'Y');
        $gravityResidual = abs(($vy - $prevVy) / $dt + GRAVITY);
    }
    $gravityResidual = min($gravityResidual, 100.0);

    if (!empty($t['IsGrounded']) || !empty($t['IsSwimming'])) {
        $state['air_time']  = 0.0;
        $state['vy_streak'] = 0.0;
    } else {
        $state['air_time'] = min($state['air_time'] + $dt, AIR_TIME_CAP);
        if ($vy > 0.5) $state['vy_streak'] += $dt;
        else           $state['vy_streak']  = 0.0;
    }

    $groundToAir = 0.0;
    if ($prev !== null && !empty($prev['IsGrounded'])
            && empty($t['IsGrounded']) && empty($t['IsSwimming'])) {
        $groundToAir = max(0.0, 4.0 - $vy);
    }

    // ── WINDOW 22..26 ──────────────────────────────────────
    $state['window'][] = [
        'speed'       => $speedAdj,
        'vy'          => $vyCapped,
        'is_grounded' => !empty($t['IsGrounded']) ? 1.0 : 0.0,
        'grav_res'    => $physOk ? $gravityResidual : null,
    ];
    if (count($state['window']) > WINDOW_SIZE) {
        array_shift($state['window']);
    }
    $win = $state['window'];
    $winSpeedStd = $winVyMax = $winVyMin = $winAirFrac = $winGravMean = 0.0;
    $n = count($win);
    if ($n >= 4) {
        $speeds = array_column($win, 'speed');
        $vys    = array_column($win, 'vy');
        $m_s = array_sum($speeds) / $n;
        $sumSq = 0.0;
        foreach ($speeds as $s) $sumSq += ($s - $m_s) ** 2;
        $winSpeedStd = sqrt($sumSq / $n);
        $winVyMax    = max($vys);
        $winVyMin    = min($vys);
        $airTicks    = 0; $grSum = 0.0; $grN = 0;
        foreach ($win as $w) {
            $airTicks += (1.0 - $w['is_grounded']);
            if ($w['grav_res'] !== null) { $grSum += $w['grav_res']; $grN++; }
        }
        $winAirFrac  = $airTicks / $n;
        $winGravMean = $grN > 0 ? $grSum / $grN : 0.0;
    }

    return [
        $speedAdj, $vyCapped, $height, abs($vyCapped), $rot,
        max(-SPEED_CAP*10, min(SPEED_CAP*10, $deltaSpeed)),
        max(-SPEED_CAP*10, min(SPEED_CAP*10, $deltaVy)),
        $water,
        ac_b($t, 'IsGrounded'), ac_b($t, 'IsSwimming'),
        ac_b($t, 'IsDucked'),   ac_b($t, 'IsSprinting'),
        ac_b($t, 'IsFlying'),   $hasParentScore,
        min($positionResidual, RESIDUAL_CAP),
        max(-ROT_ACC_CAP, min(ROT_ACC_CAP, $rotAccel)),
        $h2v,
        $gravityResidual,
        $state['air_time'],
        min($state['vy_streak'], AIR_TIME_CAP),
        min($groundToAir, 10.0),
        min($physicsPosResidual, RESIDUAL_CAP),
        min($winSpeedStd, SPEED_CAP),
        max(-SPEED_CAP, min(SPEED_CAP, $winVyMax)),
        max(-SPEED_CAP, min(SPEED_CAP, $winVyMin)),
        $winAirFrac,
        min($winGravMean, 100.0),
    ];
}

function ac_has_hard_teleport(array $ticks): array {
    $maxR = 0.0; $spikes = 0; $prev = null;
    foreach ($ticks as $t) {
        if (!is_array($t) || ac_should_skip_tick($t)) { $prev = $t; continue; }
        if ($prev !== null && empty($t['HasParent'])) {
            $dt = max(ac_f($t, 'DeltaTime', 0.05), 0.005);
            [$cx, $cy, $cz] = ac_pos($t);
            [$px, $py, $pz] = ac_pos($prev);
            $pv = (isset($prev['Velocity']) && is_array($prev['Velocity'])) ? $prev['Velocity'] : [];
            $ex = $px + ac_f($pv, 'X') * $dt;
            $ey = $py + ac_f($pv, 'Y') * $dt;
            $ez = $pz + ac_f($pv, 'Z') * $dt;
            $r  = sqrt(($cx-$ex)**2 + ($cy-$ey)**2 + ($cz-$ez)**2) / $dt;
            if ($r > $maxR) $maxR = $r;
            if ($r >= HARD_TELEPORT_RESIDUAL) $spikes++;
        }
        $prev = $t;
    }
    return [$spikes >= HARD_TELEPORT_MIN_SPIKES, $maxR];
}
