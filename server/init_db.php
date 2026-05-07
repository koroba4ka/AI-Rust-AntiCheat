<?php
require_once __DIR__ . '/config.php';

define('DB_FILE', __DIR__ . '/anticheat.sqlite');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL");
    $pdo->exec("PRAGMA synchronous=NORMAL");
    $pdo->exec("PRAGMA cache_size=10000");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        steam_id        TEXT    NOT NULL,
        mode            INTEGER NOT NULL DEFAULT 0,
        suspicion_score REAL    DEFAULT 0,
        tick_count      INTEGER NOT NULL,
        has_anomaly     INTEGER DEFAULT 0,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        raw_json        BLOB    NOT NULL
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_steam_id   ON sessions(steam_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_created_at ON sessions(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_score      ON sessions(suspicion_score)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS commands (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        type       TEXT NOT NULL,
        payload    TEXT,
        done       INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS labels (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        label      TEXT    NOT NULL,    -- 'cheater' | 'legit' | 'unsure'
        source     TEXT    NOT NULL,    -- 'admin' | 'vac' | 'auto'
        note       TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_label_session ON labels(session_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stats (
        key   TEXT PRIMARY KEY,
        value TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS player_baselines (
        steam_id        TEXT PRIMARY KEY,
        baseline_risk   REAL NOT NULL DEFAULT 0,
        baseline_peak   REAL NOT NULL DEFAULT 0,
        sessions_count  INTEGER NOT NULL DEFAULT 0,
        last_seen       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    return $pdo;
}

// php://input is a stream — cache so HMAC check and body parsing both see it.
function ac_get_body(): string {
    static $body = null;
    if ($body === null) $body = (string)file_get_contents('php://input');
    return $body;
}

function checkSecret(): void {
    $ts  = $_SERVER['HTTP_X_AC_TS']  ?? '';
    $sig = $_SERVER['HTTP_X_AC_SIG'] ?? '';
    if ($ts !== '' && $sig !== '') {
        if (abs(time() - (int)$ts) > 60) {
            http_response_code(403);
            die(json_encode(['error' => 'Stale timestamp']));
        }
        $expected = hash_hmac('sha256', $ts . "\n" . ac_get_body(), AC_SECRET);
        if (!hash_equals($expected, $sig)) {
            http_response_code(403);
            die(json_encode(['error' => 'Bad signature']));
        }
        return;
    }
    $secret = $_SERVER['HTTP_X_AC_SECRET'] ?? '';
    if (!is_string($secret) || !hash_equals(AC_SECRET, $secret)) {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden']));
    }
}

function isLocalRequest(): bool {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($remote, ['127.0.0.1', '::1', 'localhost'], true);
}

function checkAdmin(): void {
    if (AC_ALLOW_LOCAL && isLocalRequest()) return;

    $supplied = $_SERVER['HTTP_X_AC_ADMIN'] ?? ($_GET['admin'] ?? '');
    if (!is_string($supplied) || !hash_equals(AC_ADMIN_SECRET, $supplied)) {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden — admin secret required']));
    }
}

function compressJson(string $json): string {
    return gzcompress($json, 6);
}
function decompressJson(string $blob): string {
    $r = @gzuncompress($blob);
    return $r === false ? '[]' : $r;
}
