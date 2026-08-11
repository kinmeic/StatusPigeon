# Status Pigeon PHP Hub

这是与 Go Hub 使用同一份 `Report` JSON 契约的 PHP 7.2 + SQLite 版本，适合不能运行常驻 Go 二进制的虚拟主机。它只接收 push 上报，不主动拉取 agent。

## 部署

1. 确认虚拟主机启用了 PHP 7.2（或更高版本）以及 `pdo_sqlite`、`json` 扩展。
2. 将 `hub-php/` 的内容放到网站根目录，复制 `config.example.php` 为 `config.php`。
3. 修改 `config.php` 中的 `api_key`。数据库路径建议放在网站根目录之外，并保证 PHP 进程可写。
4. 直接访问 `/index.php`。本版本不依赖 URL rewrite。

管理页面为 `/admin.php`。首次登录使用当前 `api_key`，进入后可以管理 API key、查询最近接收日志，并设置独立的管理密码。新配置写入同目录的 `config.local.php`，因此 PHP-FPM 用户必须对网站目录有写权限；如果虚拟主机禁止 PHP 写文件，请手动维护 `config.php`。如需让管理页显示固定的完整接收地址，可在 `config.php` 设置 `public_base_url`，例如 `https://example.com/statuspigeon`。

接收地址为：

```text
POST /report/
POST /report/index.php
```

请求头支持 `Authorization: Bearer <api_key>` 和 `X-API-Key: <api_key>`。请求体就是 Go agent 使用的 JSON `Report`，时间戳允许服务器时间前后 5 分钟。每次接收时，Hub 还会记录 PHP 服务器观察到的 `REMOTE_ADDR`，作为主机的“服务端检测 IP”；该地址独立于 agent 上报的 IPv4/IPv6，并在主机详情页显示。

## 直接文件 API

状态页使用以下无需重写的路径：

| 路径 | 作用 |
|---|---|
| `/api/hosts.php` | 主机列表 |
| `/api/status.php?days=60` | 状态页与日聚合（桌面 60 天，移动端 30 天） |
| `/api/metrics.php?id=1&range=24h` | 单主机趋势数据 |
| `/api/index.php?resource=status` | 可选的单文件 API 入口 |

PHP 没有后台进程，因此每次请求会顺带执行失联标记和保留期清理。默认规则与 Go Hub 相同：CPU 超过 90% 或内存超过 95% 标记 `degraded`，超过 3 个 5 分钟周期没有上报标记 `down`。

主机详情页 `host.php?id=...` 与趋势接口需要先在 `admin.php` 登录；状态总览和状态 API 保持公开。详情页使用左宽右窄布局，左侧展示 CPU、内存与负载，右侧展示系统信息、agent 上报的 IP、服务端检测 IP 和最近接收时间。Hub 按 `device_id` 识别设备，Hostname 仅作显示名；磁盘 I/O 与网络瞬时速度不纳入采集、推送或存储。

## Agent 配置

OpenWrt LuCI app 或其他 agent 的目标地址可直接填：

```text
https://example.com/report/
```

也可以使用 `/report/`；目录索引由虚拟主机直接处理，不需要重写规则。
