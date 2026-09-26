<template>
  <div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
      <button class="btn sm" @click="$emit('back')">← {{ mode === 'resubmit' ? '返回' : '返回流程列表' }}</button>
      <b style="font-size:15px">{{ mode === 'resubmit' ? '修改并重新提交：' + (initial?.title || flow.name) : flow.name }}</b>
      <span v-if="mode !== 'resubmit'" class="tag blue">发起审批</span>
      <span v-if="draftId" class="tag" style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa">草稿：{{ draftNo }}</span>
    </div>
    <div style="max-width:900px">
      <div style="display:flex;align-items:center;gap:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:9px 16px;margin-bottom:14px;font-size:13px;flex-wrap:wrap">
        <span style="color:#1d4ed8;font-weight:600">🧑 发起人：{{ userName }}</span>
        <span style="color:#6b7280;font-size:12px">账号 {{ userAcc }} · {{ userProj }}</span>
        <span style="margin-left:auto;font-size:11.5px;color:#2563eb;background:#dbeafe;border-radius:10px;padding:1px 10px">自动获取，无需填写</span>
      </div>

      <div v-if="personFlds.length" style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px;margin-bottom:14px">
        <div style="display:flex;align-items:center;font-size:14px;font-weight:600;color:#111827;margin-bottom:12px">
          <span style="width:4px;height:15px;background:#2563eb;border-radius:2px;margin-right:8px"></span>👤 人员信息</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:2px 20px">
          <div v-for="f in personFlds" :key="f.key" style="margin-bottom:12px"><FieldRow :f="f" :values="values" :sel="selPerson" :person-list="personList" :dept-list="deptList" :has-project="hasProject" :has-person="hasPerson" :proj-val="projVal" :perf-display="perfDisplay" @person-change="onPersonChange" @project-change="onProjectChange" @attach="onAttach" /></div>
        </div>
      </div>
      <div v-if="jobFlds.length" style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px;margin-bottom:14px">
        <div style="display:flex;align-items:center;font-size:14px;font-weight:600;color:#111827;margin-bottom:12px">
          <span style="width:4px;height:15px;background:#2563eb;border-radius:2px;margin-right:8px"></span>📋 入职信息</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:2px 20px">
          <div v-for="f in jobFlds" :key="f.key" style="margin-bottom:12px"><FieldRow :f="f" :values="values" :sel="selPerson" :person-list="personList" :dept-list="deptList" :has-project="hasProject" :has-person="hasPerson" :proj-val="projVal" :perf-display="perfDisplay" @person-change="onPersonChange" @project-change="onProjectChange" @attach="onAttach" /></div>
        </div>
      </div>
      <div v-if="salFlds.length" style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px;margin-bottom:14px">
        <div style="display:flex;align-items:center;font-size:14px;font-weight:600;color:#111827;margin-bottom:12px">
          <span style="width:4px;height:15px;background:#2563eb;border-radius:2px;margin-right:8px"></span>💰 薪酬信息</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:2px 20px">
          <div v-for="f in salFlds" :key="f.key" style="margin-bottom:12px"><FieldRow :f="f" :values="values" :sel="selPerson" :person-list="personList" :dept-list="deptList" :has-project="hasProject" :has-person="hasPerson" :proj-val="projVal" :perf-display="perfDisplay" @person-change="onPersonChange" @project-change="onProjectChange" @attach="onAttach" /></div>
        </div>
      </div>
      <div v-if="matFlds.length" style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px;margin-bottom:14px">
        <div style="display:flex;align-items:center;font-size:14px;font-weight:600;color:#111827;margin-bottom:6px">
          <span style="width:4px;height:15px;background:#6366f1;border-radius:2px;margin-right:8px"></span>📎 材料清单（材料须上传完成后提交）</div>
        <div style="font-size:12px;color:#9ca3af;margin-bottom:8px">支持 pdf/jpg/png，单个不超过 5MB</div>
        <FieldRow v-for="f in matFlds" :key="f.key" :f="f" :values="values" :sel="selPerson" :person-list="personList" :dept-list="deptList" :has-project="hasProject" :has-person="hasPerson" :proj-val="projVal" :perf-display="perfDisplay" @person-change="onPersonChange" @project-change="onProjectChange" @attach="onAttach" />
      </div>

      <div class="row" style="margin-top:4px;padding-bottom:16px">
        <button class="btn primary" @click="submit">提交审批</button>
        <button v-if="mode === 'create'" class="btn" @click="saveDraft">💾 保存草稿</button>
        <button class="btn" @click="$emit('back')">{{ mode === 'resubmit' ? '取消' : '取消' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
// 发起审批 / 重新提交 动态表单 — 复刻 appStartFlow（app.js:5990-6260）与 appReopen 表单部分（app.js:6481-6543）
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { collectForm, perfText, deptNameList } from './approvalLogic'
import { staffOptions, staffMap } from './staffOptions'
import { loadOrgTree, orgLeaves, orgProjectOf } from '@/modules/hr/orgStore'
import FieldRow from './FieldRow.vue'

const props = defineProps({
  flow: { type: Object, required: true },
  mode: { type: String, default: 'create' }, // create | resubmit
  draftId: { type: Number, default: 0 },
  draftNo: { type: String, default: '' },
  instanceId: { type: Number, default: 0 },
  initial: { type: Object, default: null },
})
const emit = defineEmits(['back', 'done'])

const auth = useAuthStore()
const userName = auth.user ? (auth.user.name || auth.user.username) : ''
const userAcc = auth.user ? (auth.user.username || '') : ''
const userProj = auth.user ? (auth.user.project_name || '') : ''

const schema = computed(() => props.flow.form_schema || [])
const personFlds = computed(() => schema.value.filter((f) => f.group === 'person'))
const jobFlds = computed(() => schema.value.filter((f) => f.group === 'job'))
const salFlds = computed(() => schema.value.filter((f) => f.group === 'salary'))
const matFlds = computed(() => schema.value.filter((f) => f.group === 'material'))
const hasProject = computed(() => schema.value.some((x) => x.type === 'project'))
const hasPerson = computed(() => schema.value.some((x) => x.type === 'person'))
const projectKey = computed(() => (schema.value.find((x) => x.type === 'project') || {}).key || '')

const values = reactive({})
const selPerson = reactive({})
const attachTag = reactive({})
const staffOpts = ref([])

const projVal = computed(() => values[projectKey.value] || '')
const personList = computed(() => (hasProject.value ? (projVal.value ? staffOpts.value.filter((s) => s.project === projVal.value) : []) : staffOpts.value))
const deptList = computed(() => deptNameList(projVal.value, orgLeaves(), (n) => { const p = orgProjectOf(n.id); return p ? p.name : null }))
const perfDisplay = computed(() => perfText(values['fixed_monthly'], values['base_salary']))

function autoSync(s) {
  schema.value.forEach((fld) => {
    if (!fld.auto || fld.type === 'person') return
    const v = s[fld.auto] !== undefined && s[fld.auto] !== null ? s[fld.auto] : ''
    values[fld.key] = fld.auto === 'name' ? (s.name || '') : v
  })
  // 选中人员后同步回填项目（如已存在项目字段）
  schema.value.forEach((fld) => {
    if (fld.type === 'project' && s.project) values[fld.key] = s.project
  })
}
function onPersonChange(key) {
  const s = staffMap()[selPerson[key]]
  if (!selPerson[key] || !s) {
    values[key] = ''
    schema.value.forEach((fld) => { if (fld.auto && fld.type !== 'person') values[fld.key] = '' })
    return
  }
  values[key] = s.name || ''
  values[key + '_id'] = selPerson[key]
  autoSync(s)
}
function onProjectChange() {
  schema.value.forEach((fld) => {
    if (fld.type === 'person') {
      selPerson[fld.key] = ''
      values[fld.key] = ''
    }
  })
  // appPersonSync 清空联动（app.js:6178-6185）
  schema.value.forEach((fld) => {
    if (fld.auto && fld.type !== 'person') values[fld.key] = ''
  })
}
async function onAttach(ev, key) {
  const file = ev.target.files[0]
  if (!file) return
  const fd = new FormData()
  fd.append('file', file)
  try {
    const d = await api('/api/approval/upload', { form: fd, method: 'POST' })
    values[key] = d.url
    attachTag[key] = '已上传'
    toast('附件已上传')
  } catch (e) { toast(e.message, false) }
}

function submit() {
  const { form, missing } = collectForm(schema.value, values)
  if (missing.length) { toast('请填写：' + missing.join('、'), false); return }
  doSubmit(form)
}
async function doSubmit(form) {
  try {
    if (props.mode === 'resubmit') {
      await api('/api/approval/resubmit', { body: { id: props.instanceId, form_data: form } })
      toast('已重新提交')
    } else if (props.draftId) {
      // 忠实旧版：草稿提交仅发送 id（app.js:6241-6242），表单数据以最近一次保存的草稿为准
      await api('/api/approval/draft_submit', { body: { id: props.draftId } })
      toast('审批单已提交，等待审批')
    } else {
      await api('/api/approval/create', { body: { flow_key: props.flow.flow_key, form_data: form } })
      toast('审批单已提交，等待审批')
    }
    emit('done')
  } catch (e) { toast(e.message, false) }
}
async function saveDraft() {
  const { form } = collectForm(schema.value, values, true)
  try {
    const d = await api('/api/approval/draft_save', { body: { draft_id: props.draftId || 0, flow_key: props.flow.flow_key, form_data: form } })
    toast(d.flow_no ? ('草稿已保存，流程号 ' + d.flow_no) : '草稿已保存')
    emit('done', { draftSaved: true })
  } catch (e) { toast(e.message, false) }
}

onMounted(async () => {
  if (!orgLeaves().length) { try { await loadOrgTree() } catch (e) {} }
  staffOpts.value = await staffOptions()
  const fd = (props.initial && props.initial.form_data) || props.initial || {}
  schema.value.forEach((fld) => {
    const key = fld.key
    if (fld.type === 'multi') values[key] = Array.isArray(fd[key]) ? fd[key].slice() : []
    else if (fld.type === 'person') { selPerson[key] = fd[key + '_id'] != null ? String(fd[key + '_id']) : ''; values[key] = fd[key] || '' }
    else if (fld.type === 'attachment') { values[key] = fd[key] || ''; attachTag[key] = values[key] ? '已上传' : '未上传' }
    else if (fld.type === 'perf') { /* 自动计算 */ }
    else values[key] = fd[key] != null ? fd[key] : ''
  })
})
</script>
