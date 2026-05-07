"""Feature extractor for movement ticks. Mirrors server/features.php — keep them in sync."""
import math
from collections import deque

FEATURE_NAMES = [
    "speed_adj", "vy", "height", "abs_vy", "delta_rotation",
    "delta_speed", "delta_vy", "water_level",
    "is_grounded", "is_swimming", "is_ducked", "is_sprinting", "is_flying", "has_parent",
    "position_residual", "rot_accel", "h2v_ratio",
    "gravity_residual", "air_time", "vy_persist", "ground_to_air_anomaly", "physics_pos_residual",
    "win_speed_std", "win_vy_max", "win_vy_min", "win_air_fraction", "win_grav_residual_mean",
]
FEATURE_COUNT = len(FEATURE_NAMES)

HEIGHT_CAP    = 50.0
SPEED_CAP     = 100.0
ROT_CAP       = 720.0
RESIDUAL_CAP  = 50.0
ROT_ACC_CAP   = 5000.0
PING_LIMIT_MS = 250
GRAVITY       = 9.81
AIR_TIME_CAP  = 5.0
WINDOW_SIZE   = 16

HARD_TELEPORT_RESIDUAL    = 100.0
HARD_TELEPORT_MIN_SPIKES  = 2

_PARENT_WHITELIST = {
    "cargoship", "minicopter", "scraptransporthelicopter",
    "rhib", "rowboat", "motorrowboat", "tugboat",
    "ch47scientists.entity", "hotairballoon",
}


def _f(d, key, default=0.0):
    v = d.get(key, default)
    try:
        return float(v) if v is not None else float(default)
    except (TypeError, ValueError):
        return float(default)


def _b(d, key):
    return 1.0 if d.get(key) else 0.0


def _pos(t):
    p = t.get("Position") or {}
    return _f(p, "X"), _f(p, "Y"), _f(p, "Z")


def should_skip_tick(tick: dict) -> bool:
    dt = _f(tick, "DeltaTime", 0.05)
    if dt < 0.005 or dt > 2.0:
        return True
    ping = _f(tick, "Ping", 0)
    if ping > PING_LIMIT_MS:
        return True
    return False


def new_session_state() -> dict:
    """Состояние, которое extract_features несёт через сессию."""
    return {
        "air_time":   0.0,
        "vy_streak":  0.0,
        "window":     deque(maxlen=WINDOW_SIZE),  # хранит dict-ы расчётных значений
    }


def _physics_applicable(tick: dict) -> bool:
    """Когда gravity-проверка имеет смысл."""
    if tick.get("IsGrounded"):     return False
    if tick.get("IsSwimming"):     return False
    if tick.get("HasParent"):      return False
    if _f(tick, "WaterLevel") > 0.3: return False
    return True


def extract_features(tick: dict, prev: dict | None, state: dict) -> list[float]:
    """
    Превращает один tick в фиксированный вектор FEATURE_COUNT элементов.
    state мутируется внутри (накопление air_time, vy_streak, window).
    """
    dt = max(_f(tick, "DeltaTime", 0.05), 0.005)

    has_parent  = bool(tick.get("HasParent"))
    parent_name = str(tick.get("ParentName") or "none").lower()
    on_known_vehicle = has_parent and parent_name in _PARENT_WHITELIST

    pvx = _f(tick.get("ParentVelocity", {}) or {}, "X") if has_parent else 0.0
    pvz = _f(tick.get("ParentVelocity", {}) or {}, "Z") if has_parent else 0.0

    vel = tick.get("Velocity") or {}
    vx  = _f(vel, "X") - pvx
    vz  = _f(vel, "Z") - pvz
    vy  = _f(vel, "Y")
    speed = math.sqrt(vx * vx + vz * vz)

    cs = _f(tick, "ClothingSpeed", 1.0)
    if cs <= 0: cs = 1.0
    cs = max(0.5, min(2.0, cs))
    speed_adj = min(speed / cs, SPEED_CAP)
    vy_capped = max(-SPEED_CAP, min(SPEED_CAP, vy))

    height = _f(tick, "RaycastDistance", 0)
    if height < 0: height = HEIGHT_CAP
    height = min(height, HEIGHT_CAP)

    delta_rot = abs(_f(tick, "DeltaRotation"))
    rot       = min(delta_rot, ROT_CAP) / dt

    # Derived 14..16
    if prev is not None:
        prev_vel = prev.get("Velocity") or {}
        p_vx = _f(prev_vel, "X")
        p_vz = _f(prev_vel, "Z")
        p_vy = _f(prev_vel, "Y")
        prev_speed = math.sqrt(p_vx * p_vx + p_vz * p_vz)
        delta_speed = (speed - prev_speed) / dt
        delta_vy    = (vy    - p_vy)    / dt

        if on_known_vehicle or has_parent:
            position_residual = 0.0
            physics_pos_residual = 0.0
        else:
            cx, cy, cz = _pos(tick)
            px, py, pz = _pos(prev)
            # Кинематический прогноз (без physics)
            ex = px + p_vx * dt
            ey = py + p_vy * dt
            ez = pz + p_vz * dt
            position_residual = math.sqrt((cx-ex)**2 + (cy-ey)**2 + (cz-ez)**2) / dt
            # Physics-aware прогноз: добавляем гравитацию по Y когда не на земле
            if not prev.get("IsGrounded") and not prev.get("IsSwimming"):
                ey_phys = py + p_vy * dt - 0.5 * GRAVITY * dt * dt
            else:
                ey_phys = py + p_vy * dt
            physics_pos_residual = math.sqrt(
                (cx-ex)**2 + (cy-ey_phys)**2 + (cz-ez)**2
            ) / dt
        prev_rot  = abs(_f(prev, "DeltaRotation"))
        rot_accel = (delta_rot - prev_rot) / dt
    else:
        delta_speed = 0.0
        delta_vy    = 0.0
        position_residual = 0.0
        physics_pos_residual = 0.0
        rot_accel = 0.0

    water = max(0.0, min(1.0, _f(tick, "WaterLevel")))
    h2v   = min(speed_adj / (abs(vy_capped) + 0.5), 50.0)
    has_parent_score = 1.0 if on_known_vehicle else (0.5 if has_parent else 0.0)

    # ── PHYSICS (17..21) ────────────────────────────────────────────
    physics_ok = _physics_applicable(tick)
    if physics_ok and prev is not None:
        prev_vy = _f(prev.get("Velocity") or {}, "Y")
        # ожидаемое vy в свободном падении/прыжке
        gravity_residual = abs((vy - prev_vy) / dt + GRAVITY)
    else:
        gravity_residual = 0.0
    gravity_residual = min(gravity_residual, 100.0)

    # air_time / vy_streak обновляются здесь
    if tick.get("IsGrounded") or tick.get("IsSwimming"):
        state["air_time"]  = 0.0
        state["vy_streak"] = 0.0
    else:
        state["air_time"] = min(state["air_time"] + dt, AIR_TIME_CAP)
        if vy > 0.5:
            state["vy_streak"] += dt
        else:
            state["vy_streak"] = 0.0

    # ground-to-air аномалия: если в прошлом тике был на земле, а сейчас в воздухе,
    # vy при отрыве должно быть значимым (jump impulse ~5-7 m/s).
    # Если vy < 1 и игрок взлетел — подозрение на noclip/lift.
    if (prev is not None and prev.get("IsGrounded")
            and not tick.get("IsGrounded") and not tick.get("IsSwimming")):
        ground_to_air_anomaly = max(0.0, 4.0 - vy)  # больше = хуже
    else:
        ground_to_air_anomaly = 0.0

    # ── WINDOW AGGREGATES (22..26) ──────────────────────────────────
    # сохраняем для текущего тика расчётные значения
    state["window"].append({
        "speed":       speed_adj,
        "vy":          vy_capped,
        "is_grounded": 1.0 if tick.get("IsGrounded") else 0.0,
        "grav_res":    gravity_residual if physics_ok else None,
    })
    win = state["window"]
    if len(win) >= 4:
        speeds = [w["speed"] for w in win]
        vys    = [w["vy"]    for w in win]
        n = len(speeds)
        m_s = sum(speeds) / n
        win_speed_std = math.sqrt(sum((s - m_s) ** 2 for s in speeds) / n)
        win_vy_max    = max(vys)
        win_vy_min    = min(vys)
        win_air_frac  = sum(1.0 - w["is_grounded"] for w in win) / n
        grs = [w["grav_res"] for w in win if w["grav_res"] is not None]
        win_grav_mean = (sum(grs) / len(grs)) if grs else 0.0
    else:
        win_speed_std = win_vy_max = win_vy_min = win_air_frac = win_grav_mean = 0.0

    return [
        # 0..13
        speed_adj, vy_capped, height, abs(vy_capped), rot,
        max(-SPEED_CAP*10, min(SPEED_CAP*10, delta_speed)),
        max(-SPEED_CAP*10, min(SPEED_CAP*10, delta_vy)),
        water,
        _b(tick, "IsGrounded"), _b(tick, "IsSwimming"),
        _b(tick, "IsDucked"), _b(tick, "IsSprinting"), _b(tick, "IsFlying"),
        has_parent_score,
        # 14..16
        min(position_residual, RESIDUAL_CAP),
        max(-ROT_ACC_CAP, min(ROT_ACC_CAP, rot_accel)),
        h2v,
        # 17..21
        gravity_residual,
        state["air_time"],
        min(state["vy_streak"], AIR_TIME_CAP),
        min(ground_to_air_anomaly, 10.0),
        min(physics_pos_residual, RESIDUAL_CAP),
        # 22..26
        min(win_speed_std, SPEED_CAP),
        max(-SPEED_CAP, min(SPEED_CAP, win_vy_max)),
        max(-SPEED_CAP, min(SPEED_CAP, win_vy_min)),
        win_air_frac,
        min(win_grav_mean, 100.0),
    ]


def extract_session(ticks: list[dict]) -> list[list[float]]:
    """Применяет extract_features ко всей сессии, скипая мусорные тики."""
    out = []
    state = new_session_state()
    prev  = None
    for t in ticks:
        if should_skip_tick(t):
            prev = t
            continue
        out.append(extract_features(t, prev, state))
        prev = t
    return out


def has_hard_teleport(ticks: list[dict]) -> tuple[bool, float]:
    """Hard rule: ≥HARD_TELEPORT_MIN_SPIKES тиков с residual ≥ HARD_TELEPORT_RESIDUAL."""
    max_res = 0.0
    spikes  = 0
    prev = None
    for t in ticks:
        if should_skip_tick(t):
            prev = t
            continue
        if prev is not None and not (t.get("HasParent") or False):
            dt = max(_f(t, "DeltaTime", 0.05), 0.005)
            cx, cy, cz = _pos(t)
            px, py, pz = _pos(prev)
            pv = prev.get("Velocity") or {}
            ex = px + _f(pv, "X") * dt
            ey = py + _f(pv, "Y") * dt
            ez = pz + _f(pv, "Z") * dt
            r  = math.sqrt((cx-ex)**2 + (cy-ey)**2 + (cz-ez)**2) / dt
            if r > max_res: max_res = r
            if r >= HARD_TELEPORT_RESIDUAL: spikes += 1
        prev = t
    return spikes >= HARD_TELEPORT_MIN_SPIKES, max_res
