<template>
  <div>
    <h4 style="margin:0 0 10px">密码策略</h4>
    <div class="form-grid" style="max-width:560px">
      <label>密码最小长度<input type="number" v-model="pwlen" min="4" max="32"></label>
    </div>
    <div class="row" style="margin:10px 0 18px"><button class="btn primary" @click="saveSecurity">保存密码策略</button></div>
    <h4 style="margin:0 0 10px">工资条自助查询</h4>
    <div v-if="psErr" class="msg err">{{ psErr }}</div>
    <div v-else-if="cfg">
      <div class="form-grid">
        <label><input type="checkbox" v-model="cfg.enabled"> 启用工资条自助查询</label>
        <label>查询月份<select v-model="cfg.query_month"><option value="prev">上月工资</option><option value="current">当月工资</option></select></label>
        <label>每月开放开始日<input type="number" min="1" max="31" v-model="cfg.open_day_start" style="width:80px"></label>
        <label>每月开放结束日<input type="number" min="1" max="31" v-model="cfg.open_day_end" style="width:80px"></label>
        <label class="full">系统标题<input type="text" v-model="cfg.title"></label>
      </div>
      <div class="hint" style="margin:8px 0">开放日期：开始日≤结束日当月开放；开始日>结束日跨月开放。员工查询地址：<a :href="slipUrl" target="_blank" style="color:#2563eb;text-decoration:underline"><code>{{ slipUrl }}</code></a>，凭姓名+身份证后六位查询。</div>
      <div class="row"><button class="btn primary" @click="savePayslip">保存工资条设置</button></div>
    </div>
    <div v-else class="hint">加载中...</div>
  </div>
</template>

<script setup>
// 登录安全 — 复刻 settingsSecurity/securitySave/payslipCfgSave（app.js:4056-4086, 4160-4174）
import { ref, reactive, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const pwlen = ref(6)
const cfg = ref(null)
const psErr = ref('')
const slipUrl = location.origin + '/payslip.html'

async function saveSecurity() {
  try {
    await api('/api/settings/save', { body: { settings: { security: { password_min_length: parseInt(pwlen.value) } } } })
    const d = await api('/api/settings')
    auth.settings = d.settings
    toast('密码策略已保存')
  } catch (e) { alert(e.message) }
}

async function savePayslip() {
  const c = cfg.value
  const config = {
    enabled: !!c.enabled,
    query_month: c.query_month,
    open_day_start: parseInt(c.open_day_start),
    open_day_end: parseInt(c.open_day_end),
    title: String(c.title || '').trim(),
  }
  try {
    await api('/api/payslip/config/save', { body: { config } })
    toast('工资条查询设置已保存')
  } catch (e) { alert(e.message) }
}

onMounted(async () => {
  const s = (auth.settings && auth.settings.security) || {}
  pwlen.value = s.password_min_length || 6
  try {
    const ps = await api('/api/payslip/config')
    const c = ps.config || ps || {}
    cfg.value = reactive({ enabled: !!c.enabled, query_month: c.query_month || 'prev', open_day_start: c.open_day_start ?? '', open_day_end: c.open_day_end ?? '', title: c.title || '' })
  } catch (e) { psErr.value = e.message }
})
</script>
