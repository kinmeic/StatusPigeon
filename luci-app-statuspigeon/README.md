# luci-app-statuspigeon

OpenWrt LuCI app for the Status Pigeon push-only agent. The package uses the current JSON menu/ACL format and a JavaScript LuCI view; configuration is stored in the normal UCI file `/etc/config/statuspigeon`.

## Features

- Configure the hub URL, API key, optional hostname, interval and timeout in LuCI.
- Periodic reports through a procd-managed shell scheduler.
- Extra push after `ifup`/`ifupdate` through `/etc/hotplug.d/iface/95-statuspigeon`.
- JSON body matches `pkg/metrics.Report` used by the Go agent and PHP hub,
  including a stable hashed `device_id`; `hostname` is only a display label.
- No listen mode, inbound port, URL rewrite, `jq`, or resident HTTP server.

## Build and install

Every push to `main` (or a manual run of the `Build luci-app-statuspigeon` workflow) produces two artifacts:

- `luci-app-statuspigeon-ipk-openwrt-24.10.8`: `.ipk` built with the official OpenWrt 24.10.8 SDK for systems that still use opkg.
- `luci-app-statuspigeon-apk-openwrt-25.12.5`: `.apk` built with the official OpenWrt 25.12.5 x86/64 SDK after a fixed SHA-256 verification.

OpenWrt 25.12 uses APK by default, so the APK build is pinned to 25.12.5. The IPK build uses the last SDK series that still produces IPK packages.

Copy this directory into an OpenWrt buildroot package feed, then run:

```sh
make menuconfig                         # select LuCI -> Applications -> luci-app-statuspigeon
make package/luci-app-statuspigeon/compile V=s
opkg install luci-app-statuspigeon_*.ipk  # OpenWrt 24.10 and older
# OpenWrt 25.12: apk add ./luci-app-statuspigeon-*.apk
/etc/init.d/statuspigeon enable
/etc/init.d/statuspigeon start
```

The recommended endpoint is `https://example.com/report/`. The app also accepts `https://example.com/report/index.php`, `/report`, or the Hub base URL. The reporter normalizes the latter two to `/report/`; the explicit `index.php` path remains supported for hosts without a directory index.

The package needs `curl`, `ca-bundle` for HTTPS verification, libubox `jshn`, and OpenWrt's `jsonfilter` (provided by the declared dependencies). Interface addresses are read from `ubus` network status first, with `ip`/`ifconfig` fallbacks. The LuCI page shows the last attempt, last successful submission, reason, and HTTP status. It also provides a `Report Now` button.

A one-shot report can also be triggered with:

```sh
/usr/bin/statuspigeon-report manual
```
