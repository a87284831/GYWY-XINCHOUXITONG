# 季度绩效核算系统设计方案

## 1. 背景与目标

### 1.1 现状
薪酬系统已有四类人员核算入口（员工/管理/案场/总部），但绩效计算方式统一为月度发放，无法支持管理人员和总部人员的季度/半年度绩效发放模式。

### 1.2 目标
- 员工（staff）/案场（case）：维持月度发放，绩效 = 出勤比例 × 基数 × 月度系数 × 100%
- 管理（manager）/总部（hq）：季度末发放季度比例%，半年度末发放半年比例%，比例按岗位职级统一配置
- 季度中（1-3月等）绩效为 0，累计到季度末统一发放
- 季度末工资表显示逐月绩效明细（子行展开），导出 Excel 时追加逐月明细列

### 1.3 非目标
- 不改变现有员工/案场的月度核算逻辑
- 不改变钉钉同步逻辑（person_type/position_level 已有）
- 不引入绩效奖金池概念（季度绩效 = 各月基数之和 × 系数 × 比例，不是额外奖金）

## 2. 术语

| 术语 | 说明 |
|---|---|
| 季度月 | 4月、7月、10月、1月（季度末月） |
| 半年度月 | 7月、1月（半年度末月） |
| 季度中 | 非季度末的月份（1-3月、5-6月、8-9月、11-12月） |
| 季度系数 | 管理/总部人员的季度绩效系数，在核算页录入 |
| 半年度系数 | 管理/总部人员的半年度绩效系数，在核算页录入 |
| 发放比例 | 按岗位职级配置的季度/半年度发放比例（如经理级 95%/5%） |

## 3. 数据模型

### 3.1 `calc_rules.json` 扩展（新增 pay_rules）

```json
{
  "pay_rules": {
    "manager": {
      "cycle": "quarterly",
      "levels": {
        "经理级": { "quarter_ratio": 0.95, "half_year_ratio": 0.05 },
        "主管级": { "quarter_ratio": 0.90, "half_year_ratio": 0.10 },
        "专员级": { "quarter_ratio": 1.00, "half_year_ratio": 0.00 }
      },
      "default": { "quarter_ratio": 1.00, "half_year_ratio": 0.00 }
    },
    "hq": {
      "cycle": "quarterly",
      "levels": {
        "经理级": { "quarter_ratio": 0.95, "half_year_ratio": 0.05 },
        "主管级": { "quarter_ratio": 0.90, "half_year_ratio": 0.10 }
      },
      "default": { "quarter_ratio": 1.00, "half_year_ratio": 0.00 }
    },
    "staff": { "cycle": "monthly", "ratio": 1.0 },
    "case": { "cycle": "monthly", "ratio": 1.0 }
  }
}
```

### 3.2 新表 `payroll_period_coefs`

| 字段 | 类型 | 约束 | 说明 |
|---|---|---|---|
| id | bigint | PK | 主键 |
| staff_legacy_id | int | NOT NULL | 人员 legacy_id |
| period_type | enum('quarterly','half_year') | NOT NULL | 周期类型 |
| period_key | varchar(10) | NOT NULL | 如 "2026-Q1" / "2026-H1" |
| coef | decimal(5,2) | NOT NULL DEFAULT 1.00 | 绩效系数 |
| created_at | timestamp | | |
| updated_at | timestamp | | |

唯一索引：`(staff_legacy_id, period_type, period_key)`

### 3.3 `payroll_results` 扩展（row_data JSON）

季度末管理/总部人员的 `row_data` 新增字段：

```json
{
  "perf_detail": {
    "period": "2026-Q1",
    "type": "quarterly",
    "ratio": 0.95,
    "coef": 1.00,
    "months": [
      { "ym": "2026-01", "perf_attend": 26, "base": 2000, "amount": 1900.00 },
      { "ym": "2026-02", "perf_attend": 26, "base": 2000, "amount": 1900.00 },
      { "ym": "2026-03", "perf_attend": 26, "base": 2000, "amount": 1900.00 }
    ]
  }
}
```

非季度月：`perf_detail` 为 null，`perf_pay` 为 0。

## 4. 核心逻辑

### 4.1 核算流程（PayrollCalculator）

**月度核算（员工/案场）：**
```
perf_pay = perf_attend × (基数/当月天数) × coef × 1.0
```

**季度中管理/总部核算：**
```
perf_pay = 0
perf_detail = null
```

**季度末管理/总部核算：**
```
1. 取 1-3 月（或季度对应月份）的 payroll_results
2. 对每个月：month_perf = (perf_attend / req_attend) × (基数/当月天数) × 当月天数
   即 month_perf = perf_attend × (基数/当月天数)
3. 季度绩效基数 = Σ month_perf
4. 取 payroll_period_coefs 中该人员该周期的 coef
5. 取 pay_rules.manager.levels[职级].quarter_ratio
6. perf_pay = 季度绩效基数 × coef × quarter_ratio
7. perf_detail = { period, type, ratio, coef, months[] }
```

**半年度末管理/总部核算（7月/1月）：**
```
同上，但取 1-6 月（或 7-12 月），用 half_year_ratio
```

### 4.2 周期判断

```php
function isQuarterEnd(int $month): bool {
    return in_array($month, [4, 7, 10, 1]);
}

function isHalfYearEnd(int $month): bool {
    return in_array($month, [7, 1]);
}

function getQuarterMonths(int $month): array {
    return match($month) {
        4 => [1, 2, 3],
        7 => [4, 5, 6],
        10 => [7, 8, 9],
        1 => [10, 11, 12],
    };
}

function getHalfYearMonths(int $month): array {
    return match($month) {
        7 => [1, 2, 3, 4, 5, 6],
        1 => [7, 8, 9, 10, 11, 12],
    };
}
```

### 4.3 系数缺失处理

季度末核算时，若某管理/总部人员缺少季度系数：
- 该人员 perf_pay = 0
- 记入 `skipped` 数组，返回错误信息："张三（经理级）缺少 2026-Q1 季度系数，请先在核算页录入"
- 不中止整月核算，但该人员标记为跳过

### 4.4 调岗处理

- 人员从 manager 调为 staff：`person_type_since` 生效后按新类型核算
- 季度中调岗：若 1 月为 manager、3 月调为 staff，则 3 月按 staff 月度核算，Q1 季度绩效只累计 1-2 月
- 调岗人员的季度绩效按实际在职月份比例折算

## 5. 前端交互

### 5.1 核算页（Payroll.vue）

**新增"季度系数录入"按钮：**
- 位置：管理人员核算/总部人员核算 Tab 的操作栏
- 显示条件：当前月份为季度末月（4/7/10/1月）
- 按钮文案："📊 录入 Q1 季度系数" / "📊 录入 H1 半年度系数"

**录入弹窗：**
- 标题："录入 2026-Q1 季度绩效系数"
- 显示当前周期（只读）：2026-Q1
- 人员列表表格：姓名 / 项目 / 职位 / 岗位职级 / 系数输入框 / 状态
- 状态：✅ 已录入 / ❌ 未录入
- 操作：保存（校验所有必填项已填）

**工资表展示（A 方案）：**
- 季度末管理/总部人员行下方增加 3 行灰色子行（1月/2月/3月绩效明细）
- 子行格式：姓名列显示 "└─ 1月绩效"，绩效列显示金额，其余列显示基数/出勤/说明
- 子行默认展开，可点击收起/展开

**导出 Excel（C 方案）：**
- 导出时在绩效工资列后追加 3 列：1月绩效、2月绩效、3月绩效
- 季度末导出包含逐月明细，季度中导出无明细列

### 5.2 设置页（SalarySettings.vue）

**新增"绩效发放规则"区块（第⑨节）：**
- 按人员类型选择（管理/总部）
- 按岗位职级配置表格：职级 / 季度发放比例% / 半年度发放比例%
- 默认比例：100% / 0%
- 保存到 calc_rules.json

## 6. API 设计

### 6.1 系数录入

```
POST /api/payroll/period-coef/save
Body: {
  period_type: "quarterly",
  period_key: "2026-Q1",
  coefs: [
    { staff_legacy_id: 123, coef: 1.00 },
    { staff_legacy_id: 456, coef: 0.95 }
  ]
}
Response: { ok: true, saved: 2 }
```

### 6.2 系数查询

```
GET /api/payroll/period-coef/list?period_type=quarterly&period_key=2026-Q1
Response: {
  ok: true,
  period: { type: "quarterly", key: "2026-Q1" },
  coefs: [
    { staff_legacy_id: 123, name: "张三", position_level: "经理级", coef: 1.00, saved_at: "2026-04-01 10:00" }
  ]
}
```

### 6.3 待录人员查询

```
GET /api/payroll/period-coef/pending?period_type=quarterly&period_key=2026-Q1
Response: {
  ok: true,
  pending: [
    { staff_legacy_id: 123, name: "张三", project: "祥云大院", position: "项目经理", position_level: "经理级" }
  ]
}
```

## 7. 测试策略

### 7.1 单元测试

- `PayrollCalculatorTest::testQuarterlyManagerCalculation` — 季度末管理人员绩效计算
- `PayrollCalculatorTest::testQuarterlyMidMonthZero` — 季度中管理绩效为 0
- `PayrollCalculatorTest::testQuarterlyMissingCoefSkipped` — 缺系数人员跳过并报错
- `PayrollCalculatorTest::testHalfYearCalculation` — 半年度末绩效计算
- `PayrollCalculatorTest::testLevelRatioApplied` — 不同职级比例正确应用

### 7.2 集成测试

- 完整流程：录入系数 → 核算 → 验证 perf_pay 和 perf_detail
- 导出 Excel 包含逐月明细列
- 季度中核算绩效为 0，季度末核算绩效正确

### 7.3 前端测试

- 季度系数录入弹窗打开/保存/校验
- 工资表子行展开/收起
- 导出包含逐月明细列

## 8. 边界情况

| 场景 | 处理 |
|---|---|
| 季度中途入职 | 按实际在职月份计算绩效基数 |
| 季度中途离职 | 按实际在职月份计算，离职当月仍参与季度累计 |
| 季度中途调岗 | 按调岗前后类型分别计算，manager 期间参与季度累计 |
| 系数为 0 | 合法，绩效为 0 |
| 系数 > 2 | 合法，但前端提示"系数较高，请确认" |
| 历史月份重算 | 若历史月份无 perf_detail，按旧逻辑计算（兼容） |

## 9. 性能考虑

- 季度末核算需查询 1-3 月的 payroll_results，已在同一事务中，性能可接受
- perf_detail JSON 字段增加少量存储，单条 < 1KB
- 系数表数据量小（每年 4 季度 × 2 类型 × 管理人数），无需分页

## 10. 迁移计划

1. 新建 `payroll_period_coefs` 表迁移
2. `calc_rules.json` 增加 `pay_rules` 配置（默认月度）
3. 后端 `PayrollCalculator` 扩展季度/半年度逻辑
4. 前端核算页增加系数录入入口和子行展示
5. 前端设置页增加绩效规则配置
6. 测试验证
