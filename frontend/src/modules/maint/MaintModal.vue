<template>
  <Teleport to="body">
    <div class="maint-modal-mask" :class="{ show }">
      <div class="maint-modal-box">
        <div class="maint-modal-head"><span>{{ title }}</span><button class="maint-modal-x" @click="$emit('close')">×</button></div>
        <div class="maint-modal-body"><slot /></div>
        <div class="maint-modal-foot"><button class="btn" @click="$emit('close')">取消</button><button class="btn primary" @click="$emit('save')">保存</button></div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
// 维保独立弹窗外壳 — 复刻 maintModalOpen/maintModalClose（app.js:2530-2553）
// 交互保真：点击遮罩空白处不关闭，仅 × 或「取消」关闭
defineProps({ title: String, show: Boolean })
defineEmits(['close', 'save'])
</script>
