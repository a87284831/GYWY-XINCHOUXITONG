<template>
  <div>
    <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
    <div v-else-if="!logs.length" class="msg info">暂无日志</div>
    <div v-else class="table-wrap"><table class="tb">
      <thead><tr><th>时间</th><th>操作人</th><th>操作</th><th>详情</th></tr></thead>
      <tbody>
        <tr v-for="(l, i) in logs" :key="i">
          <td>{{ l.ts }}</td><td>{{ l.user }}</td><td><span class="tag blue">{{ l.action }}</span></td><td>{{ l.detail }}</td>
        </tr>
      </tbody>
    </table></div>
  </div>
</template>

<script setup>
// 操作日志 — 复刻 settingsLogs（app.js:4147-4158）
import { ref, onMounted } from 'vue'
import { api } from '@/api/client'

const logs = ref([])
const loadErr = ref('')

onMounted(async () => {
  loadErr.value = ''
  try {
    const data = await api('/api/op_logs')
    logs.value = data.logs || []
  } catch (e) { loadErr.value = e.message }
})
</script>
