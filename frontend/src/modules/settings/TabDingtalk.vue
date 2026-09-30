<template>
  <div>
    <div class="form-grid" style="max-width:640px">
      <label>AppKey（应用凭据）<input type="text" v-model="fm.app_key" placeholder="钉钉开发者后台应用详情"></label>
      <label>AgentId<input type="text" v-model="fm.agent_id" placeholder="应用 AgentId（花名册接口需要）"></label>
      <label>AppSecret<input type="password" v-model="fm.app_secret" :placeholder="fm.app_secret_masked || '首次配置请输入完整 Secret'"></label>
      <label>回调 Token（事件订阅）<input type="text" v-model="fm.callback_token" placeholder="开发者后台「事件订阅」处配置的 Token，3~32 位英文或数字"></label>
      <label class="full">回调数据加密密钥（AES Key）<input type="password" v-model="fm.callback_aes_key" :placeholder="fm.callback_aes_key_masked || '开发者后台自动生成，43 位字符串'"></label>
    </div>
    <div class="hint">
      AppKey/Secret 用于全量同步；回调 Token 与 AES Key 用于钉钉「事件订阅」推送验签解密。
      回调地址：<code>https://{{ host }}/api/dingtalk/callback</code>。密钥留空表示不修改。
    </div>
    <div class="row" style="margin-top:12px;gap:10px">
      <button class="btn primary" :disabled="saving" @click="save">{{ saving ? '保存中...' : '保存配置' }}</button>
      <button class="btn" :disabled="saving" @click="sync">立即全量同步</button>
    </div>

    <h4 style="margin:22px 0 10px">同步状态</h4>
    <table class="list" v-if="status" style="max-width:640px">
      <tbody>
        <tr><td>凭证已配置</td><td>{{ status.configured ? '是' : '否（请先保存 AppKey/Secret）' }}</td></tr>
        <tr><td>上次同步</td><td>{{ status.last_sync_at || '从未' }}（{{ statusLabel }}）</td></tr>
        <tr><td>同步人员数</td><td>{{ status.last_sync_count ?? 0 }}</td></tr>
        <tr><td>本系统在职 / 离职</td><td>{{ status.staff_active }} / {{ status.staff_resigned }}（共 {{ status.staff_total }}）</td></tr>
        <tr><td>绑定钉钉人员</td><td>{{ status.staff_bound }}</td></tr>
        <tr><td>组织节点 / 绑定钉钉部门</td><td>{{ status.org_bound }} / {{ status.org_total }}</td></tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
// 钉钉配置卡片（2026-09-30）：录入凭证 + 回调密钥 + 触发全量同步。
// 密钥仅掩码展示，留空提交 = 不修改（后端 saveConfig 跳过空值）。
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { dingtalkConfigToDraft, dingtalkDraftToPayload } from './dingtalkSettingsLogic'
import { dingtalkSyncNow } from '@/utils/dingtalk'

const fm = reactive(dingtalkConfigToDraft({}))
const status = ref(null)
const saving = ref(false)
const host = computed(() => window.location.host)

const STATUS_TEXT = { success: '成功', failed: '失败', never: '从未' }
const statusLabel = computed(() => STATUS_TEXT[status.value?.last_sync_status] || status.value?.last_sync_status || '从未')

async function load() {
  try {
    const d = await api('/api/dingtalk/config')
    Object.assign(fm, dingtalkConfigToDraft(d.config || {}))
    status.value = (await api('/api/dingtalk/status')).data
  } catch (e) { toast(e.message) }
}

async function save() {
  const payload = dingtalkDraftToPayload(fm)
  if (!Object.keys(payload).length) { toast('没有需要保存的修改'); return }
  saving.value = true
  try {
    await api('/api/dingtalk/config', { body: payload })
    toast('钉钉配置已保存')
    await load()
  } catch (e) { alert(e.message) } finally { saving.value = false }
}

function sync() {
  dingtalkSyncNow(load)
}

onMounted(load)
</script>
