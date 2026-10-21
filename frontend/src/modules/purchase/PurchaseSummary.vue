<script setup>
// 采购信息汇总查看（复刻旧 #/summary：月份列表 → 全公司月汇总（矩阵+明细+导出） → 商品搜索定位）
import { ref, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import { money, moneyOrDash } from '@/utils/format'
import { FILL_LINES } from './fillLogic'
import { lineTotals, budgetCounts } from './summaryLogic'

const view = ref('months') // months | month
const months = ref([])
const d = ref(null)
const month = ref('')
const projectId = ref(0)
const line = ref('')
const loading = ref(false)

const q = ref('')
const searchRows = ref([])
const searched = ref(false)

async function loadMonths() {
  loading.value = true
  try {
    months.value = (await api('/api/purchase/summary/months')).data.rows || []
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

async function openMonth(m) {
  view.value = 'month'
  month.value = m
  projectId.value = 0
  line.value = ''
  await loadMonth()
}

async function loadMonth() {
  loading.value = true
  try {
    const qs = '?month=' + month.value +
      (projectId.value ? '&project_id=' + projectId.value : '') +
      (line.value ? '&line=' + encodeURIComponent(line.value) : '')
    d.value = (await api('/api/purchase/summary/month' + qs)).data
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

async function doSearch() {
  if (!q.value.trim()) { searchRows.value = []; searched.value = false; return }
  loading.value = true
  try {
    searchRows.value = (await api('/api/purchase/summary/search?q=' + encodeURIComponent(q.value.trim()))).data.rows || []
    searched.value = true
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

function exportMonth() {
  download('/api/purchase/export?month=' + month.value, '广盈物业_' + month.value.slice(0, 4) + '_采购计划统计表.xlsx')
}

const monthText = computed(() => (month.value ? Number(month.value.slice(0, 4)) + ' 年 ' + Number(month.value.slice(5)) + ' 月' : ''))
const totals = computed(() => (d.value ? lineTotals(d.value.matrix, d.value.lines || FILL_LINES) : {}))
const counts = computed(() => (d.value ? budgetCounts(d.value.matrix) : { over: 0, within: 0 }))

onMounted(loadMonths)
</script>

<template>
  <div>
    <!-- 月份列表 -->
    <template v-if="view === 'months'">
      <div style="font-size:18px;font-weight:700;">采购信息汇总查看</div>
      <div style="font-size:12.5px;color:#94a3b8;margin:8px 0 14px;">
        搜索已采购的商品（名称/规格/品牌），或点击月份进入该月全公司采购汇总
      </div>
      <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
        <el-input v-model="q" placeholder="请输入要搜索的商品名称/规格/品牌" style="width:280px" clearable @keyup.enter="doSearch" />
        <el-button type="primary" @click="doSearch">搜索</el-button>
        <el-button @click="loadMonths">刷新</el-button>
      </div>

      <template v-if="searched">
        <div style="font-size:13px;color:#475569;margin-bottom:8px;">
          <template v-if="searchRows.length">找到 {{ searchRows.length }} 条采购记录，点击可定位到对应月份查看</template>
          <template v-else>未找到匹配的采购记录</template>
        </div>
        <el-table v-if="searchRows.length" :data="searchRows" border size="small" style="width:100%">
          <el-table-column label="采购月份" width="100">
            <template #default="{ row }">{{ Number(row.month.slice(0, 4)) }} 年 {{ Number(row.month.slice(5)) }} 月</template>
          </el-table-column>
          <el-table-column prop="project_name" label="项目" min-width="130" />
          <el-table-column prop="line" label="条线" width="80" />
          <el-table-column prop="item_name" label="商品名称" min-width="140" />
          <el-table-column prop="spec" label="规格型号" min-width="100" />
          <el-table-column prop="brand" label="品牌" min-width="90" />
          <el-table-column prop="unit" label="单位" width="70" />
          <el-table-column label="数量" width="80" align="right">
            <template #default="{ row }">{{ row.quantity }}</template>
          </el-table-column>
          <el-table-column label="单价" width="90" align="right">
            <template #default="{ row }">{{ row.price > 0 ? money(row.price) : '待定价' }}</template>
          </el-table-column>
          <el-table-column label="金额（元）" width="110" align="right">
            <template #default="{ row }">{{ moneyOrDash(row.total) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="100" align="center">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openMonth(row.month)">定位查看</el-button>
            </template>
          </el-table-column>
        </el-table>
      </template>

      <template v-else>
        <div style="font-size:13px;color:#475569;margin-bottom:8px;">已存档 {{ months.length }} 个月份 · 点击进入该月全公司采购汇总</div>
        <div v-if="!months.length && !loading" style="color:#94a3b8;font-size:13px;padding:30px 0;text-align:center;">暂无已存档数据（导入价格存档后显示）</div>
        <div v-loading="loading" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;">
          <div v-for="r in months" :key="r.month" class="dash-card" style="padding:14px 16px;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <div style="font-size:15px;font-weight:700;">{{ Number(r.month.slice(0, 4)) }} 年 {{ Number(r.month.slice(5)) }} 月</div>
              <el-tag size="small" type="info">已归档</el-tag>
            </div>
            <div style="font-size:13px;color:#475569;margin-top:8px;">采购金额 ¥{{ moneyOrDash(r.amount) }}</div>
            <div style="font-size:12px;color:#94a3b8;margin-top:4px;">{{ r.items }} 条明细 · {{ r.projects }} 个项目 · {{ r.lines }} 个条线</div>
            <div style="margin-top:10px;">
              <el-button size="small" type="primary" @click="openMonth(r.month)">查看汇总</el-button>
            </div>
          </div>
        </div>
      </template>
    </template>

    <!-- 月度汇总 -->
    <template v-else-if="view === 'month' && d">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <el-button @click="view = 'months'; loadMonths()">返回月份列表</el-button>
        <div style="font-size:17px;font-weight:700;">{{ monthText }} · 全公司采购汇总</div>
      </div>

      <div class="dash-kpis" style="grid-template-columns:repeat(5,1fr);margin:14px 0;">
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#3b82f6,#6366f1)"></div>
          <div class="dash-kpi-t">全公司采购金额</div>
          <div class="dash-kpi-v">{{ moneyOrDash(d.total_actual) }} <span class="dash-kpi-s">元</span></div>
        </div>
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#06b6d4,#0891b2)"></div>
          <div class="dash-kpi-t">预算合计</div>
          <div class="dash-kpi-v">{{ moneyOrDash(d.total_budget) }} <span class="dash-kpi-s">元</span></div>
        </div>
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#f43f5e,#e11d48)"></div>
          <div class="dash-kpi-t">超支</div>
          <div class="dash-kpi-v">{{ counts.over }} <span class="dash-kpi-s">个项目</span></div>
        </div>
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#10b981,#059669)"></div>
          <div class="dash-kpi-t">预算内</div>
          <div class="dash-kpi-v">{{ counts.within }} <span class="dash-kpi-s">个项目</span></div>
        </div>
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#f59e0b,#f97316)"></div>
          <div class="dash-kpi-t">明细条数</div>
          <div class="dash-kpi-v">{{ d.total_items }} <span class="dash-kpi-s">条</span></div>
        </div>
      </div>

      <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
        <span style="font-size:13px;color:#6b7280;">筛选：</span>
        <el-select v-model="projectId" style="width:200px" placeholder="全部项目" @change="loadMonth">
          <el-option :value="0" label="全部项目" />
          <el-option v-for="r in d.matrix" :key="r.project_id" :value="r.project_id" :label="r.project_name" />
        </el-select>
        <el-select v-model="line" style="width:140px" placeholder="全部条线" @change="loadMonth">
          <el-option value="" label="全部条线" />
          <el-option v-for="l in (d.lines || FILL_LINES)" :key="l" :value="l" :label="l" />
        </el-select>
        <div style="flex:1 1 auto"></div>
        <el-button type="primary" @click="exportMonth">导出该月Excel</el-button>
      </div>

      <el-table :data="d.matrix" border size="small" style="width:100%">
        <el-table-column prop="project_name" label="采购项目名称" min-width="140" fixed="left" />
        <el-table-column v-for="l in (d.lines || FILL_LINES)" :key="l" :label="l" min-width="90" align="right">
          <template #default="{ row }">
            <template v-if="row.lines[l]">{{ moneyOrDash(row.lines[l].amount) }}<span style="color:#94a3b8;font-size:11px;">（{{ row.lines[l].cnt }}）</span></template>
            <template v-else>—</template>
          </template>
        </el-table-column>
        <el-table-column label="小计(元)" width="110" align="right">
          <template #default="{ row }">{{ moneyOrDash(row.total) }}</template>
        </el-table-column>
        <el-table-column label="预算(元)" width="110" align="right">
          <template #default="{ row }">{{ moneyOrDash(row.budget) }}</template>
        </el-table-column>
        <el-table-column label="实际(元)" width="110" align="right">
          <template #default="{ row }">{{ moneyOrDash(row.actual) }}</template>
        </el-table-column>
        <el-table-column label="预算执行率" width="120" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.rate !== null" size="small" :type="row.over ? 'danger' : 'success'">{{ row.over ? '超支 ' : '预算内 ' }}{{ row.rate }}%</el-tag>
            <template v-else>—</template>
          </template>
        </el-table-column>
      </el-table>

      <div style="font-size:12.5px;color:#6b7280;margin:8px 0 16px;">
        月度合计：{{ d.lines.map((l) => l + ' ¥' + moneyOrDash(totals[l])).join('，') }}；{{ d.matrix.length }} 个项目，
        预算合计 ¥{{ moneyOrDash(d.total_budget) }}，实际合计 ¥{{ moneyOrDash(d.total_actual) }}，
        执行率 {{ d.total_rate === null ? '—' : d.total_rate + '%' }}
      </div>

      <div style="font-size:14.5px;font-weight:700;margin-bottom:8px;">采购明细（{{ d.items.length }} 条）</div>
      <el-table :data="d.items" border size="small" style="width:100%" max-height="520">
        <el-table-column prop="line" label="条线" width="80" />
        <el-table-column prop="project_name" label="项目" min-width="130" />
        <el-table-column prop="item_name" label="商品名称" min-width="140" />
        <el-table-column prop="spec" label="规格型号" min-width="100" />
        <el-table-column prop="brand" label="品牌" min-width="90" />
        <el-table-column prop="unit" label="单位" width="70" />
        <el-table-column label="数量" width="80" align="right">
          <template #default="{ row }">{{ row.quantity }}</template>
        </el-table-column>
        <el-table-column label="单价" width="90" align="right">
          <template #default="{ row }">{{ row.price > 0 ? money(row.price) : '待定价' }}</template>
        </el-table-column>
        <el-table-column label="金额（元）" width="110" align="right">
          <template #default="{ row }">{{ moneyOrDash(row.total) }}</template>
        </el-table-column>
      </el-table>
    </template>
  </div>
</template>
