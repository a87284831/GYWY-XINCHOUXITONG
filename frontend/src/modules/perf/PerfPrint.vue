<template>
  <div class="print-wrap">
    <!-- 屏幕工具条（打印时隐藏） -->
    <div v-if="!loadErr" class="print-bar no-print">
      <button class="btn sm" @click="$router.back()">← 返回</button>
      <span class="hint">建议使用浏览器「打印」选择 A4 纵向、边距默认，可另存为 PDF 后打印纸质签字。</span>
      <button class="btn sm primary" style="margin-left:auto" :disabled="!p" @click="doPrint">🖨 打印 / 另存 PDF</button>
    </div>

    <div v-if="loadErr" class="msg err" style="margin:20px">{{ loadErr }}</div>
    <div v-else-if="!p" style="padding:24px;color:#64748b">加载中…</div>

    <div v-else class="a4">
      <h1 class="pp-title">绩效考核表</h1>
      <div class="pp-sub">{{ p.periodStart }} 至 {{ p.periodEnd }}</div>

      <!-- 抬头信息 -->
      <table class="pp-head">
        <tr>
          <td class="k">被考核人</td><td class="v">{{ p.employeeName }}</td>
          <td class="k">条线/岗位</td><td class="v">{{ p.line || '-' }}</td>
        </tr>
        <tr>
          <td class="k">所属项目</td><td class="v">{{ p.project || '-' }}</td>
          <td class="k">考核周期</td><td class="v">{{ p.periodStart }} ~ {{ p.periodEnd }}</td>
        </tr>
        <tr>
          <td class="k">发起人</td><td class="v">{{ p.founderName || '-' }}</td>
          <td class="k">考评等级</td><td class="v"><b>{{ p.grade || '-' }}</b></td>
        </tr>
      </table>

      <!-- 指标明细 -->
      <table class="pp-grid">
        <thead>
          <tr>
            <th style="width:32px">序号</th><th style="width:70px">类别</th>
            <th>指标内容</th><th style="width:210px">定义/扣分规则</th>
            <th style="width:42px">权重</th><th style="width:64px">算分方式</th>
            <th style="width:150px">完成/核查情况</th>
            <th style="width:48px">自评分</th><th style="width:48px">上级分</th><th style="width:48px">最终分</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="cat in p.categories || []" :key="cat.name">
            <tr v-for="(it, ii) in cat.items || []" :key="it.id">
              <td class="c">{{ seqOf(cat, it) }}</td>
              <td v-if="ii === 0" :rowspan="(cat.items || []).length" class="c cat">{{ cat.name }}</td>
              <td>{{ it.content }}</td>
              <td class="def">{{ it.definition }}</td>
              <td class="c">{{ fmt(it.weight) }}</td>
              <td class="c">{{ CALC_LABEL[it.calcType] || it.calcType }}</td>
              <td class="situation">
                <template v-if="isSkipped(it)">未指派核查/填报人，该项不考核，不计分</template>
                <template v-else-if="it.calcType === 'check'">{{ it.checkScore != null && it.checkScore !== '' ? '核查定分：' + it.checkScore : '-' }}<template v-if="it.actualText">（{{ it.actualText }}）</template><div v-if="it.reporterName" class="who">核查人：{{ it.reporterName }}</div></template>
                <template v-else>{{ it.actualText || it.actualValue || '-' }}<div v-if="it.reporterName" class="who">填报人：{{ it.reporterName }}</div></template>
              </td>
              <td class="c">{{ it.calcType === 'manual' ? fmt(it.selfScore) : '—' }}</td>
              <td class="c">{{ it.calcType === 'manual' ? fmt(it.approverScore) : '—' }}</td>
              <td class="c"><b>{{ isSkipped(it) ? '未考核' : fmt(it.finalScore) }}</b></td>
            </tr>
          </template>
        </tbody>
      </table>

      <!-- 汇总 -->
      <table class="pp-sum">
        <tr>
          <td>权重合计</td><td class="c">{{ weightSum }}</td>
          <td>自评总分</td><td class="c">{{ fmt(p.selfTotal) }}</td>
          <td>上级评分总分</td><td class="c">{{ fmt(p.approverTotal) }}</td>
          <td>最终总分</td><td class="c big">{{ fmt(p.finalTotal) }}</td>
          <td>等级</td><td class="c big">{{ p.grade || '-' }}</td>
        </tr>
      </table>
      <div class="pp-note">计分说明：比例计分/阶梯扣分/达标扣分按既定公式自动算分；核查定分由核查人在 0~权重 范围内定分；主观评分最终分 = 自评分×{{ sw.self }}% + 上级评分×{{ sw.approver }}%。客观项一经数据填报/核查即锁定。</div>

      <!-- 审批意见 -->
      <div v-if="opinions.length" class="pp-block">
        <div class="pp-h">审批意见</div>
        <table class="pp-op">
          <tbody>
            <tr v-for="(o, i) in opinions" :key="i">
              <td style="width:110px">{{ i + 1 }}级审批人：{{ o.name }}</td>
              <td>{{ o.opinion || '（无意见）' }}</td>
              <td style="width:140px" class="c">{{ o.time ? o.time.slice(0, 16) : '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 自评依据附件 -->
      <div v-if="attachments.length" class="pp-block">
        <div class="pp-h">自评依据附件</div>
        <div class="pp-att">
          <figure v-for="att in attachments" :key="att.file">
            <img v-if="att._url" :src="att._url" :alt="att.name" />
            <div v-else class="pdfbox">PDF</div>
            <figcaption>{{ att.name }}<span v-if="att.uploaderName">（{{ att.uploaderName }}）</span></figcaption>
          </figure>
        </div>
      </div>

      <!-- 签字栏 -->
      <div class="pp-sign">
        <div>被考核人签字：________________</div>
        <div v-for="(a, i) in (p.approvers || [])" :key="i">{{ i + 1 }}级审批人签字（{{ a.name }}）：________________</div>
        <div>归档日期：______年____月____日</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api/client'
import { perfFmt, perfWeightSum, CALC_LABEL, OBJECTIVE_TYPES } from './perfLogic'

const route = useRoute()
const p = ref(null)
const meta = ref(null)
const loadErr = ref('')

const fmt = perfFmt
// 客观项未指派核查/填报人：发起阶段即承诺"自动跳过"，纸质表明确标注未考核、不计分
const isSkipped = (it) => OBJECTIVE_TYPES.includes(it.calcType) && !it.reporterId
const sw = computed(() => (meta.value && meta.value.scoreWeights) || { self: 50, approver: 50 })
const weightSum = computed(() => (p.value ? perfWeightSum(p.value) : 0))
const opinions = computed(() => {
  if (!p.value) return []
  const names = p.value.approverNames || []
  const times = p.value.approverTimes || []
  const ops = p.value.approverOpinions || []
  const aps = p.value.approvers || []
  return aps.map((a, i) => ({ name: names[i] || a.name, time: times[i] || '', opinion: ops[i] || '' }))
    .filter((o, i) => names[i] || ops[i])
})
// 仅取主观项上的附件
const attachments = computed(() => {
  const out = []
  ;(p.value?.categories || []).forEach((c) => (c.items || []).forEach((it) => {
    if (it.calcType === 'manual') (it.attachments || []).forEach((a) => out.push(a))
  }))
  return out
})

function seqOf(cat, it) {
  let seq = 1
  for (const c of p.value.categories || []) {
    for (const i2 of c.items || []) {
      if (c === cat && i2 === it) return seq
      seq++
    }
  }
  return seq
}

const isImageAtt = (att) => /\.(jpe?g|png)$/i.test(att.file || '')
async function ensureAttUrl(att) {
  try {
    const blob = await api(`/api/performance/attachment?id=${p.value.id}&file=${encodeURIComponent(att.file)}`)
    att._url = URL.createObjectURL(blob)
  } catch (e) { att._url = '' }
}

onMounted(async () => {
  try {
    const d = await api(`/api/performance/plans/${route.params.id}`)
    meta.value = d
    p.value = d.plan
    await Promise.all(attachments.value.filter(isImageAtt).map(ensureAttUrl))
  } catch (e) {
    loadErr.value = e.message
  }
})

function doPrint() { window.print() }
</script>

<style scoped>
.print-wrap { background: #e5e7eb; min-height: 100vh; padding: 18px 0; }
.print-bar { max-width: 880px; margin: 0 auto 14px; display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0, 0, 0, .12); }
.a4 { width: 800px; min-height: 1100px; margin: 0 auto; background: #fff; padding: 40px 44px; box-sizing: border-box; color: #111827; font-size: 12.5px; }
.pp-title { text-align: center; font-size: 24px; letter-spacing: 6px; margin: 0 0 2px; }
.pp-sub { text-align: center; color: #4b5563; margin-bottom: 18px; }
.pp-head { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
.pp-head td { border: 1px solid #9ca3af; padding: 6px 9px; }
.pp-head .k { background: #f3f4f6; width: 80px; font-weight: 600; white-space: nowrap; }
.pp-grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.pp-grid th, .pp-grid td { border: 1px solid #9ca3af; padding: 5px 6px; vertical-align: top; }
.pp-grid th { background: #f3f4f6; font-weight: 600; text-align: center; }
.pp-grid .c { text-align: center; }
.pp-grid .cat { background: #f9fafb; font-weight: 600; }
.pp-grid .def { color: #374151; font-size: 11px; }
.pp-grid .situation { font-size: 11.5px; }
.pp-grid .who { color: #6b7280; margin-top: 2px; }
.pp-sum { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.pp-sum td { border: 1px solid #9ca3af; padding: 6px 8px; }
.pp-sum .c { text-align: center; }
.pp-sum .big { font-size: 15px; font-weight: 700; color: #1e3a8a; }
.pp-note { color: #4b5563; font-size: 11px; line-height: 1.6; margin-bottom: 14px; }
.pp-block { margin-bottom: 14px; }
.pp-h { font-weight: 700; border-left: 4px solid #1e3a8a; padding-left: 8px; margin-bottom: 6px; }
.pp-op { width: 100%; border-collapse: collapse; }
.pp-op td { border: 1px solid #9ca3af; padding: 6px 8px; }
.pp-att { display: flex; flex-wrap: wrap; gap: 10px; }
.pp-att figure { margin: 0; width: 150px; border: 1px solid #d1d5db; border-radius: 4px; padding: 6px; text-align: center; box-sizing: border-box; }
.pp-att img { max-width: 100%; max-height: 130px; object-fit: contain; }
.pp-att .pdfbox { height: 90px; line-height: 90px; background: #fef2f2; color: #dc2626; font-weight: 700; border-radius: 4px; }
.pp-att figcaption { font-size: 10.5px; color: #374151; margin-top: 4px; word-break: break-all; }
.pp-sign { display: flex; flex-direction: column; gap: 13px; margin-top: 30px; font-size: 13px; }
@media print {
  body { background: #fff; }
  .print-wrap { background: #fff; padding: 0; }
  .no-print { display: none !important; }
  .a4 { width: auto; min-height: auto; margin: 0; padding: 0 6mm; box-shadow: none; }
  @page { size: A4 portrait; margin: 10mm; }
}
</style>
