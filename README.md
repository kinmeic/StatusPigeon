# Status Pigeon 🐦

轻量级服务器状态监控系统。Go 实现的 **Hub（监控中心）** + **Agent（被监控端）**，SQLite 存储，状态页风格 Web 展示（类似 status.deepseek.com / GitHub Status：整体状态横幅 + 每主机 90 天 uptime 色块条）。

## 特性

- **混合监控模型**
  - **Push**：Agent 主动上报。穿透 NAT，适合家用机、OpenWrt 路由器、内网服务器。
  - **Pull**：Hub 主动拉取。适合有公网域名、可直接访问的云主机。
- **失联检测**：Agent 掉线/断网/宕机，Hub 通过超时判定自动标记为 `down`。
- **单二进制部署**：Hub / Agent 各一个可执行文件，纯 Go 无 cgo，可交叉编译。Agent 支持 Linux/Mac/OpenWrt(mipsle) 等；Hub 因 SQLite 驱动（modernc.org/sqlite）限制不支持 mips 架构（Hub 一般部署在标准服务器上）。
- **精简指标**：基础信息 + CPU + 内存 + 负载。
- **状态页 UI**：90 天 uptime 色块条 + hover 详情 + 主机趋势图（Chart.js）。
- **数据自管理**：SQLite，按天自动清理过期数据。

## 目录结构

```
status-pigeon/
├── pkg/metrics/        # Agent 与 Hub 共享的指标类型
├── agent/              # 被监控端（push / listen 双模式）
└── hub/                # 监控中心（接收 + 拉取 + 存储 + API + 状态页）
```

## 快速开始

### 1. 编译

需 Go 1.21+。若网络受限，设置国内代理：`export GOPROXY=https://goproxy.cn,direct`

```bash
# Hub
cd hub && go build -o dist/statuspigeon-hub .

# Agent
cd ../agent && go build -o dist/statuspigeon-agent .
```

交叉编译（OpenWrt 等）见各目录的 `Makefile`：`cd hub && make all` / `cd agent && make all`。

### 2. 部署 Hub（监控中心）

```bash
cd hub
cp config.example.yaml config.yaml
# 编辑 config.yaml：设置 auth token、按需配置 pull_targets
./dist/statuspigeon-hub -c config.yaml
```

打开 `http://<hub-ip>:9527/` 即可看到状态页。

### 3. 部署 Agent

**Push 模式**（默认，NAT 后机器）：

```bash
cd agent
cp config.example.yaml config.yaml
# 编辑：设置 server_url 指向 Hub 的 /report，token 与 Hub 的 auth 一致
./dist/statuspigeon-agent -c config.yaml
```

**Listen 模式**（有公网域名的机器）：

```yaml
# config.yaml
mode: listen
listen_addr: ":9527"
token: "your-pull-token"   # 与 Hub pull_targets[].token 一致
```

然后在 Hub 的 `config.yaml` 的 `pull_targets` 中添加该主机：

```yaml
pull_targets:
  - name: "my-public-server"
    endpoint: "http://my-public-server.com:9527/metrics"
    token: "your-pull-token"
    enabled: true
```

### 4. 安装为系统服务

二进制内置 `install` / `uninstall` 子命令，自动按当前平台生成对应服务配置并注册：

```bash
# 安装（自动启动）
sudo ./dist/statuspigeon-agent install -c /etc/statuspigeon/config.yaml
sudo ./dist/statuspigeon-hub    install -c /etc/statuspigeon/config.yaml

# 卸载（停止并删除服务）
sudo ./dist/statuspigeon-agent uninstall
sudo ./dist/statuspigeon-hub    uninstall
```

各平台行为：

| 平台 | 生成内容 | 管理命令 |
|------|----------|----------|
| Linux (systemd) | `/etc/systemd/system/<name>.service` | `systemctl start/stop/enable` |
| OpenWrt (procd) | `/etc/init.d/<name>` | `/etc/init.d/<name> start/stop` |
| macOS (launchd) | `/Library/LaunchDaemons/<name>.plist` | `launchctl load/unload` |
| Windows | 注册系统服务 | `sc start/stop` |

> OpenWrt 会自动识别（检测 `/etc/openwrt_release`），无需额外参数。
> install 子命令选项：`-c` 指定配置文件路径（会转为绝对路径写入服务配置）、`-user` 指定运行用户（仅 systemd）、`-name` 指定服务名。

## 采集指标

| 类别 | 字段 |
|------|------|
| 基础 | os、kernel、arch、uptime |
| CPU | usage%、load1/5/15 |
| 内存 | total/used/available、used_pct、swap_total/swap_used |

## 状态判定

| 状态 | 触发条件 | 色块 |
|------|----------|------|
| operational | 正常 | 🟩 |
| degraded | CPU > 90% 或 内存 > 95%（可配置） | 🟨 |
| down | 失联超 3 个上报周期 | 🟥 |
| no-data | 当天无数据 | ⬜ |

阈值与失联周期数均在 Hub `config.yaml` 配置。

## 配置说明

### Hub（hub/config.yaml）

| 项 | 默认 | 说明 |
|----|------|------|
| `http_addr` | `:9527` | HTTP 监听地址 |
| `auth` | — | push 上报鉴权 token |
| `pull_interval` | `5m` | 拉取间隔 |
| `pull_targets` | — | 主动拉取目标列表 |
| `retention_days` | `90` | 数据保留天数 |
| `degraded_cpu` | `90` | CPU 降级阈值 % |
| `degraded_mem` | `95` | 内存降级阈值 % |
| `offline_periods` | `3` | 失联周期数 |
| `db_path` | `data/statuspigeon.db` | SQLite 路径 |

### Agent（agent/config.yaml）

| 项 | 默认 | 说明 |
|----|------|------|
| `mode` | `push` | `push` 或 `listen` |
| `hostname` | 系统主机名 | 主机名 |
| `server_url` | — | push：Hub 地址（至 /report） |
| `token` | — | push：与 Hub auth 一致；listen：拉取鉴权 |
| `interval` | `300` | push：上报间隔秒 |
| `listen_addr` | `:9527` | listen：监听地址 |

> 所有配置项均可通过 `STATUSPIGEON_*` 环境变量覆盖。

## 安全建议

- **务必设置强 token**：`openssl rand -hex 32` 生成，Hub 与 Agent 保持一致。
- push 鉴权 + 时间窗口校验（±5 分钟）防伪造与重放。
- listen 模式也支持 Bearer 鉴权，公网暴露务必启用。
- 数据库目录建议设权限 `0750`，避免 Web 直接访问。

## 技术栈

- Go 1.21+（gopsutil/v3 采集、modernc.org/sqlite 纯 Go 存储）
- 前端：原生 HTML/CSS/JS + Chart.js（本地引入，无外网依赖）
- 嵌入式静态资源（`go:embed`），Hub 单文件部署
