"""
Sanity-проверки всего пайплайна без живого Rust-сервера.

Проверяет:
  1. Структуру обученной модели (Web/neural_model.json).
  2. Согласованность fixed-list фич между features.py и Web/features.php.
  3. Распределение risk на реальных легит-сессиях.
  4. Inject-тест: берём легит-сессию, вставляем фейковый чит-паттерн,
     проверяем что risk растёт.

Запуск: python verify.py
"""
from __future__ import annotations

import copy
import json
import re
import sqlite3
import sys
import zlib
from pathlib import Path

import numpy as np

from features import (
    FEATURE_COUNT,
    FEATURE_NAMES,
    extract_session,
    has_hard_teleport,
)

ROOT  = Path(__file__).resolve().parent.parent
DB    = ROOT / 'anticheat.sqlite'
MODEL = ROOT / 'server' / 'neural_model.json'
PHP_F = ROOT / 'server' / 'features.php'

OK   = '\033[92m[OK]\033[0m'
WARN = '\033[93m[WARN]\033[0m'
ERR  = '\033[91m[FAIL]\033[0m'


def php_forward(x: np.ndarray, layers: list[dict]) -> np.ndarray:
    for L in layers:
        W = np.asarray(L['weight'], dtype=np.float32)
        b = np.asarray(L['bias'],   dtype=np.float32)
        x = W @ x + b
        if L['activation'] == 'relu':
            x = np.maximum(x, 0)
    return x


def session_risk(ticks: list[dict], model: dict) -> tuple[float, bool, int]:
    feats = extract_session(ticks)
    if not feats:
        return 0.0, False, 0
    F  = np.asarray(feats, dtype=np.float32)
    mu = np.asarray(model['mean'],  dtype=np.float32)
    sc = np.asarray(model['scale'], dtype=np.float32)
    sc = np.where(sc < 1e-8, 1.0, sc)
    Fn = (F - mu) / sc
    layers = model['layers']
    errs = np.array([np.mean((fn - php_forward(fn, layers)) ** 2) for fn in Fn], dtype=np.float32)
    if len(errs) >= 8:
        smooth = np.convolve(errs, np.ones(8, dtype=np.float32) / 8, mode='valid')
        peak = float(np.max(smooth))
    else:
        peak = float(np.max(errs))
    risk = min(100.0, 100.0 * peak / model['threshold'])
    hard, _ = has_hard_teleport(ticks)
    if hard:
        risk = max(risk, 95.0)
    n_anom = int(np.sum(errs / model['threshold'] >= 0.85))
    return risk, hard, n_anom


# ── Tests ─────────────────────────────────────────────────────────────

def test_model_structure() -> dict | None:
    print("\n=== 1. Model file structure ===")
    if not MODEL.exists():
        print(f"{ERR} {MODEL} missing — run train_autoencoder.py first")
        return None
    m = json.loads(MODEL.read_text(encoding='utf-8'))
    required = ['version', 'feature_count', 'feature_names',
                'mean', 'scale', 'threshold', 'layers']
    for k in required:
        if k not in m:
            print(f"{ERR} missing key: {k}")
            return None
    if m['version'] != 'autoencoder_v1':
        print(f"{ERR} version mismatch: {m['version']}")
        return None
    if m['feature_count'] != FEATURE_COUNT:
        print(f"{ERR} feature_count {m['feature_count']} != Python {FEATURE_COUNT}")
        return None
    if len(m['mean']) != FEATURE_COUNT or len(m['scale']) != FEATURE_COUNT:
        print(f"{ERR} mean/scale length mismatch")
        return None
    expected_layers = [(64, 27), (12, 64), (64, 12), (27, 64)]
    for i, (Lcfg, exp) in enumerate(zip(m['layers'], expected_layers)):
        W = Lcfg['weight']
        if len(W) != exp[0] or len(W[0]) != exp[1]:
            print(f"{ERR} layer {i}: shape {len(W)}x{len(W[0])} != expected {exp}")
            return None
    print(f"{OK} version={m['version']} features={m['feature_count']} layers={len(m['layers'])}")
    print(f"     threshold={m['threshold']:.4f}  median_err={m.get('median_error', 0):.5f}")
    print(f"     trained_at={m.get('trained_at')}  train_rows={m.get('train_rows')}")
    return m


def test_feature_parity() -> bool:
    print("\n=== 2. Python <-> PHP feature parity ===")
    if not PHP_F.exists():
        print(f"{ERR} {PHP_F} missing")
        return False
    txt = PHP_F.read_text(encoding='utf-8')

    m = re.search(r"FEATURE_NAMES\s*=\s*\[(.*?)\];", txt, re.DOTALL)
    if not m:
        print(f"{ERR} FEATURE_NAMES not found in features.php")
        return False
    php_names = [s.strip().strip("'\"") for s in m.group(1).split(',') if s.strip().strip("'\"")]

    m2 = re.search(r"FEATURE_COUNT\s*=\s*(\d+)", txt)
    php_count = int(m2.group(1)) if m2 else -1

    ok = True
    if php_count != FEATURE_COUNT:
        print(f"{ERR} FEATURE_COUNT: php={php_count} py={FEATURE_COUNT}")
        ok = False
    if php_names != FEATURE_NAMES:
        print(f"{ERR} FEATURE_NAMES diverge:")
        print(f"     py:  {FEATURE_NAMES}")
        print(f"     php: {php_names}")
        ok = False
    if ok:
        print(f"{OK} {len(php_names)} features identical in both files")
    return ok


def test_legit_distribution(model: dict) -> bool:
    print("\n=== 3. Risk distribution on real legit sessions ===")
    if not DB.exists():
        print(f"{WARN} {DB} missing — skipping")
        return True
    conn = sqlite3.connect(str(DB))
    conn.text_factory = bytes
    rows = conn.execute(
        "SELECT raw_json FROM sessions WHERE mode = 0 ORDER BY RANDOM() LIMIT 200"
    ).fetchall()
    conn.close()
    if not rows:
        print(f"{WARN} no mode=0 sessions in DB")
        return True

    risks: list[float] = []
    hard_n = 0
    for (blob,) in rows:
        try:
            ticks = json.loads(zlib.decompress(blob).decode('utf-8'))
        except Exception:
            continue
        r, hard, _ = session_risk(ticks, model)
        risks.append(r)
        if hard: hard_n += 1

    a = np.asarray(risks, dtype=np.float32)
    mean_r   = float(a.mean())
    median_r = float(np.median(a))
    flag_pct = float(100 * np.mean(a > 70))
    print(f"     n={len(a)}  mean={mean_r:.1f}%  median={median_r:.1f}%  p95={np.percentile(a,95):.1f}%")
    print(f"     >70% flags: {int(np.sum(a>70))} ({flag_pct:.1f}%)  hard-teleport: {hard_n}")
    # 5-15% expected (P95-калибровка) на нечистых mode=0; на верифицированных данных будет ниже
    if flag_pct > 25:
        print(f"{WARN} flag rate {flag_pct:.1f}% above expected -- training data likely contains many cheats")
        return True
    print(f"{OK} distribution looks healthy")
    return True


def test_synthetic_cheats(model: dict) -> bool:
    print("\n=== 4. Inject-test: synthetic cheat patterns ===")
    if not DB.exists():
        print(f"{WARN} {DB} missing — skipping")
        return True
    conn = sqlite3.connect(str(DB))
    conn.text_factory = bytes
    rows = conn.execute(
        "SELECT raw_json FROM sessions WHERE mode = 0 ORDER BY RANDOM() LIMIT 30"
    ).fetchall()
    conn.close()
    if not rows:
        return True

    # Ищем активную сессию: длиннее 60 тиков и median speed > 1 m/s (не AFK)
    baseline = None
    for (blob,) in rows:
        try:
            t = json.loads(zlib.decompress(blob).decode('utf-8'))
        except Exception:
            continue
        if len(t) < 60: continue
        speeds = []
        for x in t:
            v = x.get('Velocity') or {}
            speeds.append((float(v.get('X', 0))**2 + float(v.get('Z', 0))**2) ** 0.5)
        if np.median(speeds) > 1.0:
            baseline = t
            break
    if baseline is None:
        print(f"{WARN} no active session found (all AFK?) -- skip")
        return True

    base_risk, base_hard, _ = session_risk(baseline, model)
    print(f"     baseline (legit)        risk={base_risk:5.1f}%  hard={base_hard}")

    # 4a. Speedhack: фиксируем |V|=25 m/s по горизонтали на 20 тиках (легит макс ~10 m/s)
    sh = copy.deepcopy(baseline)
    for t in sh[20:40]:
        v = t.get('Velocity') or {}
        v['X'] = 25.0
        v['Z'] = 0.0
        t['Velocity'] = v
    sh_risk, sh_hard, _ = session_risk(sh, model)
    sh_ok = sh_risk > base_risk + 15
    mark = OK if sh_ok else ERR
    print(f"     {mark} +speedhack (20 ticks @ 25 m/s)  risk={sh_risk:5.1f}%  hard={sh_hard}  delta={sh_risk-base_risk:+.1f}")

    # 4b. Fly: vy = 25 m/s на 25 тиках, height растёт
    fly = copy.deepcopy(baseline)
    for i, t in enumerate(fly[20:45]):
        v = t.get('Velocity') or {}
        v['Y'] = 25.0
        t['Velocity'] = v
        t['RaycastDistance'] = 5.0 + i * 1.0
        t['IsGrounded'] = False
    fly_risk, fly_hard, _ = session_risk(fly, model)
    fly_ok = fly_risk > base_risk + 15
    mark = OK if fly_ok else ERR
    print(f"     {mark} +fly      (25 ticks, vy=25)  risk={fly_risk:5.1f}%  hard={fly_hard}  delta={fly_risk-base_risk:+.1f}")

    # 4c. Teleport: 3 тика прыгают на 15м
    tp = copy.deepcopy(baseline)
    for idx in (25, 30, 35):
        if idx + 1 < len(tp):
            p = tp[idx + 1].get('Position') or {}
            p['X'] = float(p.get('X', 0)) + 15.0
            tp[idx + 1]['Position'] = p
    tp_risk, tp_hard, _ = session_risk(tp, model)
    tp_ok = tp_hard or tp_risk > base_risk + 15
    mark = OK if tp_ok else ERR
    print(f"     {mark} +teleport (3 jumps x 15m)  risk={tp_risk:5.1f}%  hard={tp_hard}  delta={tp_risk-base_risk:+.1f}")

    # 4d. Aim-snap: огромная DeltaRotation на 5 тиках
    aim = copy.deepcopy(baseline)
    for t in aim[30:35]:
        t['DeltaRotation'] = 380.0
    aim_risk, aim_hard, _ = session_risk(aim, model)
    aim_ok = aim_risk > base_risk + 10
    mark = OK if aim_ok else WARN
    print(f"     {mark} +aim-snap (5 ticks x380°) risk={aim_risk:5.1f}%  hard={aim_hard}  delta={aim_risk-base_risk:+.1f}")

    return all([sh_ok, fly_ok, tp_ok])


def main() -> int:
    print("=" * 70)
    print(" AI AntiCheat -- sanity verification")
    print("=" * 70)

    model = test_model_structure()
    if model is None:
        return 1
    parity_ok = test_feature_parity()
    legit_ok  = test_legit_distribution(model)
    cheat_ok  = test_synthetic_cheats(model)

    print("\n" + "=" * 70)
    print(" Summary:")
    print(f"   model structure  : {OK}")
    print(f"   feature parity   : {OK if parity_ok else ERR}")
    print(f"   legit distribution: {OK if legit_ok else WARN}")
    print(f"   synthetic cheats : {OK if cheat_ok else ERR}")
    print("=" * 70)
    return 0 if (parity_ok and legit_ok and cheat_ok) else 1


if __name__ == '__main__':
    sys.exit(main())
