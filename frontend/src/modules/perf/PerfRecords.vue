<template>
  <PerfDetail v-if="detailId" :id="detailId" @back="detailId = null" />
  <div v-else class="card">
    <div class="row" style="justify-content:space-between">
      <h3 style="margin:0;border:none;padding:0">考核记录管理</h3>
      <div class="row">
        <button class="btn sm" @click="gradeSettings">⚙️ 考评等级与占比设置</button>
        <button class="btn sm" @click="load">刷新</button>
      </div>
    </div>
    <div style="margin-top:12px">
      <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
      <div v-else-if="loading" class="msg info">加载中…</div>
      <template v-else>
        <!-- 状态统计卡片 -->
        <div class="row" style="gap:10px;flex-wrap:wrap;margin-bottom:10px">
          <div v-for="c in statCards" :key="c[0]" style="flex:1;min-width:96px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.05)">
            <div :style="{ fontSize: '26px', fontWeight: 900, color: c[2], fontVariantNumeric: 'tabular-nums' }">{{ c[1] }}</div>
            <div style="font-size:12px;color:#64748b;margin-top:2px">{{ c[0] }}</div>
          </div>
        </div>
        <!-- Tab + 筛选 -->
        <div class="row" style="gap:8px;margin:10px 0;flex-wrap:wrap;align-items:center">
          <button v-for="t in tabs" :key="t[0]" :class="['btn', 'sm', tab === t[0] ? 'primary' : '']" @click="tab = t[0]">{{ t[1] }}</button>
          <span style="flex:1"></span>
          <label style="font-size:13px">季度 <select v-model.number="quarter"><option :value="0">全部季度</option><option v-for="q in 4" :key="q" :value="q">第{{ q }}季度</option></select></label>
          <label style="font-size:13px">项目 <select v-model="project" style="max-width:170px"><option value="">全部项目</option><option v-for="x in projectOpts" :key="x" :value="x">{{ x }}</option></select></label>
          <input v-model="kwInput" type="text" placeholder="按姓名/条线/项目搜索…" style="width:170px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px" />
          <button class="btn sm" @click="adminExport">📤 批量导出</button>
        </div>
        <!-- 排名 Tab -->
        <div v-if="tab === 'rank'">
          <div v-if="rankErr" class="msg err">{{ rankErr }}</div>
          <div v-else-if="rankLoading" class="msg info">排名加载中…</div>
          <div v-else-if="!rankItems.length" class="msg info">暂无已归档考核{{ project ? `（项目：${project}）` : '' }}</div>
          <div v-else class="table-wrap" style="max-height:calc(100vh - 330px)">
            <table class="tb">
              <thead><tr><th>排名</th><th>被考核人</th><th>考核周期</th><th>自评总分</th><th>考核人评分</th><th>最终总分</th><th>等级</th><th>操作</th></tr></thead>
              <tbody>
                <tr v-for="r in rankItems" :key="r.id">
                  <td class="num"><b>{{ r.rank }}</b></td>
                  <td>{{ r.employeeName }}<div class="hint" style="margin:0">{{ r.project || '' }} {{ r.line || '' }}</div></td>
                  <td style="white-space:nowrap">{{ r.periodStart || '' }} ~ {{ r.periodEnd || '' }}</td>
                  <td class="num">{{ perfFmt(r.selfTotal) }}</td><td class="num">{{ perfFmt(r.approverTotal) }}</td>
                  <td class="num"><b>{{ perfFmt(r.finalTotal) }}</b></td>
                  <td><span v-if="r.grade" class="tag purple">{{ r.grade }}</span><template v-else>-</template></td>
                  <td><button class="btn sm" @click="detailId = r.id">查看</button></td>
                </tr>
              </tbody>
            </table>
            <div class="hint" style="margin-top:6px">按最终总分从高到低排列{{ project ? `（项目：${project}）` : '' }}，共 {{ rankItems.length }} 人</div>
          </div>
        </div>
        <!-- 进行中 / 已完结列表 -->
        <template v-else>
          <div v-if="!filteredList.length" class="msg info">暂无符合条件的考核单</div>
          <div v-else class="table-wrap" style="max-height:calc(100vh - 330px)">
            <table class="tb">
              <thead><tr><th>单号</th><th>被考核人</th><th>考核周期</th><th>员工状态</th><th>权重</th><th>自评总分</th><th>考核人评分</th><th>最终总分</th><th>等级</th><th>审批链</th><th>发起人</th><th>操作</th></tr></thead>
              <tbody>
                <tr v-for="p in filteredList" :key="p.id">
                  <td>#{{ p.id }}</td>
                  <td>{{ p.employeeName }}<div class="hint" style="margin:0">{{ p.project || '' }} {{ p.line || '' }}</div></td>
                  <td style="white-space:nowrap">{{ p.periodStart || '' }} ~ {{ p.periodEnd || '' }}</td>
                  <td>
                    <span :class="`tag ${STATUS_COLOR[p.status] || 'gray'}`">{{ STATUS_LABEL[p.status] || p.status }}</span>
                    <div v-if="p.rejectOpinion" class="hint" style="margin:0;color:#c2410c" :title="p.rejectOpinion">⚠ 有驳回</div>
                  </td>
                  <td class="num">{{ perfFmt(p.weightSum) }}</td>
                  <td class="num">{{ perfFmt(p.selfTotal) }}</td>
                  <td class="num">{{ perfFmt(p.approverTotal) }}</td>
                  <td class="num"><b>{{ perfFmt(p.finalTotal) }}</b></td>
                  <td><span v-if="p.grade" class="tag purple">{{ p.grade }}</span><template v-else>-</template></td>
                  <td style="font-size:11.5px;min-width:120px">{{ chainOf(p) || '-' }}</td>
                  <td>{{ p.founderName || '-' }}</td>
                  <td style="white-space:nowrap">
                    <button class="btn sm" @click="detailId = p.id">查看</button>
                    <button v-if="p.status === 'done'" class="btn sm" @click="exportPlan(p.id)">导出</button>
                    <button v-if="p.status !== 'draft'" class="btn sm danger" @click="delPlan(p.id)">删除</button>
                  </td>
                </tr>
              </tbody>
            </table>
            <div class="hint" style="margin-top:6px">共 {{ filteredList.length }} 条记录</div>
          </div>
        </template>
      </template>
    </div>
    <!-- 考评等级与占比设置弹窗 -->
    <div v-if="gradeModal" class="modal-mask" @click.self="gradeModal = false">
      <div class="modal" style="width:620px">
        <h3>考评等级与评分占比设置</h3>
        <div style="margin:8px 0 12px;padding:12px 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px">
          <b style="font-size:14px">最终总分 = 自评总分 × 自评占比 + 考核人评分总分 × 考核人占比</b>
          <div class="row" style="margin-top:8px;gap:16px">
            <label style="font-size:13px">自评占比 % <input v-model.number="wSelf" type="number" min="0" max="100" style="width:70px" /></label>
            <label style="font-size:13px">考核人评分占比 % <input v-model.number="wAppr" type="number" min="0" max="100" style="width:70px" /></label>
            <span class="hint">两者合计必须等于 100</span>
          </div>
        </div>
        <div class="row"><b style="font-size:14px">考评等级分数线</b></div>
        <div style="margin-top:6px">
          <div v-for="(r, i) in gradeRules" :key="i" class="row" style="margin-bottom:6px">
            ≥ <input v-model.number="r.min" type="number" style="width:90px" /> 分
            等级名 <input v-model="r.label" type="text" style="width:140px" />
            <button class="btn sm danger" @click="gradeRules.splice(i, 1)">删</button>
          </div>
        </div>
        <div class="row" style="margin-top:8px"><button class="btn sm" @click="gradeRules.push({ min: 0, label: '' })">＋ 增加等级</button></div>
        <div class="hint">按最低分数线从高到低匹配，如 ≥90=优秀、80~89.99=良好。最终总分落在哪个区间即取对应等级。</div>
        <div class="row end" style="margin-top:12px">
          <button class="btn" @click="gradeModal = false">取消</button>
          <button class="btn primary" @click="saveGrade">保存设置</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import { perfFmt } from './perfLogic'
import PerfDetail from './PerfDetail.vue'

const STATUS_LABEL = { draft: '草稿', confirm: '待确认指标', ongoing: '考核进行中', report: '待数据填报', self: '待发起人自评', approve: '待逐级审批', done: '已归档' }
const STATUS_COLOR = { draft: 'gray', confirm: 'orange', ongoing: 'blue', report: 'orange', self: 'orange', approve: 'purple', done: 'green' }

const plans = ref([])
const loading = ref(true)
const loadErr = ref('')
const detailId = ref(null)
const tab = ref('ongoing')
const quarter = ref(0)
const project = ref('')
const kwInput = ref('')
const kw = ref('')
const rankItems = ref([])
const rankLoading = ref(false)
const rankErr = ref('')
// 等级设置
const gradeModal = ref(false)
const gradeRules = ref([])
const wSelf = ref(50)
const wAppr = ref(50)

let kwTimer = null
watch(kwInput, () => {
  clearTimeout(kwTimer)
  kwTimer = setTimeout(() => { kw.value = kwInput.value }, 300)
})

const doneCnt = computed(() => plans.value.filter((p) => p.status === 'done').length)
const statCards = computed(() => {
  const cnt = (s) => plans.value.filter((p) => p.status === s).length
  return [
    ['草稿', cnt('draft'), '#64748b'], ['待确认指标', cnt('confirm'), '#f59e0b'], ['考核进行中', cnt('ongoing'), '#3b82f6'],
    ['待数据填报', cnt('report'), '#f97316'], ['待发起人自评', cnt('self'), '#ef4444'], ['待逐级审批', cnt('approve'), '#8b5cf6'],
    ['已归档', doneCnt.value, '#16a34a'],
  ]
})
const projectOpts = computed(() => [...new Set(plans.value.map((p) => p.project).filter(Boolean))].sort())
const tabs = computed(() => [['ongoing', `进行中（${plans.value.length - doneCnt.value}）`], ['done', `已完结（${doneCnt.value}）`], ['rank', '项目得分排名']])

const filteredList = computed(() => plans.value.filter((p) => {
  if (tab.value === 'ongoing' && p.status === 'done') return false
  if (tab.value === 'done' && p.status !== 'done') return false
  if (quarter.value > 0 && (p.quarter || 0) !== Number(quarter.value)) return false
  if (kw.value && !((p.employeeName || '').includes(kw.value) || (p.line || '').includes(kw.value) || (p.project || '').includes(kw.value))) return false
  if (project.value && p.project !== project.value) return false
  return true
}).sort((a, b) => (b.id || 0) - (a.id || 0)))

onMounted(load)
async function load() {
  loading.value = true
  loadErr.value = ''
  try {
    const d = await api('/api/performance/plans?scope=all')
    plans.value = d.plans || []
  } catch (e) {
    loadErr.value = e.message
  }
  loading.value = false
}

watch(tab, (t) => { if (t === 'rank') loadRanking() })
watch([quarter, project], () => { if (tab.value === 'rank') loadRanking() })
watch(kw, () => { if (tab.value === 'rank') loadRanking() })

async function loadRanking() {
  rankLoading.value = true
  rankErr.value = ''
  try {
    const d = await api(`/api/performance/ranking?project=${encodeURIComponent(project.value || '')}&quarter=${Number(quarter.value) || 0}`)
    rankItems.value = d.items || []
  } catch (e) {
    rankErr.value = e.message
  }
  rankLoading.value = false
}

function chainOf(p) {
  return (p.approvers || []).map((a, i) => `${i + 1}.${a.name}${a.state === 'approved' ? '✔' : a.state === 'current' ? '（待审）' : ''}`).join(' ')
}
function exportPlan(id) {
  download(`/api/performance/export/${id}`, '绩效考核表.xlsx')
}
function adminExport() {
  download(`/api/performance/export_all?tab=${tab.value === 'rank' ? 'all' : tab.value}&quarter=${Number(quarter.value) || 0}&kw=${encodeURIComponent(kw.value || '')}&project=${encodeURIComponent(project.value || '')}`, '考核记录导出.xlsx')
}
async function delPlan(id) {
  if (!confirm('确认删除该考核单？此操作不可恢复。')) return
  try { await api('/api/performance/delete', { body: { id } }); toast('已删除'); load() } catch (e) { alert(e.message) }
}

async function gradeSettings() {
  try {
    const d = await api('/api/performance/grade_rules')
    gradeRules.value = JSON.parse(JSON.stringify(d.gradeRules || []))
    const w = d.scoreWeights || { self: 50, approver: 50 }
    wSelf.value = Number(w.self || 50)
    wAppr.value = Number(w.approver || 50)
    gradeModal.value = true
  } catch (e) { alert(e.message) }
}
async function saveGrade() {
  if (!gradeRules.value.length) { alert('至少保留一个等级'); return }
  if (gradeRules.value.some((r) => r.label === '')) { alert('等级名不能为空'); return }
  if (Math.abs((Number(wSelf.value) || 0) + (Number(wAppr.value) || 0) - 100) > 0.01) { alert('自评占比与考核人评分占比合计必须等于100'); return }
  try {
    await api('/api/performance/grade_rules', { body: { gradeRules: gradeRules.value, scoreWeights: { self: Number(wSelf.value) || 0, approver: Number(wAppr.value) || 0 } } })
    gradeModal.value = false
    toast('设置已保存')
  } catch (e) { alert(e.message) }
}
</script>
