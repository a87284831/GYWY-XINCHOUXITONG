<script setup>
// 我的填报记录（复刻旧 #/my-items：月份卡片 → 月度明细；支持 ?month=&project_id= 直入与 admin 代查）
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { FILL_LINES } from './fillLogic'
import { myYearList, monthCardTag } from './systemLogic'

const route = useRoute()
const auth = useAuthStore()
const isAdmin = computed(() => auth.user && auth.user.role === 'admin')
const queryProjectId = computed(() => Number(route.query.project_id || 0))

const now = new Date()
const currentMonth = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0')
const year = ref(now.getFullYear())
const years = ref([now.getFullYear()])
const cards = ref([])
const loading = ref(false)

const detailMonth = ref('')
const lineFilter = ref('')
const detail = ref({ imported: false, rows: [] })
const exportLoading = ref(false)

const shownRows = computed(() =>
  lineFilter.value ? detail.value.rows.filter((r) => r.line === lineFilter.value) : detail.value.rows
)
const totalAmount = computed(() => detail.value.rows.reduce((s, r) => s + (Number(r.total) || 0), 0))

function money2(n) {
  return Number(n || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function monthTitle(m) {
  return m ? Number(m.slice(0, 4)) + ' 年 ' + Number(m.slice(5)) + ' 月' : ''
}

async function loadMonths() {
  loading.value = true
  try {
    const params = new URLSearchParams({ year: String(year.value) })
    if (isAdmin.value) params.set('project_id', String(queryProjectId.value))
    const d = await api('/api/purchase/my/months?' + params.toString())
    cards.value = d.data.rows
    years.value = myYearList(now.getFullYear(), d.data.rows)
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

async function openMonth(card) {
  detailMonth.value = card.month
  lineFilter.value = ''
  loading.value = true
  try {
    const params = new URLSearchParams({ month: card.month })
    if (isAdmin.value) params.set('project_id', String(queryProjectId.value))
    const d = await api('/api/purchase/my/month-items?' + params.toString())
    detail.value = d.data
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

function back() {
  detailMonth.value = ''
  detail.value = { imported: false, rows: [] }
  loadMonths()
}

async function exportTable() {
  if (!detailMonth.value) return
  exportLoading.value = true
  try {
    const params = new URLSearchParams({ month: detailMonth.value })
    if (isAdmin.value) params.set('project_id', String(queryProjectId.value))
    const blob = await api('/api/purchase/export/my-month?' + params.toString())
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = detailMonth.value + '_采购填报明细.xlsx'
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 4000)
    toast('导出成功')
  } catch (e) {
    toast(e.message || '导出失败', false)
  } finally {
    exportLoading.value = false
  }
}

onMounted(async () => {
  if (route.query.month) {
    await openMonth({ month: route.query.month })
  } else {
    await loadMonths()
  }
})
</script>

<template>
  <!-- 月度明细 -->
  <div v-if="detailMonth">
    <div style="background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:12px;">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <el-button size="small" @click="back">← 返回</el-button>
        <span>{{ monthTitle(detailMonth) }} · 填报明细</span>
        <el-select v-model="lineFilter" style="width:130px">
          <el-option label="全部条线" value="" />
          <el-option v-for="l in FILL_LINES" :key="l" :label="l" :value="l" />
        </el-select>
        <el-tag size="small" :type="detail.imported ? 'success' : 'info'">
          {{ detail.imported ? '已导入价格' : '待招采报价' }}
        </el-tag>
        <span style="flex:1 1 auto"></span>
        <el-button size="small" type="primary" plain :loading="exportLoading" @click="exportTable">导出本表</el-button>
        <span v-if="detail.imported" style="color:#2b5a9e;font-weight:600;font-size:14px">
          合计金额 ¥{{ money2(totalAmount) }}
        </span>
      </div>
    </div>

    <el-table v-loading="loading" :data="shownRows" border stripe size="small"
      :header-cell-style="{ background:'#eef4fb', color:'#2b5a9e', fontWeight:600 }">
      <el-table-column type="index" label="#" width="46" align="center" />
      <el-table-column label="条线" width="80">
        <template #default="{ row }">
          <el-tag size="small" effect="plain">{{ row.line }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="item_name" label="商品名称" min-width="130" />
      <el-table-column prop="spec" label="规格型号" min-width="110" />
      <el-table-column prop="brand" label="品牌" width="100" />
      <el-table-column prop="unit" label="单位" width="64" align="center" />
      <el-table-column prop="quantity" label="数量" width="76" align="right" />
      <el-table-column v-if="!detail.imported" prop="stock" label="库存" width="76" align="right" />
      <el-table-column v-if="!detail.imported" prop="reason" label="申购原因" min-width="120" show-overflow-tooltip />
      <el-table-column label="单价" width="96" align="right">
        <template #default="{ row }">
          <span v-if="row.price != null" style="color:#2b5a9e;font-weight:600">¥{{ money2(row.price) }}</span>
          <span v-else style="color:#bbb">待招采报价</span>
        </template>
      </el-table-column>
      <el-table-column label="金额" width="110" align="right">
        <template #default="{ row }">
          <span v-if="row.total != null" style="color:#2b5a9e;font-weight:600">¥{{ money2(row.total) }}</span>
          <span v-else style="color:#bbb">—</span>
        </template>
      </el-table-column>
      <el-table-column v-if="detail.imported" label="状态" width="86" align="center">
        <template #default>
          <el-tag size="small" type="success">已出价</el-tag>
        </template>
      </el-table-column>
      <el-table-column v-else label="状态" width="100" align="center">
        <template #default="{ row }">
          <el-tag v-if="row.status === 'returned'" size="small" type="danger">
            已退回
            <el-tooltip :content="row.return_reason || '请修改后重新提交'" placement="top">
              <span style="margin-left:3px;cursor:help;text-decoration:underline dotted;">?</span>
            </el-tooltip>
          </el-tag>
          <el-tag v-else size="small" :type="row.status === 'confirmed' ? 'success' : row.status === 'submitted' ? 'primary' : 'info'">
            {{ row.status === 'confirmed' ? '已确认' : row.status === 'submitted' ? '已提交' : '草稿' }}
          </el-tag>
        </template>
      </el-table-column>
    </el-table>

    <div style="padding:8px 14px;color:#999;font-size:12px">
      <template v-if="detail.imported">本页为招采确认并导入价格后的最终数据（数量、单价、金额以存档为准）</template>
      <template v-else>本页为填报记录；招采确认并导入价格后，单价与金额将在此显示</template>
    </div>
  </div>

  <!-- 月份卡片列表 -->
  <div v-else>
    <div style="display:flex;align-items:center;margin-bottom:12px;">
      <span style="font-size:18px;font-weight:700;">我的填报记录</span>
      <div style="margin-left:16px">
        <span style="color:#999;font-size:12px;margin-right:8px">按月份查看，点击进入明细</span>
        <el-select v-model="year" style="width:110px" @change="loadMonths">
          <el-option v-for="y in years" :key="y" :label="y + ' 年'" :value="y" />
        </el-select>
      </div>
    </div>

    <div v-loading="loading"
      style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;min-height:120px;">
      <div v-for="card in cards" :key="card.month"
        :style="{
          border:'1px solid #d9e2f0', borderRadius:'8px', overflow:'hidden', boxSizing:'border-box',
          ...(card.returned_count > 0
            ? { borderColor:'#e64646', boxShadow:'0 0 0 1px #e64646' }
            : card.month === currentMonth ? { borderColor:'#2b5a9e' } : {}),
        }">
        <div style="padding:12px 14px;display:flex;align-items:center;justify-content:space-between;background:#fafbfd">
          <span style="font-size:16px;font-weight:600;color:#1e3a5f">{{ Number(card.month.slice(5)) }} 月</span>
          <el-tag size="small" :type="monthCardTag(card).type">{{ monthCardTag(card).text }}</el-tag>
        </div>
        <div style="padding:10px 14px;font-size:13px;color:#555;line-height:2">
          <div>
            填报 {{ card.item_count }} 条
            <template v-if="card.confirmed_count > 0"> · 已确认 {{ card.confirmed_count }}</template>
          </div>
          <div v-if="card.returned_count > 0" style="color:#e64646;font-weight:600">
            ⚠ {{ card.returned_count }} 条被退回需修改
            <div v-if="card.return_reason" style="color:#c0392b;font-weight:400;margin-top:2px">
              退回原因：{{ card.return_reason }}
            </div>
          </div>
          <div v-if="card.imported" style="color:#2b5a9e;font-weight:600">采购金额 ¥{{ money2(card.amount) }}</div>
          <div v-else-if="card.item_count > 0" style="color:#bbb">招采确认并导入价格后显示金额</div>
        </div>
        <div style="padding:10px 14px;border-top:1px solid #eef1f6;text-align:right;background:#fff">
          <el-button v-if="card.item_count > 0 || card.imported" size="small" type="primary" plain
            @click="openMonth(card)">查看明细</el-button>
          <el-button v-else size="small" disabled>暂无记录</el-button>
        </div>
      </div>
    </div>
  </div>
</template>
