using System;
using System.Collections.Generic;
using System.Security.Cryptography;
using System.Text;
using Newtonsoft.Json;
using Oxide.Core;
using Oxide.Core.Libraries;
using UnityEngine;
using Network;

namespace Oxide.Plugins
{
    [Info("AI AntiCheat", "SeniorDev", "2.2.0")]
    [Description("Anomaly-detection AntiCheat (autoencoder backend) with full telemetry collection")]
    public class AIAntiCheat : RustPlugin
    {
        // ───────────────────────── CONFIG ─────────────────────────

        private Configuration _cfg;

        private class Configuration
        {
            [JsonProperty("Server Secret (X-AC-Secret, must match config.php)")]
            public string Secret = "REPLACE_WITH_LONG_RANDOM_STRING";

            [JsonProperty("Validate Endpoint")]
            public string ValidateEndpoint = "https://your-domain.example/AIAntiCheat/validate.php";

            [JsonProperty("Collect Endpoint")]
            public string CollectEndpoint = "https://your-domain.example/AIAntiCheat/collect.php";

            [JsonProperty("Command Endpoint")]
            public string CommandEndpoint = "https://your-domain.example/AIAntiCheat/cmd.php";

            [JsonProperty("Send Interval (sec)")]
            public float Interval = 15f;

            [JsonProperty("Risk Threshold (%) for admin notification")]
            public float Threshold = 30f;

            [JsonProperty("Auto-Action Threshold (%)")]
            public float AutoActionThreshold = 75f;

            [JsonProperty("Auto-Action Type (none|log|kick)")]
            public string AutoAction = "log";

            [JsonProperty("Draw Duration (sec)")]
            public float DrawDuration = 90f;

            [JsonProperty("Collect Mode")]
            public bool CollectMode = true;

            [JsonProperty("Debug Logging")]
            public bool Debug = false;

            [JsonProperty("Max Retries on HTTP Failure")]
            public int MaxRetries = 3;

            [JsonProperty("Ignored SteamIDs")]
            public List<string> Whitelist = new List<string>();
        }

        protected override void LoadDefaultConfig()
        {
            _cfg = new Configuration();
            SaveConfig();
        }

        protected override void LoadConfig()
        {
            base.LoadConfig();
            try
            {
                _cfg = Config.ReadObject<Configuration>();
                if (_cfg == null) LoadDefaultConfig();
            }
            catch
            {
                LoadDefaultConfig();
            }
        }

        protected override void SaveConfig() => Config.WriteObject(_cfg);

        // ───────────────────────── DATA TYPES ─────────────────────

        public class TickData
        {
            public float DeltaTime;
            public Vec3 Position;
            public Vec3 Velocity;
            public float DeltaDistance;
            public float RaycastDistance;
            public float WaterLevel;
            public float DeltaRotation;
            public bool HasParent;
            public string ParentName;
            public Vec3 ParentVelocity;
            public Vec3 ParentPosition;
            public bool IsGrounded;
            public bool IsSwimming;
            public bool IsDucked;
            public bool IsSprinting;
            public bool IsFlying;
            public int Ping;
            [JsonIgnore] public bool IsAnomaly = false;
        }

        public struct Vec3
        {
            public float X, Y, Z;
            public Vec3(Vector3 v) { X = v.x; Y = v.y; Z = v.z; }
            public Vector3 ToVector3() { return new Vector3(X, Y, Z); }
        }

        public class ApiResult
        {
            [JsonProperty("SteamID")]         public string     SteamID;
            [JsonProperty("Risk")]            public float      Risk;
            [JsonProperty("Ticks")]           public int        Ticks;
            [JsonProperty("Anomalies")]       public int        Anomalies;
            [JsonProperty("AvgError")]        public float      AvgError;
            [JsonProperty("PeakError")]       public float      PeakError;
            [JsonProperty("Threshold")]       public float      Threshold;
            [JsonProperty("SuspiciousTicks")] public List<Vec3> SuspiciousTicks;
            [JsonProperty("TickRisks")]       public List<int>  TickRisks;     // per-tick risk 0..100
        }

        public class RemoteCommand
        {
            [JsonProperty("type")]    public string Type;
            [JsonProperty("payload")] public string Payload;
        }

        private class PendingBatch
        {
            public string Endpoint;
            public string Payload;
            public bool   ExpectsValidate;
            public int    Attempts;
            public Dictionary<ulong, List<TickData>> History; // для draw/auto-action
        }

        // ───────────────────────── STATE ──────────────────────────

        private readonly Dictionary<ulong, List<TickData>> _buffer    = new Dictionary<ulong, List<TickData>>();
        private readonly Dictionary<ulong, Vector3>        _lastPos   = new Dictionary<ulong, Vector3>();
        private readonly Dictionary<ulong, float>          _lastTime  = new Dictionary<ulong, float>();
        private readonly Dictionary<ulong, Quaternion>     _lastRot   = new Dictionary<ulong, Quaternion>();
        private readonly Queue<PendingBatch>               _retryQueue = new Queue<PendingBatch>();

        private int     _groundMask;
        private bool    _collectMode;
        private int     _totalSessions;
        private int     _totalAnomalies;

        // ───────────────────────── LIFECYCLE ──────────────────────

        private void OnServerInitialized()
        {
            _collectMode = _cfg.CollectMode;
            _groundMask  = LayerMask.GetMask("Terrain", "World", "Construction");

            timer.Every(_cfg.Interval, FlushAndSend);
            timer.Every(30f, PollCommands);
            timer.Every(20f, DrainRetryQueue);

            Puts("[AI-AC v2.2.0] Started | Mode: " + (_collectMode ? "COLLECT" : "VALIDATE")
                 + " | Interval: " + _cfg.Interval + "s"
                 + " | AutoAction: " + _cfg.AutoAction + " @ " + _cfg.AutoActionThreshold + "%");
        }

        private void Unload()
        {
            // Не пытаемся флашить на Unload — Oxide WebRequests падает с NRE,
            // если плагин уничтожается до того, как worker успевает стартовать HTTP.
            // Данные за последние ~15 сек теряются при reload, это допустимо.
            _buffer.Clear();
            _retryQueue.Clear();
        }

        // ───────────────────────── HOOKS ──────────────────────────

        private void OnPlayerTick(BasePlayer player, PlayerTick msg)
        {
            if (player == null || player.IsNpc || player.isMounted) return;
            if (_cfg.Whitelist.Contains(player.UserIDString)) return;

            ulong sid = player.userID;
            Vector3 pos = player.transform.position;
            float now = UnityEngine.Time.time;

            if (!_lastPos.ContainsKey(sid))
            {
                _lastPos[sid]  = pos;
                _lastTime[sid] = now;
                _lastRot[sid]  = player.eyes.rotation;
                return;
            }

            List<TickData> buf;
            if (!_buffer.TryGetValue(sid, out buf))
            {
                buf = new List<TickData>(512);
                _buffer[sid] = buf;
            }

            float groundDist = -1f;
            RaycastHit hit;
            if (Physics.Raycast(pos + new Vector3(0f, 0.1f, 0f), Vector3.down, out hit, 100f, _groundMask))
            {
                groundDist = hit.distance;
            }

            Quaternion curRot = player.eyes.rotation;
            float rotDelta    = Quaternion.Angle(_lastRot[sid], curRot);

            BaseEntity parent = player.GetParentEntity();
            bool hasParent    = parent != null;
            string parentName = "none";
            Vec3 parentVel    = new Vec3(Vector3.zero);
            Vec3 parentPos    = new Vec3(Vector3.zero);

            if (hasParent)
            {
                parentName = parent.ShortPrefabName;
                Rigidbody rb = parent.GetComponent<Rigidbody>();
                if (rb != null) parentVel = new Vec3(rb.velocity);
                parentPos = new Vec3(parent.transform.position);
            }

            int ping = 0;
            if (player.net != null && player.net.connection != null)
            {
                ping = Net.sv.GetAveragePing(player.net.connection);
            }

            buf.Add(new TickData
            {
                DeltaTime       = now - _lastTime[sid],
                Position        = new Vec3(pos),
                Velocity        = new Vec3(player.estimatedVelocity),
                DeltaDistance   = Vector3.Distance(_lastPos[sid], pos),
                RaycastDistance = groundDist,
                WaterLevel      = player.WaterFactor(),
                DeltaRotation   = rotDelta,
                HasParent       = hasParent,
                ParentName      = parentName,
                ParentVelocity  = parentVel,
                ParentPosition  = parentPos,
                IsGrounded      = player.IsOnGround(),
                IsSwimming      = player.IsSwimming(),
                IsDucked        = player.modelState != null && player.modelState.ducked,
                IsSprinting     = player.modelState != null && player.modelState.sprinting,
                IsFlying        = player.IsFlying,
                Ping            = ping
            });

            _lastPos[sid]  = pos;
            _lastTime[sid] = now;
            _lastRot[sid]  = curRot;
        }

        private void OnPlayerDisconnected(BasePlayer player, string reason)
        {
            ulong sid = player.userID;
            // Не выбрасываем буфер — пусть FlushAndSend отправит данные. Чистим только трекинг состояния.
            _lastPos.Remove(sid);
            _lastTime.Remove(sid);
            _lastRot.Remove(sid);
        }

        // После респауна позиция игрока скачкообразно меняется относительно последнего тика —
        // сбрасываем трекинг, чтобы position_residual на следующем тике был корректным.
        private void OnPlayerRespawned(BasePlayer player)
        {
            if (player == null) return;
            ulong sid = player.userID;
            _lastPos.Remove(sid);
            _lastTime.Remove(sid);
            _lastRot.Remove(sid);
        }

        private void OnEntityTakeDamage(BaseCombatEntity entity, HitInfo info)
        {
            // Большие пинки (взрывы, нокбэк) тоже искажают residual. Сбрасываем prev для пострадавшего.
            BasePlayer p = entity as BasePlayer;
            if (p == null || info == null) return;
            if (info.damageTypes != null && info.damageTypes.Total() > 50f)
            {
                ulong sid = p.userID;
                _lastPos.Remove(sid);
                _lastTime.Remove(sid);
            }
        }

        // ───────────────────────── SEND ──────────────────────────

        private void FlushAndSend()
        {
            if (_buffer.Count == 0)
            {
                if (_cfg.Debug) Puts("[AI-AC] flush: buffer empty, skip");
                return;
            }

            Dictionary<ulong, List<TickData>> snapshot = new Dictionary<ulong, List<TickData>>(_buffer);
            _buffer.Clear();

            int totalTicks = 0;
            foreach (var kv in snapshot) totalTicks += kv.Value.Count;

            string endpoint = _collectMode ? _cfg.CollectEndpoint : _cfg.ValidateEndpoint;
            string payload;
            try
            {
                payload = JsonConvert.SerializeObject(snapshot);
            }
            catch (Exception e)
            {
                PrintWarning("[AI-AC] Serialize failed: " + e.Message);
                return;
            }

            Puts(string.Format("[AI-AC] flush -> {0} | players={1} ticks={2} bytes={3} mode={4}",
                ShortUrl(endpoint), snapshot.Count, totalTicks, payload.Length,
                _collectMode ? "COLLECT" : "VALIDATE"));

            PendingBatch batch = new PendingBatch
            {
                Endpoint        = endpoint,
                Payload         = payload,
                ExpectsValidate = !_collectMode,
                Attempts        = 0,
                History         = snapshot
            };
            SendBatch(batch);
        }

        private static string ShortUrl(string url)
        {
            if (string.IsNullOrEmpty(url)) return "(empty)";
            int slash = url.LastIndexOf('/');
            return slash >= 0 ? url.Substring(slash + 1) : url;
        }

        private static readonly DateTime _epoch = new DateTime(1970, 1, 1, 0, 0, 0, DateTimeKind.Utc);

        private string ComputeHmac(string ts, string body)
        {
            using (var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(_cfg.Secret ?? string.Empty)))
            {
                byte[] data = Encoding.UTF8.GetBytes(ts + "\n" + (body ?? string.Empty));
                byte[] hash = hmac.ComputeHash(data);
                StringBuilder sb = new StringBuilder(hash.Length * 2);
                for (int i = 0; i < hash.Length; i++) sb.AppendFormat("{0:x2}", hash[i]);
                return sb.ToString();
            }
        }

        private Dictionary<string, string> AuthHeaders(string body, string mode)
        {
            string ts = ((long)(DateTime.UtcNow - _epoch).TotalSeconds).ToString();
            return new Dictionary<string, string>
            {
                { "Content-Type", "application/json" },
                { "X-AC-TS",      ts },
                { "X-AC-Sig",     ComputeHmac(ts, body) },
                { "X-AC-Mode",    mode },
            };
        }

        private void SendBatch(PendingBatch batch)
        {
            Dictionary<string, string> headers = AuthHeaders(batch.Payload, batch.ExpectsValidate ? "validate" : "collect");

            try
            {
                webrequest.Enqueue(batch.Endpoint, batch.Payload, (code, res) =>
                {
                    int respLen = res != null ? res.Length : 0;
                    if (code == 200)
                    {
                        _totalSessions++;
                        Puts(string.Format("[AI-AC] HTTP 200 <- {0} | bytes={1} mode={2}",
                            ShortUrl(batch.Endpoint), respLen, batch.ExpectsValidate ? "VALIDATE" : "COLLECT"));
                        if (batch.ExpectsValidate) ProcessResults(res, batch.History);
                        return;
                    }

                    batch.Attempts++;
                    string snippet = res == null ? "(null)" :
                        (res.Length > 200 ? res.Substring(0, 200) + "..." : res);
                    PrintWarning(string.Format("[AI-AC] HTTP {0} <- {1} attempt={2}/{3} body={4}",
                        code, ShortUrl(batch.Endpoint), batch.Attempts, _cfg.MaxRetries, snippet));
                    if (batch.Attempts < _cfg.MaxRetries)
                    {
                        _retryQueue.Enqueue(batch);
                    }
                    else
                    {
                        PrintWarning("[AI-AC] Batch dropped after " + batch.Attempts + " attempts");
                    }
                }, this, RequestMethod.POST, headers, 30f);
            }
            catch (Exception e)
            {
                PrintWarning("[AI-AC] webrequest.Enqueue failed: " + e.Message);
            }
        }

        private void DrainRetryQueue()
        {
            int drained = 0;
            while (_retryQueue.Count > 0 && drained < 4)
            {
                PendingBatch b = _retryQueue.Dequeue();
                SendBatch(b);
                drained++;
            }
        }

        // ───────────────────────── PROCESS RESULTS ────────────────

        private void ProcessResults(string json, Dictionary<ulong, List<TickData>> history)
        {
            List<ApiResult> results;
            try
            {
                results = JsonConvert.DeserializeObject<List<ApiResult>>(json);
            }
            catch (Exception e)
            {
                PrintWarning("[AI-AC] Bad JSON from validate.php: " + e.Message);
                return;
            }
            if (results == null) { PrintWarning("[AI-AC] validate.php returned null result list"); return; }

            // Compact лог по всем игрокам (не только подозрительным)
            StringBuilder summary = new StringBuilder();
            summary.Append("[AI-AC] results:");
            foreach (ApiResult r in results)
            {
                summary.AppendFormat(" {0}={1:F1}%({2}an)", r.SteamID, r.Risk, r.Anomalies);
            }
            Puts(summary.ToString());

            foreach (ApiResult r in results)
            {
                if (string.IsNullOrEmpty(r.SteamID)) continue;
                if (r.Risk < _cfg.Threshold) continue;

                ulong sid;
                if (!ulong.TryParse(r.SteamID, out sid)) continue;
                _totalAnomalies++;

                List<TickData> hist;
                if (!history.TryGetValue(sid, out hist)) continue;

                if (r.SuspiciousTicks != null)
                {
                    foreach (Vec3 sp in r.SuspiciousTicks)
                    {
                        Vector3 spv = sp.ToVector3();
                        foreach (TickData t in hist)
                        {
                            if (Vector3.Distance(spv, t.Position.ToVector3()) < 1.0f)
                            {
                                t.IsAnomaly = true;
                                break;
                            }
                        }
                    }
                }

                LogDetection(r);
                DrawForAdmins(hist, r);
                MaybeAutoAction(sid, r);
            }
        }

        private void LogDetection(ApiResult r)
        {
            // Oxide пишет в oxide/logs/aiantiсheat/detections-YYYY-MM-DD.txt
            string line = string.Format(
                "steamid={0} risk={1:F1}% peak={2:F4} threshold={3:F4} ticks={4} anomalies={5}",
                r.SteamID, r.Risk, r.PeakError, r.Threshold, r.Ticks, r.Anomalies);
            LogToFile("detections", line, this);
        }

        private void MaybeAutoAction(ulong sid, ApiResult r)
        {
            if (r.Risk < _cfg.AutoActionThreshold) return;
            string mode = (_cfg.AutoAction ?? "log").ToLowerInvariant();
            if (mode == "none") return;

            BasePlayer target = BasePlayer.FindByID(sid);
            if (mode == "kick" && target != null)
            {
                target.Kick("AI-AC: anomalous movement detected (" + r.Risk.ToString("F0") + "%). If you believe this is a mistake — contact admin.");
                Puts("[AI-AC] AUTO-KICK " + r.SteamID + " (" + r.Risk.ToString("F1") + "%)");
            }
            else
            {
                Puts("[AI-AC] HIGH-RISK player " + r.SteamID + " (" + r.Risk.ToString("F1") + "%) — see log");
            }
        }

        // 0% -> green, 50% -> yellow, 100% -> red. Возвращает Color (Unity).
        private static Color RiskToColor(int risk)
        {
            float r = Mathf.Clamp(risk, 0, 100) / 100f;
            float red, green;
            if (r < 0.5f) { red = r * 2f;          green = 1f; }
            else          { red = 1f;              green = 1f - (r - 0.5f) * 2f; }
            return new Color(red, green, 0.15f, 1f);
        }

        private void DrawForAdmins(List<TickData> hist, ApiResult r)
        {
            ulong sid;
            if (!ulong.TryParse(r.SteamID, out sid)) return;
            BasePlayer target = BasePlayer.FindByID(sid);
            string name = target != null ? target.displayName : r.SteamID;
            float dur   = _cfg.DrawDuration;

            // tickRisks может быть короче hist (если сервер скипал тики). Выравниваем по min длине.
            List<int> risks = r.TickRisks ?? new List<int>();
            int n = Mathf.Min(hist.Count, risks.Count);

            foreach (BasePlayer admin in BasePlayer.activePlayerList)
            {
                if (!admin.IsAdmin) continue;

                admin.ChatMessage(
                    "<color=#ff4444>[AI-AC]</color> <color=#ffffff>" + name + "</color> " +
                    "Risk: <color=#ffcc00>" + r.Risk.ToString("F1") + "%</color> | " +
                    "Anomalies: <color=#ff4444>" + r.Anomalies + "</color>/" + r.Ticks);

                for (int i = 0; i < hist.Count - 1; i++)
                {
                    TickData cur = hist[i];
                    TickData nxt = hist[i + 1];

                    int risk = (i < n) ? risks[i] : (cur.IsAnomaly ? 100 : 0);
                    Color col = RiskToColor(risk);

                    admin.SendConsoleCommand("ddraw.line", dur, col,
                        cur.Position.ToVector3(), nxt.Position.ToVector3());

                    if (risk >= 70)
                    {
                        admin.SendConsoleCommand("ddraw.sphere", dur, col,
                            cur.Position.ToVector3(), 0.5f);
                    }
                    else if (cur.HasParent)
                    {
                        admin.SendConsoleCommand("ddraw.sphere", dur, new Color(1f, 0.85f, 0f),
                            cur.Position.ToVector3(), 0.3f);
                    }
                }
            }
        }

        // ───────────────────────── REMOTE COMMANDS ────────────────

        private void PollCommands()
        {
            Dictionary<string, string> headers = AuthHeaders(string.Empty, "poll");
            webrequest.Enqueue(_cfg.CommandEndpoint + "?action=poll", "", (code, res) =>
            {
                if (code != 200)
                {
                    if (_cfg.Debug) Puts(string.Format("[AI-AC] poll HTTP {0}", code));
                    return;
                }
                if (string.IsNullOrEmpty(res) || res == "[]")
                {
                    if (_cfg.Debug) Puts("[AI-AC] poll: no commands");
                    return;
                }
                List<RemoteCommand> cmds;
                try
                {
                    cmds = JsonConvert.DeserializeObject<List<RemoteCommand>>(res);
                }
                catch (Exception e)
                {
                    PrintWarning("[AI-AC] Bad command JSON: " + e.Message);
                    return;
                }
                if (cmds == null) return;
                Puts("[AI-AC] poll: received " + cmds.Count + " command(s)");
                foreach (RemoteCommand cmd in cmds) HandleRemoteCommand(cmd);
            }, this, RequestMethod.GET, headers, 10f);
        }

        private void HandleRemoteCommand(RemoteCommand cmd)
        {
            if (cmd == null || string.IsNullOrEmpty(cmd.Type)) return;
            string payload = cmd.Payload ?? string.Empty;

            switch (cmd.Type)
            {
                case "set_collect_mode":
                    _collectMode = payload == "1";
                    _cfg.CollectMode = _collectMode;
                    SaveConfig();
                    Puts("[AI-AC] Mode → " + (_collectMode ? "COLLECT" : "VALIDATE"));
                    break;

                case "set_threshold":
                {
                    float th;
                    if (float.TryParse(payload, out th))
                    {
                        _cfg.Threshold = th;
                        SaveConfig();
                        Puts("[AI-AC] Threshold → " + th + "%");
                    }
                    break;
                }

                case "set_interval":
                {
                    float iv;
                    if (float.TryParse(payload, out iv) && iv > 0.5f && iv < 600f)
                    {
                        _cfg.Interval = iv;
                        SaveConfig();
                        Puts("[AI-AC] Interval → " + iv + "s (restart plugin to apply)");
                    }
                    break;
                }

                case "whitelist_add":
                    if (!string.IsNullOrEmpty(payload) && !_cfg.Whitelist.Contains(payload))
                    {
                        _cfg.Whitelist.Add(payload);
                        SaveConfig();
                        Puts("[AI-AC] Whitelist + " + payload);
                    }
                    break;

                case "whitelist_remove":
                    if (_cfg.Whitelist.Remove(payload))
                    {
                        SaveConfig();
                        Puts("[AI-AC] Whitelist − " + payload);
                    }
                    break;

                case "reload":
                    LoadConfig();
                    Puts("[AI-AC] Config reloaded.");
                    break;
            }
        }

        // ───────────────────────── CONSOLE ────────────────────────

        [ConsoleCommand("aiac.status")]
        private void CmdStatus(ConsoleSystem.Arg arg)
        {
            if (!arg.IsAdmin) return;
            arg.ReplyWith(
                "[AI-AC] Mode: " + (_collectMode ? "COLLECT" : "VALIDATE")
                + " | Sessions sent: " + _totalSessions
                + " | Anomalies: " + _totalAnomalies
                + " | Buffer: " + _buffer.Count + " players"
                + " | Retry queue: " + _retryQueue.Count);
        }

        [ConsoleCommand("aiac.mode")]
        private void CmdMode(ConsoleSystem.Arg arg)
        {
            if (!arg.IsAdmin) return;
            _collectMode = !_collectMode;
            _cfg.CollectMode = _collectMode;
            SaveConfig();
            arg.ReplyWith("[AI-AC] Mode: " + (_collectMode ? "COLLECT" : "VALIDATE"));
        }

        [ConsoleCommand("aiac.debug")]
        private void CmdDebug(ConsoleSystem.Arg arg)
        {
            if (!arg.IsAdmin) return;
            string a = (arg.GetString(0) ?? "").ToLowerInvariant();
            if (a == "on" || a == "1" || a == "true")  _cfg.Debug = true;
            else if (a == "off" || a == "0" || a == "false") _cfg.Debug = false;
            else _cfg.Debug = !_cfg.Debug;
            SaveConfig();
            arg.ReplyWith("[AI-AC] Debug: " + (_cfg.Debug ? "ON" : "OFF"));
        }

        [ConsoleCommand("aiac.flush")]
        private void CmdFlush(ConsoleSystem.Arg arg)
        {
            if (!arg.IsAdmin) return;
            arg.ReplyWith("[AI-AC] forcing flush of " + _buffer.Count + " players");
            FlushAndSend();
        }

        [ConsoleCommand("aiac.whitelist")]
        private void CmdWhitelist(ConsoleSystem.Arg arg)
        {
            if (!arg.IsAdmin) return;
            string steamId = arg.GetString(0);
            if (string.IsNullOrEmpty(steamId)) { arg.ReplyWith("Usage: aiac.whitelist <steamid>"); return; }
            if (_cfg.Whitelist.Contains(steamId)) { _cfg.Whitelist.Remove(steamId); arg.ReplyWith("Removed " + steamId + " from whitelist."); }
            else                                  { _cfg.Whitelist.Add(steamId);    arg.ReplyWith("Added " + steamId + " to whitelist."); }
            SaveConfig();
        }
    }
}
