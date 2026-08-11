'use strict';
'require view';
'require form';
'require uci';

return view.extend({
	load: function () {
		return uci.load('statuspigeon');
	},

	render: function () {
		var map = new form.Map(
			'statuspigeon',
			_('Status Pigeon'),
			_('通过 JSON push 将 OpenWrt 路由器状态发送到 Status Pigeon Hub。定时任务和网络事件均调用同一个 shell 上报脚本；此 app 不提供 listen 模式。')
		);

		var section = map.section(form.NamedSection, 'main', 'statuspigeon', _('Agent'));
		section.anonymous = true;

		var option = section.option(form.Flag, 'enabled', _('启用'));
		option.rmempty = false;
		option.default = '0';

		option = section.option(form.Value, 'endpoint', _('目标网站地址'));
		option.rmempty = false;
		option.placeholder = 'https://example.com/report/index.php';
		option.description = _('可填写完整的 /report/index.php、/report/，或 Hub 根地址；根地址会自动补全 /report/index.php。');
		option.validate = function (sectionId, value) {
			if (!value || !/^https?:\/\//i.test(value))
				return _('地址必须以 http:// 或 https:// 开头');
			return true;
		};

		option = section.option(form.Value, 'api_key', _('API key'));
		option.password = true;
		option.rmempty = false;
		option.description = _('对应 Hub 的 api_key；发送时同时使用 Bearer 和 X-API-Key 请求头。');

		option = section.option(form.Value, 'hostname', _('主机名'));
		option.placeholder = _('留空则读取 /proc/sys/kernel/hostname');
		option.description = _('用于状态页显示；留空使用路由器当前主机名。');

		option = section.option(form.Value, 'interval', _('定时上报间隔（秒）'));
		option.datatype = 'uinteger';
		option.default = '300';
		option.rmempty = false;
		option.description = _('最小 15 秒，默认 300 秒。');

		option = section.option(form.Value, 'timeout', _('HTTP 超时（秒）'));
		option.datatype = 'uinteger';
		option.default = '15';
		option.rmempty = false;

		option = section.option(form.Flag, 'report_on_network', _('网络事件上报'));
		option.default = '1';
		option.rmempty = false;
		option.description = _('接口 ifup/ifupdate 后额外立即上报一次。');

		option = section.option(form.Value, 'network_delay', _('网络事件延迟（秒）'));
		option.datatype = 'uinteger';
		option.default = '3';
		option.rmempty = false;
		option.description = _('等待 DHCP、PPPoE 等网络初始化完成后再发送。');

		return map.render();
	}
});
