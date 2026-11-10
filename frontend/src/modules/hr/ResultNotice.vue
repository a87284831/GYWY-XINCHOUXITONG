<template>
  <div class="pn" :class="`pn--${notice.kind}`">
    <div class="pn-head">
      <span class="pn-ico"><Icon :name="notice.kind === 'ok' ? 'check-circle' : 'warning'" :size="15" /></span>
      <span class="pn-title">{{ notice.title }}</span>
      <span class="pn-pill">{{ pillText }}</span>
    </div>
    <div v-if="notice.context" class="pn-ctx">{{ notice.context }}</div>
    <div v-if="chips.length" class="pn-stats">
      <span v-for="(c, i) in chips" :key="'c' + i" class="pn-chip" :class="c.tone || ''">{{ c.text }}</span>
    </div>
    <div v-if="notice.danger.length || notice.info.length || notice.skipped.length" class="pn-body">
      <div v-if="notice.danger.length" class="pn-sect dg">
        <div class="pn-sect-h">
          <Icon name="warning" :size="13" />
          {{ notice.dangerTitle || '异常 · 处理完毕前不可归档' }}
          <span class="pn-how">共 {{ notice.danger.length }} 条</span>
        </div>
        <div v-if="notice.dangerHint" class="pn-sect-hint">{{ notice.dangerHint }}</div>
        <div v-for="(w, i) in shownDanger" :key="'d' + i" class="pn-row">
          <span class="pn-name">{{ w.name }}</span>
          <span v-if="itemTag(w)" class="pn-proj">{{ itemTag(w) }}</span>
          <span class="pn-why">{{ w.reason }}</span>
        </div>
        <button v-if="notice.danger.length > FOLD" class="pn-more" type="button" @click="expandedDanger = !expandedDanger">
          {{ expandedDanger ? '收起' : `展开全部 ${notice.danger.length} 条` }}
        </button>
      </div>
      <div v-if="notice.info.length" class="pn-sect if">
        <div class="pn-sect-h">
          <Icon name="info" :size="13" />
          {{ notice.infoTitle || '提示 · 不影响核算结果' }}
          <span class="pn-how">共 {{ notice.info.length }} 条</span>
        </div>
        <div v-if="notice.infoHint" class="pn-sect-hint">{{ notice.infoHint }}</div>
        <div v-for="(w, i) in shownInfo" :key="'i' + i" class="pn-row">
          <span class="pn-name">{{ w.name }}</span>
          <span v-if="itemTag(w)" class="pn-proj">{{ itemTag(w) }}</span>
          <span class="pn-why">{{ w.reason }}</span>
        </div>
        <button v-if="notice.info.length > FOLD" class="pn-more" type="button" @click="expanded = !expanded">
          {{ expanded ? '收起' : `展开全部 ${notice.info.length} 条` }}
        </button>
      </div>
      <div v-if="notice.skipped.length" class="pn-skip">
        跳过未出薪资行（基数缺失）：<b>{{ notice.skipped.join('、') }}</b>
      </div>
    </div>
    <div v-if="footText" class="pn-foot">{{ footText }}</div>
    <div v-if="$slots.actions" class="pn-actions"><slot name="actions" /></div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import Icon from '@/components/Icon.vue'

// 通用结果提示卡片：核算结果、考勤上传校验共用同一套信息层级
// notice: { title, kind:'ok'|'warn', pill?, context?, chips?, danger[], info[], skipped[],
//           dangerTitle?, dangerHint?, infoTitle?, infoHint?, footText? }
const props = defineProps({ notice: { type: Object, required: true } })

const FOLD = 3
const expanded = ref(false)
const expandedDanger = ref(false)
const shownInfo = computed(() => (expanded.value ? props.notice.info : props.notice.info.slice(0, FOLD)))
const shownDanger = computed(() => (expandedDanger.value ? props.notice.danger : props.notice.danger.slice(0, FOLD)))
const pillText = computed(() => props.notice.pill || (props.notice.kind === 'ok' ? '正常' : `${props.notice.danger.length} 项异常需处理`))
const chips = computed(() => {
  if (Array.isArray(props.notice.chips)) return props.notice.chips
  const out = [{ text: `核算 ${props.notice.count || 0} 人` }]
  if (props.notice.danger.length) out.push({ text: `异常 ${props.notice.danger.length}`, tone: 'dg' })
  if (props.notice.info.length) out.push({ text: `提示 ${props.notice.info.length}`, tone: 'if' })
  if (props.notice.skipped.length) out.push({ text: `跳过 ${props.notice.skipped.length}` })
  return out
})
const footText = computed(() => {
  if (props.notice.footText) return props.notice.footText
  if (props.notice.kind === 'warn') return '异常处理并重新核算后，异常区块将自动消失。'
  return props.notice.info.length
    ? '提示类条目不影响核算结果与归档，仅供核对。'
    : '全部核算成功，未发现异常，可进行归档锁定。归档后数据锁定，重算需先解锁。'
})
function itemTag(w) {
  return w.tag || w.project || ''
}
</script>
