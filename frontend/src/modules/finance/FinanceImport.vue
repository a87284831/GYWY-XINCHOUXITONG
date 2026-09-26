<script setup>
// 历史数据导入（复刻旧财务页 pageImport：模板下载 → 解析预览 → 确认导入）
import { ref } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import './finance.css'

const imFile = ref(null)
const imMsg = ref('')
const result = ref(null) // { mode: 'preview'|'done', text, preview }

async function parse() {
  const f = imFile.value?.files?.[0]
  if (!f) { toast('请选择 xlsx 文件', false); return }
  imMsg.value = '上传解析中…'
  const fd = new FormData()
  fd.append('file', f)
  try {
    const d = await api('/api/finance/import/parse', { form: fd })
    imMsg.value = '解析完成，请确认预览后执行导入'
    result.value = { mode: 'preview', text: JSON.stringify(d.preview || d, null, 2), preview: d.preview }
  } catch (e) {
    imMsg.value = ''
    toast(e.message, false)
  }
}

async function run() {
  if (!confirm('确认导入？将覆盖该范围存量数据！')) return
  try {
    const r = await api('/api/finance/import/run', { body: { preview: result.value.preview } })
    result.value = { mode: 'done', text: JSON.stringify(r, null, 2), preview: null }
    toast('导入完成')
  } catch (e) {
    toast(e.message, false)
  }
}
</script>

<template>
  <div class="fcard" style="max-width:760px;">
    <div class="fc-t">导入说明</div>
    <div style="font-size:13px;color:#444;line-height:1.8;margin-top:8px;">
      ① 历史数据（2020—2025 应收 551 条 / 2026 付款 47 条）已通过服务器导入完成，金额单位：元。<br />
      ② 如需按模板重新导入：先下载模板填写（第一行表头，第二行起为数据），小文件（≤20MB）可直接上传；超大原表（>20MB，含签批图片）请走服务器通道（CLI 导入）。<br />
      ③ 重新导入会覆盖该范围内的存量数据，请谨慎操作。
    </div>
    <div style="margin-top:14px;"><a class="fbtn ghost" style="text-decoration:none;display:inline-block;" href="/finance/template/财务管理导入模板.xlsx" download>📥 下载导入模板</a></div>
    <div style="margin-top:14px;">
      <input ref="imFile" type="file" accept=".xlsx" />
      <button class="fbtn" @click="parse">上传并导入</button>
      <span style="margin-left:8px;font-size:12px;color:#666;">{{ imMsg }}</span>
    </div>
    <div v-if="result" style="margin-top:12px;">
      <pre :style="result.mode === 'preview'
        ? 'font-size:12px;max-height:300px;overflow:auto;background:#f8f9fa;padding:10px;border-radius:8px;white-space:pre-wrap;'
        : 'font-size:12px;background:#f0f9f4;padding:10px;border-radius:8px;white-space:pre-wrap;'">{{ result.text }}</pre>
      <button v-if="result.mode === 'preview'" class="fbtn" style="margin-top:8px;" @click="run">确认导入</button>
    </div>
  </div>
</template>
