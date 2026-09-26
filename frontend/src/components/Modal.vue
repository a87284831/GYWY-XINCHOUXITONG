<template>
  <Teleport to="body">
    <div class="maint-modal-mask" :class="{ show }">
      <div class="maint-modal-box" :style="width ? { width: width + 'px', maxWidth: '96vw' } : null">
        <div class="maint-modal-head"><span>{{ title }}</span><button class="maint-modal-x" @click="$emit('close')">×</button></div>
        <div class="maint-modal-body"><slot /></div>
        <div v-if="$slots.foot" class="maint-modal-foot"><slot name="foot" /></div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
// 通用弹窗外壳 — 复用 legacy.css .maint-modal-*（遮罩点击不关闭，仅 × / 取消关闭）
defineProps({ title: String, show: Boolean, width: { type: Number, default: 0 } })
defineEmits(['close'])
</script>
