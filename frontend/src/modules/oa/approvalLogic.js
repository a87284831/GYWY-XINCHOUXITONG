// 审批中心纯逻辑 — 复刻 pageApprovalCenter 群（app.js:5756-6650）与审批流树操作（app.js:6940-7165）
export const FLOW_NAME = { hire_approval: '录用审批', regular_approval: '转正审批', resign_approval: '离职审批' }
export function flowNameOf(key) { return FLOW_NAME[key] || key }

export const FLOW_ORDER = ['hire_approval', 'regular_approval', 'resign_approval']
export const FLOW_ICON = { hire_approval: '📋', regular_approval: '✅', resign_approval: '🚪' }
export const FLOW_DESC = {
  hire_approval: '新员工录用审批，通过后进入入职办理',
  regular_approval: '试用期满转正审批，通过后更新档案转正日期',
  resign_approval: '员工离职审批，通过后人员档案自动转离职',
}
export function sortFlows(flows) {
  return flows.slice().sort((a, b) => (FLOW_ORDER.indexOf(a.flow_key) - FLOW_ORDER.indexOf(b.flow_key)) || (a.id - b.id))
}

// 列表态与详情态状态文案/配色（旧版两处 stMap 不同，忠实保留）
export const LIST_STATUS = { pending: ['审批中', '#f59e0b'], approved: ['已通过', '#16a34a'], rejected: ['已驳回', '#dc2626'], withdrawn: ['已撤回', '#6b7280'], voided: ['已作废', '#9ca3af'] }
export const DETAIL_STATUS = { pending: ['审批中', '#d97706'], approved: ['已通过', '#15803d'], rejected: ['已驳回', '#dc2626'], withdrawn: ['已撤回', '#6b7280'], voided: ['已作废', '#9ca3af'] }

// 按项目取末级部门名（去重）；proj 为空返回空（必须先选项目）— 复刻 appDeptNameList（app.js:6084）
export function deptNameList(proj, leaves, projNameOf) {
  const out = []; const seen = new Set()
  if (!proj) return out
  for (const n of leaves) {
    const pn = projNameOf(n)
    if (!pn || pn !== proj) continue
    const name = (n.path || n.name || '').split('/').filter(Boolean).pop() || n.name
    if (name && !seen.has(name)) { seen.add(name); out.push(name) }
  }
  return out
}

// 绩效自动算 = 核定月薪 − 基本工资 — 复刻 appCalcPerf（app.js:6073）
export function perfText(fixed, base) {
  const perf = (parseFloat(fixed) || 0) - (parseFloat(base) || 0)
  return perf > 0 ? perf.toFixed(0) + ' 元' : '—'
}

// 表单收集与必填校验 — 复刻 appCollectForm（app.js:6261）
// values: { [key]: string|Array }，person 额外传 [key+'_id']
export function collectForm(schema, values, skipRequired) {
  const form = {}; const missing = []
  for (const fld of schema) {
    const key = fld.key
    if (fld.type === 'perf') continue
    const val = values[key] !== undefined && values[key] !== null ? values[key] : ''
    form[key] = val
    if (fld.type === 'person' && values[key + '_id']) form[key + '_id'] = values[key + '_id']
    if (!skipRequired && fld.required && (val === '' || (Array.isArray(val) && !val.length))) missing.push(fld.label || key)
  }
  return { form, missing }
}

// 步骤条状态 — 复刻 renderAppDetailModal stepsHtml（app.js:6378）
export function stepList(nodeChain, currentIndex, status) {
  const cur = currentIndex || 0
  return (nodeChain || []).map((nd, i) => ({
    name: nd.name,
    who: (nd.approvers || []).map((a) => a.name).join('、'),
    done: i < cur || status === 'approved',
    isCur: i === cur && status === 'pending',
    num: i + 1,
  }))
}

// 审批记录展开（节点×审批人，取同人意见）— 复刻 recHtml（app.js:6397）
export function approvalRecords(nodeChain, opinions) {
  const items = []
  ;(nodeChain || []).forEach((nd, i) => {
    ;(nd.approvers || []).forEach((a) => {
      const nodeOps = (opinions || {})[(nd.nodeId !== undefined && nd.nodeId !== null ? nd.nodeId : i)] || []
      const op = nodeOps.find((o) => o.name === a.name)
      items.push({ node: nd.name, name: a.name, state: a.state, op })
    })
  })
  return items
}


// 发送对象分组（按项目，组名排序）— 复刻 appSendPanel（app.js:5887）
export function sendGroups(targets) {
  const groups = {}
  ;(targets || []).forEach((s) => { (groups[s.project || '未分组'] = groups[s.project || '未分组'] || []).push(s) })
  return Object.keys(groups).sort().map((k) => ({ project: k, items: groups[k] }))
}

// 组合查询参数 — 复刻 appDoQuery（app.js:5934）
export function buildQuery(f) {
  const p = new URLSearchParams()
  if (f.flow_no) p.set('flow_no', f.flow_no)
  if (f.keyword) p.set('keyword', f.keyword)
  if (f.flow_key) p.set('flow_key', f.flow_key)
  if (f.status) p.set('status', f.status)
  if (f.date_from) p.set('date_from', f.date_from)
  if (f.date_to) p.set('date_to', f.date_to)
  return p.toString()
}

/* ---- 审批流设计器树操作（钉钉风格树） ---- */
// 定位条目所在链与下标 — 复刻 findTreeItem（app.js:6940）
export function findTreeItem(tree, id) {
  if (!tree || !tree.items) return null
  for (let i = 0; i < tree.items.length; i++) {
    const it = tree.items[i]
    if (it.id === id) return { chain: tree.items, index: i }
    if (it.type === 'branch' || it.paths) {
      for (let j = 0; j < (it.paths || []).length; j++) {
        const p = it.paths[j]
        if (p.id === id) return { chain: p.items, index: p.items.length }
        const r = findTreeItem(p, id)
        if (r) return r
      }
    }
  }
  return null
}
// 在 refId 后插入 — 复刻 insertAfter（app.js:6956）
export function insertAfter(tree, refId, newItem) {
  const loc = findTreeItem(tree, refId)
  if (loc) loc.chain.splice(loc.index + 1, 0, newItem)
  else tree.items.push(newItem)
}
// 递归删除 — 复刻 removeItemById（app.js:6962）
export function removeItemById(tree, id) {
  if (!tree) return false
  const items = tree.items || []
  for (let i = 0; i < items.length; i++) {
    if (items[i].id === id) { items.splice(i, 1); return true }
    if (items[i].type === 'branch' || items[i].paths) {
      for (const p of (items[i].paths || [])) { if (removeItemById(p, id)) return true }
    }
  }
  return false
}
// 节点在树中的引用条目 id — 复刻 findNodeRefId（app.js:6973）
export function findNodeRefId(tree, nodeId) {
  if (!tree) return null
  for (const it of (tree.items || [])) {
    if (it.type === 'approve' && it.nodeId === nodeId) return it.id
    if (it.type === 'branch' || it.paths) { for (const p of (it.paths || [])) { const r = findNodeRefId(p, nodeId); if (r) return r } }
  }
  return null
}
// 找分支 — 复刻 findBranchById（app.js:7015）
export function findBranchById(tree, branchId) {
  const walk = (t) => {
    if (!t) return null
    for (const it of (t.items || [])) {
      if (it.id === branchId) return it
      if (it.type === 'branch' || it.paths) { for (const p of (it.paths || [])) { const r = walk(p); if (r) return r } }
    }
    return null
  }
  return walk(tree)
}
// 收集树中使用的节点 id — 复刻 collectNodeIds（app.js:7160）
export function collectNodeIds(tree, set) {
  if (!tree) return
  for (const it of (tree.items || [])) {
    if (it.type === 'approve') set.add(it.nodeId)
    if (it.type === 'branch' || it.paths) { for (const p of (it.paths || [])) collectNodeIds(p, set) }
  }
}
// 旧版 default/branches 配置转树 — 复刻 legacyToTree（app.js:6692）
// 返回 { tree, seq }（seq 为消耗后的计数器值，组件回写 _appTreeSeq）
export function legacyToTree(flowConfig, nodeIds, seq) {
  const cfg = flowConfig || {}
  const items = []
  let s = seq
  const nx = () => ++s
  const def = cfg.default && cfg.default.length ? cfg.default : nodeIds
  def.forEach((nid) => { items.push({ id: 't' + nx(), type: 'approve', nodeId: nid }) })
  ;(cfg.branches || []).forEach((b) => {
    const paths = [
      { id: 'p' + nx(), label: '命中条件', default: false, cond: { field: b.field || '', op: b.op || 'eq', value: b.value || '' }, items: (b.nodes || []).map((nid) => ({ id: 't' + nx(), type: 'approve', nodeId: nid })) },
      { id: 'p' + nx(), label: '默认', default: true, items: [] },
    ]
    items.push({ id: 't' + nx(), type: 'branch', cond: { field: b.field || '', op: b.op || 'eq', value: b.value || '' }, paths })
  })
  return { tree: { type: 'chain', items }, seq: s }
}
