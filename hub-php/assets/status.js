/* Status Pigeon PHP hub overview. No rewrite or external JavaScript is needed. */
(function () {
  'use strict';

  var labels = {
    operational: '运行正常', degraded: '性能降级', down: '离线', 'no-data': '无数据'
  };
  var tooltip = document.getElementById('tooltip');

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (ch) {
      return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[ch];
    });
  }

  function state(hosts) {
    var hasDown = false, hasDegraded = false, hasNoData = false;
    hosts.forEach(function (host) {
      if (host.last_status === 'down') hasDown = true;
      if (host.last_status === 'degraded') hasDegraded = true;
      if (!host.last_status || host.last_status === 'no-data') hasNoData = true;
    });
    if (!hosts.length) return {cls: 'banner-nodata', text: '暂无监控数据'};
    if (hasDown) return {cls: 'banner-down', text: '部分系统故障'};
    if (hasDegraded) return {cls: 'banner-degraded', text: '部分系统降级'};
    if (hasNoData) return {cls: 'banner-nodata', text: '部分系统暂无数据'};
    return {cls: 'banner-ok', text: '所有系统运行正常'};
  }

  function summary(host) {
    var value = {};
    try { value = JSON.parse(host.last_summary || '{}'); } catch (ignored) {}
    var ipv4 = Array.isArray(host.ipv4) ? host.ipv4 : (value.ipv4 || []);
    var ipv6 = Array.isArray(host.ipv6) ? host.ipv6 : (value.ipv6 || []);
    var ips = ipv4.concat(ipv6).join(', ');
    return [
      value.os,
      value.cpu === undefined ? '' : 'CPU ' + Number(value.cpu).toFixed(1) + '%',
      value.mem === undefined ? '' : 'MEM ' + Number(value.mem).toFixed(1) + '%',
      ips ? 'IP ' + ips : ''
    ].filter(Boolean).join(' · ');
  }

  function formatLastSeen(timestamp) {
    var date = new Date(Number(timestamp || 0) * 1000);
    if (!timestamp || isNaN(date.getTime())) return '—';
    function pad(value) { return value < 10 ? '0' + value : String(value); }
    return date.getFullYear() + '/' + (date.getMonth() + 1) + '/' + date.getDate() +
      ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
  }

  function dayBlock(day) {
    var status = day.status || 'no-data';
    var title = day.date + ' · ' + (labels[status] || status) + ' · 可用率 ' +
      (day.uptime === undefined ? '—' : Number(day.uptime).toFixed(2) + '%');
    return '<span class="day day-' + escapeHtml(status) + '" title="' +
      escapeHtml(title) + '"></span>';
  }

  function hostCard(host) {
    var badge = host.last_status || 'no-data';
    var daily = Array.isArray(host.daily) ? host.daily : [];
    var meta = [summary(host), '最近接收：' + formatLastSeen(host.last_seen)]
      .filter(Boolean).join(' · ');
    return '<article class="host-card"><div class="host-top"><div class="host-left">' +
      '<a class="host-name" href="host.php?id=' + encodeURIComponent(host.id) + '">' +
      escapeHtml(host.hostname) + '</a><span class="badge badge-' + escapeHtml(badge) + '">' +
      escapeHtml(labels[badge] || badge) + '</span></div><span class="host-uptime">' +
      (host.uptime_pct === undefined ? '—' : Number(host.uptime_pct).toFixed(2) + '%') +
      '</span></div><div class="host-meta">' + escapeHtml(meta) +
      '</div><div class="bar" style="--bar-days:' + daily.length + '" role="img" aria-label="最近 ' +
      daily.length + ' 天状态">' + daily.map(dayBlock).join('') + '</div><div class="bar-caption"><span>' +
      (daily.length ? escapeHtml(daily[0].date) : '') + '</span><span>' +
      (daily.length ? escapeHtml(daily[daily.length - 1].date) : '') + '</span></div></article>';
  }

  function render(hosts) {
    var overall = state(hosts);
    var banner = document.getElementById('overall');
    banner.className = 'banner ' + overall.cls;
    banner.textContent = overall.text;
    // A fully healthy fleet is the default state, so keep the page quiet and
    // reserve the top banner for degraded, down, or no-data conditions.
    banner.hidden = overall.cls === 'banner-ok';
    var list = document.getElementById('host-list');
    list.innerHTML = hosts.length ? hosts.map(hostCard).join('') :
      '<p class="empty">还没有主机上报数据。</p>';
  }

  function requestedDays() {
    return window.matchMedia && window.matchMedia('(max-width: 640px)').matches ? 30 : 60;
  }

  var lastRequestedDays = requestedDays();

  function refresh() {
    var days = requestedDays();
    lastRequestedDays = days;
    fetch('api/status.php?days=' + days).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    }).then(function (hosts) {
      render(hosts);
    }).catch(function (error) {
      var banner = document.getElementById('overall');
      banner.className = 'banner banner-down';
      banner.hidden = false;
      banner.textContent = '加载失败：' + error.message;
    });
  }

  refresh();
  window.setInterval(refresh, 60000);
  window.addEventListener('resize', function () {
    var days = requestedDays();
    if (days !== lastRequestedDays) refresh();
  });
}());
