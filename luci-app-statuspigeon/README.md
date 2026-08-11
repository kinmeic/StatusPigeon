# luci-app-statuspigeon

OpenWrt LuCI app for the Status Pigeon push-only agent. The package uses the current JSON menu/ACL format and a JavaScript LuCI view; configuration is stored in the normal UCI file `/etc/config/statuspigeon`.

## Features

- Configure the hub URL, API key, optional hostname, interval and timeout in LuCI.
- Periodic reports through a procd-managed shell scheduler.
- Extra push after `ifup`/`ifupdate` through `/etc/hotplug.d/iface/95-statuspigeon`.
- JSON body matches `pkg/metrics.Report` used by the Go agent and PHP hub.
- No listen mode, inbound port, URL rewrite, `jq`, or resident HTTP server.

## Build and install

每次推送到 `main`（或手动运行 `Build luci-app-statuspigeon` workflow）都会生成两个 artifact：

- `luci-app-statuspigeon-ipk-openwrt-24.10.8`：使用 OpenWrt 24.10.8 官方 SDK 生成 `.ipk`，兼容仍使用 opkg 的系统。
- `luci-app-statuspigeon-apk-openwrt-25.12.5`：使用 OpenWrt 25.12.5 官方 x86/64 SDK 生成 `.apk`，并在下载后校验固定 SHA-256。

25.12 系列默认使用 APK，因此 APK 构建明确固定到 25.12.5；IPK 构建使用仍提供 IPK 的 24.10.8 SDK。

Copy this directory into an OpenWrt buildroot package feed, then run:

```sh
make menuconfig                         # select LuCI -> Applications -> luci-app-statuspigeon
make package/luci-app-statuspigeon/compile V=s
opkg install luci-app-statuspigeon_*.ipk  # OpenWrt 24.10 及更早版本
# OpenWrt 25.12：apk add ./luci-app-statuspigeon-*.apk
/etc/init.d/statuspigeon enable
/etc/init.d/statuspigeon start
```

The endpoint can be `https://example.com/report/index.php`, `https://example.com/report/`, or the hub base URL. The shell script normalizes the latter two to the real PHP file path, so no rewrite rule is needed.

The package needs `curl`, `ca-bundle` for HTTPS verification, and libubox `jshn` (provided by the declared dependencies). A one-shot report can be triggered with:

```sh
/usr/bin/statuspigeon-report manual
```
