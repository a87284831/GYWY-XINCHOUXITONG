<template>
  <Modal :show="show" :width="720" :title="'📤 发送流程（审批单 #' + id + '）'" @close="$emit('close')">
    <div style="font-size:12.5px;color:#6b7280;margin-bottom:12px">从人员档案中选择接收人，自动匹配其系统账号；接收人在「我的待办-收件」中查看（只读）</div>
    <div style="max-height:380px;overflow:auto;border:1px solid #eef0f3;border-radius:10px">
      <template v-if="groups.length">
        <template v-for="g in groups" :key="g.project">
          <div style="background:#f8fafc;padding:7px 14px;font-size:12px;color:#6b7280;font-weight:600;border-bottom:1px solid #eef0f3">{{ g.project }}</div>
          <label v-for="s in g.items" :key="s.id" style="display:flex;align-items:center;gap:8px;padding:8px 14px;font-size:13px;border-bottom:1px solid #f5f7fa;cursor:pointer">
            <input v-model="sel" type="radio" name="send_staff" :value="s.id">
            <span style="font-weight:500">{{ s.name }}</span>
            <span style="color:#9ca3af;font-size:12px">{{ s.position || '-' }}</span>
            <span style="margin-left:auto;color:#2563eb;font-size:12px">账号：{{ s.account }}</span>
          </label>
        </template>
      </template>
      <div v-else style="padding:20px;color:#9ca3af;text-align:center">暂无已开通账号的人员</div>
    </div>
    <template #foot>
      <button class="btn primary" @click="doSend">确认发送</button>
      <button class="btn" @click="$emit('close')">取消</button>
    </template>
  </Modal>
</template>

<script setup>
// 发送流程弹窗 — 复刻 appSendPanel/appDoSend（app.js:5881-5917）
import { computed, ref, watch } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import Modal from '@/components/Modal.vue'
import { sendGroups } from './approvalLogic'

const props = defineProps({ show: Boolean, id: { type: Number, default: 0 } })
const emit = defineEmits(['close'])

let _targets = null // 模块级缓存（复刻 _sendTargets，app.js:5880）
const sel = ref('')
const groups = computed(() => sendGroups(_targets || []))

watch(() => props.show, async (v) => {
  if (!v) return
  sel.value = ''
  if (_targets === null) {
    try { const t = await api('/api/approval/send_targets'); _targets = t.items || [] } catch (e) { _targets = [] }
  }
})
async function doSend() {
  if (!sel.value) { toast('请选择接收人', false); return }
  try {
    await api('/api/approval/send', { body: { id: props.id, staff_id: parseInt(sel.value, 10) } })
    toast('已发送')
    emit('close')
  } catch (e) { toast(e.message, false) }
}
</script>
