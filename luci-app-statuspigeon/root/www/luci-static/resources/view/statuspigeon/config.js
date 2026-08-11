'use strict';
'require view';
'require form';
'require fs';
'require ui';
'require uci';

var statusElementId = 'statuspigeon-last-status';
var submitBusy = false;

function formatTimestamp(value) {
	var timestamp = Number(value || 0);
	if (!timestamp)
		return _('Never');

	var date = new Date(timestamp * 1000);
	return isNaN(date.getTime()) ? _('Unknown') : date.toLocaleString();
}

function statusLabel(value) {
	switch (value) {
	case 'success':
		return _('Success');
	case 'failure':
		return _('Failed');
	case 'pending':
		return _('In progress');
	default:
		return _('No report recorded');
	}
}

function renderStatus(data) {
	var element = document.getElementById(statusElementId);
	if (!element)
		return;

	var parts = [
		_('Status') + ': ' + statusLabel(data.status),
		_('Last attempt') + ': ' + formatTimestamp(data.last_attempt),
		_('Last success') + ': ' + formatTimestamp(data.last_success)
	];

	if (data.reason)
		parts.push(_('Reason') + ': ' + data.reason);
	if (Number(data.http_code || 0) > 0)
		parts.push(_('HTTP') + ': ' + data.http_code);
	if (data.message)
		parts.push(_('Message') + ': ' + data.message);

	element.textContent = parts.join(' | ');
}

function renderStatusError(error) {
	var element = document.getElementById(statusElementId);
	if (!element)
		return;

	element.textContent = _('Unable to read report status') + ': ' +
		(error && error.message ? error.message : _('Unknown error'));
}

function emptyStatus() {
	return {
		status: 'never',
		last_attempt: 0,
		last_success: 0,
		reason: '',
		http_code: 0,
		message: ''
	};
}

function parseStatusFile(contents) {
	var data = emptyStatus();
	String(contents || '').split(/\r?\n/).forEach(function (line) {
		var separator = line.indexOf('=');
		if (separator < 1)
			return;
		data[line.slice(0, separator)] = line.slice(separator + 1);
	});
	data.last_attempt = Number(data.last_attempt || 0);
	data.last_success = Number(data.last_success || 0);
	data.http_code = Number(data.http_code || 0);
	return data;
}

function parseStatusResult(result) {
	if (result.code !== 0)
		throw new Error(result.stderr || _('Status command failed'));

	try {
		return JSON.parse(result.stdout || '{}');
	} catch (error) {
		throw new Error(_('Invalid status response'));
	}
}

function readStatusCommand(command, args) {
	return fs.exec(command, args || [], null).then(parseStatusResult);
}

function refreshStatus() {
	return readStatusCommand('/usr/bin/statuspigeon-report', [ 'status' ]).then(function (data) {
		renderStatus(data);
		return data;
	}, function (reportError) {
		return readStatusCommand('/usr/bin/statuspigeon-status', []).then(function (data) {
			renderStatus(data);
			return data;
		}, function (statusError) {
		/*
		 * Keep the direct file fallback for installations that have the file ACL
		 * but do not expose either command through rpcd yet. The /tmp path avoids
		 * /var/run symlink ACL mismatches on OpenWrt.
		 */
		return fs.read('/tmp/statuspigeon/last-report').then(function (contents) {
			var data = parseStatusFile(contents);
			renderStatus(data);
			return data;
		}, function (fileError) {
			if (fileError && (fileError.name === 'NotFoundError' || fileError.name === 'NoDataError')) {
				var data = emptyStatus();
				renderStatus(data);
				return data;
			}
			renderStatusError(fileError || statusError || reportError);
			return null;
		});
		});
	});
}

return view.extend({
	load: function () {
		return uci.load('statuspigeon');
	},

	render: function () {
		var map = new form.Map(
			'statuspigeon',
			_('Status Pigeon'),
			_('Send OpenWrt router metrics to Status Pigeon Hub using JSON push reports. Periodic reports and network events use the same shell reporter. This app has no listen mode.')
		);

		var section = map.section(form.NamedSection, 'main', 'statuspigeon', _('Agent'));
		section.anonymous = true;

		var option = section.option(form.Flag, 'enabled', _('Enabled'));
		option.rmempty = false;
		option.default = '0';

		option = section.option(form.Value, 'endpoint', _('Target URL'));
		option.rmempty = false;
		option.placeholder = 'https://example.com/report/';
		option.description = _('Recommended: https://example.com/report/. /report/index.php, /report, and a Hub base URL are also accepted. A base URL is normalized to /report/.');
		option.validate = function (sectionId, value) {
			if (!value || !/^https?:\/\//i.test(value))
				return _('The URL must start with http:// or https://');
			return true;
		};

		option = section.option(form.Value, 'api_key', _('API key'));
		option.password = true;
		option.rmempty = false;
		option.description = _('The API key configured on the Hub. The reporter sends both Bearer and X-API-Key headers.');

		option = section.option(form.Value, 'hostname', _('Hostname'));
		option.placeholder = _('/proc/sys/kernel/hostname if empty');
		option.description = _('Name shown on the Hub status page. Leave empty to use the router hostname.');

		option = section.option(form.Value, 'interval', _('Report interval (seconds)'));
		option.datatype = 'uinteger';
		option.default = '300';
		option.rmempty = false;
		option.description = _('Minimum 15 seconds; default 300 seconds.');

		option = section.option(form.Value, 'timeout', _('HTTP timeout (seconds)'));
		option.datatype = 'uinteger';
		option.default = '15';
		option.rmempty = false;

		option = section.option(form.Flag, 'report_on_network', _('Report on network events'));
		option.default = '1';
		option.rmempty = false;
		option.description = _('Send an extra report after ifup or ifupdate.');

		option = section.option(form.Value, 'network_delay', _('Network event delay (seconds)'));
		option.datatype = 'uinteger';
		option.default = '3';
		option.rmempty = false;
		option.description = _('Wait for DHCP, PPPoE, and other network initialization before sending.');

		var status = section.option(form.DummyValue, '_last_status', _('Last submission'));
		status.rawhtml = true;
		status.cfgvalue = function () {
			return '<span id="' + statusElementId + '">' + _('Loading...') + '</span>';
		};

		var submit = section.option(form.Button, '_submit_now', _('Report'));
		submit.inputtitle = _('Report Now');
		submit.inputstyle = 'apply';
		submit.description = _('Save the current settings and send a report immediately.');
		submit.onclick = function () {
			if (submitBusy)
				return;

			submitBusy = true;
			return map.save().then(function () {
				return fs.exec('/usr/bin/statuspigeon-report', [ 'manual' ], null);
			}).then(function (result) {
				if (result.code !== 0)
					throw new Error(result.stderr || _('Report command failed'));

				ui.addNotification(null, _('Report submitted successfully.'), 'info');
			}, function (error) {
				ui.addNotification(null, _('Report failed: ') +
					(error && error.message ? error.message : _('Unknown error')), 'error');
			}).then(function () {
				submitBusy = false;
				return refreshStatus();
			});
		};

		return map.render().then(function (node) {
			window.setTimeout(refreshStatus, 0);
			return node;
		});
	}
});
