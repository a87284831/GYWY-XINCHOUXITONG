// 绩效考核纯逻辑 — 复刻旧版 app.js:5015-5033, 5624-5633, 5093-5097, 5186-5187（与后端算分保持一致，用于实时预览）
export function perfNum(v) {
  const n = Number(v)
  return v === '' || v === null || v === undefined || Number.isNaN(n) ? null : n
}

export function perfFmt(v) {
  const n = perfNum(v)
  return n === null ? '-' : n.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1')
}

// 五类指标文案：与后端 PerformanceController::CALC_TYPES 保持一致，错误提示/打印表共用
export const CALC_LABEL = {
  ratio: '比例计分',
  ladder: '阶梯扣分',
  count: '达标扣分',
  check: '核查定分',
  manual: '主观评分',
}

export const CALC_HELP = {
  ratio: '比例计分：按完成比例折算得分。得分=权重×(实际值÷目标值)，最高不超过该指标权重、最低0分。适合达标类指标（如收缴率、入住率）。示例：权重20、目标100、实际完成80 → 得20×(80/100)=16分。',
  ladder: '阶梯扣分：以目标值为基准，每低一个阶梯单位扣指定分数，低于“记0阈值”整项记0分。得分=权重−(目标值−实际值)÷步长×每步扣分，封顶权重、最低0分。适合量化递减类指标（如投诉次数、差错数）。示例：目标100、每差5扣2分、实际90 → 扣4分。',
  count: '达标扣分：以“应完成数量”为基准，每少完成1个单位扣指定分数。得分=权重−(应完成−实际)×每缺扣分，封顶权重、最低0分。适合件数/次数类指标。示例：应完成6件、每少1件扣1分、实际4件 → 扣2分。',
  check: '核查定分：系统不做公式计算，由指定核查人在数据填报阶段依据核查结论直接在 0~权重 范围内定分（含0分），定分后即锁定，本人与上级均不可改。适合需要人工核验事实后定分的指标。核查人可以是被考核人本人。',
  manual: '主观评分：系统不自动计算分数，由本人自评与上级逐级评分分别打分，最终分=自评分×自评占比＋上级评分×上级占比（占比在系统设置中可调）。可在自评时上传截图等依据附件（每项≤5个，jpg/png/pdf，单个≤10MB）。',
}

// 客观项类型（本人/上级不可评分，分数在数据填报阶段锁定）；manual 为主观项
export const OBJECTIVE_TYPES = ['ratio', 'ladder', 'count', 'check']

// 单项指标算分：ratio=权重×(实际÷目标)；ladder=权重−(目标−实际)÷步长×每步扣分（低于记0阈值整项0分）；count=权重−(应完成−实际)×每缺扣分；封顶权重、最低0
// check=核查定分（取 checkScore，不在此预览公式）；manual=主观评分；二者均返回 null
export function perfCalcItem(item) {
  const w = perfNum(item.weight) || 0, v = perfNum(item.actualValue), p = item.calcParams || {}
  if (item.calcType === 'manual' || item.calcType === 'check' || v === null) return null
  const P = (k) => { const x = perfNum(p[k]); return x === null ? 0 : x }
  if (item.calcType === 'ratio') {
    const t = P('target'); if (!t) return 0
    return Math.round(Math.max(0, Math.min(w, (w * v) / t)) * 100) / 100
  }
  if (item.calcType === 'ladder') {
    const target = P('target') || 100, unit = P('stepUnit') || 1, ded = P('stepDeduct')
    const zt = perfNum(p.zeroThreshold)
    if (zt !== null && v < zt) return 0
    const gap = Math.max(0, target - v) / (unit || 1)
    return Math.round(Math.max(0, Math.min(w, w - gap * ded)) * 100) / 100
  }
  if (item.calcType === 'count') {
    const req = P('required'), ded = P('deductEach')
    const lack = Math.max(0, req - v)
    return Math.round(Math.max(0, Math.min(w, w - lack * ded)) * 100) / 100
  }
  return null
}

// 全部指标权重合计（跳过未填项），精确到 2 位
export function perfWeightSum(d) {
  let s = 0
  ;(d.categories || []).forEach((c) => (c.items || []).forEach((it) => {
    const w = perfNum(it.weight)
    if (w !== null) s += w
  }))
  return Math.round(s * 100) / 100
}

// 审批阶段最终分实时预览（app.js perfMixFinal）：自评×自评占比 + 考核人×考核人占比；自评为空直接取考核人评分
export function mixFinal(selfScore, approverScore, sw) {
  const av = perfNum(approverScore), sl = perfNum(selfScore)
  if (av === null) return null
  if (sl === null) return av
  return Math.round(((sl * sw.self + av * sw.approver) / 100) * 100) / 100
}

// 审批人身份 ID（staffId 优先，兼容旧账号维度 userId）
export function perfApId(a) {
  return a ? (a.staffId != null ? Number(a.staffId) : Number(a.userId ?? 0)) : 0
}

// 默认考核周期 = 本季度起止（复刻旧逻辑：Q1/Q2/Q3 末月固定 30 日，仅 12 月为 31 日）
export function defaultPeriod(now) {
  const q = Math.floor(now.getMonth() / 3)
  return {
    start: now.getFullYear() + '-' + String(q * 3 + 1).padStart(2, '0') + '-01',
    end: now.getFullYear() + '-' + String(q * 3 + 3).padStart(2, '0') + '-' + (q * 3 + 3 === 12 ? '31' : '30'),
  }
}

// 切换算分方式时的默认参数（check/manual 无参数）
export function defaultCalcParams(t) {
  return t === 'ratio' ? { target: 100 }
    : t === 'ladder' ? { target: 100, stepUnit: 1, stepDeduct: 5, zeroThreshold: 80 }
    : t === 'count' ? { required: 6, deductEach: 1 }
    : {}
}
