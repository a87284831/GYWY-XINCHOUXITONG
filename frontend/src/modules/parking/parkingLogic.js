// 停车场登记模块纯逻辑（筛选拼参 / 字段校验 / URL 标签 / 驾驶舱取数）
// 契约：docs/superpowers/specs/2026-11-24-parking-register-design.md

export const DEFAULT_REASONS = ['拜访', '送货', '施工', '快递', '其他']

// 与后端 ParkingController::PLATE_RE 保持一致（普通 7 位 / 新能源 8 位）
export const PLATE_RE = /^[京津沪渝冀晋蒙辽吉黑苏浙皖闽赣鲁豫鄂湘粤桂琼川贵云藏陕甘青宁新使领][A-HJ-NP-Z][A-HJ-NP-Z0-9]{4,5}[A-HJ-NP-Z0-9挂学警港澳]$/

// query string 拼接：跳过 undefined/null/空串；无有效参数返回空串
export function qs(params) {
  const sp = new URLSearchParams()
  Object.keys(params || {}).forEach((k) => {
    const v = params[k]
    if (v !== undefined && v !== null && String(v).trim() !== '') sp.append(k, v)
  })
  const s = sp.toString()
  return s ? '?' + s : ''
}

// 入口标签：小区名 · 门名（缺任一侧时自动省略分隔符）
export function gateLabel(projectName, gateName) {
  const p = String(projectName || '').trim()
  const g = String(gateName || '').trim()
  if (!g) return p
  return p ? `${p} · ${g}` : g
}

export function normalizePlate(v) {
  return String(v || '').toUpperCase().replace(/[\s·。.]/g, '')
}

export function isValidPlate(v) {
  return PLATE_RE.test(normalizePlate(v))
}

export function isValidPhone(v) {
  return /^1\d{10}$/.test(String(v || '').trim())
}

export function reasonsOf(config) {
  const list = config && Array.isArray(config.reasons) ? config.reasons : []
  const out = list.map((r) => String(r)).filter((r) => r !== '')
  return out.length ? out : DEFAULT_REASONS
}

// 返回错误文案；通过返回空串。规则与后端 register 校验链一致。
export function validateRegistration(form, config) {
  const f = form || {}
  if (!isValidPlate(f.plate)) return '请填写正确的车牌号'
  const name = String(f.name || '').trim()
  if (name === '') return '请填写司机姓名'
  if (name.length > 60) return '司机姓名不能超过 60 字'
  if (!isValidPhone(f.phone)) return '请填写正确的手机号'
  const visit = String(f.visit_target || '').trim()
  if (visit === '') return '请填写访问户号/受访人'
  if (visit.length > 120) return '访问户号/受访人不能超过 120 字'
  if (!reasonsOf(config).includes(String(f.reason || ''))) return '请选择事由'

  const extra = f.extra || {}
  const fields = (config && Array.isArray(config.fields) ? config.fields : []).filter(
    (x) => x && x.enabled !== false
  )
  for (const field of fields) {
    const label = String(field.label || '').trim()
    const key = String(field.key || '')
    if (label === '' || key === '') continue
    const val = String(extra[key] == null ? '' : extra[key]).trim()
    if (val === '') {
      if (field.required) return `请填写「${label}」`
      continue
    }
    if (val.length > 100) return `「${label}」内容过长`
    if (field.type === 'number' && !isNumeric(val)) return `「${label}」必须为数字`
    if (field.type === 'radio') {
      const options = (Array.isArray(field.options) ? field.options : [])
        .map((o) => String(o).trim())
        .filter((o) => o !== '')
      if (options.length && !options.includes(val)) return `「${label}」选项不正确`
    }
  }
  return ''
}

function isNumeric(v) {
  return String(v).trim() !== '' && !Number.isNaN(Number(v))
}

// 驾驶舱 KPI 卡片
export function kpiCards(kpi) {
  const k = kpi || {}
  return [
    { key: 'today', label: '今日登记', value: Number(k.today) || 0, hint: '含全部小区' },
    { key: 'week', label: '近 7 日', value: Number(k.week) || 0, hint: '滚动 7 天' },
    { key: 'month', label: '本月登记', value: Number(k.month) || 0, hint: '自然月' },
    { key: 'total', label: '累计登记', value: Number(k.total) || 0, hint: '历史总量' },
  ]
}

// 近 7 日趋势柱（pct 最小 2%，保证 0 值也有可见基线）
export function trendBars(series) {
  const rows = Array.isArray(series) ? series : []
  const max = Math.max(1, ...rows.map((r) => Number(r.count) || 0))
  return rows.map((r) => {
    const count = Number(r.count) || 0
    return { date: String(r.date || ''), count, pct: Math.max(2, Math.round((count / max) * 100)) + '%' }
  })
}

// 小区分布条（今日 TOP）
export function projectBars(rows) {
  const list = Array.isArray(rows) ? rows : []
  const max = Math.max(1, ...list.map((r) => Number(r.count) || 0))
  return list.map((r) => {
    const count = Number(r.count) || 0
    return {
      project_name: String(r.project_name || '未命名小区'),
      count,
      pct: Math.max(2, Math.round((count / max) * 100)) + '%',
    }
  })
}

// 分页器：最多展示 5 个页码按钮
export function pageList(total, page, size = 50) {
  const pages = Math.max(1, Math.ceil((Number(total) || 0) / size))
  const cur = Math.min(Math.max(1, Number(page) || 1), pages)
  const start = Math.max(1, Math.min(cur - 2, pages - 4))
  const out = []
  for (let i = start; i <= Math.min(pages, start + 4); i++) out.push(i)
  return { pages, current: cur, items: out }
}
