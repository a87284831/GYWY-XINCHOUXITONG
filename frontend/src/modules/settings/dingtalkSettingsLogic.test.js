// 钉钉配置页纯逻辑（2026-09-30 前端配置卡片）
// getConfig 返回掩码字段（app_secret_masked/callback_aes_key_masked），保存时仅提交非空输入
import { describe, it, expect } from 'vitest'
import { dingtalkConfigToDraft, dingtalkDraftToPayload } from './dingtalkSettingsLogic'

describe('dingtalkConfigToDraft', () => {
  it('明文字段进草稿，掩码字段进占位提示，草稿密钥留空表示不修改', () => {
    const draft = dingtalkConfigToDraft({
      app_key: 'dingabc',
      app_secret_masked: '123456****ef12',
      agent_id: '888',
      callback_token: 'mytoken',
      callback_aes_key_masked: 'aeskey****xyz',
      sync_status: 'success',
    })
    expect(draft).toEqual({
      app_key: 'dingabc',
      agent_id: '888',
      callback_token: 'mytoken',
      app_secret: '',
      callback_aes_key: '',
      app_secret_masked: '123456****ef12',
      callback_aes_key_masked: 'aeskey****xyz',
    })
  })

  it('空配置返回空草稿', () => {
    expect(dingtalkConfigToDraft({})).toEqual({
      app_key: '', agent_id: '', callback_token: '',
      app_secret: '', callback_aes_key: '',
      app_secret_masked: '', callback_aes_key_masked: '',
    })
  })
})

describe('dingtalkDraftToPayload', () => {
  it('仅提交非空且发生变更的字段（空 = 不修改）', () => {
    const payload = dingtalkDraftToPayload({
      app_key: 'dingabc',
      agent_id: '888',
      callback_token: '',
      app_secret: '',
      callback_aes_key: 'a'.repeat(43),
    })
    expect(payload).toEqual({ app_key: 'dingabc', agent_id: '888', callback_aes_key: 'a'.repeat(43) })
  })

  it('全为空时不提交任何字段', () => {
    expect(dingtalkDraftToPayload({ app_secret: '', callback_aes_key: '' })).toEqual({})
  })
})
