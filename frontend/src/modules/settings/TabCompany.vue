<template>
  <div>
    <div class="form-grid" style="max-width:560px">
      <label>公司简称<input type="text" v-model="fm.name"></label>
      <label>版本号<input type="text" v-model="fm.ver"></label>
      <label class="full">系统全称<input type="text" v-model="fm.full"></label>
      <label class="full">副标题<input type="text" v-model="fm.sub"></label>
      <label class="full">版权文字<input type="text" v-model="fm.copy"></label>
    </div>
    <div class="hint">保存后刷新登录页和侧边栏标题生效。</div>
    <div class="row" style="margin-top:12px"><button class="btn primary" @click="save">保存</button></div>
  </div>
</template>

<script setup>
// 公司信息 — 复刻 settingsCompany/companySave（app.js:4034-4053）
import { reactive, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const fm = reactive({ name: '', ver: '', full: '', sub: '', copy: '' })

async function save() {
  try {
    await api('/api/settings/save', { body: { settings: { company: { name: fm.name, full_name: fm.full, subtitle: fm.sub, copyright: fm.copy, version: fm.ver } } } })
    const d = await api('/api/settings')
    auth.settings = d.settings
    toast('公司信息已保存')
  } catch (e) { alert(e.message) }
}

onMounted(() => {
  const c = (auth.settings && auth.settings.company) || {}
  fm.name = c.name || ''
  fm.ver = c.version || ''
  fm.full = c.full_name || ''
  fm.sub = c.subtitle || ''
  fm.copy = c.copyright || ''
})
</script>
