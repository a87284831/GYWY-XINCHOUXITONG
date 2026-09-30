// 钉钉配置页纯逻辑（2026-09-30）
// 后端 getConfig 返回 app_secret_masked / callback_aes_key_masked 并隐去明文；
// 表单密钥留空表示「不修改」，保存仅提交非空字段（与后端 saveConfig 空值跳过行为匹配）。

const TEXT_KEYS = ['app_key', 'agent_id', 'callback_token']
const SECRET_FIELDS = ['app_secret', 'callback_aes_key']

// getConfig 响应 → 表单草稿
export function dingtalkConfigToDraft(cfg = {}) {
  const draft = {}
  for (const k of TEXT_KEYS) draft[k] = String(cfg[k] ?? '')
  for (const k of SECRET_FIELDS) draft[k] = ''
  for (const k of SECRET_FIELDS) draft[k + '_masked'] = String(cfg[k + '_masked'] ?? '')
  return draft
}

// 表单草稿 → 保存载荷（仅非空字段）
export function dingtalkDraftToPayload(draft = {}) {
  const payload = {}
  for (const k of [...TEXT_KEYS, ...SECRET_FIELDS]) {
    const v = String(draft[k] ?? '').trim()
    if (v !== '') payload[k] = v
  }
  return payload
}
