<template>
  <div class="perm-block">
    <h4 style="margin:0 0 6px">人员档案字段设置</h4>
    <div class="msg info" style="margin-bottom:12px">勾选以下字段为「必填项」。在新增/编辑人员、以及批量导入时都会按此校验：必填为空、身份证/手机/日期/枚举格式错误、上级不存在 → 保存失败或整批拒绝导入。<b>姓名、所属项目</b>始终必填，不可取消。</div>
    <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
    <div v-else-if="requirable" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px">
      <label v-for="k in Object.keys(requirable)" :key="k" class="rt-func" style="padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;background:#fff">
        <input type="checkbox" :checked="required.has(k) || k === 'name' || k === 'project'" :disabled="k === 'name' || k === 'project'" @change="toggle(k)">
        {{ requirable[k] }}{{ k === 'name' || k === 'project' ? '（固定）' : '' }}</label>
    </div>
    <div v-else class="hint">加载中...</div>
    <div class="row end" style="margin-top:14px"><button class="btn primary" @click="save">保存必填项设置</button></div>
  </div>
</template>

<script setup>
// 人员档案字段设置 — 复刻 settingsStaffField/saveStaffFieldRequired（app.js:3750-3776）
import { ref, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'

const required = ref(new Set())
const requirable = ref(null)
const loadErr = ref('')

function toggle(k) {
  const s = new Set(required.value)
  s.has(k) ? s.delete(k) : s.add(k)
  required.value = s
}

async function save() {
  try {
    await api('/api/staff/field_config', { body: { required: [...required.value] } })
    toast('必填项设置已保存'); toast('已生效于新增/编辑与批量导入校验', false)
  } catch (e) { alert(e.message) }
}

onMounted(async () => {
  loadErr.value = ''
  try {
    const d = await api('/api/staff/field_config')
    required.value = new Set(d.required || [])
    requirable.value = d.requirable || {}
  } catch (e) { loadErr.value = e.message }
})
</script>
