/* Host detail page using inline SVG charts, so the PHP hub has no vendor assets. */
(function () {
  'use strict';

  var labels = {
    operational: '运行正常', degraded: '性能降级', down: '离线', 'no-data': '无数据'
  };
  var params = new URLSearchParams(window.location.search);
  var hostId = params.get('id');
  var currentRange = '24h';

  if (!hostId) {
    document.body.innerHTML = '<p class="empty">缺少主机 id。</p>';
    return;
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (ch) {
      return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[ch];
    });
  }

  function loadHeader() {
    return fetch('api/hosts.php').then(function (response) { return response.json(); }).then(function (hosts) {
      var host = hosts.filter(function (item) { return String(item.id) === String(hostId); })[0];
      if (!host) {
        document.getElementById('host-title').textContent = '主机不存在';
        return;
      }
      document.getElementById('host-title').textContent = host.hostname;
      var status = host.last_status || 'no-data';
      var meta = [host.os, host.kernel, host.arch].filter(Boolean).join(' · ');
      document.getElementById('host-badge').innerHTML =
        '<span class="badge badge-' + escapeHtml(status) + '">' +
        escapeHtml(labels[status] || status) + '</span> <span class="host-meta">' +
        escapeHtml(meta) + '</span>';
    });
  }

  function chart(elementId, points, field, color, percentage) {
    var element = document.getElementById(elementId);
    var values = points.map(function (point) { return Number(point[field]); })
      .filter(function (value) { return isFinite(value); });
    if (!values.length) {
      element.innerHTML = '<p class="empty chart-empty">暂无数据</p>';
      return;
    }
    var width = 800, height = 220, left = 42, right = 14, top = 12, bottom = 28;
    var min = percentage ? 0 : Math.min.apply(Math, values);
    var max = percentage ? 100 : Math.max.apply(Math, values);
    if (max <= min) max = min + 1;
    var plotWidth = width - left - right, plotHeight = height - top - bottom;
    var coords = values.map(function (value, index) {
      var x = left + (values.length === 1 ? plotWidth / 2 : index * plotWidth / (values.length - 1));
      var y = top + (max - value) * plotHeight / (max - min);
      return x.toFixed(2) + ',' + y.toFixed(2);
    });
    var area = left + ',' + (height - bottom) + ' ' + coords.join(' ') +
      ' ' + (width - right) + ',' + (height - bottom);
    element.innerHTML = '<svg class="chart-svg" viewBox="0 0 ' + width + ' ' + height +
      '" role="img" aria-label="趋势图"><line x1="' + left + '" y1="' + top +
      '" x2="' + left + '" y2="' + (height - bottom) + '" class="axis"/><line x1="' +
      left + '" y1="' + (height - bottom) + '" x2="' + (width - right) + '" y2="' +
      (height - bottom) + '" class="axis"/><polygon points="' + area + '" fill="' +
      color + '" opacity="0.12"/><polyline points="' + coords.join(' ') + '" fill="none" stroke="' +
      color + '" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/></svg>';
  }

  function loadCharts(range) {
    currentRange = range;
    fetch('api/metrics.php?id=' + encodeURIComponent(hostId) + '&range=' + encodeURIComponent(range))
      .then(function (response) { return response.ok ? response.json() : []; })
      .then(function (points) {
        chart('chart-cpu', points, 'cpu', '#3498db', true);
        chart('chart-mem', points, 'mem', '#9b59b6', true);
        chart('chart-load', points, 'load1', '#e67e22', false);
      });
  }

  Array.prototype.forEach.call(document.querySelectorAll('.range-tabs button'), function (button) {
    button.addEventListener('click', function () {
      Array.prototype.forEach.call(document.querySelectorAll('.range-tabs button'), function (item) {
        item.classList.remove('active');
      });
      button.classList.add('active');
      loadCharts(button.getAttribute('data-range'));
    });
  });

  loadHeader();
  loadCharts(currentRange);
  window.setInterval(function () { loadCharts(currentRange); }, 60000);
}());
