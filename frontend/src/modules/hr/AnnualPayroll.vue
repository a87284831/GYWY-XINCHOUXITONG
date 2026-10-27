<template>
  <div class="ap-page">
    <!-- 筛选与概览 -->
    <div class="card">
      <div class="ap-head">
        <div>
          <h3>全年工资发放明细</h3>
          <p class="ap-sub">{{ year }} 年度 · {{ projectLabel }} · 在职与离职人员全员统计，金额口径为应发工资</p>
        </div>
        <div class="ap-head-act">
          <span class="tag gray">{{ rows.length }} 人</span>
          <button class="btn primary" :disabled="loading || !rows.length" @click="doExport">导出 Excel</button>
        </div>
      </div>

      <div class="ap-toolbar">
        <label class="fld">年份
          <select v-model="year" @change="load">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}年</option>
          </select>
        </label>
        <label class="fld">项目
          <select v-model="project" @change="load">
            <option value="">全部项目</option>
            <option v-for="p in auth.projects" :key="p" :value="p">{{ p }}</option>
          </select>
        </label>
        <label class="fld">姓名
          <input v-model.trim="name" placeholder="输入姓名查询" @keyup.enter="load" />
        </label>
        <button class="btn" :disabled="loading" @click="load">查询</button>
        <button class="btn sm" :disabled="loading" @click="reset">重置</button>
      </div>

      <div class="stat-cards ap-stats">
        <div class="stat">
          <div class="k">全年发薪人数</div>
          <div class="v">{{ totals.headcount }}<span class="u">人</span></div>
        </div>
        <div class="stat">
          <div class="k">全年应发总额</div>
          <div class="v">{{ money(totals.total_gross) || '0' }}</div>
        </div>
        <div class="stat">
          <div class="k">人均年应发</div>
          <div class="v">{{ money(avgGross) || '0' }}</div>
        </div>
        <div class="stat">
          <div class="k">{{ prevYm || '上月' }} 实际发薪</div>
          <div class="v">{{ totals.prev_headcount }}<span class="u">人</span></div>
          <div class="sub">应发总额 {{ money(totals.prev_gross) || '0' }}</div>
        </div>
      </div>
    </div>

    <!-- 分项目汇总 -->
    <div v-if="!loading && rows.length" class="card">
      <h3>分项目汇总</h3>
      <div class="table-wrap ap-sum">
        <table class="tb">
          <thead>
            <tr>
              <th style="text-align:left">项目</th><th class="num">全年发薪人数</th><th class="num">全年应发总额</th>
              <th class="num">人均年应发</th><th class="num">{{ prevYm }} 发薪人数</th><th class="num">{{ prevYm }} 应发总额</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in summary" :key="s.project">
              <td>{{ s.project }}</td>
              <td class="num">{{ s.headcount }}</td>
              <td class="num">{{ money(s.total_gross) }}</td>
              <td class="num">{{ money(s.avg_gross) }}</td>
              <td class="num">{{ s.prev_headcount }}</td>
              <td class="num">{{ money(s.prev_gross) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td>合计</td>
              <td class="num">{{ totals.headcount }}</td>
              <td class="num">{{ money(totals.total_gross) }}</td>
              <td class="num">{{ money(avgGross) }}</td>
              <td class="num">{{ totals.prev_headcount }}</td>
              <td class="num">{{ money(totals.prev_gross) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- 发放明细 -->
    <div class="card">
      <div class="ap-sec">
        <h3>发放明细</h3>
        <span class="ap-sec-tip">一人一行 · 横向滚动查看全年 12 个月 · 空白表示当月无发放</span>
      </div>

      <div v-if="loading" class="ap-empty">加载中…</div>
      <div v-else-if="!rows.length" class="ap-empty">该年度无工资发放数据</div>

      <div v-else class="table-wrap ap-wrap">
        <table class="tb ap-tb">
          <colgroup>
            <col style="width:150px" /><col style="width:100px" /><col style="width:104px" />
            <col style="width:80px" /><col style="width:64px" /><col style="width:96px" /><col style="width:96px" />
            <col v-for="m in 12" :key="m" style="width:76px" />
            <col style="width:110px" />
          </colgroup>
          <thead>
            <tr class="grp">
              <th colspan="7">员工信息</th>
              <th colspan="12" class="q-start">月度应发工资（元）</th>
              <th rowspan="2" class="c-total">全年应发</th>
            </tr>
            <tr class="cols">
              <th class="c0">项目</th><th>部门</th><th>岗位</th><th>姓名</th><th>状态</th><th>入职日期</th><th>离职日期</th>
              <th v-for="(ym, i) in months" :key="ym" class="mon" :class="{ 'q-start': i % 3 === 0 }">
                {{ Number(ym.slice(5)) }}月
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="i">
              <td class="c0" :title="r.project">{{ r.project || '—' }}</td>
              <td :title="r.department">{{ r.department || '—' }}</td>
              <td :title="r.position">{{ r.position || '—' }}</td>
              <td>{{ r.name }}</td>
              <td>
                <span class="tag" :class="r.status_label === '离职' ? 'gray' : 'green'">{{ r.status_label }}</span>
              </td>
              <td>{{ r.hire_date || '—' }}</td>
              <td>{{ r.resign_date || '—' }}</td>
              <td v-for="(ym, m) in months" :key="ym" class="num" :class="{ 'q-start': m % 3 === 0 }">
                {{ money(r.monthly[ym]) }}
              </td>
              <td class="num c-total">{{ money(r.total_gross) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td class="c0">合计</td>
              <td colspan="5">{{ totals.headcount }} 人</td>
              <td></td>
              <td v-for="(ym, m) in months" :key="ym" class="num" :class="{ 'q-start': m % 3 === 0 }">
                {{ money(totals.monthly[ym]) }}
              </td>
              <td class="num c-total">{{ money(totals.total_gross) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'

const auth = useAuthStore()
const nowYear = new Date().getFullYear()
const yearOptions = [nowYear, nowYear - 1, nowYear - 2]

const year = ref(nowYear)
const project = ref('')
const name = ref('')
const loading = ref(false)
const rows = ref([])
const summary = ref([])
const months = ref([])
const prevYm = ref('')
const totals = ref({ headcount: 0, total_gross: 0, monthly: {}, prev_headcount: 0, prev_gross: 0 })

const projectLabel = computed(() => project.value || '全部项目')
const avgGross = computed(() => {
  const n = totals.value.headcount || 0
  return n > 0 ? Number(((totals.value.total_gross || 0) / n).toFixed(2)) : 0
})

const query = computed(() => {
  const p = new URLSearchParams({ year: year.value })
  if (project.value) p.set('project', project.value)
  if (name.value) p.set('name', name.value)
  return p.toString()
})

const nf0 = new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 0 })
const nf2 = new Intl.NumberFormat('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** 金额紧凑显示：整数不带小数，非整数保留两位；0 显示为空白 */
function money(v) {
  const n = Number(v || 0)
  if (!n) return ''
  return Number.isInteger(n) ? nf0.format(n) : nf2.format(n)
}

async function load() {
  loading.value = true
  try {
    const res = await api(`/api/payroll/annual?${query.value}`)
    if (res.ok) {
      rows.value = res.rows || []
      summary.value = res.summary || []
      months.value = res.months || []
      prevYm.value = res.prev_ym || ''
      totals.value = res.totals || { headcount: 0, total_gross: 0, monthly: {}, prev_headcount: 0, prev_gross: 0 }
    } else {
      toast(res.error || '加载失败', false)
    }
  } catch (e) {
    toast('加载失败：' + (e.message || e), false)
  } finally {
    loading.value = false
  }
}

function reset() {
  year.value = nowYear
  project.value = ''
  name.value = ''
  load()
}

function doExport() {
  download(`/api/payroll/annual/export?${query.value}`, `全年发放明细_${year.value}.xlsx`)
}

onMounted(load)
</script>

<style scoped>
.ap-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
.ap-head h3 { margin-bottom:4px; }
.ap-head-act { display:flex; align-items:center; gap:10px; flex:none; }
.ap-sub { font-size:var(--fs-s); color:var(--text-3); line-height:1.6; }

.ap-toolbar { display:flex; align-items:center; gap:10px 14px; flex-wrap:wrap; margin-top:14px; padding:10px 12px;
  background:var(--bg-subtle); border:1px solid var(--divider); border-radius:var(--r-s); }
.ap-toolbar label.fld { gap:6px; }

.ap-stats { margin:14px 0 0; }
.ap-stats .stat { padding:12px 14px; box-shadow:none; background:var(--bg-subtle); }
.ap-stats .stat .v { font-size:var(--fs-2xl); }
.ap-stats .u { font-size:var(--fs-s); font-weight:500; color:var(--text-3); margin-left:3px; }
.ap-stats .sub { font-size:var(--fs-s); color:var(--text-3); margin-top:2px; }

.ap-sec { display:flex; align-items:baseline; gap:10px; margin-bottom:10px; }
.ap-sec h3 { margin-bottom:0; }
.ap-sec-tip { font-size:var(--fs-s); color:var(--text-3); }
.ap-empty { padding:40px 0; text-align:center; font-size:var(--fs-m); color:var(--text-3); }

/* 分项目汇总 */
.ap-sum { max-height:none; }
.ap-sum tfoot td { background:var(--bg-subtle); font-weight:700; border-top:2px solid var(--border); }

/* 明细宽表：双层表头 + 冻结首列 + 冻结合计行 */
.ap-wrap { max-height:calc(100vh - 260px); }
.ap-tb { font-size:12.5px; table-layout:fixed; min-width:1678px; }
.ap-tb th, .ap-tb td { padding:7px 10px; border-color:var(--border); }
.ap-tb td.c0, .ap-tb td:nth-child(2), .ap-tb td:nth-child(3) {
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

.ap-tb thead th { z-index:4; }
.ap-tb thead tr.grp th { height:30px; padding:0 10px; top:0; background:var(--brand-50); text-align:left;
  color:var(--text-2); font-weight:600; letter-spacing:.02em; }
.ap-tb thead tr.cols th { top:30px; text-align:left; }
.ap-tb thead tr.cols th.mon, .ap-tb thead th.c-total { text-align:right; }
.ap-tb thead th.c0 { z-index:6; background:var(--brand-50); }

.ap-tb th.c0, .ap-tb td.c0 { position:sticky; left:0; background:var(--bg-card); }
.ap-tb tbody td.c0 { z-index:2; }
.ap-tb tfoot td { position:sticky; bottom:0; z-index:4; background:var(--bg-subtle);
  border-top:2px solid var(--border); font-weight:700; }
.ap-tb tfoot td.c0 { z-index:6; background:var(--bg-subtle); }

/* 季度分隔线 + 全年合计列强调 */
.ap-tb .q-start { border-left:2px solid var(--border); }
.ap-tb td.c-total, .ap-tb th.c-total { background:var(--brand-50); color:var(--brand-700); font-weight:700; }

/* 斑马纹与悬停（提高选择器权重以覆盖全局 table.tb 规则） */
table.tb.ap-tb tbody tr:nth-child(even) td:not(.c-total) { background:var(--bg-subtle); }
table.tb.ap-tb tbody tr:hover td:not(.c-total) { background:var(--brand-50); }
</style>
