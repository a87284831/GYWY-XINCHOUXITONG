<template>
  <div class="org-chart-node" :class="node.type" @click="$emit('select', node.id)">
    <div class="org-chart-node-ico" v-html="ico"></div>
    <div class="org-chart-node-nm">{{ node.name }}</div>
    <div class="org-chart-node-cnt">在职 {{ fmtEmpty(node.count_in) }} 人</div>
  </div>
  <ul v-if="node.children && node.children.length">
    <li v-for="c in node.children" :key="c.id">
      <OrgChartNode :node="c" @select="$emit('select', $event)" />
    </li>
  </ul>
</template>

<script setup>
import { computed } from 'vue'
import { svgIco } from './icons'
import { ORG_ICON, ORG_COLOR } from './orgMeta'
import { fmtEmpty } from '@/utils/format'

const props = defineProps({ node: { type: Object, required: true } })
defineEmits(['select'])

const ico = computed(() => svgIco(ORG_ICON[props.node.type] || 'dot', 24, ORG_COLOR[props.node.type] || '#86909c', 2))
</script>
