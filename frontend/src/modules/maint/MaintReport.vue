<template>
  <div class="card">
    <h3 style="display:flex;align-items:center;gap:8px"><Icon :name="isFire ? 'flame' : 'lift'" :size="20" style="color:#f59e0b" />{{ isFire ? '消防维保报表' : '电梯维保报表' }}（{{ year }}年）</h3>
    <div class="row">
      <label class="fld">统计年度 <select v-model.number="year" @change="load">
        <option v-for="y in yearOpts" :key="y" :value="y">{{ y }} 年</option>
      </select></label>
      <button class="btn primary" @click="load">刷新</button>
      <button class="btn" @click="router.push(isFire ? '/maintFire' : '/maintElev')">前往{{ isFire ? '消防' : '电梯' }}台账</button>
    </div>
    <div style="margin-top:12px">
      <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
      <div v-else-if="!d">加载中...</div>
      <template v-else>
        <!-- 合并总览条（消防+电梯） -->
        <div class="msg info" style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
          <b>全年维保总览</b>
          <span>消防 <b>{{ d.combined.fire_count }}</b> 份 / {{ rMoney(d.combined.fire_amount) }} 元</span>
          <span>电梯 <b>{{ d.combined.elev_count }}</b> 份 / {{ rMoney(d.combined.elev_amount) }} 元</span>
          <span>合计 <b>{{ d.combined.total_count }}</b> 份 / <b style="color:#16a34a">{{ rMoney(d.combined.total_amount) }}</b> 元</span>
          <span v-if="d.yoy.amount_pct != null">金额同比 <b :style="{ color: d.yoy.amount_pct > 0 ? '#dc2626' : '#16a34a' }">{{ d.yoy.amount_pct > 0 ? '+' : '' }}{{ d.yoy.amount_pct }}%</b>（上年 {{ rMoney(d.yoy.prev_amount) }} 元 / {{ d.yoy.prev_count }} 份）</span>
          <span v-else>金额同比 —（上年无数据）</span>
        </div>
        <div class="rpt-kpi-row">
          <RCard label="维保合同数" :value="d.contract_count" sub="本年度有效合同" color="#2563eb" />
          <RCard label="在管项目数" :value="d.project_count" sub="覆盖物业项目" color="#0ea5e9" />
          <RCard label="签约总金额" :value="rMoney(d.total_amount)" sub="本年度" color="#16a34a" />
          <RCard label="风险合同" :value="d.risk_total" sub="临期/已过期/无到期日" color="#ef4444" />
          <RCard label="平均单位成本" :value="d.avg_unit_cost != null ? rMoney(d.avg_unit_cost) : '—'" :sub="isFire ? '元/㎡·年' : '元/台·年'" color="#8b5cf6" />
        </div>
        <div class="rpt-grid-2">
          <RSection title="合同状态分布"><div style="height:240px"><canvas :ref="(el) => setEl('state', el)"></canvas></div></RSection>
          <RSection title="合作方金额分布">
            <div v-if="d.partner_amount.length" style="height:240px"><canvas :ref="(el) => setEl('partner', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">无签约合作方数据</div>
          </RSection>
        </div>
        <div class="rpt-grid-2">
          <RSection title="各项目维保金额">
            <div v-if="d.project_amount.length" :style="{ height: Math.max(180, d.project_amount.length * 44) + 'px' }"><canvas :ref="(el) => setEl('proj', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">暂无项目数据</div>
          </RSection>
          <RSection :title="`各合作方${isFire ? '维保面积' : '维保台数'}分布`">
            <div v-if="d.partner_scale.length" :style="{ height: Math.max(180, d.partner_scale.length * 44) + 'px' }"><canvas :ref="(el) => setEl('scale', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">暂无数据</div>
          </RSection>
        </div>

        <!-- 单位成本对标 -->
        <RSection :title="`单位成本对标（${isFire ? '元/㎡·年' : '元/台·年'}）`">
          <div v-if="!d.unit_costs.length" class="msg info" style="text-align:center;padding:20px">暂无数据</div>
          <div v-else class="table-wrap"><table class="tb">
            <thead><tr><th>项目</th><th>签约金额</th><th>{{ isFire ? '维保面积(㎡)' : '维保台数' }}</th><th>单位成本</th><th>较均值</th></tr></thead>
            <tbody>
              <tr v-for="u in d.unit_costs" :key="u.name">
                <td><b>{{ u.name }}</b></td><td class="num">{{ rMoney(u.amount) }}</td><td class="num">{{ rMoney(u.scale) }}</td>
                <td class="num">{{ u.unit_cost != null ? rMoney(u.unit_cost) : '—' }}</td>
                <td>
                  <span v-if="u.diff_pct != null" :class="u.diff_pct > 0 ? 'tag red' : 'tag green'">{{ u.diff_pct > 0 ? '+' : '' }}{{ u.diff_pct }}%</span>
                  <span v-else style="color:#94a3b8">—</span>
                </td>
              </tr>
            </tbody>
          </table></div>
          <div style="font-size:12px;color:#94a3b8;margin-top:6px">平均单位成本 {{ d.avg_unit_cost != null ? rMoney(d.avg_unit_cost) + ' ' + (isFire ? '元/㎡' : '元/台') : '—' }}，高于均值标红，可用于续签议价参考。</div>
        </RSection>

        <!-- 异常清单（已过期 + 无到期日） -->
        <RSection title="异常合同清单（已过期 / 无到期日）">
          <div class="table-wrap"><table class="tb">
            <thead><tr><th>项目名称</th><th>签约方</th><th>签约金额</th><th>到期日期</th><th>状态</th></tr></thead>
            <tbody>
              <tr v-if="!d.abnormal_list.length"><td colspan="5" style="text-align:center;color:#94a3b8;padding:20px"><Icon name="check" /> 无异常合同</td></tr>
              <tr v-for="(r, i) in d.abnormal_list" :key="i">
                <td><b>{{ r.project }}</b></td><td>{{ r.party }}</td><td class="num">{{ rMoney(r.amount) }}</td><td>{{ r.end_date || '未登记' }}</td>
                <td><span :class="MAINT_STATUS_TAG[r.status] || 'tag gray'">{{ r.status_label }}</span></td>
              </tr>
            </tbody>
          </table></div>
        </RSection>

        <!-- 项目覆盖率（有设备但无有效合同） -->
        <RSection title="项目维保覆盖率（设备 vs 有效合同）">
          <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:8px;font-size:13px">
            <span>登记{{ isFire ? '消防' : '电梯' }}设备 <b>{{ d.coverage.device_total }}</b> 台/套</span>
            <span>涉及项目 <b>{{ d.coverage.project_with_devices }}</b> 个</span>
            <span>无有效维保合同的项目 <b :style="{ color: d.coverage.uncovered_count ? '#dc2626' : '#16a34a' }">{{ d.coverage.uncovered_count }}</b> 个</span>
          </div>
          <div v-if="d.coverage.uncovered.length" class="table-wrap"><table class="tb">
            <thead><tr><th>项目名称</th><th>设备数</th><th>说明</th></tr></thead>
            <tbody>
              <tr v-for="u in d.coverage.uncovered" :key="u.project">
                <td><b>{{ u.project }}</b></td><td>{{ u.device_count }}</td>
                <td style="color:#dc2626">有设备登记但当前无在保合同，请尽快补签</td>
              </tr>
            </tbody>
          </table></div>
          <div v-else class="msg info" style="text-align:center;padding:14px"><Icon name="check" /> 所有有设备的项目均有在保合同</div>
        </RSection>

        <RSection title="风险合同清单">
          <div class="table-wrap"><table class="tb">
            <thead><tr><th>项目名称</th><th>签约方</th><th>签约金额</th><th>到期日期</th><th>剩余天数</th><th>状态</th></tr></thead>
            <tbody>
              <tr v-if="!d.risk_list.length"><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px"><Icon name="check" /> 本年度无临期/已过期合同</td></tr>
              <tr v-for="(r, i) in d.risk_list" :key="i">
                <td><b>{{ r.project }}</b></td><td>{{ r.party }}</td><td class="num">{{ rMoney(r.amount) }}</td><td>{{ r.end_date || '未登记' }}</td><td>{{ daysText(r.end_date) }}</td>
                <td><span :class="MAINT_STATUS_TAG[r.status] || 'tag gray'">{{ r.status_label }}</span></td>
              </tr>
            </tbody>
          </table></div>
        </RSection>
      </template>
    </div>
  </div>
</template>

<script setup>
// 消防/电梯维保报表 — 复刻 pageMaintFireReport/pageMaintElevReport/pageMaintReport/maintReportRender（app.js:2556-2618）
// 升级版：6 档状态分布、异常清单、覆盖率、单位成本对标、同比、消防+电梯合并总览
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { rMoney } from '@/modules/report/reportLogic'
import { createCharts, rBar, rHBar, rDoughnut, PALETTE } from '@/modules/report/chartKit'
import { MAINT_STATUS_TAG } from './maintLogic'
import RCard from '@/modules/report/RCard.vue'
import RSection from '@/modules/report/RSection.vue'
import Icon from '@/components/Icon.vue'

const props = defineProps({ type: { type: String, default: 'fire' } })
const router = useRouter()

// 消防/电梯报表复用同一组件，路由切换时组件实例被复用，必须响应式跟随 props.type
const isFire = computed(() => props.type === 'fire')
const yearOpts = Array.from({ length: 2035 - 2020 + 1 }, (_, i) => 2020 + i)
const year = ref(new Date().getFullYear())
const d = ref(null)
const loadErr = ref('')
const charts = createCharts()
const els = {}
function setEl(key, el) { els[key] = el }

const rLabel = (v) => (v && typeof v === 'object' ? v.label : v)
const rValue = (v) => (v && typeof v === 'object' ? v.value : v)

async function draw() {
  await nextTick()
  const x = d.value
  if (!x) return
  // state_dist 的 label 由后端直接下发中文档位（正常在保/90天内到期/...）
  rBar(charts, 'state', els.state, x.state_dist.map(rLabel), x.state_dist.map(rValue), { label: '合同数', color: '#f59e0b' })
  if (x.partner_amount.length) rDoughnut(charts, 'partner', els.partner, x.partner_amount.map(rLabel), x.partner_amount.map(rValue), PALETTE, '总金额', x.total_amount, rMoney)
  if (x.project_amount.length) rHBar(charts, 'proj', els.proj, x.project_amount.map((p) => p.name), x.project_amount.map((p) => p.amount), isFire.value ? '#f59e0b' : '#3b82f6', rMoney)
  if (x.partner_scale.length) rHBar(charts, 'scale', els.scale, x.partner_scale.map(rLabel), x.partner_scale.map(rValue), isFire.value ? '#0ea5e9' : '#8b5cf6', (v) => v + (isFire.value ? ' ㎡' : ' 台'))
}

async function load() {
  loadErr.value = ''
  try {
    d.value = await api(`/api/maintenance/${isFire.value ? 'fire' : 'elev'}_report?year=${year.value}`)
    draw()
  } catch (e) { loadErr.value = e.message }
}

function daysText(end) {
  if (!end) return '—'
  const diff = Math.ceil((new Date(end + 'T00:00:00') - new Date()) / 86400000)
  return diff > 0 ? diff + '天' : diff === 0 ? '今天' : Math.abs(diff) + '天前已过'
}

onMounted(load)
// 同一组件在 fireReport / elevReport 两条路由间复用时，切换后清空旧数据并重新拉取
watch(() => props.type, () => {
  d.value = null
  charts.destroyAll()
  load()
})
onBeforeUnmount(() => charts.destroyAll())
</script>
