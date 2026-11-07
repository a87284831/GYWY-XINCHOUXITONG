# 采购模块 API 契约（Vue3 SPA 重建基准）

> 依据后端源码逐字核对：`app/Http/Controllers/Api/PurchaseController.php`、`app/Purchase/Support.php`、`app/Purchase/handlers/{auth,products,fill,admin,budget,summary,dashboard,system,export_import,log}.php`，以及旧前端 `public/purchase/index.html`（__dashAllShim）与 `public/purchase/assets/*.js`（压缩产物，仅做端点串提取核对）。
> 后端 API 完全不动，本文档是重建 11 个采购页面的唯一基准。键名均为 PHP 数组键的逐字精确值；无法从代码 100% 确认处标注「待线上对照」。

---

## 一、总则

### 1.1 BaseURL 与路由

- **BaseURL：`/api/purchase/`**。路由注册为 `Route::any('/purchase/{path?}', PurchaseController::class.'@index')->where('path','.*')`，**任意 HTTP 方法都会进入同一入口**，由 `dispatch($uri, $method)` 匹配「URI + 方法」；方法不匹配返回 `{ok:false,msg:'接口不存在'}`（HTTP 200）。
- URI 即 dispatch 中的 path（如 `fill/items`、`admin/overview`），大小写敏感。

### 1.2 鉴权（双重：平台级 + handler 级）

1. **平台鉴权（所有请求，包括 `login/logout/me`）**：控制器先执行 `requireAccount()`——读请求头 **`X-Token`**，到 Cache `payroll_api_token:{token}` 查 account_id，再取 `payroll_accounts` 行。无 token / token 失效返回 **HTTP 401**：`{"ok":false,"error":"未登录"}`（注意：用的是 **`error`** 键，不是 `msg`）。
2. **上下文映射**：平台用户 → 采购上下文（`Support::setContext`）：
   - `role`：平台 role === `'admin'` → `'admin'`，**其余一律 `'staff'`**（平台 project/staff 等角色都映射为 staff）。
   - `project_id`：由平台账号 `project_name` 查 `payroll.payroll_projects.id`；`project_name` 为空则 `project_id = null`（staff 后续大多数端点会报「项目缺失」）。
   - `id/username/name`：平台账号字段。
3. **handler 级鉴权**：每个 handler 内再调 `require_auth()`（上下文为空 → 401 `{"ok":false,"msg":"未登录或登录已过期"}`）或 `require_admin()`（非 admin → **HTTP 403** `{"ok":false,"msg":"无权限"}`）。正常请求下第二道不会触发，属防御性双重鉴权。
4. **登录换 token**：新前端应使用平台登录 `POST /api/login`（AuthController@login）获取平台 token，之后所有采购请求带 `X-Token` 头。采购模块遗留的 `POST /api/purchase/login` 虽存在，但被平台鉴权前置，**不能**作为独立登录入口。
5. 二进制导出的下载请求同理需带 `X-Token`（旧前端 shim 对下载用 URL `?token=` 或 localStorage 的 `gw_token`/`token`/`purchase_token`，再以 `X-Token` 头发送）。

### 1.3 响应封装

与 finance 模块不同，**purchase 全部 handler 都是 `{ok:true, ...}` 显式包装**。但注意三种形态：

| 形态 | 说明 |
|---|---|
| `{ok:true, data:...}` | 绝大多数端点：数据在 `data` 键 |
| `{ok:true, total:N, data:[...]}` | **分页端点把 `total` 与 `data` 平级**：`GET products`（`total`）、`GET oplogs`（`total,page,page_size`）、`GET notifications`（`total,unread`）。`products/unbound` 是 `total_rows,total_amount,data` |
| `{ok:true, msg:'...', ...}` | 写操作带 `msg`（如「已保存」）；部分还带额外平级键：`products/import` 带 `inserted/updated/errors`，`import` 带 `unbound` |

其他约定：

- **根路径** `GET /api/purchase/` → `{ok:true,msg:'广盈物业采购管理系统 API 运行中',time:'Y-m-d H:i:s'}`。
- HTTP 状态码：成功/业务失败都是 **200**；401 未登录、403 非 admin、500 服务器异常（`{"ok":false,"msg":"服务器错误: ..."}`）。
- ** PurchaseStop 异常**：handler 抛 `App\Purchase\PurchaseStop`（payload + status）；导出类 handler 输出二进制后抛空 PurchaseStop 结束请求。
- **二进制导出**：直接返回 xlsx 字节流（无 JSON 包装），响应头由 handler 经 `Support::header()` 采集后附加：`Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`、`Content-Disposition: attachment; filename="...ascii..."; filename*=UTF-8''...编码中文名...`（部分导出只有 rawurlencode 的 filename，无 filename*，如 `export/my-month`、`export/year`、预算模板）。

### 1.4 数据格式约定

- **月份**：字符串 `'Y-m'`（如 `2026-09`），凡 `month` 参数缺省一律 `date('Y-m')`（服务器当前月）。**年**：`'Y'`（如 `2026`）。
- **日期**：`'Y-m-d'`；时间戳字段 `created_at/returned_at/last_fill` 等为 MySQL datetime 字符串。
- **金额**：一律**元**（浮点，服务端 `round(x,2)`；无分→元换算）。`rate` 为百分数（`round(x,1)`，如 `85.3` 表示 85.3%；无预算时为 `null`）。
- **规格归一化 `norm_spec()`**：全角转半角 + 空白折叠，入库/匹配前服务端自动处理（前端无需处理，但展示的 spec 与用户输入可能不完全一致）。
- **归档锁**：`monthly_archive` 表存在该月记录即锁定；此后填报增删改、确认/退回、导入报价、清单外处理全部拒绝（错误 msg 均含「本月已归档锁定」），导出不受影响。
- **通知副作用 `notify_user()`**：同时写采购库 `notifications` 表和平台 `payroll.app_messages`（主页铃铛），link 固定为 `https://www.88shangcheng.top/purchase/index.html#/...`。
- **项目同步副作用**：**每个**采购 API 请求都可能触发一次项目同步（Cache `purchase_sync_projects_tick` 节流，全局每 5 分钟最多 1 次）：以 `org_nodes(type=project, enabled=1)` 为权威源全量 upsert 进采购库 `projects` 表（**采购数据唯一事实源为 `payroll` 主库；旧库 `gy_procurement` 已于 2026-11-07 删除**），并**删除**组织架构中不存在的项目。前端不应依赖采购库 `projects` 表 id 以外的东西；业务项目 id 统一 = 平台 `payroll_projects.id`。

### 1.5 分页约定

仅 3 个端点分页，参数均为 query：`page`（≥1）、`page_size`：

| 端点 | 默认 page_size | 范围 | 响应平级键 |
|---|---|---|---|
| `GET products` | 100 | 10–200 | `total` |
| `GET oplogs` | 50 | 10–200 | `total,page,page_size` |
| `GET notifications` | 20 | 5–50 | `total,unread` |

其余列表端点（fill/items、admin/customs、my/month-items 等）**无分页**。

### 1.6 调用时序（新前端标准流程）

1. 平台登录：`POST /api/login`（Body `{username, password}`）→ 平台返回 token（Cache 有效期 8 小时，见 `tokenFor()`：`Str::random(64)` 存 `payroll_api_token:{token}`）。旧前端兼容从 URL `?token=` 或 localStorage（`gw_token`/`token`/`purchase_token`）取。
2. 之后每个采购请求带请求头 `X-Token: <平台token>`，BaseURL `/api/purchase/`。
3. 建议请求封装统一判定：HTTP 401 → 跳平台登录；`ok===false` → toast `msg`；`ok===true` → 取 `data`（或平级 `total/rows` 等）。
4. 下载类（`export*`、`products/template`、`budgets/template`）：GET 请求带 `X-Token` 头取 Blob；响 Content-Type 为 xlsx MIME 时按 `Content-Disposition` 中的文件名保存（有 `filename*=UTF-8''` 的取其 rawurlencode 解码值，否则解码 `filename`）。
5. 401/403/500 之外的失败一律 HTTP 200，**不能**用 axios catch 捕获业务错误。

---

## 二、角色模型

平台 role=`admin` → 采购 admin；其余 → staff。`require_admin()` 失败返回 403「无权限」；staff 被强制限定在**本人所属项目**（`project_id` 取上下文，忽略前端传参）。

| 端点（组） | 权限 | staff 限制 |
|---|---|---|
| `login`/`logout`/`me` | 任意登录（需平台 token） | — |
| `products/lines`,`categories`,`by-name`,`search`,`{id}/synonyms GET` | 任意登录 | — |
| `products` 列表 | 任意登录 | — |
| `products` POST / PUT / DELETE、`products/template`、`products/import`、`products/unbound`、`synonyms` POST / DELETE | **admin-only** | 403 |
| `fill/window` | 任意登录 | — |
| `fill/items` GET / POST、`fill/items/{id}` PUT / DELETE / submit、`fill/frequency` | 任意登录 | 查询/写入强制 `project_id`=本人项目；只能操作本项目的记录；受窗口期限制（admin 不受限）；归档月禁写 |
| `my/months`、`my/month-items` | 任意登录 | 强制本人项目（`project_id` 参数仅 admin 生效） |
| `admin/*` 全部（overview/archive/unarchive/confirm/unconfirm/return/return-project/confirm-batch/customs*） | **admin-only** | 403 |
| `budgets*` 全部 | **admin-only** | 403 |
| `summary/*` 全部 | **admin-only** | 403 |
| `oplogs` | **admin-only** | 403 |
| `settings` GET / PUT | **admin-only** | 403 |
| `export`、`export/year`、`import`、`import/batch` | **admin-only** | 403 |
| `export/my-month` | 任意登录 | 强制本人项目（admin 可传 `project_id`） |
| `notifications*` 全部 | 任意登录 | 仅本人通知（`user_id`=本人） |
| `dashboard/*` 全部 | 任意登录 | SQL 级限定 `project_id`=本人项目（`dash_scope`），无数据返回空结构 |
| `projects/options`、`projects` GET/POST/PUT/DELETE、`windows*` 全部 | **admin-only** | 403 |

staff 写入的额外状态机约束（fill 模块）：

- `draft` 可改可删可提交；`submitted` 后员工**只能**整体撤销提交（PUT 仅 `{"status":"draft"}` 一个字段），不可改字段、不可删；admin 可改可删。
- `confirmed` 不可改不可删（admin 也一样）。
- 窗口期外员工仅允许操作 `returned` 状态的记录（退回修改不受窗口限制）。
- `returned` 记录必须员工重新提交后 admin 才能确认。

---

## 三、端点清单

约定：「data 结构」栏给出 `data` 键内部（或平级键）；`purchase_items` 行字段：`id, month, project_id, line, product_id(可null), item_name, brand, spec, unit, quantity, stock, reason, use_location, remark, is_custom, status('draft'|'submitted'|'confirmed'|'returned'), price(可null), return_reason, created_by, resubmitted_at, returned_at, updated_at`；`archived_purchases` 行字段：`id, month, project_id, line, product_id, item_name, brand, spec, unit, quantity, price, total`。

### 3.1 auth（auth.php）

| URI | 方法 | 入参 | data 结构 | 权限 | 备注 |
|---|---|---|---|---|---|
| `login` | POST | body `{username, password}` | `{token, user:{id, username, name, role, project:{id,name}\|null}}` | 任意登录（平台 token 前置） | **遗留兼容**：读采购库 `users` 表并签发本地 JWT；新前端登录走平台 `POST /api/login`，此端点实际不可用为主登录方式 |
| `logout` | POST | 无 | —（`msg:'已退出'`） | 任意登录 | 写操作日志「退出登录」 |
| `me` | GET | 无 | `{user:{id, username, name, role, project:{id,name}\|null}}` | 任意登录 | project 来自上下文 project_id 查平台库 |

### 3.2 商品库（products.php）

| URI | 方法 | 入参 | 返回结构 | 权限 | 备注 |
|---|---|---|---|---|---|
| `products/lines` | GET | 无 | `data:['环境','绿化','工程','行政',...]`（字符串数组，FIELD 排序） | 任意登录 | — |
| `products/categories` | GET | query `line`（必填） | `data:[category,...]` | 任意登录 | 缺 line → `{ok:false,msg:'缺少line参数'}` |
| `products` | GET | query `line, category, keyword, page=1, page_size=100(10–200)` | `total:int` + `data:[{id, line, category, name, brand, spec, unit, aliases}]`（aliases 为 `a\|b\|c` 竖线拼接） | 任意登录 | 分页 |
| `products/by-name` | GET | query `name`（必填）、`line, category`（可选过滤） | `data:{name, category, line, brand, unit, specs:[{id, spec, brand, unit}]}` | 任意登录 | 填报联动：同名称全部规格 |
| `products/search` | GET | query `keyword`（空返回 `data:[]`） | `data:[{id, line, category, name, brand, spec, unit}]` ≤50 条 | 任意登录 | 名称命中优先排序 |
| `products/template` | GET | 无 | **二进制 xlsx** | admin | 模板：条线/一级分类/商品名称/品牌/规格型号/单位；5 条线下拉 |
| `products/import` | POST | multipart `file`（.xlsx/.xls） | `{ok, msg, inserted:int, updated:int, errors:[...≤50]}`（平级键） | admin | 相同（条线+分类+名称+品牌+规格）覆盖更新；事务 |
| `products/unbound` | GET | 无 | `total_rows, total_amount` + `data:[{item_name, spec, unit, rows, amount, last_month, bindable, name_match, missing}]` ≤100 组 | admin | 存档中 product_id 为空的行统计 |
| `products` | POST | body `{line必填, category, name必填, brand, spec必填, unit必填}` | `data:{id:int}` | admin | 写日志「新增」 |
| `products/{id}` | PUT | body 任意子集 `{line, category, name, brand, spec, unit, status}` | `msg:'已更新'` | admin | spec 不能为空；写日志 |
| `products/{id}` | DELETE | — | `msg:'已删除'` | admin | **软删**（status=0）并删除其全部别名 |
| `products/{id}/synonyms` | GET | 路径 id | `data:[{id, alias}]` | 任意登录 | — |
| `synonyms` | POST | body `{product_id, alias}` | `msg:'已添加'` | admin | 唯一键冲突 → `{ok:false,msg:'别名已存在'}` |
| `synonyms/{id}` | DELETE | 路径 id | `msg:'已删除'` | admin | — |

### 3.3 填报（fill.php）

| URI | 方法 | 入参 | 返回结构 | 权限 | 备注 |
|---|---|---|---|---|---|
| `fill/window` | GET | query `month`（默认当月） | `data:{open:bool, msg:'填报开放中'\|..., window:{id, month, start_date, end_date, status}\|null}` | 任意登录 | open = status=1 且今日在 start~end 之间 |
| `fill/items` | GET | query `month`（默认当月）、`project_id`（staff 忽略，强制本人）、`line` | `data:[purchase_items 全字段 + project_name]` | 任意登录 | **无分页**；ORDER BY line, id |
| `fill/items` | POST | body `{month?, project_id?(staff 忽略), line必填, product_id?, item_name必填, brand, spec, unit, quantity, stock, reason, use_location, remark, status:'draft'\|'submitted'(默认draft)}` | `data:{id:int}` | 任意登录 | staff 受窗口期限制；归档月拒绝；**保存即提交**：status=submitted 时写日志并通知全部 admin（`fill_submitted`） |
| `fill/items/{id}` | PUT | body 任意子集 `{line, product_id, item_name, brand, spec, unit, quantity, stock, reason, use_location, remark, status}` | `msg:'已更新'` | 任意登录 | staff：仅本项目、非 confirmed；submitted 仅允许 `{status:'draft'}` 撤销；窗口外仅 returned 可改；传 product_id 联动 `is_custom`（null→1）；returned→submitted 记 `resubmitted_at=NOW()`；仅状态变化写日志/通知 |
| `fill/items/{id}` | DELETE | — | `msg:'已删除'` | 任意登录 | confirmed 不可删；staff 不可删 submitted；窗口外仅 returned；**删 returned 记录会连带 DELETE `archived_purchases` 中匹配（month+project+item_name+spec）行** |
| `fill/items/{id}/submit` | POST | — | `msg:'已提交'` | 任意登录 | draft→submitted；returned→submitted 记 resubmitted_at；写日志 + 通知 admin；已 submitted 报错 |
| `fill/frequency` | GET | query `product_id` 或 `item_name`（至少其一）、`spec, unit, line, project_id(staff 忽略), month`（默认当月） | `data:{count:int, total:float\|null, unit:string, same_unit:bool, detail:[{month, qty, unit}]}` | 任意登录 | 近 3 个自然月（**不含填报月**）存档统计；单位不一致时 total=null、unit=''（前端只显示次数） |
| `my/months` | GET | query `year`（默认当年）、`project_id`（仅 admin） | `data:{year, rows:[{month, window:{...}\|null, open, msg, item_count, confirmed_count, submitted_count, returned_count, return_reason, imported:bool, amount}]}` | 任意登录 | staff 强制本人项目；当年 12 个月 + 往年有数据月份；amount=存档合计 round2 |
| `my/month-items` | GET | query `month`（默认当月）、`project_id`（仅 admin） | `data:{imported:bool, rows:[...]}`：**imported=true** → `{line, item_name, spec, brand, unit, quantity, price, total}`（price/total round2）；**false** → `{line, item_name, spec, brand, unit, quantity, stock, reason, is_custom, status, price:null, total:null, return_reason, returned_at}` | 任意登录 | 已导入读存档，未导入读填报记录——**两种 rows 字段集不同** |

### 3.4 招采审核（admin.php，全部 admin-only）

| URI | 方法 | 入参 | 返回结构 | 备注 |
|---|---|---|---|---|
| `admin/overview` | GET | query `month` | `data:{month, rows:[{project_id, project_name, lines:{'环境':{total, drafts, submitted, confirmed, returned, last_fill}, '绿化':..., '工程':..., '秩序':..., '行政':...}, total, confirmed, returned, has_fill, status:'未填报'\|'已退回'\|'已确认'\|'填报中', last_fill, budget, amount, over:bool, rate}], lines:['环境','绿化','工程','秩序','行政'], summary:{total_items, confirmed, returned, pending, unfilled_count, unfilled:[{project_id, project_name}], total_budget, total_amount, total_rate}, archived:bool}` | 每项目×每条线必有一个键 |
| `admin/items/{id}/confirm` | POST | — | `msg:'已确认'` | returned 不可直接确认；写日志；通知该项目全员（`confirmed`，link `#/my-items?month=...`）；退回后重确认且 price>0 时同步存档数量金额 |
| `admin/items/{id}/unconfirm` | POST | — | `msg:'已撤销确认'` | status 置回 submitted；写日志 |
| `admin/items/{id}/return` | POST | body `{reason}`（必填） | `msg:'已退回给填报人修改'` | 仅 submitted/confirmed 可退；写日志；通知员工（`returned`） |
| `admin/return-project` | POST | body `{month, project_id, line?, reason}`（reason 必填） | `msg:'已退回 N 条给填报人修改'` | 该月该项目（可限条线）全部 submitted/confirmed 批量退回；通知员工 |
| `admin/confirm-batch` | POST | body `{month, project_id, line?}` | `msg:'已确认 N 条，跳过已退回 M 条（需员工重新提交后确认）'` | 仅确认 submitted；**有价格的 returned 记录先同步更新存档**（quantity×price）；confirmed>0 时通知员工 |
| `admin/archive` | POST | body `{month}` | `msg:'本月已归档锁定（N 条全部确认）...'` | 该月全部 purchase_items 必须 confirmed 才可归档；无记录不可归档；失败 msg 会列出最多 8 条未确认示例 |
| `admin/unarchive` | POST | body `{month}` | `msg:'已撤销归档，恢复可编辑'` | DELETE monthly_archive 行 |
| `admin/customs` | GET | 无 | `data:[purchase_items 全字段 + project_name + candidates:[{id, line, category, name, brand, spec, unit}]≤8]` | is_custom=1 且 status='submitted' 的全部记录（跨月，ORDER BY month DESC）；匹配候选时 item_name 会去掉「验收清单外」前缀 |
| `admin/customs/map` | POST | body `{item_id, product_id, save_alias?}` | `msg:'已归入标准商品「...」'` | 明细改写为标准商品字段、is_custom=0；save_alias 时 INSERT IGNORE 别名；写 `audit_records(action='map')` |
| `admin/customs/check-duplicate` | POST | body `{name}`（必填） | `data:[{id, line, category, name, brand, spec, unit}]` ≤20 | 纯名称双向包含匹配 |
| `admin/customs/save` | POST | body `{item_id, mode:'create'\|'merge'}` + 可编辑 `{line, name, category, brand, spec, unit, stock, use_location, remark, alias?, by?}`（merge 需 `product_id`） | `msg:'已新建标准商品「...」并归入'` / `'已归入标准商品「...」'` | **不涉及数量**：`quantity` 不再接收，明细行原数量保持不变（新建标准商品只涉及名称/规格/单位等清单信息）；create：必填 line/category/name + 五元组唯一键防重；merge：以目标商品字段为准（只保留员工 stock/use_location/remark）；alias 默认原 item_name（≠商品名才存）；写 audit_records(action='map'/'new')；事务 |

### 3.5 预算（budget.php，全部 admin-only）

| URI | 方法 | 入参 | 返回结构 | 备注 |
|---|---|---|---|---|
| `budgets` | GET | query `month` | `data:{month, rows:[{project_id, project_name, budget, actual, diff, over:bool, rate, items}], total_budget, total_actual, total_rate}` | budget 无记录=0；items=存档条数 |
| `budgets` | POST | body `{month, rows:[{project_id, amount}...], user_id?}` | `msg:'已保存 N 个项目预算'` | 逐行 upsert（ON DUPLICATE KEY UPDATE amount） |
| `budgets/compare` | GET | query `month` | 同 `GET budgets` | 别名端点（导出导入页用） |
| `budgets/months` | GET | query `year` | `data:{year, months:['YYYY-MM',...]}` | 仅 amount>0 的月份（预算页 12 月速览打✓） |
| `budgets/template` | GET | query `year` | **二进制 xlsx** | 项目 × 1–12 月矩阵 + 合计公式行，回填已设置预算 |
| `budgets/import` | POST | multipart `file` + `year`（form 字段） | `msg:'导入完成：写入 N 条项目×月预算...'` | 按项目名称匹配；**整行空白 = 删除该项目该年全部预算**；写日志 |

### 3.6 操作日志（system.php 的 handle_oplogs；log.php 提供 `log_action()`）

| URI | 方法 | 入参 | 返回结构 | 权限 | 备注 |
|---|---|---|---|---|---|
| `oplogs` | GET | query `action`（精确）、`keyword`（username/detail LIKE）、`date_from`、`date_to`（`DATE(created_at)>=/<=`）、`page=1`、`page_size=50(10–200)` | `total, page, page_size` + `data:[op_logs.*]`（含 `id, user_id, username:'xxx/姓名', role, action, detail, created_at`） | admin | ORDER BY id DESC；失败操作（登录失败 user_id=0, role='-'）也在日志中 |

> `log_action(action, detail)`：所有写操作自动记录（detail 截断 500 字）；日志失败不影响主流程。

### 3.7 站内通知（system.php，全部任意登录、仅本人数据）

| URI | 方法 | 入参 | 返回结构 | 备注 |
|---|---|---|---|---|
| `notifications` | GET | query `page=1`, `page_size=20(5–50)` | `total, unread` + `data:[{id, type, title, content, is_read, created_at}]` | ORDER BY id DESC；type 取值：`fill_submitted, returned, confirmed, price_imported, budget_over, window_open` |
| `notifications/unread-count` | GET | 无 | `{unread:int}`（在顶层，非 data） | 轮询用 |
| `notifications/read-all` | POST | 无 | `msg:'已全部标记为已读'` | — |
| `notifications/{id}/read` | POST | 路径 id | `msg:'已读'` | 仅本人通知可标 |

### 3.8 系统设置与窗口（system.php）

| URI | 方法 | 入参 | 返回结构 | 权限 | 备注 |
|---|---|---|---|---|---|
| `settings` | GET | 无 | `data:{price_threshold:float}`（sys_settings 表 `price_threshold`，缺省 30.0） | admin | 价格异常环比阈值（%） |
| `settings` | PUT | body `{price_threshold}`（限 1–200） | `msg:'阈值已设置为 N%'` | admin | upsert；写日志 |
| `windows` | GET | 无 | `data:[{id, month, start_date, end_date, status}]` ≤24 条 | admin | ORDER BY month DESC |
| `windows` | POST | body `{month, start_date, end_date, status:int(默认1)}` | `msg:'填报窗口已保存'` | admin | 按 month upsert；start>end 报错；**保存时若今日已在窗口期内且 status=1 → 通知全部员工（`window_open`）** |
| `windows/{id}` | DELETE | 路径 id | `msg:'已删除'` | admin | 写日志 |
| `windows/gen` | **POST** | **query** `from='Y-m'`（默认当月）、`count`（默认 12，1–60） | `msg:'已生成 N 个月窗口，跳过已有 M 个月'` + `data:{created, skipped}` | admin | 每月 20 日–min(23,月末)；已有月份跳过。**注意：POST 方法但参数走 query**（handler 读 `$_GET`） |

系统管理补充（同文件，与采购前端相关）：

| URI | 方法 | 入参 | 返回结构 | 权限 | 备注 |
|---|---|---|---|---|---|
| `projects/options` | GET | 无 | `data:[{id, name}]`（平台启用项目） | admin | 代填/筛选用 |
| `projects` | GET | 无 | `data:[{id, code, name, manager, phone, sort_no, status}]` | admin | 权威源=org_nodes 启用项目节点，id 映射平台 payroll_projects.id |
| `projects` | POST | 任意 | **恒定失败** `msg:'项目由主系统人力组织架构统一管理...'` | admin | 假端点，勿在新前端提供入口 |
| `projects/{id}` | PUT | 任意 | 恒定失败（同上） | admin | 同上 |
| `projects/{id}` | DELETE | — | 恒定失败（同上） | admin | 同上 |

> `handle_users_list/create/update/delete` 曾存在于 system.php 且从未注册路由（死代码），2026-09-30 已随 gy_procurement.users 废弃一并删除（旧库 `gy_procurement` 已于 2026-11-07 整体删除）；采购旧登录 handlers/auth.php（login/logout/me）同批删除。账号统一走平台 /api/login。

### 3.9 导出导入（export_import.php）

| URI | 方法 | 入参 | 返回结构 | 权限 | 备注 |
|---|---|---|---|---|---|
| `export` | GET | query `month` | **二进制 xlsx**（多 sheet：汇总表 + 环境/绿化/工程/秩序/行政 5 明细表） | admin | 数据源：该月已有存档→用存档（含实际单价）；否则用 purchase_items（单价列预填最近一次采购参考价、黄底）；工程表多一列「使用位置」；写日志「导出报价」 |
| `export/year` | GET | query `year` | **二进制 xlsx**（全年明细单表 + 合计行 + 自动筛选） | admin | 写日志「年度导出」 |
| `export/my-month` | GET | query `month`；`project_id`（仅 admin；staff 强制本人） | **二进制 xlsx**（单表：条线/商品/规格/品牌/单位/数量/单价/金额/申购原因/库存 + 合计行） | 任意登录 | 已导入显示单价金额，未导入显示原因/库存；写日志「员工导出」 |
| `import` | POST | multipart `file`（填好价的导出表）+ `month`（form 字段） | `{ok, msg, unbound:[{name, spec, unit, rows}]}` | admin | **覆盖式：先 DELETE 该月全部 archived_purchases 再插入**；sheet 名映射条线（环境类/绿化类/工程类/秩序部/行政办公类）；qty≤0 且 price≤0 跳过；total=round(qty×price,2)；价格>0 时回写 purchase_items.price（匹配 month+project+item_name+spec 且 status IN submitted/confirmed/returned 且 price 为空，LIMIT 1）；导入后通知相关项目员工（`price_imported`）；**预算超支时通知全部 admin（`budget_over`）并写日志**；归档月拒绝 |
| `import/batch` | POST | multipart `files[]`（多个 xlsx，**文件名需含 `2026-01` 或 `2026年1月` 格式月份**） | `{ok, msg:'批量导入完成', files:[{file, month?, ok:bool, msg}]}` | admin | 逐文件内部调用 `import` 逻辑（含覆盖式清空） |

### 3.10 汇总（summary.php，全部 admin-only）

| URI | 方法 | 入参 | 返回结构 | 备注 |
|---|---|---|---|---|
| `summary/months` | GET | 无 | `data:{rows:[{month, amount, items, projects, lines}]}` | 按 month DESC，amount=SUM(total) round2 |
| `summary/search` | GET | query `q`（空返回空 rows） | `data:{rows:[{month, project_id, project_name, line, item_name, brand, spec, unit, quantity, price, total}]}` ≤60 条 | item_name/spec/brand LIKE |
| `summary/month` | GET | query `month`、`project_id`、`line` | `data:{month, lines:[5条线], matrix:[{project_id, project_name, lines:{'环境':{amount,cnt}\|null, ...}, total, items, budget, actual, over, rate}], items:[明细同 search 行结构], total_budget, total_actual, total_rate, total_items}` | 明细 ORDER BY FIELD(条线), 项目, id |

### 3.11 驾驶舱（dashboard.php，全部任意登录；staff 经 `dash_scope` 只见本项目数据）

除 `price-trend` 外均支持 query `month`（默认当月）。结构见「四」；单独端点响应为 `{ok:true, data:<对应键内容>}`。

| URI | 方法 | 入参 | 备注 |
|---|---|---|---|
| `dashboard/all` | GET | `month`, `year` | 合并接口，见四 |
| `dashboard/monthly` | GET | `month` | — |
| `dashboard/compare` | GET | `month` | 环比上月 |
| `dashboard/yoy` | GET | `month` | 同比去年同月 |
| `dashboard/ytd` | GET | `month` | 年累计 vs 上年同期 |
| `dashboard/annual` | GET | `year`（传空字符串返回全部历史月份） | — |
| `dashboard/annual-lines` | GET | `year` | — |
| `dashboard/annual-projects` | GET | `year` | — |
| `dashboard/top` | GET | `months`(默认3,1–12)、`end_month`(默认当月) | 商品采购频次 TOP20 |
| `dashboard/price-trend` | GET | `item`（必填）、`year` | 月度均价 + 年均价对象 |
| `dashboard/price-anomalies` | GET | `month` | 阈值取 sys_settings `price_threshold`（默认 30） |
| `dashboard/budget-exec` | GET | `month` | — |
| `dashboard/fill-progress` | GET | `month` | 读 purchase_items（非存档） |
| `dashboard/custom-ratio` | GET | `month` | 读 purchase_items，金额=quantity×price（price 可能为 null → 按 0 计） |

---

## 四、驾驶舱合并接口 `GET /dashboard/all`

### 4.1 入参与响应

- query：`month='Y-m'`（默认当月）、`year='Y'`（**默认取 month 前四位**）。
- 响应：`{ok:true, data:{month, year, ...12 组键...}}`。
- 实现：服务端临时把 `month/year` 写入 `$_GET` 后逐个调用子 handler，取各自 `data` 键拼装；**price-trend 不在合并内**（需 item 参数，用户交互时单独请求）。

### 4.2 12 组键名 ↔ 子端点（与旧前端 `MERGED_KEYS` 逐字一致）

| 子端点（GET） | data 键名 | month/year 语义 |
|---|---|---|
| `dashboard/monthly` | `monthly` | month |
| `dashboard/compare` | `compare` | month（自动取上月） |
| `dashboard/yoy` | `yoy` | month（自动取去年同月） |
| `dashboard/ytd` | `ytd` | month |
| `dashboard/annual` | `annual` | year |
| `dashboard/annual-lines` | `annual_lines` | year |
| `dashboard/annual-projects` | `annual_projects` | year |
| `dashboard/top` | `top` | **end_month 默认为服务器当前月，不受所传 month 控制**（陷阱，见 4.4） |
| `dashboard/fill-progress` | `fill_progress` | month |
| `dashboard/budget-exec` | `budget_exec` | month |
| `dashboard/custom-ratio` | `custom_ratio` | month |
| `dashboard/price-anomalies` | `price_anomalies` | month |

### 4.3 各组结构摘要

- **monthly**：`{month, overview:{amount, projects, items}, lines:[{line, amount}], projects:[{project_id, name, amount}]}`（staff 视角 projects 仅本项目）。
- **compare / yoy**：`{month, prev_month, rows:[{project_id, name, current, prev, diff, rate, trend:'up'|'down'|'flat'}]}`（rate 无基数时 null；rows 覆盖全部可见项目）。
- **ytd**：`{month, ytd, prev_ytd, diff, rate}`（1 月～所选月的累计 vs 上年同期）。
- **annual**：`{year, rows:[{month, amount}]}`（month 升序；year 传空串返回全部历史月份）。
- **annual_lines**：`{year, rows:[{line, amount}]}`。
- **annual_projects**：`{year, rows:[{project_id, name, amount}]}`（amount 降序）。
- **top**：`{start, end, rows:[{item_name, spec, unit, times, qty, amount}]}` ≤20（end 往前 months-1 个月起）。
- **fill_progress**：`{month, total_projects, filled_projects, submitted_projects, confirmed_projects, returned_projects, items_total, items_submitted, items_confirmed, items_returned, rows:[{project_id, project_name, total, submitted, confirmed, returned, has_fill, status}]}`（status 同 admin/overview 口径；**数据源是 purchase_items**）。
- **budget_exec**：`{month, rows:[{project_id, project_name, budget, actual, diff, over, rate, items}], total_budget, total_actual, total_rate}`。
- **custom_ratio**：`{month, custom_items, custom_amount, total_items, total_amount, item_ratio, amount_ratio}`（清单外 = is_custom=1；金额=SUM(quantity×price)）。
- **price_anomalies**：`{month, prev_month, threshold, total, rows:[{item_name, cur_price, prev_price, rate, trend:'up'|'down', qty, cnt, units}]}`（|rate|≥threshold 才出现，按 |rate| 降序）。

### 4.4 旧前端 shim 行为（新前端可自选：直调 /dashboard/all 或拆子端点）

旧前端 `public/purchase/index.html` 内嵌 `__dashAllShim`（`window.__dashAllShimInstalled` 防重装）：

- 拦截 **GET** `/api/purchase/dashboard/<sub>`，sub ∈ `MERGED_KEYS` 的 12 个子端点；`price-trend` 与非 dashboard 请求原样放行。
- 某月首个被拦截请求 → 发 `GET /api/purchase/dashboard/all?month=<月>`（X-Token 取 URL `?token=` 或 localStorage/sessionStorage 的 `gw_token`/`token`/`purchase_token`），按月缓存 Promise；同月其余子请求直接从缓存切片，模拟响应 `{ok:true, data:<切片>}`。
- 合并失败或切片缺键 → 回退真实子请求。
- **重建建议**：新前端直接请求 `/dashboard/all?month=...` 一次取 12 组并自行分发；`price-trend` 单独请求；不再需要 shim。

---

## 五、旧前端 hash 路由 → 端点映射（已按 assets/*.js 提取结果核对补全）

| Hash 路由 | 页面组件（assets 文件） | 实际调用端点 |
|---|---|---|
| `#/dashboard` | Dashboard-z4nKb73E.js | `dashboard/all`（经 shim）→ 12 组；`dashboard/price-trend`（选商品看趋势）；`products/search`（趋势商品搜索） |
| `#/fill` | FillMonths-Cc2fs9n_.js + FillForm-D9nOsqx9.js | FillMonths：`my/months`、`projects/options`（admin 代填入口）。FillForm：`fill/window`、`fill/items` GET/POST、`fill/items/{id}` PUT/DELETE、`fill/items/{id}/submit`、**`fill/frequency`**（选商品后显示近 3 月采购频率）、`products/by-name`、`products/search` |
| `#/overview` | AdminOverview-CJuB-Ax-.js | `admin/overview`、`admin/archive`、`admin/unarchive`、`admin/items/{id}/confirm`、`admin/items/{id}/unconfirm`、`admin/items/{id}/return`、`admin/confirm-batch`、`admin/return-project`、**`fill/items`**（项目×条线下钻查看明细） |
| `#/customs` | CustomReview-ClqvMFiF-v3.js | `admin/customs`、`admin/customs/map`、`admin/customs/check-duplicate`、`admin/customs/save`、`admin/items/{id}/return`（退回）、`products/lines`、`products/categories` |
| `#/budget` | BudgetPlan-X9sZSfU0.js | `budgets` GET/POST、`budgets/months`、`budgets/template`（下载）、`budgets/import`（上传） |
| `#/summary` | PurchaseSummary-CSnRyQcd.js | `summary/months`、`summary/search`、`summary/month`、`export?month=`（月度导出下载，完整 URL `api/purchase/export`） |
| `#/export-import` | ExportImport-BaYMbdsW.js | `export`、`export/year`、`import`（单文件）、`import/batch`、`budgets/compare`（预算对比）、`dashboard/monthly`+`dashboard/annual`（页面内图表）、`admin/return-project`（页面内退回操作，待线上对照） |
| `#/products` | ProductLibrary-GCtZ_6iH.js | `products` GET/POST、`products/{id}` PUT/DELETE、`products/lines`、`products/categories`、`products/by-name`（待线上对照）、`products/template`、`products/import`、`products/unbound`、`products/{id}/synonyms`、`synonyms` POST/`synonyms/{id}` DELETE |
| `#/windows` | SystemWindows-6IyQWznm.js | `windows` GET/POST、`windows/{id}` DELETE、`windows/gen`、`projects`（项目列表展示，admin） |
| `#/oplogs` | OpLogs-BXMugBiC.js | `oplogs`（action 筛选 + keyword + 日期范围 + 分页） |
| `#/my-items` | MyItems-CqDP3Efw.js | `my/months`、`my/month-items`、`export/my-month`。**确认：不直接调用 `notifications`**（通知红点/列表由 Layout 顶栏统一处理），也**不直接调用 `fill/items`**（编辑跳转 `#/fill`） |
| `#/login` | Login-CDy3c4dc.js | 旧遗留 `login`（新前端改为平台登录 `POST /api/login` + 携带 X-Token 进入） |
| 顶栏（Layout-CETCAsIN.js） | — | `notifications/unread-count`（轮询）、`notifications`（列表，待线上对照）、`notifications/read-all`；单条已读 `notifications/{id}/read`（待线上对照） |

---

## 六、反直觉与陷阱清单（重建时务必注意）

1. **遗留 auth 端点被平台鉴权前置**：`/api/purchase/login|logout|me` 也要求有效 X-Token，`login` 不能当登录接口用。
2. **401 与业务失败键名不同**：401 用 `error`（`{ok:false,error:'未登录'}`），其余错误用 `msg`。
3. **业务错误 HTTP 200**：不能只看状态码，必须检查 `ok` 字段。
4. **分页端点 total 与 data 平级**，无 `list`/`rows` 包装（my/months、summary 等才有 `rows` 键）。
5. **`POST import` 是覆盖式导入**：先清空该月全部存档再写入。
6. **`budgets/import` 整行空白 = 删除该项目全年预算**。
7. **`windows/gen` 是 POST 但参数 `from/count` 走 query**。
8. **`projects` POST/PUT/DELETE 是恒定失败的占位端点**（项目由主系统组织架构管理）。
9. **`dashboard/all` 中 `top` 组的统计窗口终点固定为服务器当前月**，不随所选 month 变化（单独调用时可传 `end_month` 控制）。
10. ~~system.php 的 `handle_users_*` 未注册路由，为死代码~~（2026-09-30 已删除，连同 handlers/auth.php）。
11. **删除 returned 状态的填报记录会连带删除 archived_purchases 中匹配行**。
12. **导入报价会回写 purchase_items.price**（submitted/confirmed/returned 且原 price 为空的记录），员工可在 my-items 看到退回记录的单价。
13. **双重（实为三道）鉴权**：控制器 requireAccount → handler require_auth → require_admin；staff 的项目过滤在 SQL 层强制（`dash_scope`、`project_id` 覆盖），前端传参无法越权。
14. **每个请求可能触发项目全量同步**（5 分钟节流），同步会物理删除组织架构中已不存在的项目。
15. **规格字段全半角/空白归一化**在服务端完成，展示与匹配以归一化结果为准。
16. **通知双写**：采购 notifications 表 + 平台 app_messages（铃铛），link 指向线上采购页 hash 路由。
17. **my/month-items 两种 rows 结构不同**（imported true/false 字段集不一样），前端需按 imported 分支渲染。
18. **金额单位为元**（无分→元换算）；`rate` 为百分数值。

---

## 七、关键响应示例（键名逐字，可直接做 mock 基准）

### 7.1 `GET /api/purchase/dashboard/all?month=2026-09`（骨架）

```json
{
  "ok": true,
  "data": {
    "month": "2026-09", "year": "2026",
    "monthly":         { "month": "2026-09", "overview": {"amount": 0, "projects": 0, "items": 0}, "lines": [], "projects": [] },
    "compare":         { "month": "2026-09", "prev_month": "2026-08", "rows": [] },
    "yoy":             { "month": "2026-09", "prev_month": "2025-09", "rows": [] },
    "ytd":             { "month": "2026-09", "ytd": 0, "prev_ytd": 0, "diff": 0, "rate": null },
    "annual":          { "year": "2026", "rows": [{"month": "2026-01", "amount": 0}] },
    "annual_lines":    { "year": "2026", "rows": [{"line": "环境", "amount": 0}] },
    "annual_projects": { "year": "2026", "rows": [{"project_id": 1, "name": "某项目", "amount": 0}] },
    "top":             { "start": "2026-07", "end": "2026-09", "rows": [{"item_name": "", "spec": "", "unit": "", "times": 0, "qty": 0, "amount": 0}] },
    "fill_progress":   { "month": "2026-09", "total_projects": 0, "filled_projects": 0, "submitted_projects": 0, "confirmed_projects": 0, "returned_projects": 0, "items_total": 0, "items_submitted": 0, "items_confirmed": 0, "items_returned": 0, "rows": [] },
    "budget_exec":     { "month": "2026-09", "rows": [], "total_budget": 0, "total_actual": 0, "total_rate": null },
    "custom_ratio":    { "month": "2026-09", "custom_items": 0, "custom_amount": 0, "total_items": 0, "total_amount": 0, "item_ratio": 0, "amount_ratio": 0 },
    "price_anomalies": { "month": "2026-09", "prev_month": "2026-08", "threshold": 30, "total": 0, "rows": [] }
  }
}
```

### 7.2 错误形态对照

```json
// HTTP 401（平台鉴权失败，注意是 error 键）
{ "ok": false, "error": "未登录" }

// HTTP 200 业务失败（绝大多数失败长这样）
{ "ok": false, "msg": "本月已归档锁定，不可填报" }

// HTTP 403（require_admin 失败）
{ "ok": false, "msg": "无权限" }

// HTTP 500（未捕获异常）
{ "ok": false, "msg": "服务器错误: ..." }
```

### 7.3 `GET /api/purchase/my/month-items?month=2026-09`（双形态）

```json
// 已导入价格（imported=true）：行来自 archived_purchases
{ "ok": true, "data": { "imported": true, "rows": [
  { "line": "环境", "item_name": "垃圾袋", "spec": "45*50cm", "brand": "", "unit": "个",
    "quantity": 100, "price": 0.85, "total": 85.0 } ] } }

// 未导入（imported=false）：行来自 purchase_items
{ "ok": true, "data": { "imported": false, "rows": [
  { "line": "环境", "item_name": "垃圾袋", "spec": "45*50cm", "brand": "", "unit": "个",
    "quantity": 100, "stock": 12, "reason": "日常消耗", "is_custom": 0, "status": "confirmed",
    "price": null, "total": null, "return_reason": "", "returned_at": "" } ] } }
```

### 7.4 `GET /api/purchase/fill/window?month=2026-09`

```json
{ "ok": true, "data": { "open": true, "msg": "填报开放中",
  "window": { "id": 3, "month": "2026-09", "start_date": "2026-09-20", "end_date": "2026-09-23", "status": 1 } } }
// 未配置窗口时: data = { "open": false, "msg": "本月未配置填报窗口", "window": null }
```

### 7.5 purchase_items.status 状态机（前端按钮显隐依据）

```
draft ──提交(submit/PUT status)──▶ submitted ──admin确认──▶ confirmed
  ▲                                  │  │                      │
  └────员工撤销提交(PUT {status:'draft'})◀┘                      │
                                     │                        │
                                     └────admin退回(reason)────┤
                                                              ▼
                                     returned ──员工重新提交(submit)──▶ submitted（记 resubmitted_at）
```

- `draft`：员工可改/删/提交；`submitted`：员工仅可撤销提交（admin 可改/删）；`confirmed`：双方均不可改/删（admin 可 unconfirm 回 submitted 或 return）；`returned`：员工窗口外可改/删/提交。
- 归档月（monthly_archive 存在记录）以上全部写操作拒绝。

