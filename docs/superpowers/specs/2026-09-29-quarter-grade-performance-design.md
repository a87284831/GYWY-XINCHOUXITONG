# 季度绩效法（按档位区分发放节奏）设计

- 日期：2026-09-29
- 状态：待审核
- 分支：feature/frontend-unification
- 影响范围：薪酬核算（管理/总部人员）、薪酬设置、季度系数录入、工资表展示/导出、测试

## 1. 背景与目标

现有绩效发放方式两种：

- `monthly`（展示名「月度绩效法」）：每月发放当月绩效；员工/案场人员固定使用。
- `quarterly`（展示名「分期兑现绩效法」）：管理/总部人员季度中月份绩效为 0，季度末月按「季度累计 × 季度系数 × 季度比例」发放，7 月/1 月再加发半年度部分（× 半年系数 × 半年度比例）。比例按薪酬档位（专员级/主管级/经理级）配置。

业务新需求：管理/总部人员内部，**有的档位每季度发一次绩效（无比例、全额），有的档位每月发全额绩效**。需要在不破坏现有两种方式的前提下新增第三种发放方式「季度绩效法」（内部枚举 `quarter_grade`），方法内部按薪酬档位决定发放节奏。

### 非目标（本次不做）

- 不删除、不修改 `monthly` / `quarterly` 两种现有方式的任何计算逻辑与配置结构（分期兑现法后续可能重新启用）。
- 不做半年度发放（新方式只有季度与月度两种节奏）。
- 不做比例参数（新方式下两种节奏均为全额，不引入任何可配比例）。
- 不改动员工（staff）/案场（case）人员，二者仍固定 `monthly`。
- 不重算已导入的历史归档工资行（archived=true）。

## 2. 业务口径（已与用户逐例确认）

管理/总部人员整体选择「季度绩效法」后，按其人员档案 `pay_grade`（钉钉花名册同步：专员级/主管级/经理级）决定发放类型：

| 薪酬档位 | 发放类型 | 计算口径 |
|---|---|---|
| 经理级 | 季度型（quarter） | 季度中月份绩效工资 = 0；季度末月一次性发放该季度累计 |
| 主管级 | 月度型（monthly） | 每月发放当月全额绩效，不参与季度累计 |
| 专员级 | 月度型（monthly） | 同主管级 |

档位与类型的对应关系在「系统设置-薪酬设置」中配置，每档一个单选（季度发放/月度发放），默认经理级=季度、主管级/专员级=月度。一个档位只能属于一种类型，结构上禁止混用。

### 2.1 季度型计算公式

月绩效基数（沿用系统现有定义）：

```
月绩效基数 = fixed_monthly（固定月薪） − base_salary（基本工资部分）
```

当月出勤比例：

```
出勤比例 = perf_att（绩效计薪出勤天数） / req_att（应出勤天数）
```

季度末月应发绩效（以 4 月发 Q1 为例）：

```
Q1绩效 = 季度系数 × ( 1月绩效基数×1月出勤比例
                   + 2月绩效基数×2月出勤比例
                   + 3月绩效基数×3月出勤比例 )
```

- 季度系数：季度末月（4 月）在「录入季度系数」弹窗中录入，**一个系数全季三个月共用**；逐月的月度绩效系数（coef）不参与（季度型人员季度内月份不填月度系数）。
- 「先加再乘」与「逐月分别乘再相加」数学恒等，实现采用先累计后乘（复用现有 `accumulatePeriodPerf`，其口径正是 基数×出勤比例、不含月度 coef）。
- 无季度比例、无半年度项，即全额发放。
- 季度末月（4/7/10/1 月）只发「刚结束季度」：4 月→Q1、7 月→Q2、10 月→Q3、1 月→上年 Q4。
- 7 月/1 月**不再**有半年度 H1/H2 部分。

### 2.2 月度型计算公式

```
当月绩效 = 月绩效基数 × 出勤比例 × 当月月度绩效系数（coef）
```

- 每月独立发放，全额（无比例参数）。
- 4 月等季度末月也只发当月绩效，不补发前三个月、不生成季度累计。
- 月度型人员不需要录入季度系数，缺季度系数不对其报错。

### 2.3 数字走查（已与用户确认）

张三（经理级，季度型）：固定月薪 10000、基本工资 7000 → 月绩效基数 3000。

| 月份 | 应出勤 | 实际出勤 | 出勤比例 | 基数×出勤比例 | 当月绩效列 |
|---|---|---|---|---|---|
| 2026-01 | 22 | 22 | 100% | 3000.00 | 0 |
| 2026-02 | 17 | 16 | 94.12% | 2823.53 | 0 |
| 2026-03 | 22 | 22 | 100% | 3000.00 | 0 |
| 2026-04 | 录入季度系数 0.9 | — | — | 合计 8823.53 | **8823.53 × 0.9 = 7941.18** |

横向逐月明细列显示 3000.00 / 2823.53 / 3000.00（各月基数×出勤比例）。

李四（主管级，月度型）：月绩效基数 2100。

| 月份 | 出勤 | 月度系数 | 当月绩效 |
|---|---|---|---|
| 2026-01 | 满勤 | 1.0 | 2100 × 100% × 1.0 = 2100.00 |
| 2026-02 | 16/17 | 0.95 | 2100 × 94.12% × 0.95 = 1877.65 |
| 2026-04 | 满勤 | 0.9 | 2100 × 100% × 0.9 = 1890.00（仅当月，不累计） |

## 3. 配置数据结构（calc_rules.json → pay_rules）

只新增分支，不改动现有 `monthly`/`quarterly` 结构。

```json
"pay_rules": {
  "manager": {
    "cycle": "quarter_grade",
    "levels": {
      "经理级": { "mode": "quarter" },
      "主管级": { "mode": "monthly" },
      "专员级": { "mode": "monthly" }
    }
  },
  "hq": { "cycle": "monthly", "ratio": 1.0 }
}
```

- 新增 `cycle` 枚举值 `quarter_grade`（展示名「季度绩效法」）。
- `levels`：三档键名沿用 `CalcRules::PAY_GRADES`；每档仅一个字段 `mode`，取值 `quarter` | `monthly`。
- 缺省值：cycle 缺省按 `monthly`；某档 mode 缺省按 `monthly`（保证旧配置无 levels 时月度型人员照发、季度型判定为未配置）。
- 旧 `quarterly.levels.<档>.quarter_ratio / half_year_ratio` 字段保留，互不干扰。

## 4. 技术设计

### 4.1 CalcRules::getPayRule()

新增 `quarter_grade` 分支，签名不变：

```
getPayRule(personType: 'manager'|'hq', payGrade: string): array
```

quarter_grade 返回：

```php
['cycle' => 'quarter_grade', 'mode' => 'quarter'|'monthly', 'configured' => bool]
```

- 档位为空：`mode='monthly', configured=false`（由调用方标 missing_pay_grade）。
- 档位不在三档：`configured=false`（标 invalid_pay_grade）。
- 命中且有 mode：`configured=true`。
- 现有 monthly / quarterly 返回结构保持不变。

### 4.2 PayrollCalculator（约 L409-L475 绩效覆盖段）

在现有 `if ($payRule['cycle'] === 'quarterly')` 旁新增并列分支处理 `quarter_grade`，不改动 quarterly 分支一行：

- 读取档位 → getPayRule。
- 档位校验沿用现有显式失败：空 `missing_pay_grade`、不在三档 `invalid_pay_grade`。
- **mode=quarter（季度型）**：
  - 仅季度末月（isQuarterEnd）计算：复用 `quarterPeriod()` 取周期、`accumulatePeriodPerf()` 取 Σ(基数×出勤比例) 与逐月明细、`periodCoef(...,'quarterly',$qKey)` 取季度系数。
  - 缺季度系数：perf_pay=0，perf_detail 标 `missing_coef`。
  - 有系数：perf_pay = round(季度系数 × Σ, 2)；perf_detail 结构沿用现有季度字段（period/type='quarterly'/coef/months），**不含 half_year 键**。
  - 非季度末月：perf_pay=0（与现有一致）。
  - 不调用任何半年度逻辑。
- **mode=monthly（月度型）**：
  - 当月绩效已在核算器常规段按月度公式算出（基数 × 出勤比例 × coef），quarter_grade 月度型分支**不覆盖、不置 0、不查季度系数**，即保留常规段结果。
  - 不生成 perf_detail（无季度累计）。

边界沿用：入职前月份不累计（accumulatePeriodPerf 只取存在的历史/当月行）；季中入职只累计在职月；历史归档行不参与重算。

### 4.3 季度系数录入接口 PayrollController::pendingPeriodCoef()

- quarter_grade 模式下，`items` 名单**只包含 mode=quarter 的档位人员**（月度型人员不进录入名单，避免要求其填季度系数）。
- quarter_grade 模式下 `half_period` 返回 null、不查询/不返回 `half_coef`（弹窗不显示半年度录入）。
- monthly / quarterly 模式行为保持现状（quarterly 仍返回 half_period/half_coef）。
- 判断模式需读取 calc_rules 的 pay_rules（manager/hq 各自 cycle，名单按人员 person_type 对应规则过滤）。

### 4.4 前端

- SalarySettings.vue：发放周期下拉新增第三项「季度绩效法」（value=`quarter_grade`）。选中后渲染三档表格，每档一行：档位名 + 一组单选（季度发放/月度发放），无比例输入框。保存写入 levels.<档>.mode。旧 quarterly 的比例表格、monthly 维持原样。
- 系数录入弹窗：quarter_grade 模式下半年度系数区域隐藏；名单由接口控制（仅季度型）。
- Payroll.vue / payrollLogic.js：横向逐月明细列逻辑无需改动——其取数依赖 perf_detail.months，季度型人员季度末月有明细，月度型人员无 perf_detail 即空列；列头仍按周期 Q1/Q2/Q3/Q4 生成（不出现 H1/H2）。
- 工资表「应发绩效工资」列：季度型季度末月显示累计额，其余月份 0；月度型每月显示当月额。无需新增列。

### 4.5 导出

Excel 导出走后端 ExportController，明细列由 perf_detail 驱动。quarter_grade 无 half_year，导出自然只有季度逐月列、无半年度列，无需改导出代码（实施时回归验证）。

## 5. 错误处理

沿用现有「显式标错、计 0、不静默兜底」原则：

| 情况 | 季度型 | 月度型 |
|---|---|---|
| pay_grade 为空 | perf_pay=0，perf_detail.error=missing_pay_grade | 同左（无档位无法判定类型） |
| pay_grade 不在三档 | invalid_pay_grade，计 0 | 同左 |
| 档位 mode 配置缺失 | 按未配置 missing_pay_rule，计 0 | 默认 monthly 正常发放 |
| 季度型缺季度系数 | missing_coef，计 0 | 不适用（不查系数） |

## 6. 测试策略（TDD，先写失败用例）

后端新增/扩展用例（金额精确断言，手工期望值）：

1. 经理级季度型：1/2/3 月 perf_pay=0；4 月 = Σ(基数×出勤比例)×季度系数（断言 7941.18 同构数据），perf_detail.months 三个月金额正确、无 half_year 键。
2. 主管级月度型：每月 perf_pay = 基数×出勤比例×月度 coef；4 月不累计、不生成 perf_detail、不报 missing_coef。
3. 专员级月度型：同 2。
4. 季度型缺季度系数 → perf_pay=0 且 missing_coef；同表月度型人员当月正常。
5. 档位缺失/无效 → missing_pay_grade / invalid_pay_grade 计 0。
6. 季中入职：季度末累计只含在职月份。
7. pendingPeriodCoef：quarter_grade 下名单仅含季度型档位；half_period=null。
8. 回归：旧 quarterly（分期兑现，含半年度）结果不变；旧 monthly 不变。

前端：设置页 quarter_grade 单选渲染与保存结构；工资表季度末横向列对季度型显示、月度型为空。现有 214 个前端测试保持全绿。

## 7. 数据与上线

- 本地演示数据（seed_demo_payroll.php 生成的 621 行）在开发前执行 `--clean` 删除。
- 本地 calc_rules.json 的 pay_rules 补 quarter_grade 骨架（经理级 quarter，主管/专员 monthly），无比例字段。
- 生产：部署新代码后在薪酬设置中选择「季度绩效法」并确认各档类型；钉钉花名册 pay_grade 仍须先同步（既有前置条件，非本次新增）。
- 无数据库迁移：复用 payroll_period_coefs（period_type='quarterly'）、payroll_results.row_data.perf_detail、calc_rules.json。

## 8. 风险与兼容性

- 配置并存：三种 cycle 互不影响，切回旧方式只需在下拉改选，旧数据结构保留。
- 绩效基数/出勤口径复用现有函数，避免新写一套累计逻辑产生口径漂移。
- 主要风险点：getPayRule 新增分支须保证旧 monthly/quarterly 返回结构字节级不变（用回归测试 8 锁定）。
