<template>
  <div style="padding:4px">
    <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
    <div v-else-if="!p">加载中…</div>
    <template v-else>
      <div class="row" style="margin-bottom:10px">
        <button class="btn sm" @click="$emit('back')">← 返回列表</button>
        <h3 style="margin:0;border:none;padding:0">{{ p.employeeName }}（{{ p.line || '' }}）绩效考核</h3>
        <span :class="`tag ${stCol}`">{{ statusLabel }}</span>
        <button class="btn sm" style="margin-left:auto" @click="exportPlan">📤 导出考核表</button>
      </div>
      <!-- 流程步骤 -->
      <div class="perf-steps">
        <template v-for="(n, i) in PERF_FLOW_NAME" :key="n">
          <div :class="['pf-step', i <= stepIdx ? 'on' : '', i === stepIdx ? 'cur' : '']">
            <span class="pf-dot">{{ i + 1 }}</span><span class="pf-name">{{ n }}</span>
          </div>
          <div v-if="i < PERF_FLOW_NAME.length - 1" class="pf-line"></div>
        </template>
      </div>
      <!-- 单据信息 -->
      <div class="card" style="margin-top:12px">
        <div class="row" style="gap:24px;font-size:13px;color:#475569">
          <span>被考核人：<b>{{ p.employeeName }}</b></span><span>条线：{{ p.line || '-' }}</span><span>项目：{{ p.project || '-' }}</span>
          <span>考核周期：<b>{{ p.periodStart || '-' }} ~ {{ p.periodEnd || '-' }}</b>（第{{ p.quarter || '-' }}季度）</span><span>发起人：{{ p.founderName || '-' }}</span>
          <span>审批链：{{ chainText }}</span>
        </div>
        <div v-if="p.rejectOpinion" class="msg err" style="margin-top:8px">⚠ 最近一次驳回意见：{{ p.rejectOpinion }}</div>
      </div>
      <!-- 状态操作区 -->
      <div v-if="p.status === 'draft' && (isFounder || isAdmin)" class="msg info">
        单据为草稿状态。<button class="btn sm primary" @click="$router.push({ path: '/perfCreate', query: { id: p.id } })">前往编辑/提交</button>
      </div>
      <div v-else-if="p.status === 'confirm' && canActApproval" class="card perf-action">
        <b>① 请核对指标设置与权重，并确认员工提交的考核周期</b>
        <div class="row" style="margin-top:8px;gap:12px;align-items:center;flex-wrap:wrap">
          <span class="tag blue" style="font-size:14px">考核周期：{{ p.periodStart || '-' }} ~ {{ p.periodEnd || '-' }}</span>
          <span class="tag gray">权重合计 {{ perfWeightSum(p) }}</span>
          <span style="font-size:12px;color:#64748b">周期由发起人提交，审核人直接核对确认即可，无需再选择年度/季度。</span>
          <button class="btn success" @click="actConfirm">✔ 确认通过（进入考核周期）</button>
          <button class="btn danger" @click="openReject('reject_confirm')">驳回修改</button>
        </div>
      </div>
      <div v-else-if="p.status === 'ongoing'" class="msg info">
        考核周期进行中（{{ p.periodStart }} ~ {{ p.periodEnd }}），周期结束次日将自动转入数据填报。
        <div v-if="isFounder || isAdmin" class="row" style="margin-top:10px;gap:10px;align-items:center">
          <button class="btn warn" @click="actStartReport">⏭ 提前结束周期，立即转入数据填报</button>
          <span class="hint" style="margin:0">仅管理员/发起人可操作，用于需提前启动填报的特殊情况</span>
        </div>
      </div>
      <div v-else-if="p.status === 'report'" class="card perf-action">
        <b>③ 数据填报阶段</b>：各指标填报人填写实际完成值，全部填齐后自动转入发起人自评。
        <div class="row" style="margin-top:8px;gap:10px;align-items:center;flex-wrap:wrap">
          <template v-if="isFounder || isAdmin">
            <button class="btn warn" @click="actUrge">📢 一键催办未填报人</button>
            <button class="btn primary" @click="actFinishReport">✔ 完成填报，进入发起人自评{{ repPending ? `（还差 ${repPending} 项）` : '' }}</button>
          </template>
          <span v-if="isAdmin" class="hint" style="margin:0">管理员可代填任意项</span>
        </div>
      </div>
      <div v-else-if="p.status === 'self' && canSelf" class="card perf-action">
        <b>④ 发起人自评</b>：系统已按算分方式自动算出参考分，可逐项调整自评分，完成后提交审批。
        <div class="row end" style="margin-top:8px"><button class="btn primary" @click="actSelfSubmit">提交自评，送上级审批</button></div>
      </div>
      <div v-else-if="p.status === 'approve' && canActApproval" class="card perf-action">
        <b>⑤ 逐级审批（第 {{ (p.currentStep || 0) + 1 }} / {{ (p.approvers || []).length }} 级 · 当前：{{ curAp ? curAp.name : '' }}）</b>
        <div class="hint" style="margin:4px 0 0">请逐项填写<b>考核人评分</b>，系统自动按「自评{{ sw.self }}% + 考核人{{ sw.approver }}%」算出每项最终分（在最终分列实时预览），如有个别项需要微调可直接修改最终分。</div>
        <div class="row end" style="margin-top:8px">
          <button class="btn danger" @click="openReject('reject_approve')">驳回</button>
          <button class="btn success" @click="openApprove">✔ {{ (p.currentStep || 0) + 1 >= p.approvers.length ? '终审通过并归档' : '审批通过，送下一级' }}</button>
        </div>
      </div>
      <div v-else-if="p.status === 'done'" class="msg ok">已归档：最终总分 <b>{{ perfFmt(p.finalTotal) }}</b>，考评等级 <b>{{ p.grade || '' }}</b></div>
      <!-- 指标明细 -->
      <div class="card">
        <div class="row" style="margin-bottom:8px">
          <h3 style="margin:0;border:none;padding:0">考核指标明细</h3>
          <span class="tag gray">权重合计 {{ perfWeightSum(p) }}</span>
          <span class="tag blue">自动总分 {{ perfFmt(p.autoTotal) }}</span>
          <span class="tag orange">自评总分 {{ perfFmt(p.selfTotal) }}</span>
          <span class="tag purple">考核人评分 {{ perfFmt(p.approverTotal) }}</span>
          <span class="tag green">最终总分 {{ perfFmt(p.finalTotal) }}{{ p.grade ? ` · ${p.grade}` : '' }}</span>
          <span v-if="meta && meta.scoreWeights" class="hint" style="font-size:11.5px">占比：自评{{ meta.scoreWeights.self }}% + 考核人{{ meta.scoreWeights.approver }}%</span>
        </div>
        <div class="table-wrap" style="max-height:none">
          <table class="tb">
            <thead><tr><th>序号</th><th>指标类别</th><th>指标内容</th><th>定义/扣分规则</th><th>权重</th><th>算分方式/来源/填报人</th><th>完成情况</th><th>自动分</th><th>自评分</th><th>考核人评分</th><th>最终分</th></tr></thead>
            <tbody>
              <template v-for="cat in p.categories || []">
                <tr v-for="(it, ii) in cat.items || []" :key="it.id">
                  <td>{{ seqOf(cat, it) }}</td>
                  <td v-if="ii === 0" :rowspan="(cat.items || []).length" style="vertical-align:top;background:#f8fafc;font-weight:600">{{ cat.name }}</td>
                  <td style="white-space:normal;min-width:150px">{{ it.content }}</td>
                  <td style="white-space:normal;max-width:260px;font-size:11.5px;color:#64748b">{{ it.definition }}</td>
                  <td class="num">{{ perfFmt(it.weight) }}</td>
                  <td style="font-size:11.5px">
                    <span :title="CALC_HELP[it.calcType] || ''" style="cursor:help;border-bottom:1px dashed #94a3b8">{{ CALC_LABEL[it.calcType] || it.calcType }} ⓘ</span>
                    <div class="hint" style="margin:0">{{ it.sourceDept || '' }}<br />填报:{{ it.reporterName || '-' }}{{ it.reporterId ? '' : ' ·未指派→自动跳过' }}</div>
                  </td>
                  <td style="white-space:normal">
                    <template v-if="p.status === 'report' && (isAdmin || myReportItems.includes(it.id))">
                      <template v-if="it.calcType === 'manual'">
                        <input v-model="it._actualText" type="text" style="width:150px" placeholder="完成情况说明（选填）" />
                        <button class="btn sm" @click="actReport(it, isAdmin && !myReportItems.includes(it.id))">提交</button>
                      </template>
                      <template v-else>
                        <input v-model="it._actualValue" type="text" style="width:80px" placeholder="实际值" />
                        <input v-model="it._actualText" type="text" style="width:90px" placeholder="情况说明" />
                        <button class="btn sm" @click="actReport(it, isAdmin && !myReportItems.includes(it.id))">保存</button>
                      </template>
                    </template>
                    <template v-else>{{ it.actualText || it.actualValue || '-' }}</template>
                    <div v-if="it.reportBy" class="hint" style="margin:0;color:#16a34a">✔ 已提交：{{ it.reportBy }}</div>
                  </td>
                  <td class="num">{{ perfFmt(it.autoScore) }}</td>
                  <td class="num">
                    <input v-if="p.status === 'self' && canSelf" v-model.number="it._selfScore" type="number" style="width:64px" step="0.01" />
                    <template v-else>{{ perfFmt(it.selfScore) }}</template>
                  </td>
                  <td class="num">
                    <input v-if="p.status === 'approve' && canActApproval" v-model.number="it._approverScore" type="number" style="width:64px" step="0.01" placeholder="考核分" @input="mixFinalOf(it)" />
                    <template v-else>{{ perfFmt(it.approverScore) }}</template>
                  </td>
                  <td class="num">
                    <input v-if="p.status === 'approve' && canActApproval" v-model.number="it._finalScore" type="number" style="width:64px" step="0.01" placeholder="自动/微调" />
                    <template v-else>{{ perfFmt(it.finalScore) }}</template>
                    <div v-for="(o, oi) in it.overrides || []" :key="oi" class="hint" style="margin:0;color:#c2410c">改:{{ perfFmt(o.from) }}→{{ perfFmt(o.to) }} {{ o.by }}</div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
      <!-- 同项目排名参考 -->
      <div v-if="(p.status === 'approve' || p.status === 'done') && p.project" class="card" style="margin-top:12px">
        <h3>同项目绩效得分排名（已归档，供审批/微调参考）</h3>
        <div style="font-size:12.5px;color:#64748b">
          <div v-if="rankRefErr" class="hint">排名加载失败</div>
          <div v-else-if="!rankRef.length" class="hint">该项目暂无已归档考核</div>
          <div v-else class="table-wrap" style="max-height:240px">
            <table class="tb">
              <thead><tr><th>排名</th><th>员工</th><th>项目</th><th>自评</th><th>考核人</th><th>最终</th><th>等级</th><th></th></tr></thead>
              <tbody>
                <tr v-for="r in rankRef" :key="r.id">
                  <td class="num"><b>{{ r.rank }}</b></td><td>{{ r.employeeName }}</td><td>{{ r.project }}</td>
                  <td class="num">{{ perfFmt(r.selfTotal) }}</td><td class="num">{{ perfFmt(r.approverTotal) }}</td>
                  <td class="num"><b>{{ perfFmt(r.finalTotal) }}</b></td><td><span v-if="r.grade" class="tag purple">{{ r.grade }}</span><template v-else>-</template></td>
                  <td><span v-if="r.employeeName === p.employeeName" class="tag blue">当前单</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <!-- 流程记录 -->
      <div class="card">
        <h3>流程记录</h3>
        <div class="perf-logs">
          <div v-for="(l, li) in logsDesc" :key="li" class="pf-log">
            <span class="pf-log-ts">{{ l.ts }}</span><span class="pf-log-actor">{{ l.actor }}</span>
            <span>{{ l.action }}<template v-if="l.comment">｜<span style="color:#c2410c">{{ l.comment }}</span></template></span>
          </div>
          <span v-if="!logsDesc.length" class="hint">暂无</span>
        </div>
      </div>
      <!-- 驳回弹窗 -->
      <div v-if="modal === 'reject'" class="modal-mask" @click.self="modal = null">
        <div class="modal">
          <h3>驳回（需填写意见）</h3>
          <textarea v-model="rejectOpinion" rows="4" style="width:100%" placeholder="请说明驳回原因，将退回发起人"></textarea>
          <div class="row end" style="margin-top:12px">
            <button class="btn" @click="modal = null">取消</button>
            <button class="btn danger" @click="doReject">确认驳回</button>
          </div>
        </div>
      </div>
      <!-- 审批通过弹窗 -->
      <div v-if="modal === 'approve'" class="modal-mask" @click.self="modal = null">
        <div class="modal">
          <h3>{{ isLastStep ? '终审通过并归档' : '审批通过，送下一级' }}</h3>
          <label style="font-size:13px;color:#595959">审批意见（可留空）</label>
          <textarea v-model="approveOpinion" rows="3" style="width:100%;margin-top:4px" placeholder="通过">通过</textarea>
          <div class="row end" style="margin-top:12px">
            <button class="btn" @click="modal = null">取消</button>
            <button class="btn success" @click="doApprove">确认通过</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'
import { perfFmt, perfWeightSum, mixFinal, perfApId } from './perfLogic'

const PERF_FLOW = ['draft', 'confirm', 'ongoing', 'report', 'self', 'approve', 'done']
const PERF_FLOW_NAME = ['制表', '确认指标', '周期进行', '数据填报', '发起人自评', '逐级审批', '归档']
const PERF_STATUS_LABEL = { draft: '草稿', confirm: '待确认指标', ongoing: '考核进行中', report: '待数据填报', self: '待发起人自评', approve: '待逐级审批', done: '已归档' }
const PERF_STATUS_COLOR = { draft: 'gray', confirm: 'orange', ongoing: 'blue', report: 'orange', self: 'orange', approve: 'purple', done: 'green' }
const CALC_LABEL = { ratio: '比率得分', ladder: '阶梯扣分', count: '数量达标', manual: '人工评分' }
const CALC_HELP = {
  ratio: '比率得分：按完成比例折算得分。得分=权重×(实际值÷目标值)，最高不超过该指标权重、最低0分。',
  ladder: '阶梯扣分：以目标值为基准，每低一个阶梯单位扣指定分数，低于“记0阈值”整项记0分。',
  count: '数量达标：以“应完成数量”为基准，每少完成1个单位扣指定分数。',
  manual: '人工评分：系统不自动计算分数，由发起人自评与考核人逐级评分分别打分。',
}

const props = defineProps({ id: { type: [Number, String], required: true } })
defineEmits(['back'])
const auth = useAuthStore()

const meta = ref(null)
const p = ref(null)
const loadErr = ref('')
const rankRef = ref([])
const rankRefErr = ref(false)
const modal = ref(null)
const rejectEndpoint = ref('')
const rejectOpinion = ref('')
const approveOpinion = ref('通过')

const sw = computed(() => (meta.value && meta.value.scoreWeights) || { self: 50, approver: 50 })
const isAdmin = computed(() => !!(meta.value && meta.value.isAdmin) || auth.user?.role === 'admin')
const meInfo = computed(() => (meta.value && meta.value.me) || {})
const stCol = computed(() => PERF_STATUS_COLOR[p.value.status] || 'gray')
const statusLabel = computed(() => PERF_STATUS_LABEL[p.value.status] || p.value.status)
const stepIdx = computed(() => PERF_FLOW.indexOf(p.value.status))
const curAp = computed(() => (p.value.approvers || [])[p.value.currentStep || 0])
const isApprover = computed(() => {
  const a = curAp.value
  return !!(a && (perfApId(a) === meInfo.value.staffId || perfApId(a) === meInfo.value.userId))
})
const isFounder = computed(() => p.value.founderId === meInfo.value.userId)
const myReportItems = computed(() => {
  const ids = []
  ;(p.value.categories || []).forEach((c) => (c.items || []).forEach((it) => {
    if (it.reporterId === meInfo.value.staffId || it.reporterId === meInfo.value.userId) ids.push(it.id)
  }))
  return ids
})
const canActApproval = computed(() => isApprover.value || isAdmin.value)
const canSelf = computed(() => isFounder.value || isAdmin.value)
const repPending = computed(() => {
  let n = 0
  ;(p.value.categories || []).forEach((c) => (c.items || []).forEach((it) => {
    if (!it.reporterId || it.reportBy) return
    n++
  }))
  return n
})
const isLastStep = computed(() => (p.value.currentStep || 0) + 1 >= p.value.approvers.length)
const chainText = computed(() => (p.value.approvers || []).map((a, i) => `${i + 1}.${a.name}${a.state === 'approved' ? '✔' : a.state === 'current' ? '（待审）' : ''}`).join(' → '))
const logsDesc = computed(() => ((p.value.logs || []).slice().reverse()))

// 编辑输入的临时字段（复刻旧版以 DOM 输入框为数据源的方式）
function withEditFields(plan) {
  (plan.categories || []).forEach((c) => (c.items || []).forEach((it) => {
    it._actualValue = it.actualValue ?? ''
    it._actualText = it.actualText || ''
    it._selfScore = it.selfScore ?? it.autoScore ?? ''
    it._approverScore = it.approverScore ?? ''
    it._finalScore = ''
  }))
  return plan
}

onMounted(fetchPlan)

async function fetchPlan() {
  try {
    meta.value = await api(`/api/performance/plans/${props.id}`)
    p.value = withEditFields(JSON.parse(JSON.stringify(meta.value.plan)))
    if (p.value.status === 'approve' || p.value.status === 'done') loadRankRef()
    else { rankRef.value = []; rankRefErr.value = false }
  } catch (e) {
    loadErr.value = e.message
  }
}

function seqOf(cat, it) {
  // 按类别×指标顺序生成全局序号（与旧版 seq++ 一致）
  let seq = 1
  for (const c of p.value.categories || []) {
    for (const i2 of c.items || []) {
      if (c === cat && i2 === it) return seq
      seq++
    }
  }
  return seq
}

async function loadRankRef() {
  rankRefErr.value = false
  try {
    const d = await api(`/api/performance/ranking?project=${encodeURIComponent(p.value.project || '')}&quarter=0`)
    rankRef.value = (d.items || []).slice(0, 20)
  } catch (e) { rankRefErr.value = true }
}

// 审批时：填考核人评分自动按占比算该项最终分预览
function mixFinalOf(it) {
  const f = mixFinal(it.selfScore, it._approverScore, sw.value)
  it._finalScore = f === null ? '' : f
}

function reload() { loadErr.value = ''; p.value = null; fetchPlan() }

async function actConfirm() {
  try { await api('/api/performance/confirm', { body: { id: p.value.id } }); toast('已确认，进入考核周期'); reload() } catch (e) { alert(e.message) }
}
async function actStartReport() {
  if (!confirm('确认提前结束考核周期并立即转入数据填报？\n转入后，各指标填报人即可在「待我填报」中填写实际完成值。')) return
  try { await api('/api/performance/start_report', { body: { id: p.value.id } }); toast('已转入数据填报'); reload() } catch (e) { alert(e.message) }
}
async function actFinishReport() {
  const pending = repPending.value
  if (pending > 0 && !confirm(`还有 ${pending} 项指标未填写实际值，确认现在就结束数据填报、进入发起人自评吗？`)) return
  try { await api('/api/performance/finish_report', { body: { id: p.value.id } }); toast(pending ? '已提前进入发起人自评' : '全部填齐，已进入发起人自评'); reload() } catch (e) { alert(e.message) }
}
function openReject(endpoint) {
  rejectEndpoint.value = endpoint
  rejectOpinion.value = ''
  modal.value = 'reject'
}
async function doReject() {
  const opinion = rejectOpinion.value.trim()
  if (!opinion) { alert('请填写驳回意见'); return }
  try {
    await api('/api/performance/' + rejectEndpoint.value, { body: { id: p.value.id, opinion } })
    modal.value = null; toast('已驳回', false); reload()
  } catch (e) { alert(e.message) }
}
async function actReport(it, adminFill) {
  try {
    const r = await api(adminFill ? '/api/performance/admin_fill' : '/api/performance/report', {
      body: { id: p.value.id, itemId: it.id, actualValue: it._actualValue, actualText: it._actualText },
    })
    toast('已提交' + (r.status === 'self' ? '，全部指标填报完成，已转发起人自评' : ''))
    reload()
  } catch (e) { alert(e.message) }
}
async function actUrge() {
  try {
    const r = await api('/api/performance/urge', { body: { id: p.value.id } })
    if (!r.pending.length) toast('已无待填报项')
    else toast('已催办：' + r.pending.map((x) => x.name).join('、'))
  } catch (e) { alert(e.message) }
}
async function actSelfSubmit() {
  const items = []
  for (const c of p.value.categories) for (const it of c.items) {
    if (it._selfScore !== undefined && it._selfScore !== null && it._selfScore !== '') items.push({ id: it.id, selfScore: it._selfScore })
  }
  try { await api('/api/performance/self_submit', { body: { id: p.value.id, items } }); toast('自评已提交，进入审批'); reload() } catch (e) { alert(e.message) }
}
function openApprove() { approveOpinion.value = '通过'; modal.value = 'approve' }
async function doApprove() {
  const items = []
  for (const c of p.value.categories) for (const it of c.items) {
    const item = { id: it.id }
    if (it._approverScore !== '' && it._approverScore != null) item.approverScore = it._approverScore
    if (it._finalScore !== '' && it._finalScore != null) item.finalScore = it._finalScore
    items.push(item)
  }
  const opinion = (approveOpinion.value || '通过').trim()
  modal.value = null
  try {
    const r = await api('/api/performance/approve', { body: { id: p.value.id, items, opinion } })
    toast(r.status === 'done' ? `终审通过，等级：${r.grade}` : '已通过，送下一级')
    reload()
  } catch (e) { alert(e.message) }
}
function exportPlan() {
  download(`/api/performance/export/${p.value.id}`, '绩效考核表.xlsx')
}
</script>
