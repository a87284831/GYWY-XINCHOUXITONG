<template>
  <div class="card rpt-card" style="margin-top:16px">
    <div class="rpt-card-head">{{ title }}</div>
    <slot />
  </div>
</template>

<script setup>
// 报表区块卡 — 复刻 rSection（app.js:2251-2253）
defineProps({ title: String })
</script>
