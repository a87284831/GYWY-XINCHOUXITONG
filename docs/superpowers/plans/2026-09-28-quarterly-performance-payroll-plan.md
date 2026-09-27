# 季度绩效核算系统实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在薪酬核算系统中支持管理/总部人员的季度/半年度绩效发放模式，员工/案场维持月度发放。

**Architecture:** 在现有 PayrollCalculator 中扩展周期判断逻辑，新增 payroll_period_coefs 表存储季度/半年度系数，calc_rules.json 扩展 pay_rules 配置按职级比例，前端核算页内嵌系数录入入口和逐月绩效明细子行展示。

**Tech Stack:** PHP 8.3 / Laravel 10 / Vue 3 / MySQL

**Spec:** [docs/superpowers/specs/2026-09-28-quarterly-performance-payroll-design.md](docs/superpowers/specs/2026-09-28-quarterly-performance-payroll-design.md)

## Global Constraints

- 员工/案场核算逻辑完全不变
- 钉钉同步逻辑不变（person_type/position_level 已有字段）
- 历史数据兼容：无 perf_detail 的旧数据按原逻辑展示
- 所有金额计算保留2位小数

## Review Focus

1. 季度中途调岗（manager→staff）：1-2月按 manager 累计，3月起按 staff 月度
2. 季度系数为 0：合法，绩效为 0，不报错
3. 季度系数 > 2：前端提示"系数较高，请确认"，后端不拦截
4. 半年度末同时是季度末（7月/1月）：先发季度比例，再发半年度比例，两笔分开
5. 非季度末月误点"录入系数"：按钮隐藏，无法触发

---

### Task 1: 数据库迁移 — payroll_period_coefs 表

**Files:**
- Create: `database/migrations/2026_09_28_000001_create_payroll_period_coefs_table.php`

**Interfaces:**
- Produces: `payroll_period_coefs` 表结构

- [ ] **Step 1: 创建迁移文件**

```php
Schema::create('payroll_period_coefs', function (Blueprint $table) {
    $table->id();
    $table->integer('staff_legacy_id')->comment('人员 legacy_id');
    $table->enum('period_type', ['quarterly', 'half_year'])->comment('周期类型');
    $table->string('period_key', 10)->comment('周期标识，如 2026-Q1 / 2026-H1');
    $table->decimal('coef', 5, 2)->default(1.00)->comment('绩效系数');
    $table->timestamps();
    $table->unique(['staff_legacy_id', 'period_type', 'period_key']);
});
```

- [ ] **Step 2: 运行迁移测试**

Run: `php artisan migrate --pretend`
Expected: 显示 SQL，无语法错误

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_09_28_000001_create_payroll_period_coefs_table.php
git commit -m "feat: 新增 payroll_period_coefs 季度/半年度系数表"
```

---

### Task 2: CalcRules 扩展 — pay_rules 配置读取

**Files:**
- Modify: `app/Services/CalcRules.php` — 新增 `getPayRule()` 方法
- Test: `tests/Unit/CalcRulesTest.php`

**Interfaces:**
- Consumes: `calc_rules.json` 的 `pay_rules` 节点
- Produces: `CalcRules::getPayRule(string $personType, string $positionLevel = ''): array`

返回格式：
```php
[
    'cycle' => 'monthly' | 'quarterly',
    'ratio' => 1.0,           // 月度人员固定 1.0
    'quarter_ratio' => 0.95,  // 季度比例（季度人员）
    'half_year_ratio' => 0.05, // 半年度比例（季度人员）
]
```

- [ ] **Step 1: 写失败测试**

```php
public function test_get_pay_rule_returns_monthly_for_staff(): void
{
    $rules = new CalcRules(['pay_rules' => ['staff' => ['cycle' => 'monthly', 'ratio' => 1.0]]]);
    $rule = $rules->getPayRule('staff');
    $this->assertEquals('monthly', $rule['cycle']);
    $this->assertEquals(1.0, $rule['ratio']);
}

public function test_get_pay_rule_returns_level_ratio_for_manager(): void
{
    $rules = new CalcRules(['pay_rules' => [
        'manager' => [
            'cycle' => 'quarterly',
            'levels' => ['经理级' => ['quarter_ratio' => 0.95, 'half_year_ratio' => 0.05]],
            'default' => ['quarter_ratio' => 1.0, 'half_year_ratio' => 0.0],
        ]
    ]]);
    $rule = $rules->getPayRule('manager', '经理级');
    $this->assertEquals(0.95, $rule['quarter_ratio']);
    $rule = $rules->getPayRule('manager', '未知职级');
    $this->assertEquals(1.0, $rule['quarter_ratio']); // fallback to default
}
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit tests/Unit/CalcRulesTest.php --filter=test_get_pay_rule`
Expected: FAIL — 方法不存在

- [ ] **Step 3: 实现 `CalcRules::getPayRule()`**

```php
public function getPayRule(string $personType, string $positionLevel = ''): array
{
    $rules = $this->get('pay_rules', []);
    $rule = $rules[$personType] ?? ['cycle' => 'monthly', 'ratio' => 1.0];
    if (($rule['cycle'] ?? 'monthly') === 'monthly') {
        return ['cycle' => 'monthly', 'ratio' => $rule['ratio'] ?? 1.0];
    }
    // quarterly: 按职级取比例，无则取 default
    $levelRule = $rule['levels'][$positionLevel] ?? $rule['default'] ?? ['quarter_ratio' => 1.0, 'half_year_ratio' => 0.0];
    return [
        'cycle' => 'quarterly',
        'quarter_ratio' => (float)($levelRule['quarter_ratio'] ?? 1.0),
        'half_year_ratio' => (float)($levelRule['half_year_ratio'] ?? 0.0),
    ];
}
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit tests/Unit/CalcRulesTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/CalcRules.php tests/Unit/CalcRulesTest.php
git commit -m "feat: CalcRules 支持按人员类型+职级读取绩效发放规则"
```

---

### Task 3: PayrollCalculator 扩展 — 季度绩效计算

**Files:**
- Modify: `app/Services/PayrollCalculator.php` — 新增 `calculateQuarterlyPerf()` 和周期判断方法
- Test: `tests/Feature/PayrollQuarterlyTest.php`

**Interfaces:**
- Consumes: `CalcRules::getPayRule()`、`payroll_period_coefs` 表、历史 `payroll_results`
- Produces: 修改后的 `computeRow()` 支持季度绩效计算

- [ ] **Step 1: 写失败测试**

```php
public function test_quarterly_manager_mid_month_perf_is_zero(): void
{
    // 3月（季度中）管理人员 perf_pay = 0
}

public function test_quarterly_manager_end_month_calculates_q1(): void
{
    // 4月（季度末）管理人员 perf_pay = Q1累计 × 系数 × 0.95
}

public function test_quarterly_missing_coef_skips_with_error(): void
{
    // 缺系数人员跳过，记入 skipped
}
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit tests/Feature/PayrollQuarterlyTest.php`
Expected: FAIL — 季度逻辑不存在

- [ ] **Step 3: 实现周期判断和季度绩效计算**

在 `PayrollCalculator` 中新增：

```php
private function isQuarterEnd(int $month): bool { return in_array($month, [4, 7, 10, 1]); }
private function isHalfYearEnd(int $month): bool { return in_array($month, [7, 1]); }
private function getQuarterMonths(int $month): array { ... }
private function getHalfYearMonths(int $month): array { ... }

private function calculateQuarterlyPerf(array $person, string $ym, float $base, array $payRule): array
{
    // 返回 ['perf_pay' => float, 'perf_detail' => array|null, 'skipped' => string|null]
}
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit tests/Feature/PayrollQuarterlyTest.php`
Expected: PASS

- [ ] **Step 5: 运行全量测试确保无回归**

Run: `vendor/bin/phpunit`
Expected: 全部 PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/PayrollCalculator.php tests/Feature/PayrollQuarterlyTest.php
git commit -m "feat: PayrollCalculator 支持季度/半年度绩效计算"
```

---

### Task 4: API — 系数录入/查询接口

**Files:**
- Modify: `app/Http/Controllers/Api/PayrollController.php` — 新增 3 个端点
- Test: `tests/Feature/PayrollPeriodCoefApiTest.php`

**Interfaces:**
- Produces:
  - `POST /api/payroll/period-coef/save`
  - `GET /api/payroll/period-coef/list`
  - `GET /api/payroll/period-coef/pending`

- [ ] **Step 1: 写失败测试**

```php
public function test_save_period_coef(): void { ... }
public function test_list_period_coef(): void { ... }
public function test_pending_period_coef(): void { ... }
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit tests/Feature/PayrollPeriodCoefApiTest.php`
Expected: FAIL — 路由不存在

- [ ] **Step 3: 实现 3 个 API 端点**

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit tests/Feature/PayrollPeriodCoefApiTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PayrollController.php tests/Feature/PayrollPeriodCoefApiTest.php
git commit -m "feat: 季度系数录入/查询 API"
```

---

### Task 5: 前端 — 核算页系数录入入口

**Files:**
- Modify: `frontend/src/modules/hr/Payroll.vue` — 新增系数录入按钮和弹窗
- Test: `frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js`

**Interfaces:**
- Consumes: `POST /api/payroll/period-coef/save`、`GET /api/payroll/period-coef/pending`

- [ ] **Step 1: 写失败测试**

```javascript
test('季度末月显示录入系数按钮', () => { ... })
test('点击按钮打开录入弹窗并加载待录人员', () => { ... })
```

- [ ] **Step 2: 运行测试确认失败**

Run: `npx vitest run frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js`
Expected: FAIL

- [ ] **Step 3: 实现录入按钮和弹窗**

- 按钮显示条件：`payTab === 'mgr' || payTab === 'hq'` 且当前月是季度末月
- 弹窗：周期显示（只读）+ 待录人员表格（姓名/项目/职级/系数输入框）+ 保存按钮

- [ ] **Step 4: 运行测试确认通过**

Run: `npx vitest run frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/hr/Payroll.vue frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js
git commit -m "feat: 核算页季度系数录入入口"
```

---

### Task 6: 前端 — 工资表逐月绩效明细子行

**Files:**
- Modify: `frontend/src/modules/hr/Payroll.vue` — 表格渲染逻辑
- Test: `frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js`

**Interfaces:**
- Consumes: `row_data.perf_detail`（Task 3 后端返回）

- [ ] **Step 1: 写失败测试**

```javascript
test('季度末人员显示逐月绩效子行', () => { ... })
test('点击收起/展开子行', () => { ... })
```

- [ ] **Step 2: 运行测试确认失败**

Run: `npx vitest run frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js`
Expected: FAIL

- [ ] **Step 3: 实现子行展开逻辑**

- 季度末管理/总部人员行下方渲染 3 行子行（1月/2月/3月绩效）
- 子行样式：灰色背景、缩进姓名列、小字号
- 默认展开，可点击切换

- [ ] **Step 4: 运行测试确认通过**

Run: `npx vitest run frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/hr/Payroll.vue frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js
git commit -m "feat: 工资表季度绩效逐月明细子行展示"
```

---

### Task 7: 前端 — 导出 Excel 追加逐月明细列

**Files:**
- Modify: `frontend/src/modules/hr/Payroll.vue` — 导出逻辑
- Test: `frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js`

**Interfaces:**
- Consumes: 同 Task 6

- [ ] **Step 1: 写失败测试**

```javascript
test('导出包含逐月绩效明细列', () => { ... })
```

- [ ] **Step 2: 运行测试确认失败**

Run: `npx vitest run frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js`
Expected: FAIL

- [ ] **Step 3: 实现导出逻辑**

- 导出时在绩效工资列后追加 3 列：1月绩效、2月绩效、3月绩效
- 从 `perf_detail.months` 读取逐月金额

- [ ] **Step 4: 运行测试确认通过**

Run: `npx vitest run frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/hr/Payroll.vue frontend/src/modules/hr/__tests__/PayrollQuarterly.test.js
git commit -m "feat: 导出 Excel 追加逐月绩效明细列"
```

---

### Task 8: 前端 — 设置页绩效规则配置

**Files:**
- Modify: `frontend/src/modules/settings/SalarySettings.vue` — 新增第⑨节
- Test: `frontend/src/modules/settings/__tests__/SalarySettingsPayRules.test.js`

**Interfaces:**
- Consumes: `GET /api/calc_rules`、`POST /api/calc_rules/save`

- [ ] **Step 1: 写失败测试**

```javascript
test('显示绩效规则配置区块', () => { ... })
test('按职级配置比例并保存', () => { ... })
```

- [ ] **Step 2: 运行测试确认失败**

Run: `npx vitest run frontend/src/modules/settings/__tests__/SalarySettingsPayRules.test.js`
Expected: FAIL

- [ ] **Step 3: 实现第⑨节"绩效发放规则"**

- 人员类型选择（管理/总部）
- 职级比例表格：职级 / 季度比例% / 半年度比例% / 操作（删除）
- 添加职级按钮
- 默认比例行（不可删除）

- [ ] **Step 4: 运行测试确认通过**

Run: `npx vitest run frontend/src/modules/settings/__tests__/SalarySettingsPayRules.test.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add frontend/src/modules/settings/SalarySettings.vue frontend/src/modules/settings/__tests__/SalarySettingsPayRules.test.js
git commit -m "feat: 设置页绩效发放规则配置"
```

---

### Task 9: 集成验证与构建

**Files:**
- Modify: `public/app` — 构建产物

- [ ] **Step 1: 运行全量测试**

Run: `vendor/bin/phpunit && npx vitest run`
Expected: 全部 PASS

- [ ] **Step 2: 构建前端**

Run: `npm run build`
Expected: 构建成功，无错误

- [ ] **Step 3: 本地验证**

- 8899 预览访问 /app
- 验证设置页第⑨节正常显示
- 验证核算页季度末月显示录入按钮
- 验证非季度末月不显示录入按钮

- [ ] **Step 4: Commit**

```bash
git add public/app
git commit -m "build: 季度绩效核算功能构建产物"
```
