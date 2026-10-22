<template>
  <div>
    <div class="card">
      <h3>系统更新日志</h3>
      <div v-if="loading" style="color:#9ca3af; font-size:13px;">加载中…</div>
      <div v-else-if="error" style="color:#dc2626; font-size:13px;">{{ error }}</div>
      <template v-else>
        <div v-for="e in entries" :key="e.version" class="cl-entry">
          <div class="cl-head">
            <span class="cl-ver">{{ e.version }}</span>
            <span class="tag gray" v-if="e.version === latest">当前版本</span>
            <span class="cl-date">{{ e.date }}<template v-if="e.commit"> · {{ e.commit }}</template></span>
          </div>
          <div v-for="(it, i) in e.items" :key="i" class="cl-item">
            <span class="tag" :class="catCls(it.category)">{{ it.category }}</span>
            <span>{{ it.text }}</span>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/api/client'
import { latestVersion, markRead } from '@/modules/home/changelogLogic'

const entries = ref([])
const latest = ref('')
const loading = ref(true)
const error = ref('')

const CAT_CLS = { 新增: 'green', 修复: 'red', 优化: 'blue' }
const catCls = (c) => CAT_CLS[c] || 'gray'

onMounted(async () => {
  try {
    const d = await api('/api/changelog?limit=200')
    entries.value = d.items || []
    latest.value = latestVersion(entries.value)
    // 主动看过日志页 = 已读到最新（与弹窗的「知道了」同效）
    markRead(latest.value)
  } catch (e) {
    error.value = e.message || '加载失败'
  }
  loading.value = false
})
</script>

<style scoped>
.cl-entry { border: 1px solid #eef0f3; border-radius: 8px; padding: 12px 14px; margin-bottom: 12px; }
.cl-head { margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
.cl-ver { font-weight: 700; color: #1e3a8a; font-size: 14px; }
.cl-date { color: #9ca3af; font-size: 12px; }
.cl-item { display: flex; gap: 8px; align-items: baseline; font-size: 13px; color: #374151; padding: 3px 0; }
.cl-item .tag { flex-shrink: 0; }
</style>
