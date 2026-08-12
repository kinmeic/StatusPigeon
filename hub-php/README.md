# Status Pigeon PHP Hub

这是与 Go Hub 使用同一份 `Report` JSON 契约的 PHP 7.2 + SQLite 版本，适合不能运行常驻 Go 二进制的虚拟主机。它只接收 push 上报，不主动拉取 agent。

## 部署

1. 确认虚拟主机启用了 PHP 7.2（或更高版本）以及 `pdo_sqlite`、`json` 扩展。
2. 将 `hub-php/` 的内容放到网站根目录，复制 `config.example.php` 为 `config.php`。
3. 修改 `config.php` 中的 `api_key`。数据库路径建议放在网站根目录之外，并保证 PHP 进程可写。
4. 直接访问 `/index.php`。本版本不依赖 URL rewrite。

管理页面为 `/admin.php`。首次登录使用当前 `api_key`，进入后可以管理 API key、查询最近接收日志，并设置独立的管理密码。新配置写入同目录的 `config.local.php`，因此 PHP-FPM 用户必须对网站目录有写权限；如果虚拟主机禁止 PHP 写文件，请手动维护 `config.php`。如需让管理页显示固定的完整接收地址，可在 `config.php` 设置 `public_base_url`，例如 `https://example.com/statuspigeon`。

管理登录默认启用防暴力破解保护：同一直接连接来源在 15 分钟内连续 5 次凭据错误后临时锁定 15 分钟；每次失败会按 250ms、500ms、1s、2s…递增延迟，达到锁定阈值时返回 HTTP `429` 和 `Retry-After`。登录成功后会清除该来源的失败状态。失败、触发锁定、CSRF 校验失败和成功登录都会写入 SQLite 的安全审计日志，可在管理页的“日志查询”中查看；锁定期间不会为每一次重复请求追加日志，避免攻击者利用日志写入制造存储压力。日志默认保留 90 天。可在 `config.php` 调整 `login_max_failures`、`login_window_seconds`、`login_lockout_seconds`、`login_delay_base_ms`、`login_delay_max_ms` 和 `login_audit_retention_days`。限速使用服务器看到的 `REMOTE_ADDR`，不会信任客户端提交的 `X-Forwarded-For` 来绕过限制。

接收地址为：

```text
POST /report/
POST /report/index.php
```

请求头支持 `Authorization: Bearer <api_key>` 和 `X-API-Key: <api_key>`，并要求 `Content-Type: application/json`。请求体就是 Go agent 使用的 JSON `Report`，时间戳允许服务器时间前后 5 分钟；内容完全相同的网络重试不会重复累计统计。`api_key` 为空时默认拒绝接收，只有隔离开发环境才应显式设置 `allow_unauthenticated_reports => true`。每次接收时，Hub 会尝试记录公网服务端观察地址，并与 agent 上报的 IPv4/IPv6 合并、按 IP 去重，在详情页按 `IP@接口` 格式显示。若 PHP 位于内网反向代理之后，Hub 只在直接连接地址为内网时读取常见的 `X-Forwarded-For`、`X-Real-IP` 或 `CF-Connecting-IP`；解析 `X-Forwarded-For` 时从代理链末端选择公网地址，避免采用客户端可伪造的最左值。没有可确认的公网地址时不会显示错误的 `@hub` 内网地址。

## 直接文件 API

状态页使用以下无需重写的路径：

| 路径 | 作用 |
|---|---|
| `/api/hosts.php` | 完整主机列表（需管理会话） |
| `/api/status.php?days=60` | 状态页与日聚合（桌面 60 天，移动端 30 天） |
| `/api/metrics.php?id=1&range=24h` | 单主机趋势数据 |
| `/api/index.php?resource=status` | 可选的单文件 API 入口 |

PHP 没有后台进程，因此每次请求会顺带执行失联标记和保留期清理。默认规则是内存超过 95% 标记 `degraded`，超过 3 个 5 分钟周期没有上报标记 `down`。CPU 使用率不再采集、传输或参与状态判定。

主机详情页 `host.php?id=...`、完整主机列表与趋势接口需要先在 `admin.php` 登录；状态总览和经过脱敏的状态 API 保持公开，不返回设备唯一 ID、IP、Agent 版本和硬件详情。详情页使用左宽右窄布局，左侧按“系统负载、内存、磁盘占用率”展示趋势，右侧展示 CPU 型号、内存/磁盘大小、合并后的 IP 列表和最近接收时间。Hub 按 `device_id` 识别设备，Hostname 仅作显示名；CPU 使用率、磁盘 I/O 与网络瞬时速度不纳入采集、推送或存储。

## Agent 配置

OpenWrt LuCI app 或其他 agent 的目标地址可直接填：

```text
https://example.com/report/
```

也可以使用 `/report/`；目录索引由虚拟主机直接处理，不需要重写规则。
