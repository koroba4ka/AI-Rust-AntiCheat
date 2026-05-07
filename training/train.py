"""Train anomaly-detection autoencoder on legit movement sessions."""
from __future__ import annotations

import json
import sqlite3
import time
import zlib
from pathlib import Path

import numpy as np
import torch
from torch import nn
from torch.utils.data import DataLoader, TensorDataset

from features import (
    FEATURE_COUNT,
    FEATURE_NAMES,
    extract_session,
)

ROOT          = Path(__file__).resolve().parent.parent
DB_FILE       = ROOT / 'anticheat.sqlite'
OUT_JSON      = ROOT / 'server' / 'neural_model.json'
OUT_PT        = ROOT / 'autoencoder.pt'

SEED              = 1337
MAX_SESSIONS      = 20000
MAX_TICKS_PER     = 800
HIDDEN            = 64
LATENT            = 12
EPOCHS            = 60
BATCH_SIZE        = 4096
LR                = 1e-3
WEIGHT_DECAY      = 1e-5
PATIENCE          = 6
HOLDOUT_FRAC      = 0.15
SESSION_PCTL      = 95.0
SMOOTH_WINDOW     = 8         # must match SMOOTH_WINDOW in server/validate.php
REFINE_PASSES     = 1
REFINE_DROP_PCTL  = 99.0
NUM_THREADS       = 0         # 0 = all cores; set to 4 to keep CPU free for gameplay

torch.manual_seed(SEED)
np.random.seed(SEED)
if NUM_THREADS > 0:
    torch.set_num_threads(NUM_THREADS)
device = torch.device('cuda' if torch.cuda.is_available() else 'cpu')


class Autoencoder(nn.Module):
    def __init__(self, dim: int, hidden: int, latent: int):
        super().__init__()
        self.encoder = nn.Sequential(
            nn.Linear(dim, hidden),
            nn.ReLU(),
            nn.Linear(hidden, latent),
        )
        self.decoder = nn.Sequential(
            nn.Linear(latent, hidden),
            nn.ReLU(),
            nn.Linear(hidden, dim),
        )

    def forward(self, x):
        return self.decoder(self.encoder(x))


def load_features() -> tuple[np.ndarray, list[np.ndarray]]:
    """Возвращает (плоский массив тиков для обучения, список фич по сессиям для калибровки)."""
    if not DB_FILE.exists():
        raise SystemExit(f"DB not found: {DB_FILE}")
    print(f"[1/6] Loading sessions from {DB_FILE}")
    conn = sqlite3.connect(str(DB_FILE))
    conn.text_factory = bytes
    rows = conn.execute(
        "SELECT raw_json FROM sessions WHERE mode = 0 LIMIT ?",
        (MAX_SESSIONS,),
    ).fetchall()
    conn.close()
    print(f"      sessions fetched: {len(rows)}")

    all_feats: list[list[float]] = []
    sessions: list[np.ndarray] = []
    skipped = 0
    for (blob,) in rows:
        try:
            ticks = json.loads(zlib.decompress(blob).decode('utf-8'))
        except Exception:
            skipped += 1
            continue
        feats = extract_session(ticks)
        if not feats:
            continue
        full = np.asarray(feats, dtype=np.float32)
        sessions.append(full)
        if len(feats) > MAX_TICKS_PER:
            idx = np.random.choice(len(feats), MAX_TICKS_PER, replace=False)
            feats = [feats[i] for i in sorted(idx)]
        all_feats.extend(feats)

    if not all_feats:
        raise SystemExit("No usable feature rows after filtering. Collect more data.")
    arr = np.asarray(all_feats, dtype=np.float32)
    print(f"      tick rows: {arr.shape[0]:,} | features per tick: {arr.shape[1]} | "
          f"sessions: {len(sessions)} | skipped: {skipped}")
    return arr, sessions


def fit_normalizer(X: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Robust scaling: median + IQR. Меньше зависит от выбросов."""
    med = np.median(X, axis=0)
    q1  = np.percentile(X, 25, axis=0)
    q3  = np.percentile(X, 75, axis=0)
    iqr = (q3 - q1)
    iqr = np.where(iqr < 1e-6, 1.0, iqr)
    return med.astype(np.float32), iqr.astype(np.float32)


def model_to_dict(model: Autoencoder) -> list[dict]:
    enc1, _, enc2 = model.encoder
    dec1, _, dec2 = model.decoder
    layers = [
        (enc1, 'relu'),
        (enc2, 'linear'),
        (dec1, 'relu'),
        (dec2, 'linear'),
    ]
    out = []
    for layer, act in layers:
        W = layer.weight.detach().cpu().numpy()
        b = layer.bias.detach().cpu().numpy()
        out.append({
            'weight': W.tolist(),
            'bias':   b.tolist(),
            'activation': act,
        })
    return out


def train_one_pass(Xtr: np.ndarray, Xte: np.ndarray, label: str) -> tuple[Autoencoder, float]:
    print(f"      [{label}] dim={FEATURE_COUNT} hidden={HIDDEN} latent={LATENT} | train={len(Xtr):,} val={len(Xte):,}")
    model = Autoencoder(FEATURE_COUNT, HIDDEN, LATENT).to(device)
    opt   = torch.optim.AdamW(model.parameters(), lr=LR, weight_decay=WEIGHT_DECAY)
    crit  = nn.MSELoss()

    tr_loader = DataLoader(
        TensorDataset(torch.from_numpy(Xtr)),
        batch_size=BATCH_SIZE, shuffle=True, drop_last=False,
    )
    te_t = torch.from_numpy(Xte).to(device)

    best_val   = float('inf')
    best_state = None
    bad        = 0
    for epoch in range(1, EPOCHS + 1):
        model.train()
        running, n = 0.0, 0
        for (xb,) in tr_loader:
            xb = xb.to(device, non_blocking=True)
            opt.zero_grad(set_to_none=True)
            recon = model(xb)
            loss  = crit(recon, xb)
            loss.backward()
            opt.step()
            running += loss.item() * xb.size(0)
            n += xb.size(0)
        train_loss = running / max(n, 1)

        model.eval()
        with torch.no_grad():
            val_loss = crit(model(te_t), te_t).item()

        improved = val_loss < best_val - 1e-5
        if improved:
            best_val   = val_loss
            best_state = {k: v.detach().clone() for k, v in model.state_dict().items()}
            bad = 0
        else:
            bad += 1

        if epoch % 10 == 0 or improved:
            mark = '*' if improved else ' '
            print(f"      [{label}] ep {epoch:3d} {mark} train={train_loss:.5f}  val={val_loss:.5f}")

        if bad >= PATIENCE:
            print(f"      [{label}] early stop @ ep {epoch}")
            break

    if best_state is not None:
        model.load_state_dict(best_state)
    return model, best_val


def reconstruction_errors(model: Autoencoder, Xn: np.ndarray) -> np.ndarray:
    model.eval()
    chunks = []
    with torch.no_grad():
        for i in range(0, len(Xn), 65536):
            t = torch.from_numpy(Xn[i:i + 65536]).to(device)
            r = model(t).cpu().numpy()
            chunks.append(np.mean((Xn[i:i + 65536] - r) ** 2, axis=1))
    return np.concatenate(chunks) if chunks else np.array([])


def main() -> None:
    t0 = time.time()
    X, sessions = load_features()

    print(f"[2/6] Robust scaling")
    mean, scale = fit_normalizer(X)
    Xn = ((X - mean) / scale).astype(np.float32)

    print(f"[3/6] Train/holdout split ({1.0 - HOLDOUT_FRAC:.0%}/{HOLDOUT_FRAC:.0%})")
    perm = np.random.permutation(len(Xn))
    cut = int(len(Xn) * (1.0 - HOLDOUT_FRAC))
    Xtr, Xte = Xn[perm[:cut]], Xn[perm[cut:]]

    print(f"[4/6] Initial training")
    model, val = train_one_pass(Xtr, Xte, label="init")

    for p in range(REFINE_PASSES):
        print(f"[4.{p+1}/6] Robust refinement pass {p+1}: dropping top-{100 - REFINE_DROP_PCTL:.1f}% outliers")
        errs_tr = reconstruction_errors(model, Xtr)
        cut_err = float(np.percentile(errs_tr, REFINE_DROP_PCTL))
        keep = errs_tr <= cut_err
        kept = int(keep.sum())
        print(f"        dropped {len(Xtr) - kept:,} of {len(Xtr):,} (cut at MSE={cut_err:.5f})")
        Xtr2 = Xtr[keep]
        model, val = train_one_pass(Xtr2, Xte, label=f"refine{p+1}")

    print(f"[5/6] Calibrating threshold on session-level smoothed peaks (P{SESSION_PCTL:.0f})")
    # Симуляция инференса: для каждой легит-сессии считаем peak-of-smoothed-window — то же,
    # что делает validate.php. Берём P95 этих пиков как порог: ~5% легит-сессий получит risk≥100%.
    session_peaks: list[float] = []
    tick_errs_all: list[float] = []
    for sess in sessions:
        Sn = ((sess - mean) / np.where(scale < 1e-8, 1.0, scale)).astype(np.float32)
        errs = reconstruction_errors(model, Sn)
        if len(errs) == 0: continue
        tick_errs_all.append(errs)
        if len(errs) >= SMOOTH_WINDOW:
            kernel = np.ones(SMOOTH_WINDOW, dtype=np.float32) / SMOOTH_WINDOW
            smooth = np.convolve(errs, kernel, mode='valid')
            session_peaks.append(float(np.max(smooth)))
        else:
            session_peaks.append(float(np.max(errs)))

    peaks_arr  = np.asarray(session_peaks, dtype=np.float32)
    threshold  = float(np.percentile(peaks_arr, SESSION_PCTL))
    median_pk  = float(np.median(peaks_arr))
    p99_pk     = float(np.percentile(peaks_arr, 99.0))
    median_err = float(np.median(np.concatenate(tick_errs_all))) if tick_errs_all else 0.0
    print(f"      session peaks: n={len(peaks_arr)}  median={median_pk:.4f}  P{SESSION_PCTL:.0f}={threshold:.4f}  P99={p99_pk:.4f}")
    print(f"      tick MSE median: {median_err:.5f}")

    print(f"[6/6] Exporting model")
    OUT_JSON.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        'version':       'autoencoder_v1',
        'feature_count': FEATURE_COUNT,
        'feature_names': FEATURE_NAMES,
        'mean':          mean.tolist(),
        'scale':         scale.tolist(),
        'threshold':     threshold,
        'median_error':  median_err,
        'session_p99':   p99_pk,
        'layers':        model_to_dict(model),
        'trained_at':    int(time.time()),
        'train_rows':    int(len(Xtr)),
        'holdout_rows':  int(len(Xte)),
        'refine_passes': REFINE_PASSES,
        'calibration':   f'session_peak_p{int(SESSION_PCTL)}',
    }
    with OUT_JSON.open('w', encoding='utf-8') as f:
        json.dump(payload, f)
    torch.save(model.state_dict(), str(OUT_PT))
    elapsed = time.time() - t0
    size_mb = OUT_JSON.stat().st_size / (1024 * 1024)
    print(f"\nDONE in {elapsed:.1f}s")
    print(f"  -> {OUT_JSON}  ({size_mb:.2f} MB)")
    print(f"  -> {OUT_PT}")


if __name__ == '__main__':
    main()
