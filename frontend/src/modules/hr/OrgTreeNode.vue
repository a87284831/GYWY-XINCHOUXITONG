<template>
  <div>
    <div class="tree-row" :class="{ active: selId === node.id }" :style="{ paddingLeft: depth * 18 + 6 + 'px' }" @click="$emit('select', node.id)">
      <span class="tree-arrow" :class="{ open: hasKids }" :style="hasKids ? '' : 'visibility:hidden'">▶</span>
      <span class="tree-ico" v-html="ico"></span>
      <span class="tree-nm">{{ node.name }}<span v-if="!node.enabled" style="font-size:10px;color:#f53f3f"> 停用</span></span>
      <span class="tree-cnt">{{ fmtEmpty(node.count_in) }}</span>
    </div>
    <div v-if="hasKids">
      <OrgTreeNode
        v-for="c in node.children" :key="c.id" :node="c" :depth="depth + 1"
        :sel-id="selId" @select="$emit('select', $event)"
      />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { svgIco } from './icons'
import { ORG_ICON, ORG_COLOR } from './orgMeta'
import { fmtEmpty } from '@/utils/format'

const props = defineProps({ node: { type: Object, required: true }, depth: { type: Number, default: 0 }, selId: { type: Number, default: null } })
defineEmits(['select'])

const hasKids = computed(() => props.node.children && props.node.children.length)
const ico = computed(() => svgIco(ORG_ICON[props.node.type] || 'dot', 15, ORG_COLOR[props.node.type] || '#86909c', 2))
</script>
