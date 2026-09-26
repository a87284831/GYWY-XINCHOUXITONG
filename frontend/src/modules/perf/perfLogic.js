// 绩效考核纯逻辑 — 复刻旧版 app.js:5015-5033, 5624-5633, 5093-5097, 5186-5187（与后端算分保持一致，用于实时预览）
export function perfNum(v) {
  const n = Number(v)
  return v === '' || v === null || v === undefined || Number.isNaN(n) ? null : n
}

export function perfFmt(v) {
  const n = perfNum(v)
  return n === null ? '-' : n.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1')
}

// 单项指标算分：ratio=权重×(实际÷目标)；ladder=权重−(目标−实际)÷步长×每步扣分（低于记0阈值整项0分）；count=权重−(应完成−实际)×每缺扣分；封顶权重、最低0
export function perfCalcItem(item) {
  const w = perfNum(item.weight) || 0, v = perfNum(item.actualValue), p = item.calcParams || {}
  if (item.calcType === 'manual' || v === null) return null
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

// 切换算分方式时的默认参数
export function defaultCalcParams(t) {
  return t === 'ratio' ? { target: 100 }
    : t === 'ladder' ? { target: 100, stepUnit: 1, stepDeduct: 5, zeroThreshold: 80 }
    : t === 'count' ? { required: 6, deductEach: 1 }
    : {}
}
