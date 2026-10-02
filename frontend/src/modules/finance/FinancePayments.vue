<script setup>
// 付款记录（复刻旧财务页 pagePayments/renderPayments：2026年度、三状态块、未支付自动计算只读、分块保存）
import { ref, reactive, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import { fmtMoney, ledgerMonthSum, payGroupByProject } from './financeLogic'
import { moneyOrDash } from '@/utils/format'
import './finance.css'

const d = ref(null)
const cellV = reactive({}) // `${sk}|${pid}|${type}` → 编辑值
const touched = reactive(new Set()) // `${sk}|${pid}` 有编辑

const types = computed(() => Object.keys(d.value?.types || {}))
const blockKeys = computed(() => Object.keys(d.value?.blocks || {}))

function initEditState(res) {
  Object.keys(cellV).forEach((k) => delete cellV[k])
  touched.clear()
  Object.keys(res.blocks).forEach((sk) => {
    if (sk === 'unpaid') return
    res.blocks[sk].projects.forEach((pj) => {
      types.value.forEach((k) => {
        cellV[`${sk}|${pj.project_id}|${k}`] = (pj.types[k] || 0) ? String(pj.types[k]) : ''
      })
    })
  })
}

async function load() {
  try {
    const res = await api('/api/finance/payments')
    d.value = res
    initEditState(res)
  } catch (e) {
    toast(e.message, false)
  }
}

function pTotal(sk, pj) {
  const key = `${sk}|${pj.project_id}`
  if (!touched.has(key)) return moneyOrDash(pj.total)
  const s = ledgerMonthSum(types.value.map((k) => cellV[`${sk}|${pj.project_id}|${k}`]))
  return moneyOrDash(s)
}

async function saveBlock(sk) {
  const cells = []
  d.value.blocks[sk].projects.forEach((pj) => {
    types.value.forEach((k) => {
      cells.push({ project_id: pj.project_id, type: k, value: cellV[`${sk}|${pj.project_id}|${k}`] })
    })
  })
  const byProj = payGroupByProject(cells)
  let cnt = 0
  try {
    for (const pid of Object.keys(byProj)) {
      const res = await api('/api/finance/payments/save', { body: { project_id: pid, status: sk, items: byProj[pid] } })
      cnt += res.saved || 0
    }
    toast(`已保存 ${cnt} 项`)
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

function exportXlsx() {
  download('/api/finance/export_payments', '付款记录_2026年度.xlsx')
}

onMounted(load)
</script>

<template>
  <div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <button class="fbtn" @click="load">加载</button>
      <button class="fbtn ghost" @click="exportXlsx">导出 Excel</button>
      <span style="color:#888;font-size:12px;">2026年度 · 每个项目不分月份 · 三个状态区块（已确认数据 / 已支付 / 未支付）；金额单位：元</span>
    </div>

    <template v-if="d">
      <div v-for="sk in blockKeys" :key="sk" style="margin-top:18px;">
        <div style="font-weight:700;font-size:15px;color:#1f3a5f;margin-bottom:8px;">{{ d.blocks[sk].status_name }}</div>
        <div v-if="sk === 'unpaid'" style="color:#8a6d1a;background:#fff8e1;border:1px solid #f0e0a8;border-radius:8px;padding:6px 12px;font-size:12px;margin-bottom:8px;">
          未支付 = 已确认数据 − 已支付（自动计算，不可编辑）；修改已确认或已支付后保存，本块自动刷新
        </div>
        <div style="overflow:auto;">
          <table class="ftbl" style="min-width:1200px;">
            <thead>
              <tr>
                <th style="position:sticky;left:0;background:#f0f4fa;">项目名称</th>
                <th v-for="k in types" :key="k" style="min-width:110px;">{{ d.types[k] }}</th>
                <th>合计</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="pj in d.blocks[sk].projects" :key="pj.project_id">
                <td style="position:sticky;left:0;background:#fff;font-weight:600;white-space:nowrap;">{{ pj.name }}</td>
                <td v-for="k in types" :key="k">
                  <div v-if="sk === 'unpaid'" class="pnum ro"
                    style="padding:6px 8px;background:#f7f8fa;border:1px solid #e9e9e9;border-radius:8px;color:#555;font-size:13px;">
                    {{ (pj.types[k] || 0) ? fmtMoney(pj.types[k]) : '—' }}
                  </div>
                  <input v-else class="pnum" v-model="cellV[`${sk}|${pj.project_id}|${k}`]" placeholder="0.00"
                    @input="touched.add(`${sk}|${pj.project_id}`)" />
                </td>
                <td :style="sk === 'unpaid' ? 'color:#555;' : ''">{{ pTotal(sk, pj) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="sk !== 'unpaid'" style="margin-top:8px;">
          <button class="fbtn sm" @click="saveBlock(sk)">保存本块（{{ d.blocks[sk].status_name }}）</button>
        </div>
      </div>
    </template>
  </div>
</template>
