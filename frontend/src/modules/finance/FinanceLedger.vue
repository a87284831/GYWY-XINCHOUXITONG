<script setup>
// 应收费用台账（复刻旧财务页 pageLedger/renderLedger/showLedgerAtt：月×类别可编辑、行内保存、附件管理）
import { ref, reactive, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import { FIN, ensureMeta } from './meta'
import { fmtMoney, ledgerMonthSum, ledgerSaveItems, defaultProjId, qs, defaultYears } from './financeLogic'
import './finance.css'

const year = ref(new Date().getFullYear())
const projId = ref(0)
const d = ref(null)
const cellV = reactive({}) // `${month}|${catKey}` → 编辑值
const stV = reactive({}) // month → {confirm, contract, payment, discount_amount, discount_households, remark}
const touchedM = reactive(new Set()) // 有类别输入编辑的月份
const att = reactive({ month: '', list: [] })
const attFile = ref(null)

const cats = computed(() => Object.keys(d.value?.categories || {}))

function initEditState(res) {
  Object.keys(cellV).forEach((k) => delete cellV[k])
  Object.keys(stV).forEach((k) => delete stV[k])
  touchedM.clear()
  res.months.forEach((m) => {
    cats.value.forEach((k) => {
      cellV[`${m.month}|${k}`] = (m.categories[k] || {}).amount ? String((m.categories[k] || {}).amount) : ''
    })
    const st = m.status || {}
    stV[m.month] = {
      confirm: st.confirm === 1 ? '1' : st.confirm === 0 ? '0' : '',
      contract: st.contract === 1 ? '1' : st.contract === 0 ? '0' : '',
      payment: st.payment === 1 ? '1' : st.payment === 0 ? '0' : '',
      discount_amount: st.discount_amount ? String(st.discount_amount) : '',
      discount_households: st.discount_households || '',
      remark: st.remark || '',
    }
  })
}

async function load() {
  const p = FIN.isAdmin ? projId.value : FIN.projectId || 0
  try {
    const res = await api('/api/finance/ledger' + qs({ project_id: p, year: year.value }))
    d.value = res
    initEditState(res)
  } catch (e) {
    toast(e.message, false)
  }
}

function mTotal(m) {
  if (!touchedM.has(m.month)) return m.total ? fmtMoney(m.total) : '—'
  const s = ledgerMonthSum(cats.value.map((k) => cellV[`${m.month}|${k}`]))
  return s > 0 ? fmtMoney(s) : '—'
}

async function saveMonth(m) {
  const p = FIN.isAdmin ? projId.value : FIN.projectId || 0
  const items = ledgerSaveItems(cats.value.map((k) => ({ category: k, value: cellV[`${m.month}|${k}`] })))
  const status = stV[m.month]
  try {
    const res = await api('/api/finance/ledger/save', { body: { project_id: p, month: m.month, items, status } })
    toast(`已保存 ${m.month}${res.saved ? `（${res.saved} 项金额）` : '（状态/减免）'}`)
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

function exportXlsx() {
  const p = FIN.isAdmin ? projId.value : FIN.projectId || 0
  download('/api/finance/export_ledger' + qs({ year: year.value, project_id: p }),
    `应收台账_${year.value === 'all' ? '全部年度' : year.value}.xlsx`)
}

async function openAtt(m) {
  const p = FIN.isAdmin ? projId.value : FIN.projectId || 0
  try {
    const res = await api('/api/finance/ledger/attachments' + qs({ project_id: p, month: m.month }))
    att.month = m.month
    att.list = res.attachments || []
  } catch (e) {
    toast(e.message, false)
  }
}

async function uploadAtt() {
  const f = attFile.value?.files?.[0]
  if (!f) { toast('请选择文件', false); return }
  const p = FIN.isAdmin ? projId.value : FIN.projectId || 0
  const fd = new FormData()
  fd.append('file', f)
  fd.append('project_id', p)
  fd.append('month', att.month)
  try {
    await api('/api/finance/ledger/attachment_upload', { form: fd })
    toast('上传成功')
    openAtt({ month: att.month })
  } catch (e) {
    toast(e.message, false)
  }
}

async function delAtt(a) {
  if (!confirm('确认删除该附件？')) return
  try {
    await api('/api/finance/ledger/attachment_delete', { body: { id: a.id } })
    toast('已删除')
    openAtt({ month: att.month })
  } catch (e) {
    toast(e.message, false)
  }
}

onMounted(async () => {
  await ensureMeta()
  if (FIN.isAdmin) projId.value = defaultProjId(FIN.projects)
  load()
})
</script>

<template>
  <div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <select v-if="FIN.isAdmin" v-model="projId" class="fsel" style="max-width:220px;">
        <option v-for="p in FIN.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
      <select v-model="year" class="fsel">
        <option value="all">截至目前（全部年度）</option>
        <option v-for="y in defaultYears()" :key="y" :value="y">{{ y }}年</option>
      </select>
      <button class="fbtn" @click="load">加载</button>
      <button class="fbtn ghost" @click="exportXlsx">导出 Excel</button>
      <span style="color:#888;font-size:12px;">金额单位：元；可直接在单元格内编辑，按“保存本月”落库</span>
    </div>

    <div v-if="d" style="overflow:auto;margin-top:14px;">
      <table class="ftbl" style="min-width:1900px;">
        <thead>
          <tr>
            <th style="position:sticky;left:0;background:#f0f4fa;">月份</th>
            <th v-for="k in cats" :key="k" style="min-width:110px;">{{ d.categories[k] }}</th>
            <th>月度合计</th>
            <th style="min-width:96px;">地产确认</th>
            <th style="min-width:96px;">合同签订</th>
            <th style="min-width:96px;">付款流程</th>
            <th style="min-width:100px;">减免金额</th>
            <th style="min-width:84px;">减免户数</th>
            <th style="min-width:150px;">备注</th>
            <th style="min-width:90px;">附件</th>
            <th style="position:sticky;right:0;background:#f0f4fa;">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in d.months" :key="m.month">
            <td style="position:sticky;left:0;background:#fff;font-weight:600;white-space:nowrap;">{{ m.month }}</td>
            <td v-for="k in cats" :key="k">
              <input class="fnum" v-model="cellV[`${m.month}|${k}`]" placeholder="0.00"
                @input="touchedM.add(m.month)" />
            </td>
            <td>{{ mTotal(m) }}</td>
            <td>
              <select v-model="stV[m.month].confirm" class="fsel" style="padding:5px 6px;font-size:12px;border-radius:8px;border:1px solid #d9d9d9;background:#fff;">
                <option value="">未填</option><option value="1">已确认</option><option value="0">未确认</option>
              </select>
            </td>
            <td>
              <select v-model="stV[m.month].contract" class="fsel" style="padding:5px 6px;font-size:12px;border-radius:8px;border:1px solid #d9d9d9;background:#fff;">
                <option value="">未填</option><option value="1">已签订</option><option value="0">未签订</option>
              </select>
            </td>
            <td>
              <select v-model="stV[m.month].payment" class="fsel" style="padding:5px 6px;font-size:12px;border-radius:8px;border:1px solid #d9d9d9;background:#fff;">
                <option value="">未填</option><option value="1">已完成</option><option value="0">未完成</option>
              </select>
            </td>
            <td><input class="fnum" v-model="stV[m.month].discount_amount" placeholder="0.00" /></td>
            <td><input style="width:70px;padding:6px 8px;border:1px solid #d9d9d9;border-radius:8px;" v-model="stV[m.month].discount_households" placeholder="户数" /></td>
            <td><input style="width:140px;padding:6px 8px;border:1px solid #d9d9d9;border-radius:8px;" v-model="stV[m.month].remark" placeholder="备注" /></td>
            <td style="white-space:nowrap;">
              <button :class="m.attachments ? 'att-tag has' : 'att-tag none'" @click="openAtt(m)"
                :title="m.attachments ? '点击查看/上传凭证附件' : ''">
                {{ m.attachments ? `📎${m.attachments}份` : '无附件' }}
              </button>
            </td>
            <td style="position:sticky;right:0;background:#fff;">
              <button class="fbtn sm" @click="saveMonth(m)">保存本月</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="att.month" style="margin-top:16px;background:#fff;border:1px solid #e4e3dd;border-radius:12px;padding:16px;">
      <div style="font-weight:600;margin-bottom:8px;">📎 {{ att.month }} 凭证附件（{{ att.list.length }}）</div>
      <div v-if="att.list.length" style="display:flex;gap:8px;flex-wrap:wrap;">
        <div v-for="a in att.list" :key="a.id" style="border:1px solid #e4e3dd;border-radius:8px;padding:8px 12px;font-size:12px;display:flex;align-items:center;gap:8px;">
          <span>📄 {{ a.file_name }}</span><span style="color:#999;">{{ Math.round(a.file_size / 1024) }}KB</span>
          <button class="fbtn sm ghost" @click="delAtt(a)">删除</button>
        </div>
      </div>
      <div v-else style="color:#999;font-size:13px;margin-bottom:6px;">暂无附件</div>
      <div style="margin-top:8px;display:flex;gap:8px;align-items:center;">
        <input ref="attFile" type="file" multiple style="font-size:12px;" />
        <button class="fbtn sm" @click="uploadAtt">上传附件</button>
      </div>
    </div>
  </div>
</template>
