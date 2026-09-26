// 组织树缓存与辅助（复刻旧版 app.js 的 ORG_TREE/ORG_SEL 与 orgFlat/orgLeaves/orgNode/orgProjectOf/orgPathOf/loadOrgTree）
import { api } from '@/api/client'

let ORG_TREE = null // 组织树缓存
let ORG_SEL = null // 当前选中节点

export function orgTree() { return ORG_TREE }
export function orgSel() { return ORG_SEL }
export function setOrgSel(n) { ORG_SEL = n }

export async function loadOrgTree(force) {
  if (!ORG_TREE || force) {
    const d = await api('/api/org/tree')
    ORG_TREE = d.tree
  }
  return ORG_TREE
}

export function orgFlat(tree, out) {
  out = out || []
  for (const n of tree || []) { out.push(n); orgFlat(n.children, out) }
  return out
}

export function orgLeaves() {
  return (ORG_TREE ? orgFlat(ORG_TREE) : []).filter((n) => n.type === 'department' || n.type === 'team')
}

export function orgNode(id) {
  return (ORG_TREE ? orgFlat(ORG_TREE) : []).find((n) => n.id === Number(id))
}

export function orgProjectOf(id) {
  let n = orgNode(id)
  const seen = new Set()
  while (n && !seen.has(n.id)) {
    seen.add(n.id)
    if (n.type === 'project') return n
    n = n.parent_id ? orgNode(n.parent_id) : null
  }
  return null
}

export function orgPathOf(id) {
  const n = orgNode(id)
  return n ? n.path : ''
}
