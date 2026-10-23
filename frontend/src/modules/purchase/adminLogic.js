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

// 按 id 在商品列表里查找（宽松比较：Element Plus 可能把 option value 转成字符串）
export function findProduct(list, id) {
  if (id === null || id === undefined || id === '') return null
  const key = String(id)
  return (list || []).find((p) => String(p.id) === key) || null
}

// 清单外审核「归入标准商品」下拉选项：远程搜索结果优先，无搜索时回退后端预置候选；
// 已选中的商品始终保留在选项里，避免下一次搜索后标签退化成 id
export function productOptions(row) {
  const searched = Array.isArray(row && row._search) ? row._search : []
  const base = searched.length ? searched : ((row && row.candidates) || [])
  const picked = row && row._selItem
  if (picked && !base.some((c) => c.id === picked.id)) return [picked].concat(base)
  return base
}

// 商品选项展示文案：名称（规格）·品牌
export function productLabel(p) {
  const name = (p && p.name) || ''
  const spec = (p && p.spec) || ''
  const brand = (p && p.brand) || ''
  return name + '（' + spec + '）' + (brand ? '·' + brand : '')
}

// 预算行状态：超预算 / 预算内 / 未导入 / 未设预算
export function budgetRowState(row) {
  if (row.budget > 0 && row.over) return 'over'
  if (row.budget > 0 && row.actual > 0) return 'within'
  if (row.budget > 0) return 'noimport'
  return 'unset'
}

// 导入弹窗年份下拉（前一年 ~ 后四年，兼顾跨年备料与远期预算）
export function budgetYears(now) {
  const y = now.getFullYear()
  return [y - 1, y, y + 1, y + 2, y + 3, y + 4]
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

// 规格归一化：全角转半角 + 折叠空白 + 首尾去空格（与后端 norm_spec 口径一致）
export function normSpec(s) {
  if (s === null || s === undefined) return ''
  const half = String(s).replace(/[\uFF01-\uFF5E]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xFEE0))
  return half.replace(/\u3000/g, ' ').replace(/[ \t]+/g, ' ').trim()
}

// 归入标准商品弹窗的对照与校验（纯函数）：
// 名称/条线/分类/品牌强制取标准商品；规格默认取填报原值；单位必须与标准商品一致才允许提交
export function buildMapPlan(item, product, input = {}) {
  const spec = normSpec(input.spec !== undefined ? input.spec : (item && item.spec))
  const unit = normSpec(input.unit !== undefined ? input.unit : (item && item.unit))
  const productUnit = normSpec(product && product.unit)
  const unitMismatch = !!product && unit !== productUnit
  return {
    name: (product && product.name) || '',
    brand: (product && product.brand) || '',
    line: (product && product.line) || '',
    category: (product && product.category) || '',
    spec,
    unit,
    productUnit,
    unitMismatch,
    canSubmit: !!product && spec !== '' && !unitMismatch,
  }
}

// 「归入」请求体：名称统一由后端强制，前端只提交规格与单位（供校验）及冲突处理动作
export function mapPayload(row, uid, opts = {}) {
  return {
    item_id: row.id,
    product_id: row._sel,
    save_alias: row._saveAlias,
    created_by: uid,
    spec: opts.spec !== undefined ? opts.spec : row.spec,
    unit: opts.unit !== undefined ? opts.unit : row.unit,
    spec_action: opts.spec_action || 'add',
  }
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
  if (mode === 'add_spec') payload.base_product_id = pid
  return payload
}

// 全年速览：某月是否已设置预算
export function hasBudgetMonth(months, year, n) {
  return months.includes(year + '-' + String(n).padStart(2, '0'))
}
