<template>
  <!-- 列表/管理视图 -->
  <div v-if="view === 'list'" class="org-page">
    <div class="org-topbar">
      <span class="org-topbar-title"><span v-html="ico('network', 18, '#165dff', 2)"></span> 组织架构 <span style="color:#86909c;font-weight:400;font-size:13px">/ 部门管理</span></span>
      <div style="margin-left:auto;display:flex;gap:10px">
        <button class="btn primary" @click="openChart"><span v-html="ico('network', 14, '#fff', 2)"></span> 组织架构图</button>
        <button class="btn" @click="addChild(null)"><span v-html="ico('plus', 14, '#1f2329', 2.2)"></span> 添加部门</button>
      </div>
    </div>
    <div style="display:flex;gap:14px;align-items:flex-start;margin-top:14px">
      <div class="org-side">
        <div class="org-search"><div class="org-search-box"><span v-html="ico('search', 14, '#86909c', 2)"></span><input placeholder="搜索部门" v-model="kw" @input="onSearch" /></div></div>
        <div class="org-tree-list">
          <div v-if="treeErr" class="msg err">{{ treeErr }}</div>
          <div v-else-if="!tree || !tree.length" class="msg info">尚未初始化组织架构。请在钉钉同步后使用，或先在「项目档案」新增项目。</div>
          <template v-else-if="!hits">
            <OrgTreeNode v-for="n in tree" :key="n.id" :node="n" :depth="0" :sel-id="selId" @select="selNode" />
          </template>
          <template v-else-if="hits.length">
            <div v-for="n in hits" :key="n.id" class="tree-row" :class="{ active: selId === n.id }" style="padding-left:10px" @click="selNode(n.id)">
              <span class="tree-ico" v-html="nodeIco(n.type, 15)"></span>
              <span class="tree-nm">{{ n.name }}</span>
              <span class="tree-cnt">{{ n.count_in || 0 }}</span>
            </div>
          </template>
          <div v-else class="msg info" style="font-size:12px">未找到「{{ kw.trim() }}」相关部门</div>
        </div>
      </div>
      <div class="org-main">
        <div v-if="!selId" class="msg info">← 在左侧选择节点查看详情；也可以在节点上执行「＋子节点 / 编辑 / 停用 / 删除」。</div>
        <div v-else-if="detailErr" class="msg err">{{ detailErr }}</div>
        <template v-else-if="n">
          <div class="org-card">
            <div class="org-card-head">
              <span class="org-node-big-ico" :style="{ background: ORG_BG[n.type] || '#e8f3ff' }" v-html="nodeIco(n.type, 22)"></span>
              <div>
                <div style="display:flex;align-items:center;gap:10px">
                  <h2 style="font-size:18px;font-weight:600">{{ n.name }}</h2>
                  <span class="org-tag" :class="ORG_TYPE_TAG[n.type] || 'org-tag-dept'">{{ ORG_TYPE_NAME[n.type] || n.type }}</span>
                  <span v-if="n.enabled" class="org-tag" style="background:#eafff1;color:#00b42a">启用</span>
                  <span v-else class="org-tag" style="background:#ffece8;color:#f53f3f">停用</span>
                </div>
                <div style="font-size:12px;color:#86909c;margin-top:4px">路径：{{ n.path || '—' }}</div>
              </div>
              <div style="margin-left:auto;display:flex;gap:8px">
                <button v-if="n.type === 'project'" class="btn sm primary" @click="addChild(n.id)"><span v-html="ico('plus', 12, '#fff', 2.2)"></span> 添加部门</button>
                <button class="btn sm" @click="editNode(n.id)"><span v-html="ico('pencil', 11, '#1f2329', 2)"></span> 编辑</button>
                <button v-if="n.type !== 'company'" :class="n.enabled ? 'btn sm danger' : 'btn sm'" @click="toggleStatus(n.id)">{{ n.enabled ? '停用' : '启用' }}</button>
              </div>
            </div>
            <div class="org-meta">
              <span>上级：<b>{{ parentName(n) || '—' }}</b></span>
              <span>在职人数：<b>{{ n.count_in || 0 }}</b></span>
              <span>离职人数：<b>{{ n.count_out || 0 }}</b></span>
              <span>子部门：<b>{{ subDepts.length }}</b></span>
              <span v-if="n.type === 'project'">状态：<b style="color:#00b42a">启用</b></span>
            </div>
          </div>
          <div class="org-kpis">
            <div class="org-kpi"><div class="org-kpi-lbl">在职人数</div><div class="org-kpi-num">{{ n.count_in || 0 }}<small>人</small></div></div>
            <div class="org-kpi"><div class="org-kpi-lbl">离职（历史）</div><div class="org-kpi-num">{{ n.count_out || 0 }}<small>人</small></div></div>
            <div class="org-kpi"><div class="org-kpi-lbl">子部门</div><div class="org-kpi-num">{{ subDepts.length }}<small>个</small></div></div>
            <div class="org-kpi"><div class="org-kpi-lbl">节点编码</div><div class="org-kpi-num" style="font-size:17px;line-height:34px">{{ n.code || '—' }}</div></div>
          </div>
          <div v-if="n.type === 'project'" class="org-card" style="margin-top:12px">
            <div class="org-panel-head">项目档案字段</div>
            <div class="org-meta" style="margin-top:8px">
              <span>负责人：<b>{{ n.contact || '—' }}</b></span>
              <span>电话：<b>{{ n.phone || '—' }}</b></span>
              <span>地址：<b>{{ n.address || '—' }}</b></span>
              <span>别名：<b>{{ (n.aliases || []).join('、') || '—' }}</b></span>
            </div>
            <div style="margin-top:8px"><button class="btn sm" @click="editProject(n.id)">编辑项目档案字段</button></div>
          </div>
          <div v-if="subDepts.length" class="org-card" style="margin-top:12px">
            <div class="org-panel-head">下级部门 <span style="font-weight:400;color:#86909c;font-size:12px">（点击进入）</span></div>
            <div v-for="s in subDepts" :key="s.id" class="org-subdept" style="cursor:pointer" @click="selNode(s.id)">
              <span class="tree-ico" style="margin-right:8px" v-html="nodeIco(s.type, 15)"></span>
              <span style="font-weight:500;font-size:13.5px">{{ s.name }}</span>
              <span style="color:#86909c;font-size:12px;margin-left:8px">{{ s.count_in || 0 }} 人</span>
              <span style="margin-left:auto;display:flex;gap:8px" @click.stop>
                <span class="org-link danger" @click="delNode(s.id)">删除</span>
                <span class="org-link" @click="editNode(s.id)">编辑</span>
              </span>
            </div>
          </div>
          <div class="org-card" style="margin-top:12px">
            <div class="org-panel-head">部门成员 <span style="font-weight:400;color:#86909c;font-size:12px">（含本节点及子孙部门，来自人事档案）</span></div>
            <div style="padding:10px 14px">
              <div v-if="!members.length" style="color:#86909c;font-size:13px;padding:8px 0">暂无在职人员（成员需在【人员档案】中维护，此处仅展示）</div>
              <div v-else class="table-wrap">
                <table class="tb">
                  <thead><tr><th>姓名</th><th>职位</th><th>员工状态</th><th>项目</th><th>操作</th></tr></thead>
                  <tbody>
                    <tr v-for="s in members" :key="s.id">
                      <td>{{ s.name }}</td><td>{{ s.position || '-' }}</td>
                      <td><span :class="s.deleted ? 'tag gray' : 'tag green'">{{ s.deleted ? '离职' : '在职' }}</span></td>
                      <td>{{ s.project || '-' }}</td>
                      <td><button class="btn sm" @click="moveStaffTo(s.id)">调动</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
    <div style="margin-top:10px">
      <button class="btn success" @click="syncNow">🔄 立即从钉钉同步</button>
      <span class="hint" style="margin:0 0 0 10px">组织架构由钉钉自动同步，请勿手动修改。</span>
    </div>
  </div>

  <!-- 组织架构图视图 -->
  <div v-else class="org-chart-page">
    <div class="org-chart-toolbar">
      <span class="org-chart-title"><span v-html="ico('network', 16, '#165dff', 2)"></span> 组织架构图</span>
      <span style="font-size:12px;color:#86909c">公司 / 项目 / 部门</span>
      <div style="display:flex;gap:16px;font-size:12px;color:#4e5969">
        <span><span v-html="ico('building', 13, '#165dff', 2)"></span> 公司</span>
        <span><span v-html="ico('pin', 13, '#7c3aed', 2)"></span> 项目</span>
        <span><span v-html="ico('folder', 13, '#00b42a', 2)"></span> 部门</span>
      </div>
      <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
        <div style="display:flex;align-items:center;gap:6px">
          <button class="btn sm" @click="zoomChart(-1)"><span v-html="ico('zoomout', 13, '#1f2329', 2)"></span></button>
          <span style="font-size:12px;color:#86909c;width:44px;text-align:center">{{ Math.round(zoom * 100) }}%</span>
          <button class="btn sm" @click="zoomChart(1)"><span v-html="ico('zoomin', 13, '#1f2329', 2)"></span></button>
        </div>
        <button class="btn sm" @click="zoomChart(0)">适应窗口</button>
        <button class="btn sm" @click="window.print()"><span v-html="ico('printer', 13, '#1f2329', 2)"></span> 打印</button>
        <button class="btn sm" @click="closeChart"><span v-html="ico('arrow', 13, '#1f2329', 2)"></span> 返回部门管理</button>
      </div>
    </div>
    <div class="org-chart-stage">
      <div class="org-chart" :style="{ transform: `scale(${zoom})` }">
        <ul>
          <li v-for="n in tree" :key="n.id">
            <OrgChartNode :node="n" @select="chartSel" />
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- 新增节点弹窗 -->
  <div v-if="modal === 'add'" class="modal-mask" @mousedown.self="modal = ''">
    <div class="modal" style="width:480px">
      <h3>在「{{ addParent ? addParent.name : '—' }}」下新增{{ addParentType === 'company' ? '项目' : '部门' }}</h3>
      <div class="form-grid">
        <label>节点名称<input type="text" v-model="mName" :placeholder="addParentType === 'company' ? '如：临沂万城花开' : '如：客服一部'" /></label>
        <label>节点类型<select v-model="mType">
          <option v-if="addParentType === 'company'" value="project">项目</option>
          <option v-else value="department">部门</option>
        </select></label>
        <label>编码（可选）<input type="text" v-model="mCode" /></label>
      </div>
      <div class="row end" style="margin-top:14px">
        <button class="btn" @click="modal = ''">取消</button>
        <button class="btn primary" @click="saveChild">创建</button>
      </div>
    </div>
  </div>

  <!-- 编辑节点弹窗 -->
  <div v-if="modal === 'edit'" class="modal-mask" @mousedown.self="modal = ''">
    <div class="modal" style="width:520px">
      <h3>编辑节点 — {{ editN && editN.name }}</h3>
      <div class="form-grid">
        <label>节点名称<input type="text" v-model="mName" /></label>
        <label>编码<input type="text" v-model="mCode" /></label>
        <label>备注<input type="text" v-model="mNote" /></label>
        <template v-if="editN && editN.type !== 'company'">
          <label>在组织架构中隐藏<select v-model="mHidden"><option value="0">否</option><option value="1">是</option></select>
            <div class="hint" style="font-size:11px;color:#6b7280">隐藏后：组织架构图与人事档案「选部门」中均不显示该节点及其下级</div>
          </label>
        </template>
        <template v-if="editN && editN.type === 'project'">
          <label>负责人<input type="text" v-model="mContact" /></label>
          <label>联系电话<input type="text" v-model="mPhone" /></label>
          <label>地址<input type="text" v-model="mAddr" /></label>
          <label>别名（逗号分隔）<input type="text" v-model="mAlias" /></label>
        </template>
      </div>
      <div class="row end" style="margin-top:14px">
        <button class="btn" @click="modal = ''">取消</button>
        <button class="btn primary" @click="saveNode">保存</button>
      </div>
    </div>
  </div>

  <!-- 成员调动弹窗 -->
  <div v-if="modal === 'move'" class="modal-mask" @mousedown.self="modal = ''">
    <div class="modal" style="width:480px">
      <h3>调动人员（ID {{ moveStaffId }}）到新部门</h3>
      <div class="form-grid">
        <label>目标部门<select v-model="moveOrgId">
          <option value="">— 选择目标部门 —</option>
          <option v-for="n in orgLeaves()" :key="n.id" :value="n.id">{{ n.path }}</option>
        </select></label>
        <label>调动原因<input type="text" v-model="moveReason" placeholder="例：调往客服部" /></label>
      </div>
      <div class="row end" style="margin-top:14px">
        <button class="btn" @click="modal = ''">取消</button>
        <button class="btn primary" @click="doMoveStaff">确认调动</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'
import { dingtalkSyncNow } from '@/utils/dingtalk'
import { svgIco } from './icons'
import { ORG_ICON, ORG_COLOR, ORG_BG, ORG_TYPE_NAME, ORG_TYPE_TAG } from './orgMeta'
import { loadOrgTree, orgTree, orgFlat, orgLeaves, orgNode, orgProjectOf, orgSel, setOrgSel } from './orgStore'
import OrgTreeNode from './OrgTreeNode.vue'
import OrgChartNode from './OrgChartNode.vue'

const auth = useAuthStore()
const view = ref('list')
const tree = ref(null)
const treeErr = ref('')
const kw = ref('')
const hits = ref(null)
const selId = ref(orgSel())
const detailErr = ref('')
const members = ref([])
const modal = ref('')
const zoom = ref(1)

// 弹窗数据
const addParentId = ref(null)
const mName = ref('')
const mType = ref('department')
const mCode = ref('')
const mNote = ref('')
const mHidden = ref('0')
const mContact = ref('')
const mPhone = ref('')
const mAddr = ref('')
const mAlias = ref('')
const editN = ref(null)
const moveStaffId = ref(null)
const moveOrgId = ref('')
const moveReason = ref('')

const ico = svgIco
const nodeIco = (type, size) => svgIco(ORG_ICON[type] || 'dot', size, ORG_COLOR[type] || '#86909c', 2)

const n = computed(() => (selId.value ? orgNode(selId.value) : null))
const subDepts = computed(() => ((n.value && n.value.children) || []).filter((c) => !c.hidden))
const addParent = computed(() => (addParentId.value ? orgNode(addParentId.value) : tree.value && tree.value.length ? tree.value[0] : null))
const addParentType = computed(() => (addParent.value ? addParent.value.type : 'company'))

function parentName(node) {
  if (!node.parent_id) return null
  const p = orgNode(node.parent_id)
  return p ? p.name : null
}

function onSearch() {
  const k = kw.value.trim()
  if (!k) { hits.value = null; return }
  const flat = orgFlat(tree.value || [])
  hits.value = flat.filter((x) => x.name.includes(k) || (x.path || '').includes(k))
}

// 复刻 orgLoad：拉树并同步启用项目列表到全局
async function load(force) {
  treeErr.value = ''
  try {
    const d = await api('/api/org/tree')
    tree.value = d.tree
    if (Array.isArray(d.projects)) {
      auth.projects = d.projects.filter((p) => p.status === '启用').map((p) => p.name)
    }
  } catch (e) { treeErr.value = e.message }
}

function selNode(id) {
  selId.value = id
  setOrgSel(id)
  hits.value = null
  kw.value = ''
  loadMembers(id)
}

async function loadMembers(id) {
  members.value = []
  try {
    const d = await api(`/api/org/staff?org_id=${id}`)
    members.value = d.staff || []
  } catch (e) { /* 忽略 */ }
}

function syncNow() {
  dingtalkSyncNow(() => load(true))
}

function addChild(parentId) {
  addParentId.value = parentId || selId.value || null
  const p = addParent.value
  mType.value = p && p.type === 'company' ? 'project' : 'department'
  mName.value = ''
  mCode.value = ''
  modal.value = 'add'
}

async function saveChild() {
  const name = mName.value.trim()
  if (!name) return toast('节点名称必填', false)
  try {
    await api('/api/org/node', { body: { parent_id: addParentId.value, name, type: mType.value, code: mCode.value } })
    modal.value = ''
    toast('已创建')
    const cur = selId.value || addParentId.value
    await load(true)
    if (cur) selNode(cur)
  } catch (e) { alert(e.message) }
}

function editNode(id) {
  const node = orgNode(id)
  editN.value = node
  mName.value = node.name
  mCode.value = node.code || ''
  mNote.value = node.note || ''
  mHidden.value = node.hidden ? '1' : '0'
  mContact.value = node.contact || ''
  mPhone.value = node.phone || ''
  mAddr.value = node.address || ''
  mAlias.value = (node.aliases || []).join(',')
  modal.value = 'edit'
}

async function saveNode() {
  const node = editN.value
  const body = { id: node.id, name: mName.value.trim(), code: mCode.value, note: mNote.value }
  if (node.type !== 'company') body.hidden = mHidden.value === '1'
  if (node.type === 'project') {
    body.contact = mContact.value
    body.phone = mPhone.value
    body.address = mAddr.value
    body.aliases = mAlias.value.split(/[,，]/).map((s) => s.trim()).filter(Boolean)
  }
  try {
    await api('/api/org/node/update', { body })
    modal.value = ''
    toast('已保存')
    await load(true)
    selNode(node.id)
  } catch (e) { alert(e.message) }
}

function editProject(nodeId) {
  editNode(nodeId)
}

async function toggleStatus(id) {
  const node = orgNode(id)
  const on = !node.enabled
  try {
    await api('/api/org/node/status', { body: { id, enabled: on } })
    toast(on ? '已启用' : '已停用（不再接收新人员）')
    await load(true)
    selNode(id)
  } catch (e) { alert(e.message) }
}

async function delNode(id) {
  const node = orgNode(id)
  if (!window.confirm(`确认删除「${node.name}」？有在职人员或子节点时将被拒绝（只能停用）。`)) return
  try {
    await api('/api/org/node/delete', { body: { id } })
    toast('已删除')
    selId.value = null
    setOrgSel(null)
    await load(true)
  } catch (e) { alert(e.message) }
}

function moveStaffTo(staffId) {
  moveStaffId.value = staffId
  moveOrgId.value = ''
  moveReason.value = ''
  modal.value = 'move'
}

async function doMoveStaff() {
  if (!moveOrgId.value) return toast('请选择目标部门', false)
  try {
    const r = await api('/api/org/staff/move', { body: { staff_id: moveStaffId.value, org_id: Number(moveOrgId.value), reason: moveReason.value } })
    modal.value = ''
    toast('已调动到 ' + r.dept_path)
    await load(true)
    if (selId.value) loadMembers(selId.value)
  } catch (e) { alert(e.message) }
}

// ---------- 组织架构图 ----------
function openChart() {
  if (!tree.value || !tree.value.length) { toast('请先初始化组织架构', false); return }
  zoom.value = 1
  view.value = 'chart'
}

function closeChart() {
  view.value = 'list'
  if (selId.value) selNode(selId.value)
}

function chartSel(id) {
  setOrgSel(id)
  selId.value = id
  closeChart()
}

function zoomChart(d) {
  if (d === 0) zoom.value = 1
  else zoom.value = Math.min(1.8, Math.max(0.5, zoom.value + d * 0.1))
}

onMounted(() => load(true))
</script>
