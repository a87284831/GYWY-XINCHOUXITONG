<template>
  <div class="pn" :class="`pn--${notice.kind}`">
    <div class="pn-head">
      <span class="pn-ico">
        <svg v-if="notice.kind === 'ok'" width="14" height="14" viewBox="0 0 16 16" fill="none">
          <path d="M3 8.5 6.5 12 13 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <svg v-else width="14" height="14" viewBox="0 0 16 16" fill="none">
          <path d="M8 2 14.5 13.5H1.5L8 2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
          <path d="M8 6.5V9.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
          <circle cx="8" cy="11.6" r=".9" fill="currentColor" />
        </svg>
      </span>
      <span class="pn-title">{{ notice.title }}</span>
      <span class="pn-pill">{{ notice.kind === 'ok' ? '正常' : `${notice.danger.length} 项异常需处理` }}</span>
    </div>
    <div class="pn-stats">
      <span class="pn-chip">核算 <b>{{ notice.count }}</b> 人</span>
      <span v-if="notice.danger.length" class="pn-chip dg">异常 <b>{{ notice.danger.length }}</b></span>
      <span v-if="notice.info.length" class="pn-chip if">提示 <b>{{ notice.info.length }}</b></span>
      <span v-if="notice.skipped.length" class="pn-chip">跳过 <b>{{ notice.skipped.length }}</b></span>
    </div>
    <div v-if="notice.danger.length || notice.info.length || notice.skipped.length" class="pn-body">
      <div v-if="notice.danger.length" class="pn-sect dg">
        <div class="pn-sect-h">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
            <path d="M8 2 14.5 13.5H1.5L8 2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
            <path d="M8 6.5V9.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            <circle cx="8" cy="11.6" r=".9" fill="currentColor" />
          </svg>
          异常 · 处理完毕前不可归档
        </div>
        <div v-for="(w, i) in notice.danger" :key="'d' + i" class="pn-row">
          <span class="pn-name">{{ w.name }}</span>
          <span v-if="w.project" class="pn-proj">{{ w.project }}</span>
          <span class="pn-why">{{ w.reason }}</span>
        </div>
      </div>
      <div v-if="notice.info.length" class="pn-sect if">
        <div class="pn-sect-h">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
            <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.6" />
            <path d="M8 7.5V11.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            <circle cx="8" cy="5" r=".9" fill="currentColor" />
          </svg>
          提示 · 不影响核算结果
          <span class="pn-how">共 {{ notice.info.length }} 条</span>
        </div>
        <div v-for="(w, i) in shownInfo" :key="'i' + i" class="pn-row">
          <span class="pn-name">{{ w.name }}</span>
          <span v-if="w.project" class="pn-proj">{{ w.project }}</span>
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
    <div class="pn-foot">{{ footText }}</div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({ notice: { type: Object, required: true } })

const FOLD = 3
const expanded = ref(false)
const shownInfo = computed(() => (expanded.value ? props.notice.info : props.notice.info.slice(0, FOLD)))
const footText = computed(() => {
  if (props.notice.kind === 'warn') return '异常处理并重新核算后，异常区块将自动消失。'
  return props.notice.info.length
    ? '提示类条目不影响核算结果与归档，仅供核对。'
    : '全部核算成功，未发现异常，可进行归档锁定。归档后数据锁定，重算需先解锁。'
})
</script>
