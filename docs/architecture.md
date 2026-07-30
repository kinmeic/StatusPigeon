# Status Pigeon 架构说明

## 总体架构

Status Pigeon 是一个 **push + pull 混合模型** 的轻量级服务器状态监控系统，由两部分组成：

```
                        ┌─────────────────────────────────────────────┐
   push 模式 agent       │              Hub（监控中心）                 │
  (NAT 后/无公网IP)      │  ┌──────────┐  ┌─────────┐  ┌───────────┐   │
 ┌──────────────┐  POST  │  │ /report  │  │ puller  │  │ cleaner   │   │
 │ collector    │───────▶│  │ handler  │  │ 循环    │  │ 失联+清理 │   │
 │ reporter     │ report │  └────┬─────┘  └────┬────┘  └───────────┘   │
 └──────────────┘        │       │             │                       │
                        │       ▼             │ GET /metrics           │
                        │  ┌──────────────────▼────────────────────┐   │
                        │  │              SQLite 存储               │   │
                        │  │  hosts / metrics_raw / uptime_daily    │   │
                        │  └──────────────────┬────────────────────┘   │
                        │                     │                       │
                        │  ┌──────────────────▼────────────────────┐   │
                        │  │   /api/*  JSON 接口 + 静态状态页(embed)│   │
                        │  └───────────────────────────────────────┘   │
                        └─────────────────────────────────────────────┘
                                              ▲
                                              │ HTTP GET /metrics
                        ┌─────────────────────┴────────────┐
   pull 模式 agent       │  listen 模式 agent (有公网域名)   │
  ┌──────────────┐       │  ┌──────────────────────────┐    │
  │ collector    │       │  │  /metrics  /health       │    │
  │ listener     │◀──────│  │  Bearer 鉴权             │    │
  └──────────────┘       │  └──────────────────────────┘    │
                         └──────────────────────────────────┘
```

## 两种监控模型

### Push 模式（默认，适合 NAT 后/无公网 IP 的机器）

Agent 主动向 Hub 发起 HTTP POST 上报。**能穿透 NAT**，是家用机、OpenWrt 路由器、内网服务器的唯一可行方式。

- Agent 按配置间隔（默认 5 分钟）采集并上报
- Hub 收到后校验 Token + 时间窗口（防重放）→ 入库
- **失联检测**：Hub 后台 `cleaner` 周期性扫描，若某主机超过 `offline_periods`（默认 3 个周期）无上报，判定为 `down` 并更新当天聚合

### Pull 模式（适合有公网域名/可直接访问的主机）

这类主机无需主动上报，而是暴露 `GET /metrics` 接口，由 Hub 主动按 `pull_interval` 拉取。

- Hub 的 `puller` 后台循环遍历 `pull_targets`
- 拉取失败（超时/拒绝）记录日志，连续失联同样由 `cleaner` 基于 `last_seen` 判定 `down`
- 适合公网 VPS、云主机等 Hub 能直接访问的机器

## 模块组成

| 组件 | 路径 | 职责 |
|------|------|------|
| **共享类型** | `pkg/metrics/` | Agent 与 Hub 共用的 Report/Metrics 结构，保证 push/pull 数据契约一致 |
| **Agent** | `agent/` | Go 二进制，双模式（push/listen），gopsutil 采集 |
| **Hub** | `hub/` | Go 二进制：接收上报 + 主动拉取 + 存储 + API + 静态页 |
| **前端** | `hub/assets/` | 静态 HTML/CSS/JS，embed 打包进 hub 单二进制 |

## Hub 内部结构（hub/*.go）

| 文件 | 职责 |
|------|------|
| `config.go` | YAML + 环境变量配置加载 |
| `store.go` | SQLite 存储层：建表、ingest、聚合、查询 |
| `judge.go` | 状态判定（阈值 → operational/degraded） |
| `ingest.go` | push 上报入库流程 |
| `puller.go` | pull 主动拉取循环 |
| `cleaner.go` | 后台失联扫描 + 数据清理 |
| `handler.go` | HTTP 路由：`/report`、`/api/*`、静态页 |
| `main.go` | 入口，组装各后台 goroutine |

## 数据模型（SQLite）

三张表：

- **hosts** — 主机注册表 + 缓存的最新状态/摘要（状态页直读，无需聚合查询）
- **metrics_raw** — 全量原始指标，详情页趋势图用；按 `host_id, ts` 索引
- **uptime_daily** — 按天聚合，状态页 90 天色块条专用（查询极快）

`source` 字段区分数据来源（`push`/`pull`）。

## 状态判定规则

| 状态 | 条件 | 色块颜色 |
|------|------|----------|
| `operational` | CPU ≤ 阈值 且 内存 ≤ 阈值 | 🟩 绿 |
| `degraded` | CPU > 90% 或 内存 > 95% | 🟨 黄 |
| `down` | 失联（超 3 个上报周期无数据） | 🟥 红 |
| `no-data` | 该天无任何样本 | ⬜ 灰 |

- 阈值、失联周期数均可配置
- 当天色块取当天所有样本的**最差状态**
- `uptime_pct` = 非降级样本 / 总样本

## API 接口

| 方法 路径 | 说明 |
|-----------|------|
| `POST /report` | 接收 push 上报（Bearer 鉴权 + 时间窗口） |
| `GET /api/hosts` | 主机列表 + 最新状态/摘要 |
| `GET /api/status?days=90` | 全部主机 N 天聚合（色块条） |
| `GET /api/metrics?id=&range=1h\|24h\|7d` | 单主机指标序列（趋势图） |
| `GET /` | 状态页；`GET /host.html?id=` 详情页 |

## 关键设计决策

1. **纯 Go，无 cgo**：用 `modernc.org/sqlite`（纯 Go 实现）替代 `mattn/go-sqlite3`，使 Hub 可静态交叉编译到任意架构，与 Agent 一致。
2. **embed 打包前端**：HTML/CSS/JS 通过 `//go:embed` 打进 Hub 二进制，部署只需一个文件。
3. **共享 metrics 包**：Agent 与 Hub 引用同一份结构定义，避免数据契约漂移。
4. **缓存最新状态**：`hosts.last_status/last_summary` 让状态页无需实时聚合即可展示当前状态。
