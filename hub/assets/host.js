// host.js — 主机详情页：CPU / 内存 / 负载趋势图（Chart.js）。
(function () {
  'use strict';

  const STATUS_LABEL = {
    operational: '运行正常', degraded: '性能降级', down: '离线', 'no-data': '无数据',
  };

  const params = new URLSearchParams(location.search);
  const hostId = params.get('id');
  if (!hostId) { document.body.innerHTML = '<p class="empty">缺少主机 id。</p>'; return; }

  let currentRange = '24h';
  const charts = {};

  function commonOpts(label, color, pct) {
    return {
      type: 'line',
      data: { labels: [], datasets: [{
        label, data: [],
        borderColor: color, backgroundColor: color + '33',
        borderWidth: 2, pointRadius: 0, tension: 0.3, fill: true,
      }] },
      options: {
        responsive: true,
        animation: false,
        scales: {
          x: { ticks: { maxTicksLimit: 8, color: '#8a8f9c' }, grid: { display: false } },
          y: { beginAtZero: true, max: pct ? 100 : undefined,
               ticks: { color: '#8a8f9c' }, grid: { color: '#f0f1f4' } },
        },
        plugins: { legend: { display: false } },
      },
    };
  }

  async function loadHeader() {
    const res = await fetch('/api/hosts');
    const hosts = await res.json();
    const h = hosts.find((x) => String(x.id) === String(hostId));
    if (!h) { document.getElementById('host-title').textContent = '主机不存在'; return; }
    document.getElementById('host-title').textContent = h.hostname;
    const cls = h.last_status || 'no-data';
    // 从摘要中取 IP。
    let ipLine = '';
    try {
      const s = JSON.parse(h.last_summary || '{}');
      const ips = [...(s.ipv4 || []), ...(s.ipv6 || [])].join(', ');
      if (ips) ipLine = ' · IP ' + ips;
    } catch (e) { /* ignore */ }
    document.getElementById('host-badge').innerHTML =
      `<span class="badge badge-${cls}">${STATUS_LABEL[cls] || cls}</span>` +
      ` <span class="host-meta">${h.os || ''} · ${h.kernel || ''} · ${h.arch || ''}${ipLine}</span>`;
  }

  async function loadCharts(range) {
    currentRange = range;
    const res = await fetch(`/api/metrics?id=${hostId}&range=${range}`);
    if (!res.ok) { return; }
    const pts = await res.json();

    const labels = pts.map((p) =>
      new Date(p.ts * 1000).toLocaleString('zh-CN', { hour12: false }));
    const cpu = pts.map((p) => p.cpu);
    const mem = pts.map((p) => p.mem);
    const load = pts.map((p) => p.load1);

    draw('chart-cpu', 'CPU', '#3498db', labels, cpu, true);
    draw('chart-mem', '内存', '#9b59b6', labels, mem, true);
    draw('chart-load', '负载', '#e67e22', labels, load, false);
  }

  function draw(canvasId, label, color, labels, data, pct) {
    if (charts[canvasId]) {
      charts[canvasId].data.labels = labels;
      charts[canvasId].data.datasets[0].data = data;
      charts[canvasId].update('none');
      return;
    }
    const el = document.getElementById(canvasId);
    if (!el) return;
    const cfg = commonOpts(label, color, pct);
    cfg.data.labels = labels;
    cfg.data.datasets[0].data = data;
    charts[canvasId] = new Chart(el.getContext('2d'), cfg);
  }

  document.querySelectorAll('.range-tabs button').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.range-tabs button').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      loadCharts(btn.dataset.range);
    });
  });

  loadHeader();
  loadCharts(currentRange);
  setInterval(() => loadCharts(currentRange), 60 * 1000);
})();
