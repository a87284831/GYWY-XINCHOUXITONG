<script setup>
// ECharts 封装：props.option 变化即重渲染（notMerge），实例生命周期与 resize 自管理
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import * as echarts from 'echarts'

const props = defineProps({
  option: { type: Object, default: null },
  height: { type: [Number, String], default: 300 },
})

const el = ref(null)
let inst = null

function render() {
  if (!el.value || !props.option) return
  if (!inst) {
    inst = echarts.init(el.value)
    inst.setOption(props.option)
  } else {
    inst.setOption(props.option, true)
  }
}
function resize() {
  if (inst) inst.resize()
}

onMounted(() => {
  render()
  window.addEventListener('resize', resize)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', resize)
  if (inst) {
    inst.dispose()
    inst = null
  }
})
watch(() => props.option, render, { deep: true })
</script>

<template>
  <div ref="el" :style="{ width: '100%', height: typeof height === 'number' ? height + 'px' : height }"></div>
</template>
