<template>
  <div :data-tick="tick">
    <div style="display:flex;gap:16px;flex-wrap:wrap">
      <div style="width:220px;flex:none">
        <div class="row" style="margin-bottom:8px"><button class="btn success sm" @click="appFlowNew">＋ 新建流程</button></div>
        <div>
          <div v-for="f in S.flows" :key="f.flow_key" class="rt-tab" :class="{ active: S.flow && S.flow.flow_key === f.flow_key }" style="margin:0 0 6px;width:100%;text-align:left" @click="appFlowEdit(f.flow_key)">
            {{ f.name }}{{ f.enabled ? '' : '（停用）' }}</div>
        </div>
      </div>
      <div style="flex:1;min-width:640px">
        <div v-if="!S.flow" class="hint" style="padding:24px;text-align:center">选择左侧流程进行编辑</div>
        <template v-else>
          <div style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 16px;margin-bottom:12px">
            <div class="row" style="margin-bottom:6px;flex-wrap:wrap">
              <label class="fld" style="min-width:220px">流程名称 <input v-model="hdrName"></label>
              <label class="fld" style="min-width:150px"><input type="checkbox" v-model="hdrEnabled"> 启用该流程</label>
              <div style="flex:1"></div>
              <button v-if="S.flow.id" class="btn sm" @click="appFlowToggle">{{ S.flow.enabled ? '停用' : '启用' }}</button>
              <button v-if="S.flow.id" class="btn sm danger" @click="appFlowDelete">删除流程</button>
            </div>
            <div style="font-size:12px;color:#9ca3af">表单字段与审批流保存后对所有账号生效。内部字段标识由系统自动生成，无需填写英文。</div>
          </div>
          <div style="display:flex;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin-bottom:10px;background:#fff">
            <div class="af-tab" :class="{ active: S.tab === 'form' }" @click="setTab('form')">📝 表单设计</div>
            <div class="af-tab" :class="{ active: S.tab === 'flow' }" @click="setTab('flow')">🔄 审批流设计</div>
          </div>

          <!-- Tab1：表单设计（所见即所得） -->
          <div v-if="S.tab === 'form'" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
            <div style="width:230px;flex:none;background:#fafbfc;border:1px solid #eef0f3;border-radius:10px;padding:14px">
              <div style="font-size:13px;font-weight:700;color:#374151;margin-bottom:4px">字段添加面板</div>
              <div style="font-size:11.5px;color:#9ca3af;margin-bottom:10px">选择要加入的分组，点击「＋ 添加字段」；在右侧预览区可编辑/删除/移动字段。</div>
              <div v-for="g in APP_GROUPS" :key="g.key" style="margin-bottom:14px">
                <div style="font-size:13px;font-weight:700;color:#374151;margin-bottom:7px">{{ g.icon }} {{ g.name }}<span v-if="g.key === 'material'" style="font-weight:400;font-size:11px;color:#9ca3af">（仅附件上传）</span></div>
                <button class="btn sm" style="width:100%;border:1.5px dashed #bfdbfe;color:#2563eb;background:#fff" @click="openFldModal(-1, g.key)">＋ 添加字段</button>
              </div>
            </div>
            <div style="flex:1;min-width:380px">
              <div style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;color:#111827;margin-bottom:8px">发起表单预览<span style="font-size:11.5px;color:#9ca3af;font-weight:400">（与发起审批界面一致，鼠标悬停字段出现操作按钮）</span></div>
              <div style="border:1.5px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden">
                <div style="padding:10px 16px;font-size:14px;font-weight:700;color:#1f2937;border-bottom:1px solid #eee;background:#f8fafc">{{ S.flow.name }} · 发起表单预览</div>
                <template v-for="g in APP_GROUPS" :key="g.key">
                  <div class="app-sec-title" :style="{ marginTop: g.key === 'person' ? '0' : '14px' }">{{ g.icon }} {{ g.name }}</div>
                  <div v-for="x in groupFields(g.key)" :key="x.f.key || x.i" class="fp-row" style="display:flex;align-items:center;gap:10px;padding:9px 16px;border-top:1px solid #f7f7f7;position:relative">
                    <span style="width:120px;flex:none;font-size:13px;color:#374151">
                      <span v-if="x.f.required" style="color:#dc2626">*</span>{{ x.f.label }}<span v-if="x.f.hint" style="display:block;font-size:11px;color:#9ca3af;font-weight:400">{{ x.f.hint }}</span>
                    </span>
                    <span style="flex:1;height:28px;border:1px solid #eef0f3;border-radius:6px;display:flex;align-items:center;padding:0 10px;font-size:12.5px" :style="{ background: x.f.type === 'attachment' ? '#fafafa' : '#fff' }">
                      <span style="color:#9ca3af">{{ ctlText(x.f) }}</span>
                    </span>
                    <span class="fp-opts" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.1);padding:2px;white-space:nowrap;display:none">
                      <button class="btn sm" style="border:none" @click="openFldModal(x.i)">编辑</button>
                      <button class="btn sm" style="border:none" @click="fldMove(x.i, -1)">↑</button>
                      <button class="btn sm" style="border:none" @click="fldMove(x.i, 1)">↓</button>
                      <button class="btn sm" style="border:none;color:#dc2626" @click="fldDel(x.i)">删除</button>
                    </span>
                  </div>
                  <div v-if="!groupFields(g.key).length" style="color:#c0c6cf;font-size:12.5px;padding:8px 4px">暂无字段，点左侧「＋ 添加字段」添加</div>
                </template>
              </div>
            </div>
          </div>

          <!-- Tab2：审批流设计（钉钉风格树状） -->
          <FlowDesign v-else :tick="tick" />

          <div class="row" style="margin-top:14px">
            <button class="btn primary" @click="appFlowSave">💾 保存流程配置</button>
            <button class="btn" @click="cancelEdit">取消</button>
          </div>
        </template>
      </div>
    </div>

    <!-- 字段编辑弹窗 -->
    <Modal :title="S.fldIdx >= 0 ? '编辑字段' : '添加字段'" :show="S.fldOpen" @close="closeFldModal">
      <div style="width:460px">
        <div style="display:grid;gap:10px;margin-top:12px">
          <label class="fld">所属分组 <select v-model="fldM.group"><option v-for="g in APP_GROUPS" :key="g.key" :value="g.key">{{ g.name }}</option></select></label>
          <label class="fld">字段名称（显示在表单上的中文名） <input v-model="fldM.label" placeholder="如：入职渠道"></label>
          <label class="fld">类型 <select v-model="fldM.type"><option v-for="t in APP_FLD_TYPES" :key="t[0]" :value="t[0]">{{ t[1] }}</option></select></label>
          <label class="fld">填写提示（可选，显示在字段下方的小字说明） <input v-model="fldM.hint" placeholder="如：18位，将校验合法性"></label>
          <label class="fld"><input type="checkbox" v-model="fldM.required"> 必填</label>
          <label class="fld" style="align-items:flex-start">选项（下拉/单选/多选用，逗号分隔）
            <textarea v-model="fldM.opts" rows="2" style="width:100%"></textarea></label>
        </div>
        <div style="font-size:11.5px;color:#9ca3af;margin-top:8px">系统会自动生成内部标识，您在界面上只需填写中文名称即可。</div>
      </div>
      <template #foot><button class="btn primary" @click="fldSave">保存</button><button class="btn" @click="closeFldModal">取消</button></template>
    </Modal>

    <!-- 节点编辑弹窗 -->
    <Modal :title="'编辑审批节点：' + (curNodeFn() ? curNodeFn().name : '')" :show="!!S.nodeId" :width="600" @close="closeNode">
      <div style="width:560px" v-if="curNodeFn()">
        <div style="display:grid;gap:10px;margin-top:12px">
          <label class="fld">节点名称 <input :value="curNodeFn().name" @change="curNodeFn().name = $event.target.value"></label>
          <label class="fld">通过规则
            <select :value="curNodeFn().mode" @change="curNodeFn().mode = $event.target.value">
              <option value="any">任一审批人通过即可</option>
              <option value="all">会签（全部审批人通过）</option>
            </select></label>
        </div>
        <div style="font-size:12.5px;font-weight:600;margin:12px 0 6px">审批人规则</div>
        <div>
          <div v-for="(r, i) in curNodeFn().approvers" :key="i" style="border:1px solid #eef0f3;border-radius:8px;padding:8px;margin-bottom:6px">
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
              <select :value="r.type" @change="r.type = $event.target.value">
                <option value="position">指定岗位</option>
                <option value="leader">发起人的直属上级</option>
                <option value="accounts">指定账号</option>
              </select>
              <button class="btn sm danger" @click="curNodeFn().approvers.splice(i, 1)">删</button>
            </div>
            <div v-if="r.type === 'position'">
              <div style="font-size:12.5px;color:#374151">选择岗位（来自花名册）：
                <select :value="r.position" style="width:100%;margin-top:4px" @change="r.position = $event.target.value">
                  <option value="">请选择岗位</option>
                  <option v-for="x in S.positions" :key="x.position" :value="x.position">{{ x.position }}（{{ x.count }}人）</option>
                </select></div>
              <div style="margin-top:6px;font-size:12px;color:#6b7280"><b>匹配范围：</b>
                <label style="margin-right:12px"><input type="radio" :name="'nrs_' + i" value="project" :checked="r.scope !== 'global'" @change="r.scope = 'project'"> 发起人所在项目</label>
                <label><input type="radio" :name="'nrs_' + i" value="global" :checked="r.scope === 'global'" @change="r.scope = 'global'"> 物业总部（全局）</label></div>
            </div>
            <div v-else-if="r.type === 'leader'">
              <div style="font-size:12px;color:#6b7280">若发起人无直属上级，则退给：</div>
              <select multiple size="3" style="width:100%" @change="r.fallback_accounts = selIds($event)">
                <option v-for="u in enabledUsersFn()" :key="u.id" :value="u.id" :selected="ruleIds(r.fallback_accounts).includes(Number(u.id))">{{ u.name || u.username }}</option>
              </select>
            </div>
            <div v-else>
              <select multiple size="4" style="width:100%" @change="r.accountIds = selIds($event)">
                <option v-for="u in enabledUsersFn()" :key="u.id" :value="u.id" :selected="ruleIds(r.accountIds).includes(Number(u.id))">{{ u.name || u.username }}{{ u.project ? '·' + u.project : '' }}</option>
              </select>
              <div style="font-size:11px;color:#6b7280">按住 Ctrl 多选；仅显示启用账号</div>
            </div>
          </div>
          <div v-if="!(curNodeFn().approvers || []).length" style="color:#9ca3af;font-size:12px">尚未配置审批人</div>
        </div>
        <button class="btn success sm" style="margin-top:8px" @click="addRule">＋ 添加审批人规则</button>
      </div>
      <template #foot><button class="btn primary" @click="saveNode">保存节点</button><button class="btn" @click="closeNode">取消</button></template>
    </Modal>

    <!-- 分支编辑弹窗 -->
    <Modal title="编辑条件分支" :show="!!S.branchId" :width="560" @close="closeBranch">
      <div style="width:520px" v-if="curBranchFn()">
        <div class="hint" style="margin:8px 0;font-size:12px;color:#6b7280">分支依据发起人填写的表单字段。命中某条件则走该分支，全部未命中则走「默认分支」。分支内可继续添加审批节点或嵌套分支。</div>
        <div style="font-weight:600;font-size:12.5px;margin:6px 0">分支条件</div>
        <div v-for="(p, i) in curBranchFn().paths" :key="p.id" style="border:1px solid #bbf7d0;background:#f0fdf4;border-radius:8px;padding:8px 10px;margin-bottom:6px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
            <b style="font-size:12.5px;color:#15803d">{{ p.default ? '🔸 默认分支' : '✅ 条件分支' }}</b>
            <input v-if="!p.default" v-model="p.label" placeholder="条件标签" style="border:1px solid #d1fae5;border-radius:6px;padding:4px 8px;font-size:12.5px;width:120px">
            <span style="flex:1"></span>
            <button v-if="!p.default" class="btn sm danger" @click="pathDel(i)">删</button>
          </div>
          <div v-if="!p.default" style="display:grid;gap:6px;grid-template-columns:1fr 90px 1fr">
            <select v-model="p.cond.field"><option value="">条件字段</option><option v-for="f in S.flow.form_schema" :key="f.key" :value="f.key">{{ f.label || f.key }}</option></select>
            <select v-model="p.cond.op"><option v-for="o in BRANCH_OPS" :key="o[0]" :value="o[0]">{{ o[1] }}</option></select>
            <input v-model="p.cond.value" placeholder="条件值" style="border:1px solid #d1fae5;border-radius:6px;padding:4px 8px;font-size:12.5px">
          </div>
        </div>
      </div>
      <template #foot><button class="btn primary" @click="saveBranch">保存</button><button class="btn" @click="closeBranch">取消</button></template>
    </Modal>
  </div>
</template>

<script>
// 模块级状态（跨设置 tab 切换保留）— 复刻旧版全局变量（app.js:6652-6659, 6804-6805）
const MOD = { flows: [], flow: null, users: [], positions: [], nodes: [], tab: 'form', seq: 1000, fldIdx: -1, fldGroup: 'person', fldOpen: false, nodeId: null, branchId: null, addMenu: null }
</script>

<script setup>
// 审批权责设置（表单设计器 + 审批流设计器）— 复刻 settingsApprovalFlow 群（app.js:6668-7205）
import { ref, reactive, h, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { insertAfter, removeItemById, findNodeRefId, findBranchById, collectNodeIds, legacyToTree } from '@/modules/oa/approvalLogic'
import Modal from '@/components/Modal.vue'

const S = MOD
const APP_GROUPS = [
  { key: 'person', name: '人员信息', icon: '👤' },
  { key: 'job', name: '入职信息', icon: '📋' },
  { key: 'salary', name: '薪酬信息', icon: '💰' },
  { key: 'material', name: '材料清单', icon: '📎' },
]
const APP_FLD_TYPES = [['text', '单行文本'], ['textarea', '多行文本'], ['number', '数字'], ['amount', '金额'], ['date', '日期'], ['select', '下拉选择'], ['radio', '单选'], ['multi', '多选'], ['person', '选择人员'], ['project', '选择项目'], ['department', '部门(按项目联动)'], ['attachment', '附件上传']]
const BRANCH_OPS = [['eq', '等于'], ['neq', '不等于'], ['contains', '包含'], ['gt', '大于'], ['lt', '小于'], ['gte', '大于等于'], ['lte', '小于等于'], ['empty', '为空'], ['notempty', '不为空']]
const OP_TXT = { eq: '等于', neq: '不等于', contains: '包含', gt: '大于', lt: '小于', gte: '大于等于', lte: '小于等于', earlier: '早于', later: '晚于', empty: '为空', notempty: '不为空' }

const tick = ref(0)
const rr = () => { tick.value++ }
const hdrName = ref('')
const hdrEnabled = ref(true)
const fldM = reactive({ group: 'person', label: '', type: 'text', required: false, hint: '', opts: '' })

function curNodeFn() { return (S.nodeId && S.nodes.find((n) => n.id === S.nodeId)) || null }
function curBranchFn() { return (S.branchId && S.flow && findBranchById(S.flow.flow_config.tree, S.branchId)) || null }
function enabledUsersFn() { return S.users.filter((u) => u.enabled) }
function ruleIds(arr) { return (arr || []).map(Number) }
function selIds(e) { return [...e.target.selectedOptions].map((o) => Number(o.value)) }
function groupFields(gkey) { return S.flow.form_schema.map((f, i) => ({ f, i })).filter((x) => (x.f.group || 'person') === gkey) }
function ctlText(f) {
  if (f.type === 'attachment') return '未上传 · 上传'
  if (['select', 'radio', 'multi', 'project', 'department', 'person'].includes(f.type)) return '请选择 ▾'
  if (f.type === 'date') return '请选择日期'
  if (f.type === 'amount' || f.type === 'number') return '请输入金额/数字'
  return '请输入'
}

/* ---- 列表与编辑入口 ---- */
async function appFlowLoad() {
  const d = await api('/api/approval/flows')
  S.flows = d.flows || []
  rr()
}
function appFlowNew() {
  S.flow = { id: 0, flow_key: '', name: '', enabled: true, form_schema: [], flow_config: { nodes: {}, tree: { type: 'chain', items: [] } } }
  S.nodes = []
  S.tab = 'form'
  hdrName.value = ''
  hdrEnabled.value = true
  rr()
}
async function appFlowEdit(fk) {
  if (!S.positions.length) {
    try { const p = await api('/api/approval/positions'); S.positions = p.positions || [] } catch (e) { S.positions = [] }
  }
  if (!S.users.length) {
    try { const u = await api('/api/users'); S.users = u.users || [] } catch (e) { S.users = [] }
  }
  const f = S.flows.find((x) => x.flow_key === fk)
  if (!f) return
  S.flow = JSON.parse(JSON.stringify(f))
  S.nodes = Object.entries(S.flow.flow_config.nodes || {}).map(([id, n]) => ({ id, name: n.name, mode: n.mode, approvers: n.approvers || [] }))
  if (!S.flow.flow_config.tree) {
    const r = legacyToTree(S.flow.flow_config || {}, S.nodes.map((n) => n.id), S.seq)
    S.flow.flow_config.tree = r.tree
    S.seq = r.seq
  }
  S.tab = 'form'
  hdrName.value = S.flow.name
  hdrEnabled.value = !!S.flow.enabled
  rr()
}
function cancelEdit() {
  hdrName.value = S.flow.name
  hdrEnabled.value = !!S.flow.enabled
  rr()
}
function setTab(t) { S.tab = t; S.addMenu = null; rr() }

/* ---- Tab1：字段编辑 ---- */
function openFldModal(idx, group) {
  S.fldIdx = idx
  if (group) S.fldGroup = group
  const f = idx >= 0 ? S.flow.form_schema[idx] : { label: '', type: 'text', group: S.fldGroup, required: false, options: [], hint: '' }
  fldM.group = f.group || S.fldGroup
  fldM.label = f.label || ''
  fldM.type = f.type || 'text'
  fldM.required = !!f.required
  fldM.hint = f.hint || ''
  fldM.opts = (f.options || []).map((o) => (typeof o === 'string' ? o : o.value || o.label || '')).join(',')
  S.fldOpen = true
  rr()
}
function closeFldModal() { S.fldOpen = false; rr() }
function fldSave() {
  const label = fldM.label.trim()
  if (!label) { toast('请填写字段名称', false); return }
  const opts = fldM.opts.split(/[,，]/).map((s) => s.trim()).filter(Boolean)
  const f = S.fldIdx >= 0 ? S.flow.form_schema[S.fldIdx] : { key: 'f_' + Date.now().toString().slice(-6), group: fldM.group }
  f.label = label
  f.type = fldM.type
  f.required = fldM.required
  f.options = opts
  f.hint = fldM.hint.trim() || undefined
  f.group = fldM.group
  if (S.fldIdx < 0) S.flow.form_schema.push(f)
  S.fldOpen = false
  S.tab = 'form'
  rr()
}
function fldDel(i) { S.flow.form_schema.splice(i, 1); rr() }
function fldMove(i, dir) {
  const j = i + dir
  if (j < 0 || j >= S.flow.form_schema.length) return
  const arr = S.flow.form_schema
  const t = arr[i]; arr[i] = arr[j]; arr[j] = t
  rr()
}

/* ---- Tab2：审批流树渲染（h 函数渲染，复刻 appFlowDesignHtml） ---- */
function nodeDef(id) { return S.nodes.find((n) => n.id === id) }
function ruleText(n) {
  if (!n || !(n.approvers || []).length) return '未配置审批人'
  return n.approvers.map((r) => {
    if (r.type === 'role') return '旧版角色配置（' + (r.role || '?') + '）'
    if (r.type === 'position') {
      const pos = S.positions.find((p) => p.position === r.position) ? r.position : (r.position || '未选岗位')
      return '指定岗位·' + pos + '（' + (r.scope === 'global' ? '物业总部' : '发起人所在项目') + '）'
    }
    if (r.type === 'leader') return '发起人直属上级'
    return '指定账号×' + (r.accountIds || []).length
  }).join('、')
}
function branchCondText(cond) {
  const fld = S.flow.form_schema.find((x) => x.key === cond.field)
  return `${fld ? fld.label : cond.field || '?'} ${OP_TXT[cond.op] || cond.op} ${cond.value}`
}
function connectorVn() { return h('div', { style: 'margin-left:26px;width:2px;height:20px;background:#c9d7f0' }) }
function nodeCardVn(item) {
  const nd = nodeDef(item.nodeId)
  const all = nd && nd.mode === 'all'
  return h('div', { class: 'flow-node-card' + (all ? ' all' : '') }, [
    h('div', { style: 'display:flex;align-items:center;gap:8px' }, [
      h('span', { style: `width:28px;height:28px;border-radius:50%;background:${all ? '#fdeeee' : '#eef4ff'};color:${all ? '#e24f4f' : '#3b82f6'};display:flex;align-items:center;justify-content:center;font-size:14px;flex:none` }, '👤'),
      h('div', { style: 'flex:1;min-width:0' }, [
        h('div', { style: 'font-size:13.5px;font-weight:700;color:#1f2937' }, nd ? nd.name : '节点已删除'),
        h('div', { style: 'font-size:11.5px;color:#6b7280;margin-top:2px' }, ruleText(nd)),
      ]),
      h('span', { style: `font-size:11px;padding:2px 9px;border-radius:10px;background:${all ? '#fee2e2' : '#eef2ff'};color:${all ? '#dc2626' : '#4f46e5'};flex:none` }, all ? '会签' : '任一通过'),
    ]),
    h('div', { style: 'margin-top:7px;display:flex;gap:5px' }, [
      h('button', { class: 'btn sm', onClick: () => openNodeModal(item.nodeId) }, '编辑'),
      h('button', { class: 'btn sm', onClick: () => appNodeDel(item.nodeId) }, '删除'),
    ]),
  ])
}
function addBtnVn(refId) {
  return h('div', { style: 'margin:2px 0 2px 30px;position:relative' }, [
    h('button', { class: 'flow-add-btn', onClick: (e) => { e.stopPropagation(); S.addMenu = refId; rr() } }, [h('span', { style: 'font-size:15px;font-weight:700' }, '＋'), ' 添加']),
    S.addMenu === refId
      ? h('div', { class: 'flow-add-menu' }, [
          h('div', { class: 'mi', onClick: () => appNodeAddAfter(refId) }, '👤 审批人'),
          h('div', { class: 'mi', onClick: () => appBranchAddAfter(refId) }, '🔀 条件分支'),
        ])
      : null,
  ])
}
function renderBranchVn(b) {
  return h('div', { style: 'position:relative;margin:8px 0;padding-left:26px;border-left:2px solid #86efac' }, [
    h('div', { style: 'display:flex;align-items:center;gap:8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:6px 14px;font-size:13px;color:#15803d;font-weight:600;margin-bottom:10px' }, [
      '🔀 条件分支：' + branchCondText(b.cond || {}),
      h('button', { class: 'btn sm', style: 'margin-left:auto', onClick: () => { S.branchId = b.id; rr() } }, '编辑条件'),
      h('button', { class: 'btn sm', style: 'color:#dc2626', onClick: () => appBranchDel(b.id) }, '删'),
    ]),
    h('div', { style: 'display:flex;align-items:flex-start;gap:10px' }, (b.paths || []).map((p) =>
      h('div', { style: 'flex:1;min-width:0;border-top:2px solid #86efac;position:relative' }, [
        h('div', { style: 'display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #bbf7d0;color:#15803d;font-size:12px;padding:3px 11px;border-radius:13px;margin:2px 0 8px' }, p.default ? '🔸 默认' : '✅ ' + (p.label || '命中')),
        h('div', renderChainVn(p)),
        addBtnVn(p.id),
      ]))),
    h('div', { style: 'display:flex;align-items:center;gap:8px;margin-top:8px' }, [
      h('button', { class: 'btn sm', style: 'color:#15803d', onClick: () => appPathAdd(b.id) }, '＋ 添加分支条件'),
    ]),
  ])
}
function renderChainVn(p) {
  return (p.items || []).map((item) => renderItemVn(item)).reduce((acc, vn, i) => (i ? [...acc, connectorVn(), vn] : [vn]), [])
}
function renderItemVn(item) {
  if (item.type === 'branch' || item.paths) return renderBranchVn(item)
  return h('div', { style: 'margin-left:26px;padding-left:0' }, [h('div', { class: 'flow-node-wrap' }, [nodeCardVn(item)]), addBtnVn(item.id)])
}
function renderTreeVn(tree) {
  if (!tree) return []
  if (tree.type === 'branch') return [renderBranchVn(tree)]
  return (tree.items || []).map((item) => renderItemVn(item)).reduce((acc, vn, i) => (i ? [...acc, connectorVn(), vn] : [vn]), [])
}
const FlowDesign = () => h('div', { style: 'background:#fafbfc;border:1px solid #eef0f3;border-radius:12px;padding:20px 22px' }, [
  h('div', { style: 'display:flex;align-items:center;gap:8px;margin-bottom:6px' }, [
    h('span', { style: 'width:12px;height:12px;border-radius:50%;background:#10b981' }),
    h('span', { style: 'font-size:13px;color:#374151;font-weight:600' }, '发起人（自动获取账号）'),
  ]),
  connectorVn(),
  ...renderTreeVn(S.flow && S.flow.flow_config.tree),
  h('div', { style: 'display:flex;align-items:center;gap:8px;margin-left:26px;margin-top:8px' }, [
    h('span', { style: 'width:12px;height:12px;border-radius:50%;background:#d1d5db' }),
    h('span', { style: 'font-size:13px;color:#4b5563;font-weight:600' }, '结束'),
  ]),
  h('div', { style: 'font-size:11.5px;color:#9ca3af;margin-top:14px;line-height:1.8;background:#fff;border:1px dashed #e5e7eb;border-radius:8px;padding:8px 12px', innerHTML: '新增入口：每个节点正下方「＋ 添加」→ 选择 <b>审批人</b>（此步之后加审批节点）/ <b>条件分支</b>（此处分叉）/ <b>抄送人</b>（此步之后抄送）。条件分支每条子链末尾也有「＋ 添加」可继续延伸或嵌套分支。' }),
])

/* ---- 树操作 ---- */
function appNodeAddAfter(refId) {
  const nid = 'n' + (++S.seq)
  S.nodes.push({ id: nid, name: '审批节点' + S.nodes.length, mode: 'any', approvers: [{ type: 'position', position: '', scope: 'project', accountIds: [], fallback_accounts: [] }] })
  insertAfter(S.flow.flow_config.tree, refId, { id: 't' + (++S.seq), type: 'approve', nodeId: nid })
  S.addMenu = null
  S.tab = 'flow'
  openNodeModal(nid)
}
function appBranchAddAfter(refId) {
  const bitem = { id: 't' + (++S.seq), type: 'branch', cond: { field: '', op: 'eq', value: '' }, paths: [
    { id: 'p' + (++S.seq), label: '命中条件', default: false, cond: { field: '', op: 'eq', value: '' }, items: [] },
    { id: 'p' + (++S.seq), label: '默认', default: true, items: [] },
  ] }
  insertAfter(S.flow.flow_config.tree, refId, bitem)
  S.addMenu = null
  S.tab = 'flow'
  rr()
}
function appNodeDel(nodeId) {
  if (!confirm('确定删除该审批节点？')) return
  const tree = S.flow.flow_config.tree
  const ref = findNodeRefId(tree, nodeId)
  if (ref) removeItemById(tree, ref)
  S.nodes = S.nodes.filter((n) => n.id !== nodeId)
  rr()
}
function appBranchDel(branchId) {
  if (!confirm('确定删除该条件分支？')) return
  removeItemById(S.flow.flow_config.tree, branchId)
  rr()
}
function appPathAdd(branchId) {
  const b = findBranchById(S.flow.flow_config.tree, branchId)
  if (!b) return
  b.paths.push({ id: 'p' + (++S.seq), label: '条件' + b.paths.length, default: false, cond: { field: '', op: 'eq', value: '' }, items: [] })
  rr()
}

/* ---- 节点编辑 ---- */
function openNodeModal(nid) {
  const n = S.nodes.find((x) => x.id === nid)
  if (!n) return
  ;(n.approvers || []).forEach((r) => {
    if (r.type === 'role') {
      r.type = 'position'; r.position = ''; r.scope = r.role === 'admin' ? 'global' : 'project'
      r.accountIds = []; r.fallback_accounts = []
    }
  })
  S.nodeId = nid
  rr()
}
function closeNode() { S.nodeId = null; rr() }
function addRule() {
  const n = curNodeFn()
  if (!n) return
  ;(n.approvers || (n.approvers = [])).push({ type: 'position', position: '', scope: 'project', accountIds: [], fallback_accounts: [] })
  rr()
}
function saveNode() {
  const n = curNodeFn()
  if (!n) return
  n.name = String(n.name || '').trim() || n.name
  const rules = []
  ;(n.approvers || []).forEach((r) => {
    const r2 = { type: r.type, position: '', role: '', accountIds: [], fallback_accounts: [] }
    if (r.type === 'position') { r2.position = r.position || ''; r2.scope = r.scope || 'project' }
    if (r.type === 'leader') r2.fallback_accounts = ruleIds(r.fallback_accounts)
    if (r.type === 'accounts') r2.accountIds = ruleIds(r.accountIds)
    rules.push(r2)
  })
  n.approvers = rules
  S.nodeId = null
  S.tab = 'flow'
  rr()
}

/* ---- 分支编辑 ---- */
function closeBranch() { S.branchId = null; rr() }
function pathDel(i) {
  const b = curBranchFn()
  if (b && b.paths[i] && !b.paths[i].default) b.paths.splice(i, 1)
  rr()
}
function saveBranch() {
  const b = curBranchFn()
  if (!b) return
  ;(b.paths || []).forEach((p) => {
    if (p.default) return
    if (!p.cond) p.cond = { field: '', op: 'eq', value: '' }
    p.cond = { field: p.cond.field || '', op: p.cond.op || 'eq', value: p.cond.value || '' }
  })
  b.cond = ((b.paths || []).find((p) => !p.default) || {}).cond || { field: '', op: 'eq', value: '' }
  S.branchId = null
  S.tab = 'flow'
  rr()
}

/* ---- 保存 ---- */
async function appFlowSave() {
  if (!S.flow) return
  const name = hdrName.value.trim()
  const enabled = hdrEnabled.value
  if (!name) { toast('请填写流程名称', false); return }
  if (!S.flow.flow_key) S.flow.flow_key = 'flow_' + Date.now().toString().slice(-8)
  if (!S.flow.form_schema.length) { toast('请至少添加一个表单字段', false); return }
  const used = new Set()
  collectNodeIds(S.flow.flow_config.tree, used)
  S.nodes = S.nodes.filter((n) => used.has(n.id))
  if (!S.nodes.length) { toast('请至少添加一个审批节点', false); return }
  const nodes = {}
  S.nodes.forEach((n) => { nodes[n.id] = { name: n.name, mode: n.mode, approvers: n.approvers } })
  S.flow.flow_config.nodes = nodes
  try {
    const body = { id: S.flow.id, flow_key: S.flow.flow_key, name, form_schema: S.flow.form_schema, flow_config: S.flow.flow_config, enabled }
    await api('/api/approval/flow_save', { body })
    toast('流程配置已保存')
    await appFlowLoad()
    appFlowEdit(S.flow.flow_key)
  } catch (e) { toast(e.message, false) }
}
async function appFlowToggle() {
  await api('/api/approval/flow_toggle', { body: { id: S.flow.id } })
  toast('已切换启用状态')
  await appFlowLoad()
  appFlowEdit(S.flow.flow_key)
}
async function appFlowDelete() {
  if (!confirm('确定删除该流程？有审批单的流程无法删除。')) return
  try {
    await api('/api/approval/flow_delete', { body: { id: S.flow.id } })
    toast('流程已删除')
    S.flow = null
    await appFlowLoad()
  } catch (e) { toast(e.message, false) }
}

onMounted(appFlowLoad)
</script>
