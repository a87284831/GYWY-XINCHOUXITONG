<template>
  <Modal :show="!!inst" :width="900" title="审批详情" @close="$emit('close')">
    <template v-if="inst">
      <div style="min-width:720px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <b style="font-size:16px;color:#111827">{{ inst.title }}</b>
          <span class="tag" :style="'color:' + st[1] + ';border-color:' + st[1]">{{ st[0] }}</span>
        </div>
        <div style="font-size:12px;color:#9ca3af;margin-bottom:4px">流程号：<b style="color:#2563eb">{{ inst.flow_no || '-' }}</b> · 流程：{{ inst.flow_name }} · {{ inst.project || '' }}</div>
        <div style="font-size:12px;color:#9ca3af;margin-bottom:12px">🧑 发起人：{{ inst.applicant }} · 发起时间：{{ fmtTime(inst.time) }}</div>

        <div style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:10px 18px;margin-bottom:14px">
          <div class="appsteps">
            <div v-for="(sp, i) in steps" :key="i" class="appstep" :class="{ done: sp.done, cur: sp.isCur }">
              <div class="dot">{{ sp.done ? '✓' : (sp.isCur ? '●' : sp.num) }}</div>
              <div :style="'font-size:12px;margin-top:7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:' + (sp.done ? '#15803d' : (sp.isCur ? '#b45309' : '#6b7280')) + ';font-weight:' + (sp.isCur ? '600' : '500')">{{ sp.name }}</div>
              <div style="font-size:10.5px;color:#9ca3af;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ sp.who }}</div>
            </div>
          </div>
        </div>

        <div v-for="g in groups" :key="g.key" style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px;margin-bottom:12px">
          <div style="display:flex;align-items:center;font-size:14px;font-weight:600;color:#111827;margin-bottom:12px">
            <span :style="'width:4px;height:15px;background:' + g.bar + ';border-radius:2px;margin-right:8px'"></span>{{ g.icon }} {{ g.title }}</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px 20px;background:#fafbfc;border:1px solid #eef1f5;border-radius:10px;padding:14px 18px;margin-bottom:12px">
            <div v-for="fld in g.flds" :key="fld.key" style="display:flex;min-width:0">
              <div style="width:86px;color:#8a93a3;font-size:12.5px;padding-top:2px;flex-shrink:0">{{ fld.label || fld.key }}</div>
              <div style="font-size:13.5px;color:#111827;font-weight:500;word-break:break-all">
                <span v-if="fld.type === 'perf'" :style="perfV > 0 ? 'color:#dc2626;font-weight:700' : ''">{{ perfDisplay }}</span>
                <template v-else-if="fld.type === 'attachment' && fd[fld.key]"><a :href="fd[fld.key]" target="_blank" style="color:#2563eb">查看附件 →</a></template>
                <template v-else>{{ Array.isArray(fd[fld.key]) ? fd[fld.key].join('、') : (fd[fld.key] === '' || fd[fld.key] == null ? '-' : fd[fld.key]) }}</template>
              </div>
            </div>
          </div>
        </div>

        <div style="display:flex;gap:18px;margin-bottom:14px;flex-wrap:wrap">
          <div style="flex:1;min-width:300px;background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px">
            <div style="display:flex;align-items:center;font-size:14px;font-weight:600;color:#111827;margin-bottom:12px"><span style="width:4px;height:15px;background:#6366f1;border-radius:2px;margin-right:8px"></span>📎 材料清单</div>
            <div v-if="!matFlds.length" style="color:#9ca3af;font-size:12.5px;padding:8px 4px">本流程无需上传材料</div>
            <div v-for="fld in matFlds" :key="fld.key" style="display:flex;justify-content:space-between;align-items:center;padding:11px 4px;border-bottom:1px solid #f0f2f6">
              <div style="min-width:0;padding-right:8px">
                <div style="font-size:13.5px;font-weight:500;color:#1f2937">{{ fld.label || fld.key }}</div>
                <div style="font-size:11.5px;color:#9ca3af;margin-top:2px">{{ fld.hint || '' }}</div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:10px">
                <template v-if="fd[fld.key]">
                  <span style="font-size:11.5px;padding:2px 10px;border-radius:14px;background:#e7f7ef;color:#15803d;white-space:nowrap">已上传</span>
                  <a :href="fd[fld.key]" target="_blank" style="font-size:11.5px;color:#2563eb;white-space:nowrap">{{ String(fd[fld.key]).split('/').pop() || '查看附件' }} →</a>
                </template>
                <span v-else style="font-size:11.5px;padding:2px 10px;border-radius:14px;background:#fff7ed;color:#c2410c;white-space:nowrap">未上传</span>
              </div>
            </div>
          </div>
          <div style="flex:1;min-width:300px;background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px">
            <div style="display:flex;align-items:center;font-size:14px;font-weight:600;color:#111827;margin-bottom:12px"><span style="width:4px;height:15px;background:#6366f1;border-radius:2px;margin-right:8px"></span>📝 审批记录</div>
            <div v-if="!records.length" style="color:#9ca3af;font-size:12.5px">暂无审批记录</div>
            <div v-for="(it, i) in records" :key="i" style="position:relative;padding:0 0 16px 20px;margin-left:5px;border-left:2px solid #e5e9f0">
              <div :style="'position:absolute;left:-5px;top:4px;width:12px;height:12px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 2px ' + (it.state === 'approved' ? '#b5e6cc' : '#c7d2fe') + ';background:' + (it.state === 'approved' ? '#16a34a' : '#6366f1')"></div>
              <div style="font-size:11px;color:#9ca3af;margin-bottom:2px">{{ it.node }} · {{ it.name }}</div>
              <div style="font-size:12.5px"><b style="color:#1f2937">{{ it.name }}</b> <span :style="'color:' + (it.state === 'approved' ? '#15803d' : '#2563eb') + ';font-weight:600'">{{ it.state === 'approved' ? '同意' : '待审批' }}</span><span v-if="opTime(it)" style="color:#9ca3af;font-size:11px"> · {{ opTime(it) }}</span></div>
              <div v-if="it.op && it.op.opinion" style="color:#6b7280;font-size:12px;margin-top:3px;background:#fff;padding:5px 9px;border-radius:6px;border:1px solid #eef1f5;display:inline-block">{{ it.op.opinion }}</div>
            </div>
          </div>
        </div>

        <!-- 审批操作（当前节点审批人） -->
        <div v-if="isCurApprover" style="margin-top:4px;border-top:1px solid #eef0f3;padding-top:12px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap">
          <div style="flex:1;min-width:220px">
            <div style="font-size:13px;font-weight:600;margin-bottom:6px">您的审批意见</div>
            <textarea v-model="opinion" rows="2" placeholder="填写审批意见（驳回时必填）" style="width:100%"></textarea>
          </div>
          <div class="row" style="margin:0">
            <button class="btn success" @click="act('approve')">✓ 通过</button>
            <button class="btn" style="background:#fff;color:#dc2626;border-color:#f3c1c2" @click="act('reject')">✕ 驳回</button>
          </div>
        </div>
        <!-- 发起人操作 -->
        <div v-if="isApplicant && (inst.status === 'pending' || inst.status === 'rejected')" class="row" style="margin-top:14px;border-top:1px solid #eef0f3;padding-top:12px">
          <button v-if="inst.status === 'pending' && canWithdraw" class="btn warn" @click="voidOr('withdraw')">↩ 撤回</button>
          <button v-if="inst.status === 'rejected'" class="btn primary" @click="$emit('reopen', inst)">✎ 修改并重新提交</button>
          <button class="btn" @click="voidOr('void')">🗑 作废</button>
        </div>
        <!-- 发送给人员查看 -->
        <div v-if="inst.status === 'approved' || inst.status === 'rejected'" class="row" style="margin-top:14px;border-top:1px solid #eef0f3;padding-top:12px">
          <button class="btn" style="background:#fff;color:#7c3aed;border-color:#c4b5fd" @click="$emit('send', inst.id)">📤 发送给人员查看</button>
          <span style="font-size:12px;color:#9ca3af">可将此已完结流程发送给人员（自动匹配账号），对方在我的待办-收件中查看</span>
        </div>
      </div>
    </template>
  </Modal>
</template>

<script setup>
// 审批详情弹窗 — 复刻 renderAppDetailModal + appAct/appVoidOr（app.js:6359-6480）
import { computed, ref, watch } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import Modal from '@/components/Modal.vue'
import { DETAIL_STATUS, stepList, approvalRecords, perfText } from './approvalLogic'

const props = defineProps({ instanceId: { type: Number, default: 0 }, readonly: Boolean })
const emit = defineEmits(['close', 'changed', 'reopen', 'send'])

const inst = ref(null)
const me = ref({})
const opinion = ref('')

const st = computed(() => (inst.value ? (DETAIL_STATUS[inst.value.status] || ['未知', '#6b7280']) : ['', '']))
const steps = computed(() => (inst.value ? stepList(inst.value.node_chain, inst.value.current_index, inst.value.status) : []))
const records = computed(() => (inst.value ? approvalRecords(inst.value.node_chain, inst.value.opinions) : []))
const fd = computed(() => (inst.value ? (inst.value.form_data || {}) : {}))
const schema = computed(() => (inst.value ? (inst.value.form_schema || []) : []))
const byGroup = (g) => schema.value.filter((f) => f.group === g)
const groups = computed(() => [
  { key: 'person', bar: '#2563eb', icon: '👤', title: '人员信息', flds: byGroup('person') },
  { key: 'job', bar: '#2563eb', icon: '📋', title: '入职信息', flds: byGroup('job') },
  { key: 'salary', bar: '#2563eb', icon: '💰', title: '薪酬信息', flds: byGroup('salary') },
].filter((g) => g.flds.length))
const matFlds = computed(() => byGroup('material'))
const perfV = computed(() => (parseFloat(fd.value.fixed_monthly) || 0) - (parseFloat(fd.value.base_salary) || 0))
const perfDisplay = computed(() => perfText(fd.value.fixed_monthly, fd.value.base_salary))
const isCurApprover = computed(() => {
  if (!inst.value || props.readonly) return false
  const cur = (inst.value.node_chain || [])[inst.value.current_index] || {}
  return inst.value.status === 'pending' && (cur.approvers || []).some((a) => a.accountId === me.value.id && a.state !== 'approved')
})
const isApplicant = computed(() => !props.readonly && inst.value && inst.value.applicant_id === me.value.id)
const canWithdraw = computed(() => {
  if (!inst.value) return false
  const cur = (inst.value.node_chain || [])[inst.value.current_index] || {}
  return !(cur.approvers || []).some((a) => a.state === 'approved')
})

function fmtTime(t) { return t ? String(t).replace('T', ' ') : '' }
function opTime(it) { return it.op && it.op.time ? String(it.op.time).replace('T', ' ').slice(0, 16) : '' }

async function load() {
  if (!props.instanceId) { inst.value = null; return }
  inst.value = null
  try {
    const d = await api('/api/approval/' + props.instanceId)
    inst.value = d.instance
    me.value = d.me || {}
  } catch (e) { toast(e.message, false); emit('close') }
}
watch(() => props.instanceId, load, { immediate: true })

async function act(a) {
  try {
    await api('/api/approval/' + a, { body: { id: inst.value.id, opinion: opinion.value.trim() } })
    toast(a === 'approve' ? '已同意' : '已驳回')
    emit('changed')
    emit('close')
  } catch (e) { toast(e.message, false) }
}
async function voidOr(a) {
  try {
    await api('/api/approval/' + a, { body: { id: inst.value.id } })
    toast(a === 'withdraw' ? '已撤回' : '已作废')
    emit('changed')
    emit('close')
  } catch (e) { toast(e.message, false) }
}
</script>
