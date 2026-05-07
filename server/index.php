<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <title>AI AntiCheat Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.plot.ly/plotly-2.24.1.min.js"></script>
    <style>
        body { background-color: #121212; color: #e0e0e0; }
        .card { background-color: #1e1e1e; border: 1px solid #333; }
        .chart-container { position: relative; height: 300px; width: 100%; margin-bottom: 20px;}
        .table-hover tbody tr:hover { background-color: #2a2a2a; cursor: pointer;}
        #plot3d { height: 400px; width: 100%; }
        .score-high { color: #ff4d4d; font-weight: bold; }
        .score-mid { color: #ffcc00; }
        .score-low { color: #4CAF50; }
    </style>
</head>
<body>

<div class="container-fluid mt-3">
    <h2 class="mb-4">🛡️ AI Movement AntiCheat — Admin Panel <span class="badge bg-secondary" style="font-size: 0.5em; vertical-align: middle;">autoencoder v1</span></h2>
    <p class="text-muted small">Risk = peak reconstruction error / model threshold. &gt;70% — устойчиво аномальное движение, &gt;40% — стоит ревью.</p>
    
    <div class="row">
        <!-- Левая колонка: Список логов -->
        <div class="col-md-4">
            <div class="card p-3 h-100">
                <h5>Последние сессии (60 сек)</h5>
                <button class="btn btn-sm btn-outline-info mb-3" onclick="loadSessions()">🔄 Обновить</button>
                <div class="table-responsive" style="max-height: 80vh; overflow-y: auto;">
                    <table class="table table-dark table-hover table-sm text-center">
                        <thead>
                            <tr>
                                <th>SteamID</th>
                                <th>Режим</th>
                                <th>Риск (%)</th>
                                <th>Время</th>
                            </tr>
                        </thead>
                        <tbody id="sessionList">
                            <!-- Заполняется через JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Правая колонка: Аналитика (Графики и 3D) -->
        <div class="col-md-8" id="analysisView" style="display: none;">
            <div class="card p-3 mb-3">
                <div class="d-flex justify-content-between align-items-start flex-wrap">
                    <h4 id="detailTitle">Детальный анализ: SteamID</h4>
                    <div>
                        <span class="badge bg-secondary" id="badgeTicks">Тиков: 0</span>
                        <span class="badge bg-danger"    id="badgePing">Макс Пинг: 0</span>
                        <span class="badge"              id="badgeMaxRisk"  style="background:#444">Peak Risk: -</span>
                        <span class="badge"              id="badgeAvgRisk"  style="background:#444">Avg Risk: -</span>
                        <span class="badge bg-info"      id="badgeBaseline" style="display:none">Baseline: -</span>
                    </div>
                </div>
                <div class="mt-2 d-flex gap-2 align-items-center">
                    <span class="text-muted small">Метка админа:</span>
                    <button class="btn btn-sm btn-outline-danger"  onclick="setLabel('cheater')">🚫 Cheater</button>
                    <button class="btn btn-sm btn-outline-success" onclick="setLabel('legit')">✅ Legit</button>
                    <button class="btn btn-sm btn-outline-warning" onclick="setLabel('unsure')">❔ Unsure</button>
                    <span id="labelStatus" class="small text-info ms-2"></span>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <h5>🎯 3D Путь — окраска по риску</h5>
                        <p class="text-muted small mb-0">Зелёный = легит, красный = читер. Ромбы — пики &gt;70%.</p>
                        <div id="plot3d"></div>
                    </div>
                    <div class="col-md-6">
                        <h5>Скорость бега (м/с) — толщина и цвет = риск</h5>
                        <div class="chart-container"><canvas id="speedChart"></canvas></div>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-12">
                        <h5>Risk таймлайн</h5>
                        <div class="chart-container" style="height: 120px"><canvas id="riskChart"></canvas></div>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-6">
                        <h5>Резкость прицела (Delta Rotation)</h5>
                        <div class="chart-container"><canvas id="aimChart"></canvas></div>
                    </div>
                    <div class="col-md-6">
                        <h5>График Пинга (ms)</h5>
                        <div class="chart-container"><canvas id="pingChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let speedChartInstance, aimChartInstance, pingChartInstance, riskChartInstance;
    let currentSessionId = null;

    async function setLabel(label) {
        if (!currentSessionId) return;
        const status = document.getElementById('labelStatus');
        status.textContent = '...';
        const fd = new FormData();
        fd.append('session_id', currentSessionId);
        fd.append('label', label);
        try {
            const res = await fetch('api.php?action=set_label', { method: 'POST', body: fd });
            const j = await res.json();
            status.textContent = j.ok ? 'saved as ' + label : ('error: ' + (j.error || 'unknown'));
        } catch (e) {
            status.textContent = 'network error';
        }
    }

    // Загрузка списка сессий
    async function loadSessions() {
        const res = await fetch('api.php?action=get_sessions');
        const data = await res.json();
        
        let html = '';
        data.forEach(s => {
            let colorClass = s.suspicion_score > 70 ? 'score-high' : (s.suspicion_score > 40 ? 'score-mid' : 'score-low');
            let mode = s.mode === 0 ? '🧠 Сбор' : '⚔️ Бой';
            html += `<tr onclick="loadDetails(${s.id}, '${s.steam_id}')">
                        <td>${s.steam_id}</td>
                        <td>${mode}</td>
                        <td class="${colorClass}">${s.suspicion_score}%</td>
                        <td style="font-size: 0.8em">${s.created_at.split(' ')[1]}</td>
                     </tr>`;
        });
        document.getElementById('sessionList').innerHTML = html;
    }

    // green (0%) -> yellow (50%) -> red (100%)
    function riskColor(risk) {
        const r = Math.max(0, Math.min(100, risk)) / 100;
        let red, green;
        if (r < 0.5) { red = Math.round(255 * (r * 2)); green = 255; }
        else         { red = 255; green = Math.round(255 * (1 - (r - 0.5) * 2)); }
        return `rgb(${red},${green},60)`;
    }

    async function loadDetails(id, steamId) {
        document.getElementById('analysisView').style.display = 'block';
        document.getElementById('detailTitle').innerText = `Анализ: ${steamId} (ID: ${id})`;
        currentSessionId = id;
        document.getElementById('labelStatus').textContent = '';

        // Параллельно: per-tick scoring и baseline по этому SteamID
        const [scoredRes, baselineRes] = await Promise.all([
            fetch(`api.php?action=get_session_scored&id=${id}`),
            fetch(`api.php?action=get_player_baseline&steam_id=${encodeURIComponent(steamId)}`)
        ]);
        const payload  = await scoredRes.json();
        const baseline = await baselineRes.json();
        const ticks    = payload.ticks || [];
        const threshold = payload.threshold;

        const baselineBadge = document.getElementById('badgeBaseline');
        if (baseline && baseline.sessions_count) {
            baselineBadge.style.display = 'inline';
            baselineBadge.innerText = `Baseline: ${(+baseline.baseline_risk).toFixed(1)}% (${baseline.sessions_count} сесс.)`;
        } else {
            baselineBadge.style.display = 'none';
        }

        let times = [], speeds = [], aimDeltas = [], pings = [], risks = [];
        let xData = [], yData = [], zData = [];
        let maxPing = 0, maxRisk = 0, sumRisk = 0;

        ticks.forEach((t, index) => {
            times.push(index);
            const speed = t.DeltaTime > 0 ? +(t.DeltaDistance / t.DeltaTime).toFixed(2) : 0;
            speeds.push(speed);
            aimDeltas.push(t.DeltaRotation);
            pings.push(t.Ping);
            if (t.Ping > maxPing) maxPing = t.Ping;
            const risk = +(t.Risk || 0);
            risks.push(risk);
            if (risk > maxRisk) maxRisk = risk;
            sumRisk += risk;
            if (t.Position) {
                xData.push(t.Position.X);
                yData.push(t.Position.Z); // Rust Z -> Plotly Y
                zData.push(t.Position.Y); // Rust Y -> Plotly Z (высота)
            }
        });

        const avgRisk = ticks.length ? (sumRisk / ticks.length) : 0;
        document.getElementById('badgeTicks').innerText  = `Тиков: ${ticks.length}`;
        document.getElementById('badgePing').innerText   = `Макс Пинг: ${maxPing} ms`;
        document.getElementById('badgeMaxRisk').innerText = `Peak Risk: ${maxRisk.toFixed(1)}%`;
        document.getElementById('badgeMaxRisk').style.background = riskColor(maxRisk);
        document.getElementById('badgeAvgRisk').innerText = `Avg Risk: ${avgRisk.toFixed(1)}%`;
        document.getElementById('badgeAvgRisk').style.background = riskColor(avgRisk);

        renderCharts(times, speeds, aimDeltas, pings, risks);
        render3DPlot(xData, yData, zData, risks);
    }

    function renderCharts(labels, speeds, aim, pings, risks) {
        if(speedChartInstance) speedChartInstance.destroy();
        if(aimChartInstance) aimChartInstance.destroy();
        if(pingChartInstance) pingChartInstance.destroy();
        if(riskChartInstance) riskChartInstance.destroy();

        const commonOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } };
        const pointColors = risks.map(r => riskColor(r));

        // Speed: каждый сегмент линии красится по risk текущей точки
        speedChartInstance = new Chart(document.getElementById('speedChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: speeds,
                    borderColor: '#888',
                    borderWidth: 1,
                    pointBackgroundColor: pointColors,
                    pointBorderColor: pointColors,
                    pointRadius: 2,
                    segment: {
                        borderColor: ctx => riskColor(risks[ctx.p1DataIndex] || 0),
                        borderWidth: ctx => (risks[ctx.p1DataIndex] || 0) > 50 ? 3 : 1.5,
                    }
                }]
            },
            options: { ...commonOptions, scales: { y: { suggestedMax: 15 } } }
        });

        aimChartInstance = new Chart(document.getElementById('aimChart'), {
            type: 'bar',
            data: { labels: labels, datasets: [{ data: aim, backgroundColor: pointColors }] },
            options: commonOptions
        });

        pingChartInstance = new Chart(document.getElementById('pingChart'), {
            type: 'line',
            data: { labels: labels, datasets: [{ data: pings, borderColor: '#ffcc00', borderWidth: 2, pointRadius: 0 }] },
            options: { ...commonOptions, scales: { y: { beginAtZero: true } } }
        });

        // Отдельный risk-таймлайн
        riskChartInstance = new Chart(document.getElementById('riskChart'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{ data: risks, backgroundColor: pointColors, borderWidth: 0 }]
            },
            options: { ...commonOptions, scales: { y: { min: 0, max: 100 } } }
        });
    }

    function render3DPlot(x, y, z, risks) {
        // Цветной 3D трек: лента точек, окрашенных по риску, тонкая серая нить-связка.
        const trace = {
            x: x, y: y, z: z,
            mode: 'lines+markers',
            type: 'scatter3d',
            line: { color: '#444', width: 2 },
            marker: {
                size: 4,
                color: risks,
                colorscale: [
                    [0.0, '#00ff3c'],
                    [0.4, '#c8ff1a'],
                    [0.6, '#ffd000'],
                    [0.8, '#ff5e00'],
                    [1.0, '#ff0033'],
                ],
                cmin: 0,
                cmax: 100,
                showscale: true,
                colorbar: {
                    title: { text: 'Risk %', font: { color: '#ddd' } },
                    thickness: 12,
                    tickfont: { color: '#ddd' },
                    bgcolor: '#1e1e1e',
                    bordercolor: '#333',
                    len: 0.85,
                }
            },
            hovertemplate: 'tick %{pointNumber}<br>risk %{marker.color:.1f}%<extra></extra>'
        };

        // Отдельный slice — красные сферы для пиков (>70%) — заметно даже издалека
        const peakIdx = risks.map((r, i) => r > 70 ? i : -1).filter(i => i >= 0);
        const peaks = {
            x: peakIdx.map(i => x[i]),
            y: peakIdx.map(i => y[i]),
            z: peakIdx.map(i => z[i]),
            mode: 'markers',
            type: 'scatter3d',
            marker: { size: 8, color: '#ff0033', symbol: 'diamond', line: { color: '#fff', width: 1 } },
            hovertemplate: 'PEAK tick %{pointNumber}<extra></extra>',
            showlegend: false,
        };

        const layout = {
            margin: { l: 0, r: 0, b: 0, t: 0 },
            paper_bgcolor: '#1e1e1e',
            scene: {
                xaxis: { showbackground: false, color: '#666' },
                yaxis: { showbackground: false, color: '#666' },
                zaxis: { showbackground: false, color: '#666', title: 'Высота' },
                camera: { eye: {x: 1.5, y: 1.5, z: 0.5} }
            }
        };
        Plotly.newPlot('plot3d', [trace, peaks], layout, {displaylogo: false});
    }

    // Первичная загрузка
    loadSessions();
</script>

</body>
</html>