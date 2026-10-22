// 更新日志纯逻辑：版本比较、未读过滤、已读标记（localStorage）
// 版本号规则 vYYYY.M.D.N（当日序号 N 不补零，逐段按数值比较）

export const READ_KEY = 'payroll_changelog_read_version'

// "v2026.10.23.1" → [2026, 10, 23, 1]；非法段按 0 处理
export function parseVersion(v) {
  return String(v || '')
    .replace(/^v/i, '')
    .split('.')
    .map((x) => parseInt(x, 10) || 0)
}

// a < b → -1；a = b → 0；a > b → 1（段数不同时缺失段按 0 补齐）
export function compareVersions(a, b) {
  const pa = parseVersion(a)
  const pb = parseVersion(b)
  const n = Math.max(pa.length, pb.length)
  for (let i = 0; i < n; i++) {
    const d = (pa[i] || 0) - (pb[i] || 0)
    if (d !== 0) return d < 0 ? -1 : 1
  }
  return 0
}

// 未读条目：version 严格大于 lastRead 的条目（保持传入顺序，即后端的新→旧倒序）
// lastRead 为空（从未读过）→ 全部算未读
export function unreadEntries(entries, lastRead) {
  const list = Array.isArray(entries) ? entries : []
  if (!lastRead) return list.slice()
  return list.filter((e) => compareVersions(e && e.version, lastRead) > 0)
}

// 列表中的最新版本（按版本号取最大，不依赖顺序）
export function latestVersion(entries) {
  const list = Array.isArray(entries) ? entries : []
  let best = ''
  for (const e of list) {
    const v = e && e.version
    if (v && (!best || compareVersions(v, best) > 0)) best = v
  }
  return best
}

export function getReadVersion(storage) {
  const s = storage || (typeof localStorage !== 'undefined' ? localStorage : null)
  if (!s) return ''
  try {
    return s.getItem(READ_KEY) || ''
  } catch (e) {
    return ''
  }
}

// 标记已读到指定版本；version 为空不写入（防止把"空"当成已读基线导致以后全不弹）
export function markRead(version, storage) {
  if (!version) return
  const s = storage || (typeof localStorage !== 'undefined' ? localStorage : null)
  if (!s) return
  try {
    s.setItem(READ_KEY, version)
  } catch (e) { /* 隐私模式等写入失败静默：下次还会弹，可接受 */ }
}
