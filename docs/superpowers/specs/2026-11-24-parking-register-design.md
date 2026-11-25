# 停车场登记模块 · 设计（2026-11-24）

> 状态：用户已批准（2026-11-24），本文档为定稿口径。
> 需求：外来司机微信扫码登记进场；管理员后台看报表/导出 Excel；分司机端与管理员端。

## 1. 已确认口径（用户拍板）

| 决策点 | 结论 |
|--------|------|
| 出场登记 | **只登记进场**，不做出场/在场统计 |
| 自定义字段范围 | **全公司一套**（全局配置），非每小区独立 |
| 微信集成 | **纯 H5 免授权**（微信内扫码直接填，无 openid/公众号依赖） |
| 重复扫码 | **不限制**，每次扫码生成新记录 |
| 数据可见性 | 总部管理员看全部；**项目账号登录后台只能看自己小区** |
| 二维码 | **用户自行生成**：系统只提供带「小区名+门名」标签的入口 URL（复制用），不做二维码渲染 |
| 驾驶舱 | 后台必须有数据驾驶舱展示（KPI + 趋势 + 小区分布） |
| 报表/导出 | 筛选维度：小区 + 门 + 日期范围；明细含车牌/姓名/手机号/访问户号/事由 |

## 2. 架构（复用已验证先例）

- **司机端**：独立轻量 H5 静态页 `public/parking.html`（照 `invoice.html` 模式，单文件无构建，弱网秒开）。
  - URL：`域名/parking.html?p={gate_entry_token}`（48 位随机 token，仿 `invoice_project_bindings.entry_token`）
  - 流程：GET `/api/parking/entry`（取小区名/门名/字段配置）→ 填表 → POST `/api/parking/register` → 完成屏（✓ + 小区名·门名 + 车牌 + 时间）
- **管理端**：现有 SPA 新页面 `/parking`「停车场登记」，4 个 Tab：
  1. **数据驾驶舱**：6 项 KPI（今日登记/昨日对比、近 7 日、本月、累计、启用门、今日高峰）+ 7 张图表（近 7/30/90 天趋势、24 小时时段分布、事由分布、小区排行、门排行、星期分布、近 6 个月）+ 最近登记明细；复用报表中心 RCard/RSection + Chart.js 样式
  2. **登记数据**：日期范围 + 小区 + 门筛选 → 明细表格 + 分页 + 导出 Excel
  3. **门与入口 URL**：每小区门的增删改/启停；每门展示「小区名 · 门名」+ 入口 URL + 复制按钮（二维码用户自行生成）；禁用门后旧码失效（entry 返回 404）
  4. **登记字段设置**（仅管理员）：事由单选选项 + 自定义字段（label/类型 文本·数字·单选/必填/启用）
- **公开接口鉴权**：照开票模式——无中间件，凭不透明 token + 启用开关校验（`GATE_DISABLED` / `ENTRY_REQUIRED`）。

## 3. 数据模型（2 张新表 + 全局配置 JSON，不改现有表）

```sql
parking_gates           -- 小区的门
  id, project_id(idx), name varchar(60), entry_token varchar(64) unique,
  enabled tinyint default 1, timestamps, UNIQUE(project_id, name)

parking_registrations   -- 登记记录
  id, gate_id(idx), project_id(idx), gate_name varchar(60), project_name varchar(120),
  plate varchar(20), name varchar(60),
  phone varchar(20), visit_target varchar(120), reason varchar(30),
  extra JSON nullable, ip varchar(45), created_at(idx), updated_at


字段配置 → legacy_json_snapshots(file_name='parking_config.json')  -- 沿用工资条配置先例
  { reasons: [...单选选项], fields: [{key,label,type:text|number|radio,required,enabled,options:[...]}] }
```

- 固定字段：车牌号*、司机姓名*、手机号*（11 位校验）、访问户号/受访人*、事由*（单选，选项可配，默认 拜访/送货/施工/快递/其他）。
- 副本身份：`gate_name` / `project_name` 由 register 时冗余写入，门/小区改名或删门后历史报表仍可读。
- 自定义字段答案存 `extra` JSON（key = label 的 md5 前 8 位，保证改 label 不丢历史数据）。

## 4. 接口

公开（无登录）：
- `GET /api/parking/entry?p={token}` → `{project_name, gate_name, config:{reasons, fields}}`；缺参 400 `ENTRY_REQUIRED`、门不存在/禁用 404 `GATE_DISABLED`
- `POST /api/parking/register?p={token}` → 校验车牌正则（7-8 位，含新能源）/手机号/必填/单选值/自定义字段类型 + **同 IP 限频（>10 次/分钟 → 429 `TOO_MANY_REQUESTS`）** → 入库 → `{ok, project_name, gate_name, plate, time}`

管理端（X-Token，控制器内 requireAccount）：
- `GET/POST /api/parking/gates`、`POST /api/parking/gates/delete` —— 门管理（admin，`parking_manage` 权限）
- `GET/POST /api/parking/config` —— 字段配置（admin）
- `GET /api/parking/stats?days=7|30|90` —— 驾驶舱：KPI（今日/昨日对比、近7日、本月、累计、启用门、今日高峰）+ 趋势/24小时/事由/小区/门/星期/近6月 + 最近登记
- `GET /api/parking/list?from&to&project_id&gate_id&page` —— 明细分页
- `GET /api/parking/export?同 list 参数` —— xlsx（列：登记时间/小区/门/车牌/姓名/手机号/访问户号/事由/自定义字段…，仿 `InvoiceAdminController::export` 最简模式）
- **范围强制**：非 admin 账号调 stats/list/export 时 `project_id` 强制取 `auth.project_id`（照考勤导出先例）；门/配置管理 403。

## 5. 权限与菜单

- `AppModules` 新增模块 `parking`「停车场管理」，权限点：`parking_view`（登记数据查看）、`parking_manage`（门与字段配置，仅 admin 实际放行）。
- SPA 菜单新增 section「停车场管理」（icon `car`，Icon.vue 补一个 car 图标），页面 key `parking`，路由 `/parking`。

## 6. 安全与错误处理

- token 不可猜（48 位随机）；门禁用/删除后 entry 立即 404，已贴二维码自然失效。
- register 无登录无验证码 → 靠 IP 限频 + 字段长度/类型校验兜底；记录 ip 供审计。
- 导出全字段含手机号：管理员与项目账号（限自己小区）均可见，用户未要求脱敏。
- 校验失败统一 `{ok:false, error, code}` + 语义化状态码（400/403/404/422/429）。

## 7. 测试

- PHPUnit（sqlite + RefreshDatabase，照 `InvoiceMvpTest` 夹具模式）：
  - `ParkingPublicTest`：entry 正常/禁用/缺参；register 成功（含自定义字段）、车牌非法、手机号非法、必填缺、单选值非法、数字字段非数字、限频 429、禁用门 404。
  - `ParkingAdminTest`：门 CRUD（401/403/成功）、配置读写、list/export/stats 范围强制（项目账号只能自己小区）、导出内容断言。
- Vitest：`parkingLogic.test.js`（筛选拼参、字段校验、URL 标签、KPI 计算等纯函数）。

## 8. 明确不做（本期 YAGNI）

出场登记、车辆照片、黑名单、公众号授权、防重复提交、每小区独立字段、二维码图片渲染。

## 9. 交付约定

- 版本 v2026.11.24.1 起；每完成一批：changelog.json + 开发记录 + 交接文档同步；部署照 §5.1 流程（本次含 `migrate --force`）。
