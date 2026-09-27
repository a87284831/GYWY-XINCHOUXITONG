// 采购招采审核三页（填报进度确认/清单外审核/预算管理）纯逻辑——复刻旧 bundle：
// AdminOverview-CJuB-Ax-.js / CustomReview-ClqvMFiF-v3.js / BudgetPlan-X9sZSfU0.js

// 旧版 ee()：datetime → "MM-DD HH:MM"
export function monthShort(s) {
  return s ? String(s).replace('T', ' ').slice(5, 16) : ''
}

// 明细行状态 → 中文文案与 tag 配色（returned 深红底）
export function statusMeta(status) {
  if (status === 'returned') return { text: '已退回', type: 'danger', dark: true }
  if (status === 'confirmed') return { text: '已确认', type: 'success' }
  if (status === 'submitted') return { text: '已提交', type: 'primary' }
  return { text: '草稿', type: 'info' }
}

// 条线单元格 tag：全部确认→success，否则 primary（调用方保证 total>0）
export function lineTagType(cell) {
  return cell.confirmed === cell.total ? 'success' : 'primary'
}

// 预算页汇总卡（复刻 BudgetPlan 的 reduce 求和与 round 规则）
export function budgetStats(rows) {
  const budget = rows.reduce((o, r) => o + (Number(r.budget) || 0), 0)
  const actual = rows.reduce((o, r) => o + (Number(r.actual) || 0), 0)
  return {
    budget,
    actual,
    rate: budget ? Math.round((actual / budget) * 1000) / 10 : 0,
    remain: Math.round((budget - actual) * 100) / 100,
    overCount: rows.filter((r) => r.over).length,
    filledCount: rows.filter((r) => Number(r.budget) > 0).length,
  }
}

// 预算行状态：超预算 / 预算内 / 未导入 / 未设预算
export function budgetRowState(row) {
  if (row.budget > 0 && row.over) return 'over'
  if (row.budget > 0 && row.actual > 0) return 'within'
  if (row.budget > 0) return 'noimport'
  return 'unset'
}

// 导入弹窗年份下拉（当前年 ±1）
export function budgetYears(now) {
  const y = now.getFullYear()
  return [y - 1, y, y + 1]
}

// 清单外审核：填报行 → 编辑入库表单
// （purchase_items 无 category 字段恒空；quantity/use_location 不继承，员工值仅在 merge 时由后端保留）
export function seedCustomForm(row) {
  return {
    name: row.item_name || '',
    brand: row.brand || '',
    spec: row.spec || '',
    unit: row.unit || '',
    line: row.line || '',
    category: '',
    alias: row.item_name || '',
    quantity: '',
    stock: row.stock ?? '',
    use_location: '',
    remark: row.remark || '',
  }
}

// 「归入」候选商品请求体
export function mapPayload(row, uid) {
  return { item_id: row.id, product_id: row._sel, save_alias: row._saveAlias, created_by: uid }
}

// 「保存入库」请求体：create 无 product_id；merge 带 product_id
export function saveCustomPayload(form, row, mode, pid, uid) {
  const payload = {
    item_id: row.id,
    mode,
    name: form.name,
    brand: form.brand,
    spec: form.spec,
    unit: form.unit,
    line: form.line,
    category: form.category,
    alias: form.alias,
    quantity: form.quantity,
    stock: form.stock,
    use_location: form.use_location,
    remark: form.remark,
    created_by: uid,
  }
  if (mode === 'merge') payload.product_id = pid
  return payload
}

// 全年速览：某月是否已设置预算
export function hasBudgetMonth(months, year, n) {
  return months.includes(year + '-' + String(n).padStart(2, '0'))
}
