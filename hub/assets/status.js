// status.js — 状态页渲染：整体横幅 + 每主机 90 天色块条。
(function () {
  'use strict';

  const STATUS_LABEL = {
    operational: '运行正常',
    degraded: '性能降级',
    down: '离线',
    'no-data': '无数据',
  };

  const tooltip = document.getElementById('tooltip');

  async function fetchStatus() {
    const res = await fetch('/api/status?days=90');
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  }

  function overallState(hosts) {
    if (!hosts.length) return { cls: 'banner-nodata', text: '暂无监控数据' };
    let hasDown = false, hasDegraded = false;
    for (const h of hosts) {
      if (h.last_status === 'down') hasDown = true;
      else if (h.last_status === 'degraded') hasDegraded = true;
    }
    if (hasDown) return { cls: 'banner-down', text: '部分系统故障' };
    if (hasDegraded) return { cls: 'banner-degraded', text: '部分系统降级' };
    return { cls: 'banner-ok', text: '所有系统运行正常' };
  }

  function renderBanner(hosts) {
    const o = overallState(hosts);
    const el = document.getElementById('overall');
    el.className = 'banner ' + o.cls;
    el.textContent = o.text;
  }

  function renderHosts(hosts) {
    const list = document.getElementById('host-list');
    if (!hosts.length) {
      list.innerHTML = '<p class="empty">还没有主机上报数据。</p>';
      return;
    }
    list.innerHTML = hosts.map(hostCard).join('');
    bindTooltips();
  }

  function hostCard(h) {
    const badgeCls = h.last_status || 'no-data';
    const badgeText = STATUS_LABEL[badgeCls] || badgeCls;
    let meta = '';
    try {
      const s = JSON.parse(h.last_summary || '{}');
      meta = [s.os, 'Load ' + (s.load1 != null ? s.load1.toFixed(2) : '-'),
              'MEM ' + (s.mem != null ? s.mem.toFixed(0) + '%' : '-')]
        .filter(Boolean).join(' · ');
    } catch (e) { /* ignore */ }

    return `
      <article class="host-card">
        <div class="host-top">
          <div class="host-left">
            <a class="host-name" href="/host.html?id=${h.id}">${escapeHtml(h.hostname)}</a>
            <span class="badge badge-${badgeCls}">${badgeText}</span>
          </div>
          <div class="host-right">
            <span class="host-uptime">${h.uptime_pct != null ? h.uptime_pct.toFixed(2) + '%' : '—'}</span>
          </div>
        </div>
        <div class="host-meta">${escapeHtml(meta)}</div>
        <div class="bar-wrap" style="margin-top:10px">
          <div class="bar">${h.daily.map(dayBlock).join('')}</div>
        </div>
      </article>`;
  }

  function dayBlock(d) {
    const cls = d.status;
    const label = STATUS_LABEL[cls] || cls;
    const data = [
      `date:${d.date}`,
      `status:${label}`,
      `uptime:${d.uptime != null ? d.uptime.toFixed(2) + '%' : '—'}`,
    ].join('|');
    return `<div class="day day-${cls}" data-info="${data}" title=""></div>`;
  }

  function bindTooltips() {
    document.querySelectorAll('.day').forEach((el) => {
      el.addEventListener('mouseenter', (e) => showTip(e, el));
      el.addEventListener('mousemove', (e) => moveTip(e));
      el.addEventListener('mouseleave', () => hideTip());
    });
  }

  function showTip(e, el) {
    const parts = Object.fromEntries(
      el.dataset.info.split('|').map((s) => s.split(':'))
    );
    tooltip.innerHTML =
      `<b>${parts.date}</b><br>` +
      `<span class="t-row">${parts.status}</span><br>` +
      `<span class="t-row">可用率 ${parts.uptime}</span>`;
    tooltip.hidden = false;
    moveTip(e);
  }
  function moveTip(e) {
    const pad = 14;
    let x = e.clientX + pad, y = e.clientY + pad;
    const r = tooltip.getBoundingClientRect();
    if (x + r.width > window.innerWidth) x = e.clientX - r.width - pad;
    if (y + r.height > window.innerHeight) y = e.clientY - r.height - pad;
    tooltip.style.left = x + 'px';
    tooltip.style.top = y + 'px';
  }
  function hideTip() { tooltip.hidden = true; }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  async function refresh() {
    try {
      const hosts = await fetchStatus();
      renderBanner(hosts);
      renderHosts(hosts);
      document.getElementById('updated-at').textContent =
        '更新于 ' + new Date().toLocaleTimeString('zh-CN');
    } catch (err) {
      document.getElementById('overall').textContent = '加载失败：' + err.message;
    }
  }

  refresh();
  setInterval(refresh, 60 * 1000); // 每分钟刷新
})();
