<template>
  <div class="modal-mask" @mousedown.self="$emit('close')">
    <div class="modal" style="width:560px">
      <h3><Icon name="clipboard" /> 系统更新啦（{{ entries.length }} 个版本）</h3>
      <div class="cl-list">
        <div v-for="e in entries" :key="e.version" class="cl-entry">
          <div class="cl-head">
            <span class="cl-ver">{{ e.version }}</span>
            <span class="cl-date">{{ e.date }}</span>
          </div>
          <div v-for="(it, i) in e.items" :key="i" class="cl-item">
            <span class="tag" :class="catCls(it.category)">{{ it.category }}</span>
            <span>{{ it.text }}</span>
          </div>
        </div>
      </div>
      <div class="row end" style="margin-top:16px">
        <button class="btn primary" @click="$emit('close')">知道了</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import Icon from '@/components/Icon.vue'

defineProps({ entries: { type: Array, default: () => [] } })
defineEmits(['close'])

const CAT_CLS = { 新增: 'green', 修复: 'red', 优化: 'blue' }
const catCls = (c) => CAT_CLS[c] || 'gray'
</script>

<style scoped>
.cl-list { max-height: 55vh; overflow: auto; }
.cl-entry { border: 1px solid #eef0f3; border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; }
.cl-head { margin-bottom: 6px; }
.cl-ver { font-weight: 700; color: #1e3a8a; font-size: 13.5px; }
.cl-date { color: #9ca3af; font-size: 12px; margin-left: 8px; }
.cl-item { display: flex; gap: 8px; align-items: baseline; font-size: 13px; color: #374151; padding: 3px 0; }
.cl-item .tag { flex-shrink: 0; }
</style>
