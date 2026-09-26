<template>
  <div class="rpt-kpi" :style="{ borderTop: '3px solid ' + color }">
    <div class="rpt-kpi-lbl">{{ label }}</div>
    <div class="rpt-kpi-num" :style="{ color }">{{ value }}</div>
    <div v-if="info" class="rpt-delta" :style="{ color: info.color, fontSize: '12px', marginTop: '2px' }">较上期 {{ info.arrow }} {{ info.value }}{{ suffix || '%' }}</div>
    <div v-if="sub" class="rpt-kpi-sub">{{ sub }}</div>
  </div>
</template>

<script setup>
// KPI 卡片 — 复刻 rCard（app.js:2244-2250）+ rDelta（2236-2242）
import { computed } from 'vue'
import { deltaInfo } from './reportLogic'

const props = defineProps({
  label: String,
  value: [String, Number],
  delta: { type: Number, default: null },
  suffix: { type: String, default: '' },
  sub: { type: String, default: '' },
  color: { type: String, default: '#2563eb' },
})
const info = computed(() => deltaInfo(props.delta))
</script>
