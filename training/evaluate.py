"""
Оценка обученной модели по реальным меткам админа.

Берёт сессии из таблицы `labels` (label = cheater | legit | unsure),
прогоняет ту же логику, что валидатор PHP (без HTTP — напрямую на Python),
и считает precision/recall + распределение risk по классам.

Запуск: python evaluate.py
"""
from __future__ import annotations

import json
import sqlite3
import zlib
from pathlib import Path

import numpy as np

from features import FEATURE_COUNT, extract_session, has_hard_teleport, new_session_state, extract_features

ROOT      = Path(__file__).resolve().parent.parent
DB_FILE   = ROOT / 'anticheat.sqlite'
MODEL     = ROOT / 'server' / 'neural_model.json'
SMOOTH    = 8
FLAG_RAT  = 0.85


def php_forward(x: np.ndarray, layers: list[dict]) -> np.ndarray:
    for L in layers:
        W = np.array(L['weight'], dtype=np.float32)
        b = np.array(L['bias'],   dtype=np.float32)
        x = W @ x + b
        if L['activation'] == 'relu':
            x = np.maximum(x, 0)
    return x


def session_risk(ticks: list[dict], model: dict) -> tuple[float, int, float, bool]:
    feats = extract_session(ticks)
    if not feats:
        return 0.0, 0, 0.0, False
    F = np.asarray(feats, dtype=np.float32)
    mean  = np.asarray(model['mean'],  dtype=np.float32)
    scale = np.asarray(model['scale'], dtype=np.float32)
    scale = np.where(scale < 1e-8, 1.0, scale)
    Fn = (F - mean) / scale

    layers = model['layers']
    errs = np.empty(len(Fn), dtype=np.float32)
    for i, fn in enumerate(Fn):
        recon = php_forward(fn, layers)
        errs[i] = float(np.mean((fn - recon) ** 2))

    n_anom    = int(np.sum(errs / model['threshold'] >= FLAG_RAT))
    avg_err   = float(np.mean(errs))
    if len(errs) >= SMOOTH:
        # peak smoothed window (зеркало validate.php)
        kernel = np.ones(SMOOTH, dtype=np.float32) / SMOOTH
        smooth = np.convolve(errs, kernel, mode='valid')
        peak   = float(np.max(smooth))
    else:
        peak = float(np.max(errs))

    risk = min(100.0, 100.0 * peak / model['threshold'])
    hard, _ = has_hard_teleport(ticks)
    if hard:
        risk = max(risk, 95.0)
    return risk, n_anom, avg_err, hard


def main() -> None:
    if not MODEL.exists():
        raise SystemExit(f"Model not found: {MODEL}. Run train_autoencoder.py first.")
    if not DB_FILE.exists():
        raise SystemExit(f"DB not found: {DB_FILE}")

    model = json.loads(MODEL.read_text(encoding='utf-8'))
    if model.get('feature_count') != FEATURE_COUNT:
        raise SystemExit(
            f"feature_count mismatch: model={model.get('feature_count')} code={FEATURE_COUNT}. "
            f"Re-run training."
        )

    conn = sqlite3.connect(str(DB_FILE))
    conn.text_factory = bytes
    rows = conn.execute(
        """SELECT s.id, s.steam_id, l.label, s.suspicion_score, s.raw_json
           FROM labels l
           JOIN sessions s ON s.id = l.session_id
           WHERE l.label IN ('cheater', 'legit')
           ORDER BY l.id DESC"""
    ).fetchall()
    conn.close()

    if not rows:
        print("No labels yet. Mark sessions in the dashboard first (cheater/legit buttons).")
        return

    print(f"Evaluating on {len(rows)} labelled sessions...\n")
    by_class: dict[str, list[float]] = {'cheater': [], 'legit': []}
    confusion = {'tp': 0, 'fp': 0, 'tn': 0, 'fn': 0}
    threshold_pct = 70.0
    rows_out = []

    for sid, steam, label, stored_score, blob in rows:
        try:
            ticks = json.loads(zlib.decompress(blob).decode('utf-8'))
        except Exception:
            continue
        label_str = label.decode() if isinstance(label, (bytes, bytearray)) else str(label)
        steam_str = steam.decode() if isinstance(steam, (bytes, bytearray)) else str(steam)
        risk, n_anom, avg_err, hard = session_risk(ticks, model)
        by_class[label_str].append(risk)
        predicted_cheater = risk >= threshold_pct
        actual_cheater    = label_str == 'cheater'
        if predicted_cheater and actual_cheater: confusion['tp'] += 1
        elif predicted_cheater:                   confusion['fp'] += 1
        elif actual_cheater:                      confusion['fn'] += 1
        else:                                     confusion['tn'] += 1
        rows_out.append((sid, steam_str, label_str, risk, n_anom, hard))

    def stats(name: str, vals: list[float]) -> None:
        if not vals:
            print(f"  {name:<8}: no samples")
            return
        a = np.asarray(vals)
        print(f"  {name:<8}: n={len(a):3d}  mean={a.mean():6.2f}%  median={np.median(a):6.2f}%  "
              f"p25={np.percentile(a,25):6.2f}%  p75={np.percentile(a,75):6.2f}%  max={a.max():6.2f}%")

    print("Risk distribution by class:")
    stats('legit',   by_class['legit'])
    stats('cheater', by_class['cheater'])

    tp, fp, tn, fn = confusion['tp'], confusion['fp'], confusion['tn'], confusion['fn']
    precision = tp / (tp + fp) if (tp + fp) else 0.0
    recall    = tp / (tp + fn) if (tp + fn) else 0.0
    f1        = 2 * precision * recall / (precision + recall) if (precision + recall) else 0.0
    print(f"\nClassification @ risk >= {threshold_pct}%:")
    print(f"  TP={tp}  FP={fp}  TN={tn}  FN={fn}")
    print(f"  precision={precision:.3f}  recall={recall:.3f}  F1={f1:.3f}")

    print(f"\nLast 15 sessions:")
    print(f"  {'id':>5} {'steam':<20} {'label':<8} {'risk':>7} {'anom':>5} {'hard':>5}")
    for sid, steam, label, risk, n_anom, hard in rows_out[:15]:
        print(f"  {sid:>5} {steam:<20} {label:<8} {risk:>6.1f}% {n_anom:>5} {'Y' if hard else '':>5}")


if __name__ == '__main__':
    main()
