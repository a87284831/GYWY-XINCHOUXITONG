<template>
  <div class="card" style="display:flex;gap:0;padding:0;overflow:hidden">
    <!-- 左侧导航（app.js:5758-5775） -->
    <div style="width:172px;flex-shrink:0;background:#f8fafc;border-right:1px solid #eef0f3;padding:8px 0 16px">
      <div style="padding:12px 16px 6px;font-size:14px;font-weight:700;color:#111827">📋 审批中心</div>
      <div style="font-size:11px;color:#9ca3af;padding:10px 16px 4px;font-weight:600">我的工作</div>
      <div v-for="t in [['todo','我的待办',todoBadge],['approve','待我审批',0],['mine','我的审批',0]]" :key="t[0]" class="app-nav" :class="{ on: tab === t[0] }" @click="switchTab(t[0])">
        <span>{{ t[1] }}</span><span v-if="t[2]" class="app-nav-badge">{{ t[2] }}</span>
      </div>
      <div style="font-size:11px;color:#9ca3af;padding:10px 16px 4px;font-weight:600">发起与办理</div>
      <div v-for="t in [['start','发起审批'],['onboard','入职办理']]" :key="t[0]" class="app-nav" :class="{ on: tab === t[0] }" @click="switchTab(t[0])">
        <span>{{ t[1] }}</span>
      </div>
      <div style="font-size:11px;color:#9ca3af;padding:10px 16px 4px;font-weight:600">流程中心</div>
      <div v-for="t in [['flowmgt','流程管理'],['query','查询流程']]" :key="t[0]" class="app-nav" :class="{ on: tab === t[0] }" @click="switchTab(t[0])">
        <span>{{ t[1] }}</span>
      </div>
    </div>

    <!-- 右侧内容 -->
    <div style="flex:1;min-width:0;padding:16px 20px 20px">
      <div v-if="errMsg" class="msg err">{{ errMsg }}</div>

      <!-- ============ 我的待办：草稿/收件 ============ -->
      <template v-if="tab === 'todo'">
        <div style="display:flex;gap:10px;margin-bottom:12px">
          <button class="btn sm" :class="todoTab === 'draft' ? 'primary' : ''" @click="todoTab = 'draft'">📝 草稿（保存未提交）</button>
          <button class="btn sm" :class="todoTab === 'inbox' ? 'primary' : ''" @click="todoTab = 'inbox'">📥 收件（他人发送）</button>
        </div>
        <!-- 草稿列表 -->
        <template v-if="todoTab === 'draft'">
          <div v-if="!drafts.length" class="msg info">暂无草稿。发起审批时可点「保存草稿」暂存</div>
          <div v-else class="table-wrap"><table class="tb">
            <thead><tr><th>流程号</th><th>流程</th><th>标题</th><th>项目</th><th>更新时间</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="it in drafts" :key="it.id">
                <td><b style="color:#2563eb;font-size:12.5px">{{ it.flow_no || '-' }}</b></td>
                <td>{{ flowNameOf(it.flow_key) }}</td>
                <td>{{ it.title || '-' }}</td><td>{{ it.project || '-' }}</td>
                <td>{{ fmtTime(it.time) }}</td>
                <td>
                  <button class="btn sm primary" @click="editDraft(it)">继续编辑</button>
                  <button class="btn sm success" style="margin-left:6px" @click="draftSubmit(it.id)">提交</button>
                  <button class="btn sm" style="margin-left:6px;color:#dc2626;border-color:#f3c1c2" @click="draftDelete(it.id)">删除</button>
                </td>
              </tr>
            </tbody>
          </table></div>
        </template>
        <!-- 收件列表 -->
        <template v-else>
          <div v-if="!inbox.length" class="msg info">暂无收件。已完结流程可被他人发送给您查看</div>
          <div v-else class="table-wrap"><table class="tb">
            <thead><tr><th>流程号</th><th>流程</th><th>标题</th><th>发送人</th><th>发送时间</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="it in inbox" :key="it.id">
                <td><b style="color:#2563eb;font-size:12.5px">{{ it.flow_no || '-' }}</b></td>
                <td>{{ it.flow_name || '-' }}</td><td>{{ it.title || '-' }}</td>
                <td>{{ it.from_name }}</td><td>{{ fmtTime(it.time) }}</td>
                <td>
                  <button class="btn sm primary" @click="openDetail(it.instance_id, true)">查看</button>
                  <button class="btn sm" style="margin-left:6px;color:#dc2626;border-color:#f3c1c2" @click="inboxDelete(it.id)">删除</button>
                </td>
              </tr>
            </tbody>
          </table></div>
        </template>
      </template>

      <!-- ============ 待我审批 / 我的审批 / 流程管理：实例表 ============ -->
      <template v-else-if="['approve', 'mine', 'flowmgt'].includes(tab)">
        <template v-if="tab === 'mine'">
          <div class="row" style="margin-bottom:10px">
            <button class="btn sm" :class="mineScope === 'mine' ? 'primary' : ''" @click="loadMine('mine')">我发起的</button>
            <button class="btn sm" :class="mineScope === 'done' ? 'primary' : ''" @click="loadMine('done')">已办/已结束</button>
          </div>
        </template>
        <template v-if="tab === 'flowmgt'">
          <div class="row" style="margin-bottom:10px">
            <button v-for="s in [['all','全部流程'],['pending','已发起'],['done','已完结'],['void','已废弃']]" :key="s[0]" class="btn sm" :class="flowScope === s[0] ? 'primary' : ''" @click="loadFlowMgt(s[0])">{{ s[1] }}</button>
          </div>
          <div class="hint" style="margin-bottom:10px">流程管理展示系统内全部审批流程（管理员）。已发起=进行中；已完结=已通过+已驳回；已废弃=已撤回+已作废。</div>
        </template>
        <div v-if="!insts.length" class="msg info">{{ tab === 'approve' ? '暂无待您审批的单据' : '暂无记录' }}</div>
        <div v-else class="table-wrap"><table class="tb">
          <thead><tr><th>流程号</th><th>流程</th><th>标题</th><th>员工状态</th><th>进度</th><th>发起人</th><th>时间</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="it in insts" :key="it.id">
              <td><b style="color:#2563eb;font-size:12.5px">{{ it.flow_no || '-' }}</b></td>
              <td>{{ it.flow_name }}</td><td>{{ it.title }}</td>
              <td><span class="tag" :style="'color:' + st(it)[1] + ';border-color:' + st(it)[1]">{{ st(it)[0] }}</span></td>
              <td>{{ it.current_index }}/{{ it.total_nodes }}</td>
              <td>{{ it.applicant }}</td><td>{{ fmtTime(it.time) }}</td>
              <td>
                <button class="btn sm primary" @click="openDetail(it.id)">详情</button>
                <button v-if="tab === 'flowmgt' && (it.status === 'approved' || it.status === 'rejected')" class="btn sm" style="margin-left:6px" @click="sendId = it.id">📤 发送</button>
              </td>
            </tr>
          </tbody>
        </table></div>
      </template>

      <!-- ============ 发起审批 ============ -->
      <template v-else-if="tab === 'start'">
        <AppForm v-if="startFlow" :flow="startFlow" :mode="reopenMode" :draft-id="draftId" :draft-no="draftNo" :instance-id="reopenInstId" :initial="formInitial" @back="cancelForm" @done="formDone" />
        <template v-else>
          <div v-if="!pubFlows.length" class="msg info">暂无可用审批流程，请联系管理员在系统设置→审批权责设置中配置</div>
          <template v-else>
            <div class="page-sub" style="margin-bottom:12px">选择流程类型，发起对应审批</div>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
              <div v-for="f in pubFlows" :key="f.flow_key" class="home-app" style="width:250px;cursor:pointer;border:1px solid #eef0f3" @click="startNew(f.flow_key)">
                <div class="ha-head"><div class="ha-ico" style="background:#8b5cf6">{{ FLOW_ICON[f.flow_key] || '📋' }}</div><div class="ha-name">{{ f.name }}</div></div>
                <div class="ha-desc" style="min-height:36px">{{ FLOW_DESC[f.flow_key] || (f.form_schema.length + ' 个表单字段') }}</div>
                <div class="ha-go" style="color:#8b5cf6">去发起 →</div>
              </div>
            </div>
          </template>
        </template>
      </template>

      <!-- ============ 查询流程 ============ -->
      <template v-else-if="tab === 'query'">
        <div class="page-sub" style="margin-bottom:10px">按流程号/关键字/类型/状态/日期组合查询全部流程（管理员可查全部，其他账号仅本人发起）</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;background:#f8fafc;border:1px solid #eef0f3;border-radius:10px;padding:12px 14px">
          <label style="font-size:12.5px">流程号<input v-model="q.flow_no" type="text" placeholder="如 LS20260904-001" style="width:170px"></label>
          <label style="font-size:12.5px">关键字<input v-model="q.keyword" type="text" placeholder="标题/发起人/流程名" style="width:160px"></label>
          <label style="font-size:12.5px">流程类型<select v-model="q.flow_key" style="width:120px"><option value="">全部</option><option value="hire_approval">录用审批</option><option value="regular_approval">转正审批</option><option value="resign_approval">离职审批</option></select></label>
          <label style="font-size:12.5px">状态<select v-model="q.status" style="width:110px"><option value="">全部</option><option value="pending">审批中</option><option value="approved">已通过</option><option value="rejected">已驳回</option><option value="withdrawn,voided">已废弃</option></select></label>
          <label style="font-size:12.5px">开始日期<input v-model="q.date_from" type="date"></label>
          <label style="font-size:12.5px">结束日期<input v-model="q.date_to" type="date"></label>
          <button class="btn primary" @click="doQuery">查询</button>
          <button class="btn" @click="clearQuery">重置</button>
        </div>
        <div>
          <div v-if="!queried" class="msg info">输入条件后点击「查询」</div>
          <div v-else-if="qLoading" class="msg info">查询中...</div>
          <div v-else-if="!qItems.length" class="msg info">未查询到符合条件的流程</div>
          <div v-else class="table-wrap"><table class="tb">
            <thead><tr><th>流程号</th><th>流程</th><th>标题</th><th>员工状态</th><th>进度</th><th>发起人</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="it in qItems" :key="it.id">
                <td><b style="color:#2563eb;font-size:12.5px">{{ it.flow_no || '-' }}</b></td>
                <td>{{ it.flow_name }}</td><td>{{ it.title }}</td>
                <td><span class="tag" :style="'color:' + st(it)[1] + ';border-color:' + st(it)[1]">{{ st(it)[0] }}</span></td>
                <td>{{ it.current_index }}/{{ it.total_nodes }}</td><td>{{ it.applicant }}</td>
                <td>{{ fmtTime(it.time) }}</td>
                <td><button class="btn sm primary" @click="openDetail(it.id)">详情</button></td>
              </tr>
            </tbody>
          </table></div>
        </div>
      </template>

      <!-- ============ 入职办理 ============ -->
      <template v-else-if="tab === 'onboard'">
        <div v-if="!isAdmin" class="msg info">入职办理由人力（管理员）负责</div>
        <template v-else>
          <div v-if="!onboards.length" class="msg info">暂无待办理的入职清单</div>
          <div v-else class="table-wrap"><table class="tb">
            <thead><tr><th>ID</th><th>员工</th><th>项目</th><th>办理进度</th><th>创建时间</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="o in onboards" :key="o.id">
                <td>#{{ o.id }}</td><td>{{ o.staff_name }}</td><td>{{ o.project }}</td>
                <td>{{ o.done_count }}/{{ o.total_count }}</td><td>{{ fmtTime(o.time) }}</td>
                <td><button class="btn sm primary" @click="onboardId = o.id">办理</button></td>
              </tr>
            </tbody>
          </table></div>
        </template>
      </template>
    </div>

    <!-- 弹窗们 -->
    <DetailModal :instance-id="detailId" :readonly="detailReadonly" @close="detailId = 0" @changed="refresh" @reopen="reopen" @send="onDetailSend" />
    <SendModal :show="sendId > 0" :id="sendId" @close="sendId = 0" />
    <OnboardModal :show="onboardId > 0" :id="onboardId" @close="onboardId = 0" @saved="onboardId = 0" />
  </div>
</template>

<script setup>
// 审批中心 — 复刻 pageApprovalCenter（app.js:5756-6650）
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { flowNameOf, sortFlows, LIST_STATUS, FLOW_ICON, FLOW_DESC, buildQuery } from './approvalLogic'
import DetailModal from './DetailModal.vue'
import SendModal from './SendModal.vue'
import OnboardModal from './OnboardModal.vue'
import AppForm from './AppForm.vue'

const auth = useAuthStore()
const isAdmin = computed(() => auth.user && auth.user.role === 'admin')

const tab = ref('todo')
const todoTab = ref('draft')
const mineScope = ref('mine')
const flowScope = ref('all')
const errMsg = ref('')

const drafts = ref([])
const inbox = ref([])
const insts = ref([])
const pubFlows = ref([])
const onboards = ref([])
const q = reactive({ flow_no: '', keyword: '', flow_key: '', status: '', date_from: '', date_to: '' })
const qItems = ref([])
const queried = ref(false)
const qLoading = ref(false)

// 表单态（发起/草稿编辑/重新提交共用 AppForm）
const startFlow = ref(null)
const reopenMode = ref('create')
const draftId = ref(0)
const draftNo = ref('')
const reopenInstId = ref(0)
const formInitial = ref(null)

// 弹窗态
const detailId = ref(0)
const detailReadonly = ref(false)
const sendId = ref(0)
const onboardId = ref(0)

const st = (it) => LIST_STATUS[it.status] || ['未知', '#6b7280']
const fmtTime = (t) => (t ? String(t).replace('T', ' ') : '')

async function loadTodo() {
  const [d, ib] = await Promise.all([api('/api/approval/drafts'), api('/api/approval/inbox')])
  drafts.value = d.items || []
  inbox.value = ib.items || []
}
async function loadInsts(scope) {
  const d = await api('/api/approval/list?scope=' + scope)
  insts.value = d.items || []
}
async function loadMine(scope) {
  mineScope.value = scope
  insts.value = []
  await loadInsts(scope)
}
async function loadFlowMgt(scope) {
  flowScope.value = scope
  let qy = 'scope=all'
  if (scope === 'pending') qy = 'scope=all&status=pending'
  else if (scope === 'done') qy = 'scope=all&status=approved,rejected'
  else if (scope === 'void') qy = 'scope=all&status=withdrawn,voided'
  insts.value = []
  const d = await api('/api/approval/list?' + qy)
  insts.value = d.items || []
}
async function loadOnboards() {
  if (!isAdmin.value) return
  const d = await api('/api/approval/onboard/list?status=pending')
  onboards.value = d.items || []
}

function switchTab(t) {
  tab.value = t
  errMsg.value = ''
  refresh()
}
async function refresh() {
  try {
    if (tab.value === 'todo') await loadTodo()
    else if (tab.value === 'approve') await loadInsts('approve')
    else if (tab.value === 'mine') await loadInsts(mineScope.value || 'mine')
    else if (tab.value === 'flowmgt') await loadFlowMgt(flowScope.value || 'all')
    else if (tab.value === 'start') await loadPubFlows()
    else if (tab.value === 'onboard') await loadOnboards()
  } catch (e) { errMsg.value = e.message }
}
async function loadPubFlows() {
  const d = await api('/api/approval/flows_public')
  pubFlows.value = sortFlows(d.flows || [])
}

/* ---- 草稿/收件操作（app.js:5830-5873） ---- */
async function editDraft(it) {
  try {
    const d = await api('/api/approval/drafts/' + it.id)
    startDraftForm(d.draft)
  } catch (e) { toast(e.message, false) }
}
async function draftSubmit(id) {
  if (!confirm('确认提交该草稿进入审批流程？')) return
  try {
    const d = await api('/api/approval/draft_submit', { body: { id } })
    toast('已提交，流程号 ' + (d.flow_no || ''))
    loadTodo()
  } catch (e) { toast(e.message, false) }
}
async function draftDelete(id) {
  if (!confirm('确认删除该草稿？')) return
  try {
    await api('/api/approval/draft_delete', { body: { id } })
    toast('草稿已删除')
    loadTodo()
  } catch (e) { toast(e.message, false) }
}
async function inboxDelete(id) {
  if (!confirm('确认删除该收件？（不影响原流程）')) return
  try {
    await api('/api/approval/inbox_delete', { body: { id } })
    toast('收件已删除')
    loadTodo()
  } catch (e) { toast(e.message, false) }
}

/* ---- 发起/草稿编辑/重新提交（app.js:5990/6481） ---- */
function startDraftForm(draft) {
  const f = pubFlows.value.find((x) => x.flow_key === draft.flow_key) || { flow_key: draft.flow_key, name: flowNameOf(draft.flow_key), form_schema: [] }
  reopenMode.value = 'create'
  draftId.value = draft.id || 0
  draftNo.value = draft.flow_no || ''
  reopenInstId.value = 0
  formInitial.value = draft.form_data ? draft : null
  startFlow.value = f
}
async function startNew(fk) {
  await loadPubFlows()
  const f = pubFlows.value.find((x) => x.flow_key === fk)
  if (!f) { toast('流程不存在', false); return }
  reopenMode.value = 'create'
  draftId.value = 0
  draftNo.value = ''
  reopenInstId.value = 0
  formInitial.value = null
  startFlow.value = f
}
function reopen(inst) {
  detailId.value = 0
  reopenMode.value = 'resubmit'
  draftId.value = 0
  draftNo.value = ''
  reopenInstId.value = inst.id
  formInitial.value = inst
  startFlow.value = { flow_key: inst.flow_key, name: inst.flow_name, form_schema: inst.form_schema }
  tab.value = 'start'
}
function cancelForm() {
  startFlow.value = null
  formInitial.value = null
  draftId.value = 0
  reopenInstId.value = 0
  // 草稿编辑返回 → 流程列表；重新提交返回 → 我的审批（复刻 appTab('start')/appTab('mine')）
  tab.value = reopenMode.value === 'resubmit' ? 'mine' : 'start'
  refresh()
}
function formDone(payload) {
  startFlow.value = null
  formInitial.value = null
  draftId.value = 0
  reopenInstId.value = 0
  if (payload && payload.draftSaved) { tab.value = 'start'; loadPubFlows() } else { tab.value = 'mine'; refresh() }
}

/* ---- 查询（app.js:5934-5970） ---- */
async function doQuery() {
  queried.value = true
  qLoading.value = true
  try {
    const d = await api('/api/approval/search?' + buildQuery(q))
    qItems.value = d.items || []
  } catch (e) { qItems.value = []; errMsg.value = e.message }
  qLoading.value = false
}
function clearQuery() {
  q.flow_no = ''; q.keyword = ''; q.flow_key = ''; q.status = ''; q.date_from = ''; q.date_to = ''
  doQuery()
}

/* ---- 详情/发送（app.js:5874/6353） ---- */
function openDetail(id, readonly) {
  detailReadonly.value = !!readonly
  detailId.value = id
}
function onDetailSend(id) {
  detailId.value = 0
  sendId.value = id
}

onMounted(refresh)
</script>
