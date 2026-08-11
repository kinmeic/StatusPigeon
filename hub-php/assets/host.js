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

  function setText(id, value) {
    var element = document.getElementById(id);
    if (element) element.textContent = value === '' || value === null || value === undefined ? '—' : String(value);
  }

  function formatTimestamp(timestamp) {
    var value = Number(timestamp || 0);
    return value > 0 ? new Date(value * 1000).toLocaleString('zh-CN') : '—';
  }

  function formatDuration(seconds) {
    var total = Math.max(0, Number(seconds || 0));
    if (!isFinite(total) || total <= 0) return '—';
    var days = Math.floor(total / 86400);
    total -= days * 86400;
    var hours = Math.floor(total / 3600);
    total -= hours * 3600;
    var minutes = Math.floor(total / 60);
    var parts = [];
    if (days) parts.push(days + ' 天');
    if (hours || days) parts.push(hours + ' 小时');
    parts.push(minutes + ' 分钟');
    return parts.join(' ');
  }

  function formatBytes(value) {
    var number = Math.max(0, Number(value || 0));
    if (!isFinite(number)) return '—';
    var units = ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s'];
    var index = 0;
    while (number >= 1024 && index < units.length - 1) {
      number /= 1024;
      index++;
    }
    return (number >= 100 ? number.toFixed(0) : number.toFixed(1)) + ' ' + units[index];
  }

  function formatIPList(value) {
    return Array.isArray(value) && value.length ? value.join('\n') : '—';
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
      document.getElementById('host-badge').innerHTML =
        '<span class="badge badge-' + escapeHtml(status) + '">' +
        escapeHtml(labels[status] || status) + '</span>';

      var summary = {};
      try { summary = JSON.parse(host.last_summary || '{}'); } catch (ignored) {}
      setText('host-os', host.os || summary.os || '—');
      setText('host-kernel', host.kernel || '—');
      setText('host-arch', host.arch || '—');
      setText('host-agent', host.agent_version || '—');
      setText('host-uptime', formatDuration(summary.uptime));
      setText('host-last-report', formatTimestamp(host.last_seen));
      setText('host-ipv4', formatIPList(summary.ipv4));
      setText('host-ipv6', formatIPList(summary.ipv6));
    });
  }

  function numericValue(point, field) {
    if (point[field] === null || point[field] === undefined || point[field] === '') return null;
    var value = Number(point[field]);
    return isFinite(value) ? value : null;
  }

  function chart(elementId, points, field, color, percentage) {
    var element = document.getElementById(elementId);
    var values = points.map(function (point) { return numericValue(point, field); })
      .filter(function (value) { return value !== null; });
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

  function multiChart(elementId, points, series) {
    var element = document.getElementById(elementId);
    var allValues = [];
    series.forEach(function (item) {
      points.forEach(function (point) {
        var value = numericValue(point, item.field);
        if (value !== null) allValues.push(value);
      });
    });
    if (!allValues.length) {
      element.innerHTML = '<p class="empty chart-empty">暂无数据</p>';
      return;
    }
    var width = 800, height = 220, left = 42, right = 14, top = 12, bottom = 28;
    var min = 0, max = Math.max.apply(Math, allValues);
    if (max <= min) max = 1;
    var plotWidth = width - left - right, plotHeight = height - top - bottom;
    var lines = series.map(function (item) {
      var coords = [];
      points.forEach(function (point, index) {
        var value = numericValue(point, item.field);
        if (value !== null) {
          var x = left + (points.length === 1 ? plotWidth / 2 : index * plotWidth / (points.length - 1));
          var y = top + (max - value) * plotHeight / (max - min);
          coords.push(x.toFixed(2) + ',' + y.toFixed(2));
        }
      });
      return coords.length ? '<polyline points="' + coords.join(' ') + '" fill="none" stroke="' +
        item.color + '" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>' : '';
    }).join('');
    var legend = series.map(function (item) {
      return '<span class="chart-legend-item"><i style="background:' + item.color + '"></i>' +
        escapeHtml(item.label) + '</span>';
    }).join('');
    element.innerHTML = '<div class="chart-legend">' + legend + '</div><svg class="chart-svg" viewBox="0 0 ' +
      width + ' ' + height + '" role="img" aria-label="趋势图"><line x1="' + left +
      '" y1="' + top + '" x2="' + left + '" y2="' + (height - bottom) + '" class="axis"/><line x1="' +
      left + '" y1="' + (height - bottom) + '" x2="' + (width - right) + '" y2="' + (height - bottom) +
      '" class="axis"/>' + lines + '</svg>';
  }

  function loadCharts(range) {
    currentRange = range;
    fetch('api/metrics.php?id=' + encodeURIComponent(hostId) + '&range=' + encodeURIComponent(range))
      .then(function (response) { return response.ok ? response.json() : []; })
      .then(function (points) {
        chart('chart-cpu', points, 'cpu', '#3498db', true);
        chart('chart-mem', points, 'mem', '#9b59b6', true);
        chart('chart-load', points, 'load1', '#e67e22', false);
        multiChart('chart-disk', points, [
          {field: 'disk_read_bps', label: '读取', color: '#2ecc71'},
          {field: 'disk_write_bps', label: '写入', color: '#e67e22'}
        ]);
        multiChart('chart-network', points, [
          {field: 'network_rx_bps', label: '接收', color: '#3498db'},
          {field: 'network_tx_bps', label: '发送', color: '#9b59b6'}
        ]);
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
  window.setInterval(function () { loadCharts(currentRange); loadHeader(); }, 60000);
}());
