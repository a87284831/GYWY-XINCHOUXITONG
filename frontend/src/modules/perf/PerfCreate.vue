<template>
  <div class="card">
    <h3>{{ isEdit ? '编辑考核单' : '发起绩效考核' }}</h3>
    <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
    <div v-else-if="!draft">加载中…</div>
    <template v-else>
      <!-- 头部：被考核人 / 条线 / 项目 -->
      <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);border-radius:10px;padding:16px 20px;color:#fff;display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;margin-bottom:14px">
        <div style="flex:1;min-width:180px">
          <div style="font-size:12px;opacity:.85;margin-bottom:4px">被考核员工</div>
          <template v-if="isAdmin">
            <select v-model="employeeSel" style="min-width:220px;border-radius:6px;padding:6px 8px;border:none" @change="pickEmployee">
              <option value="">选择被考核员工…</option>
              <option v-for="s in staff" :key="s.id" :value="String(s.id)">{{ s.name }}｜{{ s.project }}｜{{ s.position || '' }}</option>
            </select>
          </template>
          <template v-else>
            <div style="font-size:16px;font-weight:700">{{ meStaff ? meStaff.name + '（本人，发起人自动锁定为被考核人）' : '未绑定人员档案，请联系管理员' }}</div>
          </template>
        </div>
        <label style="color:#fff;font-size:12px">条线/岗位<input v-model="draft.line" type="text" placeholder="如：品宣条线" style="margin-top:4px;border:none;border-radius:6px;padding:6px 8px" /></label>
        <label style="color:#fff;font-size:12px">所属项目<input v-model="draft.project" type="text" style="margin-top:4px;border:none;border-radius:6px;padding:6px 8px" /></label>
      </div>
      <!-- 考核周期 -->
      <div class="row" style="margin-bottom:10px">
        <label class="fld">本次考核周期 · 开始日期 <input v-model="draft.periodStart" type="date" /></label>
        <label class="fld">结束日期 <input v-model="draft.periodEnd" type="date" /></label>
        <span class="hint" style="margin:0">自定义起止，同一员工在同一时间段内不可重复发起。</span>
      </div>
      <!-- 权重合计大条 -->
      <div :style="{ display: 'flex', alignItems: 'center', gap: '18px', padding: '18px 24px', margin: '8px 0 16px', borderRadius: '12px', border: '2px solid', boxShadow: '0 4px 14px rgba(15,23,42,.18)', background: weightOk ? '#ecfdf5' : '#fef2f2', borderColor: weightOk ? '#86efac' : '#fca5a5' }">
        <div style="font-size:16px;font-weight:800;color:#ffffff;letter-spacing:1px">全部指标权重合计</div>
        <div :style="{ fontSize: '42px', fontWeight: 900, lineHeight: 1, letterSpacing: '1px', fontVariantNumeric: 'tabular-nums', color: weightOk ? '#16a34a' : '#dc2626' }">{{ weightSum }}</div>
        <div style="font-size:20px;font-weight:800;color:#cbd5e1">/ 100 分</div>
        <span :class="weightOk ? 'tag green' : 'tag red'" style="font-size:14px;font-weight:700;padding:6px 14px;border-radius:999px">{{ weightTag }}</span>
        <div style="margin-left:auto;font-size:12px;color:#cbd5e1">提交前权重合计必须=100，否则无法提交</div>
      </div>
      <!-- 指标类别 -->
      <div v-for="(cat, ci) in draft.categories" :key="ci" class="perf-cat">
        <div class="row" style="margin-bottom:8px">
          <b>指标类别</b>
          <input v-model="cat.name" type="text" style="width:150px" />
          <span class="hint" style="margin:0">类别权重 {{ catWeight(cat) }} 分（自动汇总，仅供参考）</span>
          <button class="btn sm danger" style="margin-left:auto" @click="delCat(ci)">删除类别</button>
        </div>
        <div class="table-wrap" style="max-height:none">
          <table class="tb">
            <thead><tr><th style="min-width:170px">指标内容</th><th style="min-width:230px">指标定义与扣分规则</th><th>完成时间</th><th>权重(满分)</th><th>来源部门</th><th>数据填报/核查人</th><th style="min-width:200px">算分方式与参数</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(it, ii) in cat.items" :key="it.id">
                <td><input v-model="it.content" type="text" style="width:160px" /></td>
                <td><textarea v-model="it.definition" rows="2" style="width:220px"></textarea></td>
                <td><input v-model="it.finishTime" type="text" style="width:84px" /></td>
                <td><input :value="it.weight ?? ''" type="number" style="width:64px" @input="it.weight = $event.target.value" /></td>
                <td><input v-model="it.sourceDept" type="text" style="width:104px" /></td>
                <td>
                  <select :value="it.reporterId ?? ''" style="max-width:150px" :title="it.calcType === 'check' ? '核查定分项：由核查人在填报阶段直接定分，可以选被考核人本人' : '未选择填报人的指标在数据填报阶段自动跳过，无需填报'" @change="pickReporter(it, $event.target.value)">
                    <option value="">{{ it.calcType === 'check' ? '选择核查人…' : '选择填报人…' }}</option>
                    <option v-for="s in staffOpts" :key="s.id" :value="String(s.id)" :disabled="!s.hasAccount">{{ s.label }}</option>
                  </select>
                  <div v-if="it.calcType === 'check'" class="hint" style="margin:2px 0 0;color:#c2410c;font-weight:600">核查定分：由该核查人直接定 0~{{ it.weight || '权重' }} 分，可指定本人</div>
                  <div v-else-if="!it.reporterId" class="hint" style="margin:0;color:#c2410c">未选择填报人，填报阶段自动跳过</div>
                </td>
                <td>
                  <select v-model="it.calcType" style="margin-bottom:4px" @change="changeCalc(it)">
                    <option v-for="(lbl, k) in CALC_LABEL" :key="k" :value="k">{{ lbl }}</option>
                  </select>
                  <div style="font-size:11.5px;color:#64748b">
                    <template v-if="it.calcType === 'ratio'">目标值 <input v-model.number="it.calcParams.target" type="number" style="width:78px" placeholder="100" title="达到该值得满分" /></template>
                    <template v-else-if="it.calcType === 'ladder'">目标 <input v-model.number="it.calcParams.target" type="number" style="width:78px" placeholder="100" title="目标值" /> 每差 <input v-model.number="it.calcParams.stepUnit" type="number" style="width:78px" placeholder="1" title="单位" /> 扣 <input v-model.number="it.calcParams.stepDeduct" type="number" style="width:78px" placeholder="5" title="分" /> 低于 <input v-model.number="it.calcParams.zeroThreshold" type="number" style="width:78px" placeholder="80" title="记0" /> 记0</template>
                    <template v-else-if="it.calcType === 'count'">应完成 <input v-model.number="it.calcParams.required" type="number" style="width:78px" placeholder="6" title="数量" /> 每少1扣 <input v-model.number="it.calcParams.deductEach" type="number" style="width:78px" placeholder="1" title="分" /></template>
                    <template v-else-if="it.calcType === 'check'"><span class="tag" style="background:#fff7ed;color:#c2410c;border:1px solid #fdba74">核查人定分，无算分参数</span></template>
                    <template v-else><span class="tag gray">主观评分，无算分参数</span></template>
                  </div>
                  <div class="hint" style="margin:4px 0 0;color:#475569;line-height:1.45">💡 {{ CALC_HELP[it.calcType] || '' }}</div>
                </td>
                <td><button class="btn sm danger" @click="delItem(ci, ii)">删</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <button class="btn sm" style="margin-top:6px" @click="addItem(ci)">＋ 添加指标项</button>
      </div>
      <div class="row" style="margin:10px 0">
        <button class="btn" @click="addCat">＋ 添加指标类别</button>
        <span class="hint" style="margin:0">每项权重即该项满分；上方实时显示全部指标权重合计。</span>
      </div>
      <!-- 多级审批人 -->
      <div class="card" style="background:#f8fafc;box-shadow:none">
        <h3 style="border-left-color:#16a34a">多级审批人（按顺序逐级审批，选择的是“人员”）</h3>
        <div class="row">
          <select v-model="approverSel" style="min-width:320px">
            <option value="">选择审批人员…</option>
            <option v-for="s in staffOpts" :key="s.id" :value="String(s.id)" :disabled="!s.hasAccount">{{ s.label }}</option>
          </select>
          <button class="btn success sm" @click="addApprover">＋ 添加为审批人</button>
        </div>
        <div class="hint" style="margin:6px 0 0">只能选择「已在人事档案中且已绑定启用账号」的人员作为审批人；未绑定账号的人员已置灰不可选（判定顺序：先查档案是否存在，再查是否绑定账号）。</div>
        <div v-if="leaderTip" class="msg" :class="leaderTip.hasAccount ? 'ok' : 'err'" style="margin:4px 0">{{ leaderTip.text }}</div>
        <div style="margin-top:10px">
          <span v-if="!draft.approvers.length" class="hint">尚未添加审批人</span>
          <span v-for="(a, i) in draft.approvers" :key="i" class="tag blue" style="font-size:12.5px;padding:5px 10px;margin:3px">
            {{ i + 1 }}级 · {{ a.name }}
            <button class="btn sm" style="padding:0 6px;margin-left:5px" @click="moveApprover(i, -1)">↑</button>
            <button class="btn sm" style="padding:0 6px" @click="moveApprover(i, 1)">↓</button>
            <button class="btn sm danger" style="padding:0 6px" @click="delApprover(i)">×</button>
          </span>
        </div>
      </div>
      <div class="row end" style="margin-top:16px">
        <button class="btn" @click="$router.push('/perfMine')">返回</button>
        <button class="btn" @click="save(false)">💾 保存草稿</button>
        <button class="btn primary" @click="save(true)">✅ 提交（进入上级确认）</button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'
import { perfNum, perfWeightSum, defaultPeriod, defaultCalcParams, CALC_LABEL, CALC_HELP } from './perfLogic'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const isEdit = computed(() => !!route.query.id)

const users = ref([])
const staff = ref([])
const me = ref({})
const draft = ref(null)
const loadErr = ref('')
const employeeSel = ref('')
const approverSel = ref('')
const leaderTip = ref(null)

const isAdmin = computed(() => !!(me.value.isAdmin || me.value.role === 'admin' || auth.user?.role === 'admin'))
const meStaff = computed(() => staff.value.find((x) => String(x.id) === String(me.value.staffId)))

// 选人下拉：有启用账号的员工可选；label 复刻 perfStaffOpts
const staffOpts = computed(() => {
  const accStaff = new Set((users.value || []).filter((u) => u.enabled !== false && u.staffId).map((u) => String(u.staffId)))
  return (staff.value || []).map((s) => ({
    id: s.id,
    hasAccount: accStaff.has(String(s.id)),
    label: `${s.name}｜${s.project}｜${s.position || ''}${accStaff.has(String(s.id)) ? '' : '（未绑定账号，不可选）'}`,
  }))
})

const weightSum = computed(() => (draft.value ? perfWeightSum(draft.value) : 0))
const weightOk = computed(() => Math.abs(weightSum.value - 100) < 0.01)
const weightTag = computed(() => {
  const s = weightSum.value
  return weightOk.value ? '✓ 合计正确（=100）' : s < 100 ? `还差 ${Math.round((100 - s) * 100) / 100} 分` : `超出 ${Math.round((s - 100) * 100) / 100} 分`
})

function catWeight(cat) {
  return (cat.items || []).reduce((s, it) => s + (perfNum(it.weight) || 0), 0).toFixed(1)
}

onMounted(async () => {
  try {
    const [u, s] = await Promise.all([api('/api/performance/user_options'), api('/api/staff?cat=在职')])
    users.value = u.users || []
    staff.value = (s.staff || []).filter((x) => !x.deleted)
    const found = users.value.find((x) => String(x.username) === String(auth.user?.username))
    me.value = found || { staffId: null, role: auth.user?.role }
    if (route.query.id) {
      const d = await api(`/api/performance/plans/${route.query.id}`)
      draft.value = JSON.parse(JSON.stringify(d.plan))
      me.value = Object.assign(me.value, { isAdmin: d.isAdmin })
      employeeSel.value = draft.value.employeeId != null ? String(draft.value.employeeId) : ''
    } else {
      draft.value = { id: null, employeeId: null, employeeName: '', line: '', project: '', periodStart: '', periodEnd: '',
        categories: [{ name: '经营指标', catWeight: '', items: [] }, { name: '管理指标', catWeight: '', items: [] }], approvers: [] }
    }
    if (!draft.value.periodStart || !draft.value.periodEnd) {
      const dp = defaultPeriod(new Date())
      draft.value.periodStart = dp.start
      draft.value.periodEnd = dp.end
    }
  } catch (e) {
    loadErr.value = e.message
  }
})

// 选择被考核员工：回填条线/项目 + 直属上级自动带审批链
async function pickEmployee() {
  const d = draft.value
  const s = staff.value.find((x) => String(x.id) === String(employeeSel.value))
  d.employeeId = employeeSel.value ? Number(employeeSel.value) : null
  d.employeeName = s ? s.name : ''
  leaderTip.value = null
  if (s) {
    d.line = s.position || d.line || ''
    d.project = s.project || ''
    try {
      const r = await api('/api/performance/leader_approver?staff_id=' + s.id)
      if (r.leader) {
        if (!d.approvers.some((a) => Number(a.staffId) === Number(r.leader.staffId))) {
          d.approvers.push({ staffId: Number(r.leader.staffId), name: r.leader.name })
        }
        leaderTip.value = {
          hasAccount: r.leader.hasAccount,
          text: r.leader.hasAccount
            ? `已自动带出直属上级「${r.leader.name}」为审批人，可手动增删调整。`
            : `已带出直属上级「${r.leader.name}」，但其暂无启用账号，需先在权限页为其绑定账号才能登录审批。`,
        }
      }
    } catch (e) { /* 忽略，复刻旧 catch(() => {}) */ }
  }
}

function addCat() { draft.value.categories.push({ name: '新类别', catWeight: '', items: [] }) }
function delCat(ci) { draft.value.categories.splice(ci, 1) }
function addItem(ci) {
  draft.value.categories[ci].items.push({ id: 'i' + Date.now() + Math.floor(Math.random() * 99), content: '', definition: '', finishTime: '每季度末',
    weight: '', sourceDept: '', reporterId: null, reporterName: '', calcType: 'ratio', calcParams: { target: 100 } })
}
function delItem(ci, ii) { draft.value.categories[ci].items.splice(ii, 1) }
function pickReporter(it, val) {
  it.reporterId = val ? Number(val) : null
  const s = staff.value.find((x) => String(x.id) === String(val))
  it.reporterName = s ? s.name : ''
}
function changeCalc(it) {
  it.calcParams = defaultCalcParams(it.calcType)
}
function addApprover() {
  const sid = Number(approverSel.value)
  if (!sid) return
  if (draft.value.approvers.some((a) => perfApIdOf(a) === sid)) { toast('该审批人已添加', false); return }
  const s = staff.value.find((x) => String(x.id) === String(sid))
  draft.value.approvers.push({ staffId: sid, name: s ? s.name : '' })
  approverSel.value = ''
}
function perfApIdOf(a) { return a ? (a.staffId != null ? Number(a.staffId) : Number(a.userId ?? 0)) : 0 }
function delApprover(i) { draft.value.approvers.splice(i, 1) }
function moveApprover(i, d) {
  const a = draft.value.approvers, j = i + d
  if (j < 0 || j >= a.length) return
  ;[a[i], a[j]] = [a[j], a[i]]
}

async function save(submit) {
  const d = draft.value
  if (!isAdmin.value && me.value && me.value.staffId) d.employeeId = Number(me.value.staffId)
  if (!d.employeeId) { alert('请选择被考核员工'); return }
  if (!d.periodStart || !d.periodEnd) { alert('请选择本次考核周期的开始与结束日期'); return }
  if (d.periodEnd < d.periodStart) { alert('考核周期结束日期不能早于开始日期'); return }
  if (!d.categories.some((c) => c.items.length)) { alert('请至少添加一个指标项'); return }
  for (const c of d.categories) for (const it of c.items) { if (!it.content) { alert('存在未填指标内容的行'); return } }
  if (!d.approvers.length) { alert('请至少添加一级审批人'); return }
  const accStaff = new Set((users.value || []).filter((u) => u.enabled !== false && u.staffId).map((u) => String(u.staffId)))
  for (const a of d.approvers) { if (!accStaff.has(String(a.staffId))) { alert('审批人「' + (a.name || '') + '」未绑定启用账号，无法执行审批，请先为其开通账号'); return } }
  const ws = perfWeightSum(d)
  if (submit && Math.abs(ws - 100) > 0.01) { alert(`权重合计须为100，当前${ws}`); return }
  try {
    await api('/api/performance/plans/save', { body: { ...d, submit } })
    toast(submit ? '已提交，等待上级确认' : '草稿已保存')
    router.push('/perfMine')
  } catch (e) { alert(e.message) }
}
</script>
