/* Status Pigeon PHP hub overview. No rewrite or external JavaScript is needed. */
(function () {
  'use strict';

  var labels = {
    operational: '运行正常', degraded: '性能降级', down: '离线', 'no-data': '无数据'
  };
  var ORDER_KEY = 'statuspigeon_host_order';
  var BANNER_KEY = 'statuspigeon_banner_dismissed';
  var TAB_KEY = 'statuspigeon_host_tab';
  var tooltip = document.getElementById('tooltip');
  var lastHosts = [];

  var tabs = [
    {id: 'operational', label: '运行正常', empty: '暂无运行正常的主机。'},
    {id: 'down', label: '离线', empty: '暂无离线主机。'}
  ];

  function currentTab() {
    try {
      var saved = window.localStorage.getItem(TAB_KEY);
      if (saved === 'operational' || saved === 'down') return saved;
    } catch (ignored) {}
    return 'operational';
  }

  function tabHosts(hosts, tabId) {
    return hosts.filter(function (host) { return (host.last_status || 'no-data') === tabId; });
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (ch) {
      return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[ch];
    });
  }

  function refreshIcons() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
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

  function bannerDismissed() {
    try { return window.localStorage.getItem(BANNER_KEY) === '1'; } catch (ignored) {}
    return false;
  }

  function renderBanner(cls, text) {
    var banner = document.getElementById('overall');
    banner.className = 'banner ' + cls;
    banner.innerHTML = '<span class="banner-text">' + escapeHtml(text) + '</span>' +
      '<button type="button" class="banner-close" aria-label="关闭提示" title="关闭">' +
      '<i data-lucide="x"></i></button>';
    banner.querySelector('.banner-close').addEventListener('click', function () {
      try { window.localStorage.setItem(BANNER_KEY, '1'); } catch (ignored) {}
      banner.hidden = true;
    });
    // A fully healthy fleet is the default state, so keep the page quiet and
    // reserve the top banner for degraded, down, or no-data conditions. Once
    // dismissed, the banner stays hidden until localStorage is cleared.
    banner.hidden = cls === 'banner-ok' || bannerDismissed();
  }

  function summary(host) {
    var value = {};
    try { value = JSON.parse(host.last_summary || '{}'); } catch (ignored) {}
    return [
      value.os,
      value.load1 === undefined ? '' : 'Load ' + Number(value.load1).toFixed(2),
      value.mem === undefined ? '' : 'MEM ' + Number(value.mem).toFixed(1) + '%',
    ].filter(Boolean).join(' · ');
  }

  function formatLastSeen(timestamp) {
    var ts = Number(timestamp || 0);
    if (!ts) return '—';
    var date = new Date(ts * 1000);
    if (isNaN(date.getTime())) return '—';
    var diff = Math.floor((Date.now() - ts * 1000) / 1000);
    if (diff < 0) diff = 0;
    if (diff < 60) return diff + ' 秒前';
    if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
    function pad(value) { return value < 10 ? '0' + value : String(value); }
    return date.getFullYear() + '年' + (date.getMonth() + 1) + '月' + date.getDate() + '日 ' +
      pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
  }

  function updateLastSeen() {
    Array.prototype.forEach.call(document.querySelectorAll('.last-seen[data-last-seen]'), function (el) {
      el.textContent = formatLastSeen(el.getAttribute('data-last-seen'));
    });
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
    var meta = summary(host);
    var lastSeen = Number(host.last_seen || 0);
    return '<article class="host-card" data-host-id="' + escapeHtml(host.id) + '">' +
      '<div class="host-top"><div class="host-left">' +
      '<span class="drag-handle" title="拖拽调整顺序"><i data-lucide="grip-vertical"></i></span>' +
      '<a class="host-name" href="host.php?id=' + encodeURIComponent(host.id) + '">' +
      escapeHtml(host.hostname) + '</a><span class="badge badge-' + escapeHtml(badge) + '">' +
      escapeHtml(labels[badge] || badge) + '</span></div><span class="host-uptime">' +
      (host.uptime_pct === undefined ? '—' : Number(host.uptime_pct).toFixed(2) + '%') +
      '</span></div><div class="host-meta">' + (meta ? escapeHtml(meta) + ' · ' : '') +
      '最近接收：<span class="last-seen" data-last-seen="' + lastSeen + '">' +
      escapeHtml(formatLastSeen(lastSeen)) + '</span>' +
      '</div><div class="bar" style="--bar-days:' + daily.length + '" role="img" aria-label="最近 ' +
      daily.length + ' 天状态">' + daily.map(dayBlock).join('') + '</div><div class="bar-caption"><span>' +
      (daily.length ? escapeHtml(daily[0].date) : '') + '</span><span>' +
      (daily.length ? escapeHtml(daily[daily.length - 1].date) : '') + '</span></div></article>';
  }

  function loadOrder() {
    try {
      var saved = JSON.parse(window.localStorage.getItem(ORDER_KEY) || '[]');
      return Array.isArray(saved) ? saved : [];
    } catch (ignored) {}
    return [];
  }

  function applyOrder(hosts) {
    var order = loadOrder();
    if (!order.length) return hosts;
    var rank = {};
    order.forEach(function (id, index) { rank[String(id)] = index; });
    return hosts.slice().sort(function (a, b) {
      var hasA = rank[String(a.id)] !== undefined;
      var hasB = rank[String(b.id)] !== undefined;
      if (hasA && hasB) return rank[String(a.id)] - rank[String(b.id)];
      if (hasA) return -1;
      if (hasB) return 1;
      return 0;
    });
  }

  function persistOrder(list) {
    var visibleIds = Array.prototype.map.call(list.querySelectorAll('.host-card[data-host-id]'), function (card) {
      return card.getAttribute('data-host-id');
    });
    // The list may be filtered by a status tab. Replace only the visible
    // subsequence inside the saved order so hidden hosts keep their ranks.
    var visibleSet = {};
    visibleIds.forEach(function (id) { visibleSet[id] = true; });
    var queue = visibleIds.slice();
    var merged = loadOrder().map(function (id) {
      return visibleSet[id] ? queue.shift() : id;
    });
    // Visible hosts never saved before (new devices) go last, in DOM order.
    merged = merged.concat(queue);
    try { window.localStorage.setItem(ORDER_KEY, JSON.stringify(merged)); } catch (ignored) {}
  }

  function enableDrag(list) {
    var dragged = null;
    Array.prototype.forEach.call(list.querySelectorAll('.host-card'), function (card) {
      var handle = card.querySelector('.drag-handle');
      if (handle) {
        // Only the grip icon starts a drag, so hostname text stays selectable.
        handle.addEventListener('mousedown', function () {
          card.setAttribute('draggable', 'true');
        });
      }
      card.addEventListener('dragstart', function (event) {
        dragged = card;
        card.classList.add('dragging');
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = 'move';
          try { event.dataTransfer.setData('text/plain', card.getAttribute('data-host-id') || ''); } catch (ignored) {}
        }
      });
      card.addEventListener('dragend', function () {
        card.classList.remove('dragging');
        card.setAttribute('draggable', 'false');
        dragged = null;
        persistOrder(list);
      });
      card.addEventListener('dragover', function (event) {
        if (!dragged || card === dragged) return;
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
        var rect = card.getBoundingClientRect();
        var before = (event.clientY - rect.top) < rect.height / 2;
        list.insertBefore(dragged, before ? card : card.nextSibling);
      });
      card.addEventListener('drop', function (event) {
        event.preventDefault();
      });
    });
    document.addEventListener('mouseup', function () {
      if (dragged) return;
      Array.prototype.forEach.call(list.querySelectorAll('.host-card[draggable="true"]'), function (card) {
        card.setAttribute('draggable', 'false');
      });
    });
  }

  function renderTabs(hosts) {
    var nav = document.getElementById('host-tabs');
    if (!hosts.length) {
      nav.hidden = true;
      nav.innerHTML = '';
      return;
    }
    var active = currentTab();
    nav.innerHTML = '';
    tabs.forEach(function (tab) {
      var count = tabHosts(hosts, tab.id).length;
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'host-tab' + (tab.id === active ? ' active' : '');
      button.setAttribute('aria-selected', tab.id === active ? 'true' : 'false');
      button.innerHTML = escapeHtml(tab.label) + ' <span class="tab-count">' + count + '</span>';
      button.addEventListener('click', function () {
        try { window.localStorage.setItem(TAB_KEY, tab.id); } catch (ignored) {}
        renderTabs(lastHosts);
        renderList();
      });
      nav.appendChild(button);
    });
    nav.hidden = false;
  }

  function renderList() {
    var active = currentTab();
    var tab = tabs.filter(function (item) { return item.id === active; })[0] || tabs[0];
    var visible = applyOrder(tabHosts(lastHosts, tab.id));
    var list = document.getElementById('host-list');
    list.innerHTML = visible.length ? visible.map(hostCard).join('') :
      '<p class="empty">' + (lastHosts.length ? escapeHtml(tab.empty) : '还没有主机上报数据。') + '</p>';
    if (visible.length) enableDrag(list);
    refreshIcons();
  }

  function render(hosts) {
    lastHosts = hosts;
    var overall = state(hosts);
    renderBanner(overall.cls, overall.text);
    renderTabs(hosts);
    renderList();
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
  window.setInterval(updateLastSeen, 1000);
  window.addEventListener('resize', function () {
    var days = requestedDays();
    if (days !== lastRequestedDays) refresh();
  });
}());
