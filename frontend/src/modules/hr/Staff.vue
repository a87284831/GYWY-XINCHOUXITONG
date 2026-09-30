<template>
  <div class="card">
    <h3>人员档案</h3>
    <div style="margin-bottom:14px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
      <div
        title="点击筛选全部人员" style="cursor:pointer;background:#fff;border-radius:8px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.05);border:2px solid transparent"
        :style="{ border: '2px solid ' + (cat === '' ? '#1e3a8a' : 'transparent') }"
        @click="setCat('')"
      >
        <div style="font-size:12px;color:#8c8c8c;margin-bottom:4px">全部人员</div>
        <div style="font-size:22px;font-weight:bold;color:#1e3a8a;font-variant-numeric:tabular-nums">{{ total }}</div>
      </div>
      <div
        v-for="d in CAT_DEFS" :key="d[0]"
        :title="'点击筛选' + d[0] + '人员'" style="cursor:pointer;background:#fff;border-radius:8px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.05)"
        :style="{ border: '2px solid ' + (cat === d[0] ? d[1] : 'transparent'), borderTop: '3px solid ' + d[1] }"
        @click="setCat(d[0])"
      >
        <div style="font-size:12px;color:#8c8c8c;margin-bottom:4px">{{ d[0] }}人员</div>
        <div :style="{ fontSize: '22px', fontWeight: 'bold', color: d[1], fontVariantNumeric: 'tabular-nums' }">{{ counts[d[0]] || 0 }}</div>
      </div>
    </div>
    <div class="row">
      <label class="fld">项目 <select v-model="fProj" @change="onProjChange">
        <option value="">全部</option><option v-for="p in auth.projects" :key="p">{{ p }}</option>
      </select></label>
      <label class="fld">部门 <select v-model="fOrg" :disabled="!fProj" @change="load">
        <option v-if="!fProj" value="">请先选择项目</option>
        <option v-else value="">全部部门</option>
        <option v-for="n in deptOptions" :key="n.id" :value="n.id">{{ n.path }}</option>
      </select></label>
      <label class="fld">状态 <select v-model="fStatus" @change="load">
        <option value="">全部</option><option>正式</option><option>试用</option><option>离职</option>
      </select></label>
      <label class="fld">人员分类 <select v-model="fPersonType" @change="load">
        <option value="">全部</option><option value="staff">基层员工</option><option value="manager">管理人员</option><option value="case">案场人员</option>
      </select></label>
      <input type="text" v-model="fKw" placeholder="姓名/职位搜索" @keydown.enter="load" />
      <button class="btn primary" @click="load">查询</button>
      <button class="btn success" @click="syncNow">🔄 立即钉钉同步</button>
    </div>
    <div class="row" style="margin-top:8px">
      <span class="tag blue">已选 {{ sel.size }} 人</span>
      <button class="btn warn" @click="openBulkDeduct">批量附加扣除设置</button>
      <button class="btn warn" @click="openBulkTaxMode">批量个税模式</button>
      <button class="btn" @click="exportFiltered">📤 导出当前筛选</button>
    </div>
    <div v-if="loadErr" class="msg err" style="margin-top:12px">{{ loadErr }}</div>
    <div v-else style="margin-top:12px">
      <div class="row" style="margin-bottom:8px"><span class="tag gray">{{ staff.length }} 人</span></div>
      <div class="table-wrap" style="overflow-x:auto">
        <table class="tb" style="min-width:1500px">
          <thead><tr>
            <th><input type="checkbox" style="width:auto" :checked="allChecked" @change="toggleAll" /></th>
            <th>分类</th><th>姓名</th><th>项目</th><th>部门</th><th>职位</th><th>岗位职级</th><th>薪酬档位</th><th>员工状态</th>
            <th>性别</th><th>学历</th><th>籍贯</th><th>手机号</th>
            <th>固定月薪</th><th>基本工资</th><th>个税模式</th>
            <th>入职时间</th><th>实际转正日期</th><th>离职日期</th><th>银行卡号</th><th>证件号码</th><th>操作</th>
          </tr></thead>
          <tbody>
            <tr v-for="s in staff" :key="s.id">
              <td style="text-align:center"><input type="checkbox" class="stChk" style="width:auto" :checked="sel.has(s.id)" @change="toggle(s.id, $event.target.checked)" /></td>
              <td><span :class="'tag ' + (CAT_TAG[s.category] || 'gray')">{{ s.category || '-' }}</span></td>
              <td><b>{{ s.name }}</b></td><td>{{ s.project }}</td><td>{{ s.dept_path || '未分配' }}</td><td>{{ s.position }}</td>
              <td><span :class="s.person_type === 'case' ? 'tag green' : (s.person_type === 'manager' ? 'tag purple' : (s.person_type === 'hq' ? 'tag blue' : 'tag gray'))">{{ personTypeLabel(s.person_type) }}</span></td>
              <td>
                <span v-if="s.pay_grade" class="tag blue">{{ s.pay_grade }}</span>
                <span v-else-if="s.person_type === 'manager' || s.person_type === 'hq'" class="tag orange" title="钉钉花名册「薪酬档位」未同步，季度绩效将无法核算">未同步</span>
                <span v-else>-</span>
              </td>
              <td><span :class="'tag ' + (STATUS_TAG[s.status] || 'gray')">{{ s.status }}</span></td>
              <td>{{ s.gender || '-' }}</td><td>{{ s.education || '-' }}</td><td>{{ s.hometown || '-' }}</td><td>{{ s.phone || '-' }}</td>
              <td class="num">{{ money(s.fixed_monthly) }}</td><td class="num">{{ money(s.base_salary) }}</td>
              <td><span :class="Number(s.tax_mode ?? 0) === 1 ? 'tag blue' : 'tag gray'">{{ Number(s.tax_mode ?? 0) === 1 ? '6万扣除' : '普通' }}</span></td>
              <td>{{ s.hire_date || '-' }}</td><td>{{ s.regular_date || '-' }}</td><td>{{ s.resign_date || '-' }}</td>
              <td>{{ maskBankCard(s.bank_card) || '-' }}</td>
              <td>{{ maskIdCard(s.id_card) || '-' }}</td>
              <td>
                <button class="btn sm" @click="openDeduct(s)">附加扣除</button>
                <button class="btn sm" @click="openHistory(s)">薪资历史</button>
                <button class="btn sm" @click="openTransfers(s)">调动</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="hint">顶部卡片点击可按分类筛选（在职/离职/黑名单），可再叠加项目、部门、状态、关键字条件后导出。档案状态按入职/离职日期自动判断：有离职日期（≤今天）→离职，否则→在职；黑名单需在编辑时勾选"加入黑名单"标记。档案状态与"人员状态"（正式/试用/离职，用于核算）相互独立。先选择所属项目，再选择该项目下已设立的部门。勾选多人后可批量删除、批量设置附加扣除；工资标准（固定月薪/基本工资）的变更请到"调薪与记录"模块操作，以留存全量调薪历史。导出文件含全部档案字段（含性别/学历/籍贯/联系方式/民族/婚姻/毕业院校/专业/证书/政治面貌/家庭住址等）。</div>
    </div>
  </div>

  <!-- 附加扣除弹窗 -->
  <div v-if="dlg === 'deduct'" class="modal-mask" @mousedown.self="dlg = ''">
    <div class="modal" style="width:640px">
      <h3>个税专项附加扣除 — {{ deductStaff && deductStaff.name }}</h3>
      <div class="msg info">默认年度固定；年中变更时填写"生效月份"，自该月起按新金额扣除。金额为国家标准的月度金额（如子女教育2000元/月/孩）。</div>
      <div class="form-grid">
        <template v-for="(item, i) in DEDUCT_ITEMS" :key="item">
          <label>{{ item }}（元/月）<input type="number" step="0.01" v-model="deductForm[i].amount" /></label>
          <label>　生效月份（空=年初起）<input type="month" v-model="deductForm[i].from_ym" /></label>
        </template>
      </div>
      <div class="row end" style="margin-top:14px">
        <button class="btn" @click="dlg = ''">取消</button>
        <button class="btn primary" @click="saveDeduct">保存</button>
      </div>
    </div>
  </div>

  <!-- 批量附加扣除弹窗 -->
  <div v-if="dlg === 'bulkDeduct'" class="modal-mask" @mousedown.self="dlg = ''">
    <div class="modal" style="width:640px">
      <h3>批量附加扣除设置（{{ sel.size }} 人）</h3>
      <div class="msg info">为所选人员统一设置6项专项附加扣除的月度金额与生效月份。</div>
      <div class="form-grid">
        <template v-for="(item, i) in DEDUCT_ITEMS" :key="item">
          <label>{{ item }}（元/月）<input type="number" step="0.01" v-model="bulkForm[i].amount" /></label>
          <label>　生效月份（空=年初起）<input type="month" v-model="bulkForm[i].from_ym" /></label>
        </template>
      </div>
      <div class="row end" style="margin-top:14px">
        <button class="btn" @click="dlg = ''">取消</button>
        <button class="btn primary" @click="saveBulkDeduct">保存</button>
      </div>
    </div>
  </div>

  <!-- 批量个税模式弹窗 -->
  <div v-if="dlg === 'bulkTax'" class="modal-mask" @mousedown.self="dlg = ''">
    <div class="modal" style="width:520px">
      <h3>批量个税扣除模式设置（{{ sel.size }} 人）</h3>
      <div class="msg info">普通模式：每月按 5000 元累计减除费用（常规预扣）；<br />6万扣除模式：年初一次性按全年 6 万元减除费用扣除，累计收入不超 6 万元的月份不预扣个税（适用于上年度全年收入≤6万且在同一单位的人员，最终以汇算清缴为准）。</div>
      <div class="form-grid">
        <label>扣除模式<select v-model="bulkTaxMode">
          <option value="0">普通模式（每月5000累计）</option>
          <option value="1">6万扣除模式（年初一次性6万）</option>
        </select></label>
      </div>
      <div class="row end" style="margin-top:14px">
        <button class="btn" @click="dlg = ''">取消</button>
        <button class="btn primary" @click="saveBulkTax">保存</button>
      </div>
    </div>
  </div>

  <!-- 薪资历史弹窗 -->
  <div v-if="dlg === 'history'" class="modal-mask" @mousedown.self="dlg = ''">
    <div class="modal" style="width:760px;max-height:82vh;overflow:auto">
      <h3>薪资历史 — {{ historyStaff && historyStaff.name }}（当前：固定{{ money(historyStaff && historyStaff.fixed_monthly) }} / 基本{{ money(historyStaff && historyStaff.base_salary) }}）</h3>
      <table class="tb"><thead><tr><th>生效日期</th><th>类型</th><th>固定月薪</th><th>基本工资</th><th>说明</th></tr></thead>
        <tbody>
          <tr v-for="(h, i) in historyRows" :key="i">
            <td>{{ h.effective_date || '—' }}</td><td>{{ h.type }}</td><td class="num">{{ money(h.fixed_monthly) }}</td><td class="num">{{ money(h.base_salary) }}</td><td>{{ h.note || '' }}</td>
          </tr>
        </tbody>
      </table>
      <template v-if="adjustRows.length">
        <h3 style="margin-top:12px">调薪操作记录</h3>
        <table class="tb"><thead><tr><th>时间</th><th>操作人</th><th>类型</th><th>生效日期</th><th>原固定/基本</th><th>新固定/基本</th><th>增减(固定)</th><th>备注</th></tr></thead>
          <tbody>
            <tr v-for="(a, i) in adjustRows" :key="i">
              <td>{{ a.ts }}</td><td>{{ a.by }}</td><td>{{ a.type }}</td><td>{{ a.effective_date }}</td>
              <td class="num">{{ money(a.old_fixed) }} / {{ money(a.old_base) }}</td><td class="num">{{ money(a.new_fixed) }} / {{ money(a.new_base) }}</td>
              <td class="num">{{ a.delta_fixed >= 0 ? '+' : '' }}{{ money(a.delta_fixed) }}</td><td>{{ a.note }}</td>
            </tr>
          </tbody>
        </table>
      </template>
      <div class="row end" style="margin-top:12px"><button class="btn" @click="dlg = ''">关闭</button></div>
    </div>
  </div>

  <!-- 调动履历弹窗 -->
  <div v-if="dlg === 'transfers'" class="modal-mask" @mousedown.self="dlg = ''">
    <div class="modal" style="width:720px">
      <h3>调动履历 — {{ transferStaff && transferStaff.name }}</h3>
      <div v-if="!transferRows.length" class="hint">暂无调动记录</div>
      <table v-else class="tb"><thead><tr><th>时间</th><th>原部门</th><th>新部门</th><th>原因</th><th>操作人</th></tr></thead>
        <tbody>
          <tr v-for="(l, i) in transferRows" :key="i">
            <td>{{ l.ts || l.change_date || '-' }}</td><td>{{ l.from_path || '—' }}</td><td>{{ l.to_path || '—' }}</td><td>{{ l.reason || '' }}</td><td>{{ l.by_user || '' }}</td>
          </tr>
        </tbody>
      </table>
      <div class="row end" style="margin-top:12px"><button class="btn" @click="dlg = ''">关闭</button></div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'
import { money } from '@/utils/format'
import { dingtalkSyncNow } from '@/utils/dingtalk'
import { maskBankCard, maskIdCard } from './staffLogic'
import { loadOrgTree, orgLeaves, orgProjectOf } from './orgStore'

const auth = useAuthStore()
const CAT_DEFS = [['在职', '#16a34a'], ['离职', '#64748b'], ['黑名单', '#dc2626']]
const CAT_TAG = { '在职': 'green', '离职': 'gray', '黑名单': 'red' }
const STATUS_TAG = { '正式': 'green', '新聘': 'blue', '转正': 'purple', '试用': 'orange', '离职': 'gray' }
const DEDUCT_ITEMS = ['租房租金', '住房贷款利息', '子女教育', '赡养老人', '继续教育', '婴幼儿照护']
const personTypeLabel = (t) => (t === 'case' ? '案场人员' : t === 'manager' ? '管理人员' : t === 'hq' ? '总部人员' : (t ? '基层员工' : '—'))

const cat = ref('在职')
const counts = ref({})
const total = computed(() => Object.values(counts.value).reduce((s, v) => s + (v || 0), 0))
const fProj = ref('')
const fOrg = ref('')
const fStatus = ref('')
const fPersonType = ref('')
const fKw = ref('')
const staff = ref([])
const sel = reactive(new Set())
const loadErr = ref('')
const dlg = ref('')

// 弹窗数据
const deductStaff = ref(null)
const deductForm = ref([])
const bulkForm = ref([])
const bulkTaxMode = ref('0')
const historyStaff = ref(null)
const historyRows = ref([])
const adjustRows = ref([])
const transferStaff = ref(null)
const transferRows = ref([])

const allChecked = computed(() => staff.value.length > 0 && sel.size === staff.value.length)
const leaders = computed(() => new Map(staff.value.filter((x) => x.id).map((x) => [x.id, x.name])))
const leaderName = (s) => (s.leader_id ? leaders.value.get(s.leader_id) || '—' : '—') // 保留：编辑弹窗仍用

// 部门下拉：选了项目只保留该项目下的部门/班组（复刻 fillOrgDeptFilter）
const deptOptions = computed(() => {
  if (!fProj.value) return []
  const seen = new Set()
  const out = []
  for (const n of orgLeaves()) {
    const pn = orgProjectOf(n.id)
    if (!pn || pn.name !== fProj.value) continue
    if (seen.has(n.path)) continue
    seen.add(n.path)
    out.push(n)
  }
  return out
})

function setCat(c) {
  cat.value = c
  load()
}

// 切换项目后，原选中部门若已不属于当前项目则清空
function onProjChange() {
  if (fOrg.value && !deptOptions.value.some((n) => String(n.id) === String(fOrg.value))) fOrg.value = ''
  load()
}

function toggle(id, on) {
  if (on) sel.add(id)
  else sel.delete(id)
}

function toggleAll(e) {
  sel.clear()
  if (e.target.checked) staff.value.forEach((s) => sel.add(s.id))
}

async function load() {
  loadErr.value = ''
  try {
    await loadOrgTree()
    const q = `cat=${encodeURIComponent(cat.value)}&project=${encodeURIComponent(fProj.value)}&status=${encodeURIComponent(fStatus.value)}&person_type=${encodeURIComponent(fPersonType.value)}&kw=${encodeURIComponent(fKw.value)}&org_id=${encodeURIComponent(fOrg.value)}`
    const data = await api('/api/staff?' + q)
    staff.value = data.staff || []
    counts.value = data.counts || {}
  } catch (e) { loadErr.value = e.message }
}

function exportFiltered() {
  const q = `cat=${encodeURIComponent(cat.value)}&project=${encodeURIComponent(fProj.value)}&status=${encodeURIComponent(fStatus.value)}&kw=${encodeURIComponent(fKw.value)}&org_id=${encodeURIComponent(fOrg.value)}`
  download('/api/staff/export?' + q, '人员档案.xlsx')
}

function syncNow() {
  dingtalkSyncNow(load)
}

function newDeductForm(map) {
  return DEDUCT_ITEMS.map((item) => {
    const e = map[item] || { amount: 0, from_ym: '' }
    return { amount: e.amount, from_ym: e.from_ym || '' }
  })
}

function openDeduct(s) {
  deductStaff.value = s
  const list = Array.isArray(s.special_deductions) ? s.special_deductions : []
  const map = {}
  list.forEach((e) => (map[e.item] = e))
  deductForm.value = newDeductForm(map)
  dlg.value = 'deduct'
}

async function saveDeduct() {
  const items = DEDUCT_ITEMS.map((item, i) => ({ item, amount: parseFloat(deductForm.value[i].amount || 0), from_ym: deductForm.value[i].from_ym }))
  try {
    await api('/api/staff/deduct', { body: { staff_id: deductStaff.value.id, items } })
    deductStaff.value.special_deductions = items
    dlg.value = ''
    toast('已保存')
    load()
  } catch (e) { alert(e.message) }
}

function openBulkDeduct() {
  if (!sel.size) return toast('请先勾选人员', false)
  bulkForm.value = newDeductForm({})
  dlg.value = 'bulkDeduct'
}

async function saveBulkDeduct() {
  const items = DEDUCT_ITEMS.map((item, i) => ({ item, amount: parseFloat(bulkForm.value[i].amount || 0), from_ym: bulkForm.value[i].from_ym }))
  try {
    const r = await api('/api/staff/bulk_deduct', { body: { ids: [...sel], items } })
    toast(`已为 ${r.count} 人设置附加扣除`)
    dlg.value = ''
    load()
  } catch (e) { alert(e.message) }
}

function openBulkTaxMode() {
  if (!sel.size) return toast('请先勾选人员', false)
  bulkTaxMode.value = '0'
  dlg.value = 'bulkTax'
}

async function saveBulkTax() {
  const mode = parseInt(bulkTaxMode.value || 0)
  try {
    const r = await api('/api/staff/bulk_tax_mode', { body: { ids: [...sel], mode } })
    toast(`已为 ${r.count} 人设置${mode === 1 ? '6万扣除' : '普通'}模式`)
    dlg.value = ''
    load()
  } catch (e) { alert(e.message) }
}

async function openHistory(s) {
  historyStaff.value = s
  historyRows.value = []
  adjustRows.value = []
  dlg.value = 'history'
  try {
    const data = await api(`/api/staff_history?id=${s.id}`)
    const adj = (await api(`/api/salary_adjusts?id=${s.id}`)).adjusts
    historyRows.value = data.history || []
    adjustRows.value = adj || []
  } catch (e) { alert(e.message) }
}

async function openTransfers(s) {
  transferStaff.value = s
  transferRows.value = []
  dlg.value = 'transfers'
  try {
    const data = await api(`/api/org/transfer-logs?staff_id=${s.id}`)
    transferRows.value = data.logs || []
  } catch (e) { alert(e.message) }
}

onMounted(async () => {
  await auth.refreshProjects() // 人员档案项目下拉始终以最新项目档案为准
  try { await loadOrgTree(true) } catch (e) { /* 组织树不可用时部门筛选为空 */ }
  load()
})
</script>
