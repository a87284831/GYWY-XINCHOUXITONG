<template>
  <PerfDetail v-if="detailId" :id="detailId" @back="detailId = null" />
  <div v-else class="card">
    <div class="row" style="justify-content:space-between">
      <h3 style="margin:0;border:none;padding:0">{{ TITLES[scope] || '绩效考核' }}</h3>
      <div class="row">
        <button v-if="scope === 'mine'" class="btn primary" @click="$router.push('/perfCreate')">＋ 发起考核</button>
        <button class="btn sm" @click="load">刷新</button>
      </div>
    </div>
    <div style="margin-top:12px">
      <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
      <div v-else-if="loading" class="msg info">加载中…</div>
      <div v-else-if="!plans.length" class="msg info">暂无考核单</div>
      <div v-else class="table-wrap" style="max-height:calc(100vh - 230px)">
        <table class="tb">
          <thead><tr><th>单号</th><th>被考核人</th><th>考核周期</th><th>员工状态</th><th>权重</th><th>自评总分</th><th>考核人评分</th><th>最终总分</th><th>等级</th><th>当前审批人</th><th>发起人</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="p in plans" :key="p.id">
              <td>#{{ p.id }}</td>
              <td>{{ p.employeeName }}<div class="hint" style="margin:0">{{ p.line || '' }} {{ p.project || '' }}</div></td>
              <td>{{ p.periodStart || '' }} ~ {{ p.periodEnd || '' }}</td>
              <td><span :class="`tag ${STATUS_COLOR[p.status] || 'gray'}`">{{ STATUS_LABEL[p.status] || p.status }}</span></td>
              <td class="num">{{ perfFmt(p.weightSum) }}</td>
              <td class="num">{{ perfFmt(p.selfTotal) }}</td>
              <td class="num">{{ perfFmt(p.approverTotal) }}</td>
              <td class="num"><b>{{ p.finalTotal != null ? perfFmt(p.finalTotal) : (p.selfTotal != null ? perfFmt(p.selfTotal) : '-') }}</b></td>
              <td><span v-if="p.grade" class="tag purple">{{ p.grade }}</span><template v-else>-</template></td>
              <td>{{ p.approverName || '-' }}</td>
              <td>{{ p.founderName || '' }}</td>
              <td style="white-space:nowrap">
                <button class="btn sm" @click="detailId = p.id">查看</button>
                <template v-if="p.status === 'draft' && scope === 'mine'">
                  <button class="btn sm primary" @click="$router.push({ path: '/perfCreate', query: { id: p.id } })">编辑</button>
                  <button class="btn sm danger" @click="delPlan(p.id)">删除</button>
                </template>
                <button v-else-if="(p.status === 'confirm' || p.status === 'approve') && scope === 'approve'" class="btn sm primary" @click="detailId = p.id">去审批</button>
                <button v-else-if="p.status === 'report' && scope === 'report'" class="btn sm primary" @click="detailId = p.id">去填报{{ p.todoItems ? `（${p.todoItems}项）` : '' }}</button>
                <button v-else-if="p.status === 'self' && scope === 'mine'" class="btn sm primary" @click="detailId = p.id">去自评</button>
                <button v-if="p.status === 'done'" class="btn sm" @click="exportPlan(p.id)">导出</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import { perfFmt } from './perfLogic'
import PerfDetail from './PerfDetail.vue'

const TITLES = { mine: '我的考核', approve: '待我审批', report: '待我填报' }
const STATUS_LABEL = { draft: '草稿', confirm: '待确认指标', ongoing: '考核进行中', report: '待数据填报', self: '待发起人自评', approve: '待逐级审批', done: '已归档' }
const STATUS_COLOR = { draft: 'gray', confirm: 'orange', ongoing: 'blue', report: 'orange', self: 'orange', approve: 'purple', done: 'green' }

const props = defineProps({ scope: { type: String, required: true } })

const plans = ref([])
const loading = ref(true)
const loadErr = ref('')
const detailId = ref(null)

onMounted(load)
async function load() {
  loading.value = true
  loadErr.value = ''
  try {
    const d = await api(`/api/performance/plans?scope=${props.scope}`)
    plans.value = d.plans || []
  } catch (e) {
    loadErr.value = e.message
  }
  loading.value = false
}
function exportPlan(id) {
  download(`/api/performance/export/${id}`, '绩效考核表.xlsx')
}
async function delPlan(id) {
  if (!confirm('确认删除该考核单？此操作不可恢复。')) return
  try { await api('/api/performance/delete', { body: { id } }); toast('已删除'); load() } catch (e) { alert(e.message) }
}
</script>
