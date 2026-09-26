// 采购填报纯逻辑（行状态机/校验/载荷/文案；契约见 frontend/docs/purchase-api-contract.md §3.3）
export const FILL_LINES = ['环境', '绿化', '工程', '秩序', '行政']

export function newRow(key, line) {
  return {
    key, id: null, line: line || '', item_name: '', spec: '', brand: '', unit: '',
    quantity: 0, stock: 0, reason: '', use_location: '', remark: '',
    is_custom: false, status: 'new', product_id: null, price: 0,
    return_reason: '', returned_at: '',
    nameOptions: [], specs: [], freq: null, loadingName: false, _isNew: true,
  }
}

// 只读行：已确认（谁都不可改）；已提交且非 admin（员工需先撤销提交）
export function isRowReadonly(row, isAdmin) {
  return row.status === 'confirmed' || (row.status === 'submitted' && !isAdmin)
}
export function canCopyRow(row) {
  return row.status !== 'confirmed'
}
export function canRevokeRow(row) {
  return row.status === 'submitted'
}
export function canDeleteRow(row, isAdmin) {
  return row.status !== 'confirmed' && !(row.status === 'submitted' && !isAdmin)
}
// 可保存行：非只读且有内容（忠实旧版：!item_name && !quantity && !reason 跳过）
export function rowSaveable(row, isAdmin) {
  if (row.status === 'confirmed') return false
  if (row.status === 'submitted' && !isAdmin) return false
  return !!(row.item_name || row.quantity || row.reason)
}

export function validateRow(row, idx) {
  if (!row.line) return '第 ' + idx + ' 行：请选择条线'
  if (!row.item_name) return '第 ' + idx + ' 行：请填写商品名称'
  if (!row.is_custom && !row.spec) return '第 ' + idx + ' 行：请选择规格型号'
  if (!row.quantity) return '第 ' + idx + ' 行：请填写数量'
  return null
}

export function rowPayload(row, month, projectId, isAdmin, submit) {
  const m = {
    month,
    line: row.line,
    item_name: row.item_name,
    brand: row.brand,
    spec: row.spec,
    unit: row.unit,
    quantity: Number(row.quantity) || 0,
    stock: Number(row.stock) || 0,
    reason: row.reason,
    use_location: row.use_location,
    remark: row.remark,
    status: row.status === 'submitted' || submit ? 'submitted' : 'draft',
  }
  if (isAdmin && projectId) m.project_id = projectId
  if (!row.is_custom) m.product_id = row.product_id
  return m
}

export function saveSummary(saved, failed, submit) {
  if (failed > 0) return { type: 'warning', text: '已保存 ' + saved + ' 条，' + failed + ' 条未保存（请按提示补全）' }
  if (saved > 0) return submit
    ? { type: 'success', text: '已保存并提交 ' + saved + ' 条' }
    : { type: 'success', text: '已保存 ' + saved + ' 条' }
  return { type: 'info', text: '没有需要保存的内容' }
}

// 近3月采购单元格文案：freq=null → 选规格后显示；count>0 → 购N次+逐月明细；否则无记录
export function freqCell(freq) {
  if (!freq) return { kind: 'wait', text: '选规格后显示', detail: [] }
  if (freq.count > 0) {
    return {
      kind: 'ok',
      text: ' 购' + freq.count + '次',
      detail: (freq.detail || []).map((s) => '·' + s.qty + (s.unit || '') + '·' + Number(s.month.slice(5)) + '月'),
    }
  }
  return { kind: 'none', text: '近3月无记录', detail: [] }
}

// 月度卡片状态（my/months 行）：无窗口→未开放；open→开放中；今日<start→尚未开放；否则已截止
export function monthCardStatus(r, today) {
  if (!r.window) return { tag: '未开放', type: 'info', window: '未配置填报窗口' }
  if (r.open) return { tag: '填报开放中', type: 'success', window: '窗口：' + r.window.start_date + ' ~ ' + r.window.end_date }
  const win = '窗口：' + r.window.start_date + ' ~ ' + r.window.end_date
  return today < r.window.start_date
    ? { tag: '尚未开放', type: 'warning', window: win }
    : { tag: '已截止', type: 'warning', window: win }
}

export function draftKey(month, projectId) {
  return 'gw_purchase_fill_draft_' + month + '_' + (projectId || 0)
}

// 卡片按钮：有退回→修改后重新填报；本月开放→进入填报；其余→查看
export function fillCardAction(r, curMonth) {
  if ((r.returned_count || 0) > 0) return { text: '修改后重新填报', type: 'primary' }
  if (r.month === curMonth && r.open) return { text: '进入填报', type: 'primary' }
  return { text: '查看', type: 'default' }
}
