// 采购驾驶舱 ECharts option 构建器（数据结构契约见 frontend/docs/purchase-api-contract.md §4）
// 设计规范：方向 B 浅色 / 方向 C 深色，配色收敛为同明度带 8 色
import { cumAmount, round2 } from './purchaseLogic'
import { money } from '@/utils/format'

const moneyTip = { valueFormatter: (v) => money(v) + ' 元' }

const PALETTE_LIGHT = ['#165DFF', '#3D7BFF', '#00B42A', '#FF7D00', '#8B5CF6', '#14B8A6', '#F53F3F', '#0EA5E9']
const PALETTE_DARK = ['#4A8BFF', '#7FA8FF', '#5BD07C', '#FFB65C', '#A98BFF', '#3FD5C0', '#FF8A8A', '#4FC3F7']

function tone(dark) {
  return {
    palette: dark ? PALETTE_DARK : PALETTE_LIGHT,
    text: dark ? '#A9B4C6' : '#4E5969',
    muted: dark ? '#71809A' : '#86909C',
    grid: dark ? '#1F2A40' : '#EEF2F7',
    brand: dark ? '#4A8BFF' : '#165DFF',
    brandSoft: dark ? 'rgba(74,139,255,.35)' : '#E8F3FF',
  }
}

/** 条线占比：环形图，标签只保留百分比，金额放 tooltip 与图例 */
export function linesPieOpt(rows, dark = false) {
  const t = tone(dark)
  return {
    color: t.palette,
    tooltip: { trigger: 'item', confine: true, formatter: (p) => p.name + '：' + money(p.value) + ' 元（' + p.percent + '%）' },
    legend: { bottom: 0, type: 'scroll', icon: 'circle', itemWidth: 7, itemHeight: 7, itemGap: 12, textStyle: { fontSize: 10, color: t.muted } },
    series: [{
      type: 'pie',
      radius: ['46%', '70%'],
      center: ['50%', '42%'],
      avoidLabelOverlap: true,
      itemStyle: { borderWidth: dark ? 2 : 2, borderColor: dark ? '#131C2E' : '#fff' },
      label: { fontSize: 10, color: t.text, formatter: (p) => p.percent + '%' },
      labelLine: { length: 6, length2: 6, lineStyle: { color: t.grid } },
      data: (rows || []).map((r) => ({ name: r.line, value: round2(r.amount) })),
    }],
  }
}

/** 条线占比：横向条形图（金额+百分比），替代环形图，条线多时更易读 */
export function linesBarOpt(rows, dark = false) {
  const t = tone(dark)
  const list = (rows || []).slice()
  const total = list.reduce((s, r) => s + (Number(r.amount) || 0), 0)
  const sorted = list.sort((a, b) => (Number(b.amount) || 0) - (Number(a.amount) || 0))
  return {
    tooltip: {
      trigger: 'axis', confine: true,
      formatter: (ps) => {
        const r = sorted[ps[0].dataIndex]
        const pct = total > 0 ? ((Number(r.amount) || 0) / total * 100).toFixed(1) : '0'
        return r.line + '：' + money(r.amount) + ' 元（' + pct + '%）'
      },
    },
    grid: { left: 8, right: 56, top: 6, bottom: 6, containLabel: true },
    xAxis: { type: 'value', axisLabel: { show: false }, splitLine: { show: false }, axisLine: { show: false } },
    yAxis: {
      type: 'category',
      data: sorted.map((r) => r.line).reverse(),
      axisLabel: { fontSize: 11, color: t.text, width: 100, overflow: 'truncate' },
      axisTick: { show: false },
      axisLine: { show: false },
    },
    series: [{
      type: 'bar', barWidth: 14,
      itemStyle: { borderRadius: [0, 6, 6, 0], color: t.brand },
      label: {
        show: true, position: 'right', fontSize: 10, color: t.muted,
        formatter: (p) => {
          const r = sorted[p.dataIndex]
          const pct = total > 0 ? ((Number(r.amount) || 0) / total * 100).toFixed(1) : '0'
          return wanLabel(r.amount) + '万 (' + pct + '%)'
        },
      },
      data: sorted.map((r) => round2(r.amount || 0)).reverse(),
    }],
  }
}

function wanLabel(v) {
  return ((Number(v) || 0) / 10000).toFixed(1)
}

/** 年度逐月：柱（月度金额）+ 面积线（累计金额），单轴，避免双轴误读 */
export function annualTrendOpt(rows, dark = false) {
  const t = tone(dark)
  const data = cumAmount(rows)
  const cum = data.map((x) => x.cum)
  return {
    color: [t.brand, dark ? '#FFB65C' : '#FF7D00'],
    tooltip: { trigger: 'axis', confine: true, ...moneyTip },
    legend: { top: 0, icon: 'roundRect', itemWidth: 9, itemHeight: 9, textStyle: { fontSize: 11, color: t.muted } },
    grid: { left: 10, right: 22, top: 34, bottom: 22, containLabel: true },
    xAxis: {
      type: 'category',
      data: data.map((x) => x.month.slice(5) + '月'),
      axisLabel: { fontSize: 10, color: t.muted },
      axisTick: { show: false },
      axisLine: { lineStyle: { color: t.grid } },
    },
    yAxis: {
      type: 'value',
      axisLabel: { fontSize: 10, color: t.muted, formatter: (v) => (v / 10000).toFixed(0) + '万' },
      splitLine: { lineStyle: { color: t.grid } },
    },
    series: [
      {
        name: '月度金额', type: 'bar', barMaxWidth: 22,
        itemStyle: { borderRadius: [4, 4, 0, 0] },
        data: data.map((x) => x.amount),
      },
      {
        name: '累计金额', type: 'line', smooth: true, symbol: 'none',
        lineStyle: { width: 2.2 },
        areaStyle: {
          color: {
            type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
            colorStops: [
              { offset: 0, color: dark ? 'rgba(255,182,92,.22)' : 'rgba(255,125,0,.16)' },
              { offset: 1, color: 'rgba(255,125,0,0)' },
            ],
          },
        },
        data: cum,
      },
    ],
  }
}

/** 横条排行（TOP N），条高与字号放大，避免 20 条挤成一片 */
export function hbarOpt(rows, nameKey, valueKey, color, unit, dark = false, limit = 10) {
  const t = tone(dark)
  const sorted = (rows || []).slice().sort((a, b) => (b[valueKey] || 0) - (a[valueKey] || 0)).slice(0, limit)
  return {
    tooltip: { trigger: 'axis', confine: true, valueFormatter: (v) => (unit === '元' ? money(v) + ' 元' : v + ' ' + unit) },
    grid: { left: 8, right: 42, top: 8, bottom: 8, containLabel: true },
    xAxis: { type: 'value', axisLabel: { show: false }, splitLine: { show: false }, axisLine: { show: false } },
    yAxis: {
      type: 'category',
      data: sorted.map((r) => r[nameKey]).reverse(),
      axisLabel: { fontSize: 10.5, color: t.text, width: 120, overflow: 'truncate' },
      axisTick: { show: false },
      axisLine: { show: false },
    },
    series: [{
      type: 'bar', barWidth: 13,
      itemStyle: { borderRadius: [0, 6, 6, 0], color: color || t.brand },
      label: { show: true, position: 'right', fontSize: 9.5, color: t.muted },
      data: sorted.map((r) => round2(r[valueKey] || 0)).reverse(),
    }],
  }
}

/** 单品价格趋势：按元显示（原先除以 10000 会把几十元的单价全部显示成 0万） */
export function priceTrendOpt(trend, dark = false) {
  const t = tone(dark)
  const months = Object.keys(trend.months || {})
  return {
    tooltip: {
      trigger: 'axis', confine: true,
      formatter: (ps) => {
        const m = ps[0].axisValue
        const cell = trend.months[m]
        if (!cell) return m + '：无采购'
        return m + '：均价 ' + money(cell.avg_price) + ' 元（' + (cell.cnt || 0) + ' 次 / 总量 ' + (cell.qty || 0) + '）'
      },
    },
    grid: { left: 10, right: 22, top: 26, bottom: 22, containLabel: true },
    xAxis: {
      type: 'category',
      data: months.map((m) => m.slice(5) + '月'),
      axisLabel: { fontSize: 10, color: t.muted },
      axisTick: { show: false },
      axisLine: { lineStyle: { color: t.grid } },
    },
    yAxis: {
      type: 'value',
      name: '元',
      nameTextStyle: { fontSize: 9, color: t.muted },
      axisLabel: { fontSize: 10, color: t.muted, formatter: (v) => money(v) },
      splitLine: { lineStyle: { color: t.grid } },
    },
    series: [{
      name: trend.year + '年月度均价',
      type: 'line', smooth: true, connectNulls: false,
      symbolSize: 6,
      itemStyle: { color: t.brand },
      lineStyle: { width: 2.4 },
      areaStyle: {
        color: {
          type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
          colorStops: [
            { offset: 0, color: dark ? 'rgba(74,139,255,.28)' : 'rgba(22,93,255,.16)' },
            { offset: 1, color: 'rgba(22,93,255,0)' },
          ],
        },
      },
      data: months.map((m) => (trend.months[m] ? trend.months[m].avg_price : null)),
    }],
  }
}
