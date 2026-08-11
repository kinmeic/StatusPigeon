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
      setText('host-os-version', host.os_version || summary.os_version || '—');
      setText('host-kernel', host.kernel || '—');
      setText('host-arch', host.arch || '—');
      setText('host-uptime', formatDuration(summary.uptime));
      setText('host-last-report', formatTimestamp(host.last_seen));
      setText('host-ipv4', formatIPList(host.ipv4 || summary.ipv4));
      setText('host-ipv6', formatIPList(host.ipv6 || summary.ipv6));
    });
  }

  function numericValue(point, field) {
    if (point[field] === null || point[field] === undefined || point[field] === '') return null;
    var value = Number(point[field]);
    return isFinite(value) ? value : null;
  }

  function formatAxisValue(value, percentage) {
    if (percentage) {
      return (Math.abs(value) >= 10 ? value.toFixed(0) : value.toFixed(1)) + '%';
    }
    return Math.abs(value) >= 10 ? value.toFixed(1) : value.toFixed(2);
  }

  function formatChartTime(timestamp) {
    var date = new Date(Number(timestamp || 0) * 1000);
    if (!timestamp || isNaN(date.getTime())) return '';
    function pad(value) { return value < 10 ? '0' + value : String(value); }
    return (date.getMonth() + 1) + '/' + date.getDate() + ' ' +
      pad(date.getHours()) + ':' + pad(date.getMinutes());
  }

  function chartPointX(index, count, left, plotWidth) {
    return left + (count === 1 ? plotWidth / 2 : index * plotWidth / (count - 1));
  }

  function chartHoverMarkup(points, left, top, plotWidth, plotHeight) {
    if (!points.length) return '';
    var markup = '<line class="chart-hover-line" x1="0" y1="' + top +
      '" x2="0" y2="' + (top + plotHeight) + '" visibility="hidden"/>';
    points.forEach(function (point, index) {
      var x = chartPointX(index, points.length, left, plotWidth);
      var previous = points.length === 1 ? left :
        (index === 0 ? left : chartPointX(index - 1, points.length, left, plotWidth));
      var next = points.length === 1 ? left + plotWidth :
        (index === points.length - 1 ? left + plotWidth :
          chartPointX(index + 1, points.length, left, plotWidth));
      var zoneStart = points.length === 1 ? left : (previous + x) / 2;
      var zoneWidth = points.length === 1 ? plotWidth : (next - previous) / 2;
      markup += '<rect class="chart-hover-zone" data-index="' + index +
        '" x="' + zoneStart.toFixed(2) + '" y="' + top +
        '" width="' + zoneWidth.toFixed(2) +
        '" height="' + plotHeight + '"/>';
    });
    return markup;
  }

  function formatTooltipValue(value, percentage) {
    if (value === null || value === undefined || value === '') return '—';
    return formatAxisValue(Number(value), percentage);
  }

  function hideChartTooltip() {
    var element = document.getElementById('chart-tooltip');
    if (element) element.hidden = true;
  }

  function showChartTooltip(event, point, series, line, x) {
    var element = document.getElementById('chart-tooltip');
    if (!element || !point) return;

    var rows = series.map(function (item) {
      var value = numericValue(point, item.field);
      return '<div class="chart-tooltip-row"><span class="chart-tooltip-dot" style="background:' +
        escapeHtml(item.color || '#fff') + '"></span><span>' + escapeHtml(item.label) +
        '</span><strong>' + escapeHtml(formatTooltipValue(value, item.percentage)) +
        '</strong></div>';
    }).join('');
    element.innerHTML = '<div class="chart-tooltip-time">' +
      escapeHtml(formatTimestamp(point.ts)) + '</div>' + rows;
    element.hidden = false;

    if (line) {
      line.setAttribute('x1', x.toFixed(2));
      line.setAttribute('x2', x.toFixed(2));
      line.setAttribute('visibility', 'visible');
    }

    var offset = 14;
    var left = event.clientX + offset;
    var top = event.clientY + offset;
    var bounds = element.getBoundingClientRect();
    if (left + bounds.width > window.innerWidth - 8)
      left = event.clientX - bounds.width - offset;
    if (top + bounds.height > window.innerHeight - 8)
      top = event.clientY - bounds.height - offset;
    element.style.left = Math.max(8, left) + 'px';
    element.style.top = Math.max(8, top) + 'px';
  }

  function bindChartHover(element, points, series, left, top, plotWidth, plotHeight) {
    var svg = element.querySelector('svg');
    if (!svg) return;
    var line = svg.querySelector('.chart-hover-line');
    Array.prototype.forEach.call(svg.querySelectorAll('.chart-hover-zone'), function (zone) {
      var update = function (event) {
        var index = Number(zone.getAttribute('data-index'));
        if (!isFinite(index) || !points[index]) return;
        showChartTooltip(event, points[index], series, line,
          chartPointX(index, points.length, left, plotWidth));
      };
      zone.addEventListener('mouseenter', update);
      zone.addEventListener('mousemove', update);
      zone.addEventListener('mouseleave', hideChartTooltip);
    });
    svg.addEventListener('mouseleave', function () {
      hideChartTooltip();
      if (line) line.setAttribute('visibility', 'hidden');
    });
  }

  function yAxisMarkup(min, max, percentage, width, left, top, plotHeight, right) {
    var tickCount = 4;
    var markup = [];
    for (var index = 0; index <= tickCount; index++) {
      var ratio = index / tickCount;
      var value = max - (max - min) * ratio;
      var y = top + plotHeight * ratio;
      markup.push('<line x1="' + left + '" y1="' + y.toFixed(2) + '" x2="' +
        (width - right) + '" y2="' + y.toFixed(2) + '" class="chart-grid"/>');
      markup.push('<text x="' + (left - 8) + '" y="' + y.toFixed(2) +
        '" text-anchor="end" dominant-baseline="middle" class="chart-axis-label">' +
        formatAxisValue(value, percentage) + '</text>');
    }
    return markup.join('');
  }

  function xAxisMarkup(points, left, plotWidth, height, bottom) {
    if (!points.length) return '';
    var indexes = [0, Math.floor((points.length - 1) / 2), points.length - 1];
    var markup = [];
    indexes.forEach(function (index, position) {
      if (indexes.indexOf(index) !== position) return;
      var x = left + (points.length === 1 ? plotWidth / 2 : index * plotWidth / (points.length - 1));
      var anchor = position === 0 ? 'start' : (position === indexes.length - 1 ? 'end' : 'middle');
      var label = formatChartTime(points[index].ts);
      if (!label) return;
      markup.push('<text x="' + x.toFixed(2) + '" y="' + (height - bottom + 22) +
        '" text-anchor="' + anchor + '" class="chart-axis-label">' + label + '</text>');
    });
    return markup.join('');
  }

  function chart(elementId, points, field, color, percentage, label) {
    var element = document.getElementById(elementId);
    var samples = points.map(function (point) {
      return {point: point, value: numericValue(point, field)};
    }).filter(function (sample) { return sample.value !== null; });
    if (!samples.length) {
      element.innerHTML = '<p class="empty chart-empty">暂无数据</p>';
      return;
    }
    var values = samples.map(function (sample) { return sample.value; });
    var width = 800, height = 240, left = 64, right = 14, top = 16, bottom = 40;
    var min = percentage ? 0 : Math.min.apply(Math, values);
    var max = percentage ? 100 : Math.max.apply(Math, values);
    if (max <= min) max = min + 1;
    var plotWidth = width - left - right, plotHeight = height - top - bottom;
    var coords = samples.map(function (sample, index) {
      var value = sample.value;
      var x = chartPointX(index, values.length, left, plotWidth);
      var y = top + (max - value) * plotHeight / (max - min);
      return x.toFixed(2) + ',' + y.toFixed(2);
    });
    var area = left + ',' + (height - bottom) + ' ' + coords.join(' ') +
      ' ' + (width - right) + ',' + (height - bottom);
    var grid = yAxisMarkup(min, max, percentage, width, left, top, plotHeight, right);
    var times = xAxisMarkup(samples.map(function (sample) { return sample.point; }),
      left, plotWidth, height, bottom);
    element.innerHTML = '<svg class="chart-svg" viewBox="0 0 ' + width + ' ' + height +
      '" role="img" aria-label="趋势图">' + grid + '<line x1="' + left + '" y1="' + top +
      '" x2="' + left + '" y2="' + (height - bottom) + '" class="axis"/><line x1="' +
      left + '" y1="' + (height - bottom) + '" x2="' + (width - right) + '" y2="' +
      (height - bottom) + '" class="axis"/><polygon points="' + area + '" fill="' +
      color + '" opacity="0.12"/><polyline points="' + coords.join(' ') + '" fill="none" stroke="' +
      color + '" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>' + times +
      chartHoverMarkup(samples.map(function (sample) { return sample.point; }), left, top,
        plotWidth, plotHeight) + '</svg>';
    bindChartHover(element, samples.map(function (sample) { return sample.point; }), [{
      field: field, label: label, color: color, percentage: percentage
    }], left, top, plotWidth, plotHeight);
  }

  function loadCharts(range) {
    currentRange = range;
    fetch('api/metrics.php?id=' + encodeURIComponent(hostId) + '&range=' + encodeURIComponent(range))
      .then(function (response) { return response.ok ? response.json() : []; })
      .then(function (points) {
        chart('chart-cpu', points, 'cpu', '#3498db', true, 'CPU 使用率');
        chart('chart-mem', points, 'mem', '#9b59b6', true, '内存使用率');
        chart('chart-load', points, 'load1', '#e67e22', false, '系统负载');
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
