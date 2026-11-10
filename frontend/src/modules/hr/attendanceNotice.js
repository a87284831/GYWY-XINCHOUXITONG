// 考勤上传结果 → ResultNotice 通用提示结构
// mode: checking | uploading | error | preview | uploaded
const FOLD_HINT = '以下条目不影响上传，确认后继续'

function toRow(it) {
  return {
    name: it.name || '整单',
    tag: it.row ? `第${it.row}行` : '',
    reason: it.reason || it.message || '',
  }
}

// 兼容旧接口：没有 error_items 时把 error 字符串当作单条，避免升级期间白屏
function fallbackErrorItems(payload, message) {
  if (payload && Array.isArray(payload.error_items) && payload.error_items.length) return payload.error_items
  return [{ code: 'unknown', name: '', reason: message || (payload && payload.error) || '上传失败' }]
}

function fallbackWarningItems(r) {
  if (Array.isArray(r.warning_items) && r.warning_items.length) return r.warning_items
  return (r.warnings || []).map((w) => ({ code: 'stat_mismatch', name: '', reason: String(w) }))
}

export function buildAttendanceErrorNotice(err, base = {}) {
  const payload = (err && err.payload) || {}
  const items = fallbackErrorItems(payload, err && err.message)
  const danger = items.map(toRow)
  const canForce = payload.can_force !== undefined
    ? !!payload.can_force
    : String(err && err.message).includes('按符号统计为')
  return {
    kind: 'warn',
    title: '考勤表校验未通过',
    pill: `${danger.length} 项需处理`,
    context: base.context || '',
    chips: [
      ...(base.count ? [{ text: `解析 ${base.count} 人` }] : []),
      { text: `错误 ${danger.length}`, tone: 'dg' },
      ...(base.infoCount ? [{ text: `提示 ${base.infoCount}`, tone: 'if' }] : []),
    ],
    danger,
    info: [],
    skipped: [],
    dangerTitle: '异常 · 修正后重新上传',
    dangerHint: '按行修正后重新上传。统计列可留空由系统按符号统计；符号无效请先在「考勤符号库设置」补录。',
    infoTitle: '提示 · 不影响上传',
    footText: '未通过校验的考勤表不会写入任何数据，修正后重新上传即可。',
    actions: { force: canForce && !base.forceStats, cancel: true },
  }
}

export function buildAttendancePreviewNotice(r, base = {}) {
  const uploaded = base.mode === 'uploaded'
  const info = []
  if (r.overwrite) {
    info.push({
      name: '整表覆盖',
      tag: '',
      reason: base.isVirtual
        ? '将按人合并已有考勤（块内其他人员保留）'
        : '新表将完全替换旧数据，旧表有而新表没有的人员会被移除',
    })
  }
  if (r.has_calc) {
    info.push({ name: '已有核算', tag: '', reason: `${base.ym || ''} 已有核算结果，上传后需重新核算`.trim() })
  }
  if (base.forceStats) {
    info.push({ name: '统计列覆盖', tag: '', reason: '已勾选“仍然上传”，统计列与格子不一致时将按列值入库' })
  }
  info.push(...fallbackWarningItems(r).map(toRow))
  info.push(...((r.info_items || []).map(toRow)))

  const names = r.names || []
  const nameText = names.length
    ? `本次涉及：${names.slice(0, 10).join('、')}${names.length > 10 ? ` 等 ${names.length} 人` : ''}`
    : ''
  const title = uploaded ? `上传成功：${r.count} 人` : `校验通过：${r.count} 人`
  return {
    kind: 'ok',
    title,
    pill: uploaded ? '已完成' : '待确认',
    context: base.context || '',
    chips: [
      { text: `解析 ${r.count || 0} 人` },
      ...(r.overwrite ? [{ text: '将覆盖旧数据' }] : []),
      ...(r.has_calc ? [{ text: '已有核算结果', tone: 'dg' }] : []),
      ...(info.length ? [{ text: `提示 ${info.length}`, tone: 'if' }] : []),
    ],
    danger: [],
    info,
    skipped: [],
    infoTitle: uploaded ? '提示 · 本次上传说明' : '提示 · 不影响上传',
    infoHint: info.length ? FOLD_HINT : '',
    footText: uploaded ? (info.length ? '上传完成，提示条目请留意。' : '上传完成。') : nameText,
    actions: { confirm: !uploaded, cancel: true },
  }
}

export function buildAttendanceBusyNotice(text, context = '') {
  return {
    kind: 'ok',
    title: text,
    pill: '请稍候',
    context,
    chips: [],
    danger: [],
    info: [],
    skipped: [],
    actions: {},
  }
}
