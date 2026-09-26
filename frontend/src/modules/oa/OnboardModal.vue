<template>
  <Modal :show="show" :width="880" title="入职办理清单" @close="$emit('close')">
    <template v-if="o">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
        <span class="tag blue">{{ o.staff_name }}</span>
        <span style="font-size:12.5px;color:#6b7280">{{ o.project }} · 创建于 {{ fmtTime(o.time) }}</span>
      </div>

      <div class="app-sec-title">👤 员工信息（自动引用录用审批，如需修改请走人员档案编辑）</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        <div v-for="k in infoKeys" :key="k" style="flex:1 1 200px;min-width:180px;padding:8px 12px;background:#f8fafc;border-radius:8px">
          <div style="font-size:11.5px;color:#6b7280">{{ labelOf(k) }}</div>
          <div style="font-size:13.5px;font-weight:600;color:#1f2937;margin-top:2px">{{ String(fd[k]) }}</div>
        </div>
      </div>

      <div class="app-sec-title" style="margin-top:14px">📋 办理清单（全部勾选后才可完成入职）</div>
      <label v-for="(it, i) in items" :key="i" style="display:flex;align-items:center;gap:8px;padding:7px 0;font-size:13px;border-bottom:1px dashed #eef0f3">
        <input v-model="it.done" type="checkbox"> {{ it.label }}
      </label>

      <div class="app-sec-title" style="margin-top:14px">🪪 证件核验（录用时已上传材料自动带入，此处仅核验原件，无需重复上传）</div>
      <div v-if="!attFlds.length" style="font-size:12.5px;color:#6b7280">该流程无附件材料</div>
      <div v-for="f in attFlds" :key="f.key" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 0;border-bottom:1px dashed #eef0f3;font-size:13px">
        <span style="font-weight:600">{{ f.label || f.key }}</span>
        <template v-if="fd[f.key]">
          <span class="tag" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0">已上传</span>
          <span style="font-size:12px;color:#6b7280">{{ String(fd[f.key]) }}</span>
          <label style="margin-left:auto;font-size:12.5px;color:#374151"><input v-model="verify[f.key]" type="checkbox"> 已核验原件</label>
        </template>
        <span v-else class="tag" style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa">未上传</span>
      </div>

      <div class="app-sec-title" style="margin-top:14px">📝 补录资料（建档到人事档案，与工资条/查询联动）</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <label style="font-size:12.5px">部门<select v-model="extra.department" style="width:150px"><option value="">请选择部门</option><option v-for="v in deptOpts" :key="v" :value="v">{{ v }}</option></select></label>
        <label style="font-size:12.5px">证件号码<input v-model="extra.id_card" type="text" style="width:180px"></label>
        <label style="font-size:12.5px">银行卡号<input v-model="extra.bank_card" type="text" style="width:180px"></label>
        <label style="font-size:12.5px">试用期至<input v-model="extra.regular_date" type="date"></label>
        <label style="font-size:12.5px">劳动合同开始日期<input v-model="extra.contract_start" type="date"></label>
        <label style="font-size:12.5px">劳动合同结束日期<input v-model="extra.contract_end" type="date"></label>
      </div>
      <div style="margin-top:12px;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12.5px;color:#1d4ed8">
        💡 开通系统账号将默认使用「普通员工」权限，初始密码 123456，由管理员在账号管理中后续调整。
      </div>
      <div class="row" style="margin-top:16px;border-top:1px solid #eef0f3;padding-top:12px">
        <button class="btn" @click="save(false)">保存办理进度</button>
        <label style="font-size:12.5px;color:#374151"><input v-model="openAccount" type="checkbox"> 开通系统账号（初始密码123456）</label>
        <button class="btn success" @click="save(true)">✓ 完成入职（建档）</button>
      </div>
    </template>
  </Modal>
</template>

<script setup>
// 入职办理弹窗 — 复刻 appOnboardModal/appOnboardSave（app.js:6557-6649）
import { reactive, ref, watch } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import Modal from '@/components/Modal.vue'
import { deptNameList } from './approvalLogic'
import { loadOrgTree, orgLeaves, orgProjectOf } from '@/modules/hr/orgStore'

const props = defineProps({ show: Boolean, id: { type: Number, default: 0 } })
const emit = defineEmits(['close', 'saved'])

const o = ref(null)
const fd = ref({})
const sch = ref([])
const items = ref([])
const verify = reactive({})
const extra = reactive({ department: '', id_card: '', bank_card: '', regular_date: '', contract_start: '', contract_end: '' })
const openAccount = ref(false)
const deptOpts = ref([])

const INFO_KEYS = ['name', 'project', 'position', 'department', 'gender', 'level', 'phone', 'id_card', 'education', 'ethnicity', 'emergency_contact', 'emergency_phone', 'recruit_channel', 'hire_date', 'regular_date', 'fixed_monthly', 'base_salary']
const infoKeys = ref([])
const attFlds = ref([])

function fmtTime(t) { return t ? String(t).replace('T', ' ') : '' }
function labelOf(k) { const f = sch.value.find((x) => x.key === k); return f ? f.label : k }

watch(() => [props.show, props.id], async ([v]) => {
  if (!v || !props.id) { o.value = null; return }
  o.value = null
  try {
    if (!orgLeaves().length) { try { await loadOrgTree() } catch (e) {} }
    const d = await api('/api/approval/onboard/' + props.id)
    o.value = d.item
    fd.value = d.item.form_data || {}
    sch.value = d.item.form_schema || []
    items.value = (d.item.items || []).map((it) => ({ ...it }))
    infoKeys.value = INFO_KEYS.filter((k) => fd.value[k] !== '' && fd.value[k] != null)
    const curDept = (d.item.extra && d.item.extra.department) || fd.value.department || ''
    let opts = deptNameList(o.value.project, orgLeaves(), (n) => { const p = orgProjectOf(n.id); return p ? p.name : null })
    if (curDept && !opts.includes(curDept)) opts = [curDept, ...opts]
    deptOpts.value = opts
    attFlds.value = sch.value.filter((x) => x.type === 'attachment')
    attFlds.value.forEach((f) => { verify[f.key] = !!(d.item.extra && d.item.extra['verify_' + f.key]) })
    extra.department = curDept
    extra.id_card = (d.item.extra && d.item.extra.id_card) || fd.value.id_card || ''
    extra.bank_card = (d.item.extra && d.item.extra.bank_card) || ''
    extra.regular_date = (d.item.extra && d.item.extra.regular_date) || ''
    extra.contract_start = (d.item.extra && d.item.extra.contract_start) || ''
    extra.contract_end = (d.item.extra && d.item.extra.contract_end) || ''
    openAccount.value = !!(fd.value && fd.value.open_account)
  } catch (e) { toast(e.message, false); emit('close') }
}, { immediate: true })

async function save(done) {
  const itemRows = items.value.map((it, i) => ({ key: 'item' + i, label: it.label, done: !!it.done }))
  const extraData = {
    department: (extra.department || '').trim(),
    id_card: extra.id_card.trim(),
    bank_card: extra.bank_card.trim(),
    regular_date: extra.regular_date || '',
    contract_start: extra.contract_start || '',
    contract_end: extra.contract_end || '',
  }
  Object.keys(verify).forEach((k) => { extraData['verify_' + k] = !!verify[k] })
  try {
    await api('/api/approval/onboard/save', { body: { id: props.id, items: itemRows, extra: extraData } })
    if (done) {
      const r = await api('/api/approval/onboard/complete', { body: { id: props.id, open_account: openAccount.value } })
      if (r.result && r.result.error) { toast(r.result.error, false); emit('saved'); return }
      toast('入职办理完成，已建档' + (r.result.account ? '并开通账号：' + r.result.account.username : ''))
      emit('saved')
    } else {
      toast('办理进度已保存')
      emit('saved')
    }
  } catch (e) { toast(e.message, false) }
}
</script>
