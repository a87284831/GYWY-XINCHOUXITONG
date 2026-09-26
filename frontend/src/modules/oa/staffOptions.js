// 审批中心共享缓存 — 复刻旧版 _appStaffOpts 模块级缓存（app.js:5754）
import { api } from '@/api/client'

let _staffOpts = null

export async function staffOptions() {
  if (_staffOpts === null) {
    try { const s = await api('/api/org/staff-options'); _staffOpts = s.staff || [] } catch (e) { _staffOpts = [] }
  }
  return _staffOpts
}
export function staffMap() {
  const m = {}
  ;(_staffOpts || []).forEach((s) => { m[s.id] = s })
  return m
}
