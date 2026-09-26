// 采购驾驶舱 ECharts option 构建器（数据结构契约见 frontend/docs/purchase-api-contract.md §4）
import { cumAmount, round2 } from './purchaseLogic'
import { money } from '@/utils/format'

const moneyTip = { valueFormatter: (v) => money(v) + ' 元' }
const PALETTE = ['#3b82f6', '#06b6d4', '#8b5cf6', '#f43f5e', '#14b8a6', '#f59e0b', '#0ea5e9', '#a855f7', '#ec4899', '#10b981', '#64748b', '#84cc16']

export function projAmountOpt(rows) {
  return {
    color: PALETTE,
    tooltip: { trigger: 'axis', confine: true, ...moneyTip },
    grid: { left: 10, right: 20, top: 16, bottom: 24, containLabel: true },
    xAxis: { type: 'category', data: (rows || []).map((r) => r.name), axisLabel: { fontSize: 10, interval: 0, rotate: 30, width: 80, overflow: 'truncate' }, axisTick: { show: false } },
    yAxis: { type: 'value', axisLabel: { fontSize: 10, formatter: (v) => (v / 10000).toFixed(0) + '万' }, splitLine: { lineStyle: { color: '#eef2f7' } } },
    series: [{ type: 'bar', barMaxWidth: 26, itemStyle: { borderRadius: [4, 4, 0, 0], color: '#3b82f6' }, data: (rows || []).map((r) => round2(r.amount)) }],
  }
}

export function linesPieOpt(rows) {
  return {
    color: PALETTE,
    tooltip: { trigger: 'item', confine: true, formatter: (p) => p.name + '：' + money(p.value) + ' 元（' + p.percent + '%）' },
    legend: { bottom: 0, type: 'scroll', textStyle: { fontSize: 10 } },
    series: [{
      type: 'pie', radius: ['38%', '68%'], center: ['50%', '44%'],
      label: { fontSize: 10, formatter: (p) => p.name + '\n' + money(p.value) },
      data: (rows || []).map((r) => ({ name: r.line, value: round2(r.amount) })),
    }],
  }
}

// 环比/同比：本月 vs 对比期分组柱
export function compareOpt(rows, curLabel, baseLabel) {
  return {
    tooltip: { trigger: 'axis', confine: true, ...moneyTip },
    legend: { top: 0, textStyle: { fontSize: 11 }, data: [curLabel, baseLabel] },
    grid: { left: 10, right: 20, top: 30, bottom: 24, containLabel: true },
    xAxis: { type: 'category', data: (rows || []).map((r) => r.name), axisLabel: { fontSize: 10, interval: 0, rotate: 30, width: 80, overflow: 'truncate' }, axisTick: { show: false } },
    yAxis: { type: 'value', axisLabel: { fontSize: 10, formatter: (v) => (v / 10000).toFixed(0) + '万' }, splitLine: { lineStyle: { color: '#eef2f7' } } },
    series: [
      { name: curLabel, type: 'bar', barMaxWidth: 18, itemStyle: { borderRadius: [4, 4, 0, 0], color: '#3b82f6' }, data: (rows || []).map((r) => round2(r.current)) },
      { name: baseLabel, type: 'bar', barMaxWidth: 18, itemStyle: { borderRadius: [4, 4, 0, 0], color: '#cbd5e1' }, data: (rows || []).map((r) => round2(r.prev)) },
    ],
  }
}

export function budgetExecOpt(rows) {
  return {
    tooltip: { trigger: 'axis', confine: true, ...moneyTip },
    legend: { top: 0, textStyle: { fontSize: 11 }, data: ['预算', '实际'] },
    grid: { left: 10, right: 20, top: 30, bottom: 24, containLabel: true },
    xAxis: { type: 'category', data: (rows || []).map((r) => r.project_name), axisLabel: { fontSize: 10, interval: 0, rotate: 30, width: 80, overflow: 'truncate' }, axisTick: { show: false } },
    yAxis: { type: 'value', axisLabel: { fontSize: 10, formatter: (v) => (v / 10000).toFixed(0) + '万' }, splitLine: { lineStyle: { color: '#eef2f7' } } },
    series: [
      { name: '预算', type: 'bar', barMaxWidth: 18, itemStyle: { borderRadius: [4, 4, 0, 0], color: '#93c5fd' }, data: (rows || []).map((r) => (r.budget === null ? 0 : round2(r.budget))) },
      { name: '实际', type: 'bar', barMaxWidth: 18, itemStyle: { borderRadius: [4, 4, 0, 0], color: '#f59e0b' }, data: (rows || []).map((r) => round2(r.actual)) },
    ],
  }
}

export function customRatioOpt(d) {
  const out = round2(d.custom_amount || 0)
  const inner = round2((d.total_amount || 0) - (d.custom_amount || 0))
  return {
    color: ['#94a3b8', '#f43f5e'],
    tooltip: { trigger: 'item', confine: true, ...moneyTip },
    legend: { bottom: 0, textStyle: { fontSize: 10 }, data: ['清单内', '清单外'] },
    series: [{
      type: 'pie', radius: ['38%', '68%'], center: ['50%', '44%'],
      label: { fontSize: 10, formatter: (p) => p.name + '\n' + money(p.value) },
      data: [
        { name: '清单内', value: inner },
        { name: '清单外', value: out },
      ],
    }],
  }
}

export function annualTrendOpt(rows) {
  const data = cumAmount(rows)
  let c = 0
  const cum = data.map((x) => {
    return x.cum
  })
  void c
  return {
    tooltip: { trigger: 'axis', confine: true, ...moneyTip },
    legend: { top: 0, textStyle: { fontSize: 11 }, data: ['月度金额', '累计金额'] },
    grid: { left: 60, right: 60, top: 30, bottom: 24 },
    xAxis: { type: 'category', data: data.map((x) => x.month.slice(5) + '月'), axisLabel: { fontSize: 10 }, axisTick: { show: false } },
    yAxis: [
      { type: 'value', axisLabel: { fontSize: 10, formatter: (v) => (v / 10000).toFixed(0) + '万' }, splitLine: { lineStyle: { color: '#eef2f7' } } },
      { type: 'value', axisLabel: { fontSize: 10, formatter: (v) => (v / 10000).toFixed(0) + '万' }, splitLine: { show: false } },
    ],
    series: [
      { name: '月度金额', type: 'bar', barMaxWidth: 22, itemStyle: { borderRadius: [4, 4, 0, 0], color: '#6366f1' }, data: data.map((x) => x.amount) },
      { name: '累计金额', type: 'line', smooth: true, yAxisIndex: 1, itemStyle: { color: '#f59e0b' }, lineStyle: { width: 2.5 }, data: cum },
    ],
  }
}

export function hbarOpt(rows, nameKey, valueKey, color, unit) {
  const sorted = (rows || []).slice().sort((a, b) => (b[valueKey] || 0) - (a[valueKey] || 0)).slice(0, 20)
  return {
    tooltip: { trigger: 'axis', confine: true, valueFormatter: (v) => (unit === '元' ? money(v) + ' 元' : v) },
    grid: { left: 10, right: 46, top: 10, bottom: 20, containLabel: true },
    xAxis: { type: 'value', axisLabel: { fontSize: 10 }, splitLine: { lineStyle: { color: '#eef2f7' } } },
    yAxis: { type: 'category', data: sorted.map((r) => r[nameKey]).reverse(), axisLabel: { fontSize: 10, width: 130, overflow: 'truncate' }, axisTick: { show: false } },
    series: [{ type: 'bar', barWidth: 12, itemStyle: { borderRadius: [0, 6, 6, 0], color }, data: sorted.map((r) => round2(r[valueKey] || 0)).reverse(), label: { show: true, position: 'right', fontSize: 9 } }],
  }
}

export function priceTrendOpt(trend) {
  const months = Object.keys(trend.months || {})
  return {
    tooltip: { trigger: 'axis', confine: true, formatter: (ps) => {
      const m = ps[0].axisValue
      const cell = trend.months[m]
      if (!cell) return m + '：无采购'
      return m + '：均价 ' + money(cell.avg_price) + ' 元（' + (cell.cnt || 0) + ' 次 / 总量 ' + (cell.qty || 0) + '）'
    } },
    grid: { left: 60, right: 20, top: 30, bottom: 24 },
    xAxis: { type: 'category', data: months.map((m) => m.slice(5) + '月'), axisLabel: { fontSize: 10 }, axisTick: { show: false } },
    yAxis: { type: 'value', axisLabel: { fontSize: 10, formatter: (v) => (v / 10000).toFixed(0) + '万' }, splitLine: { lineStyle: { color: '#eef2f7' } } },
    series: [{
      name: trend.year + '年月度均价', type: 'line', smooth: true, connectNulls: false,
      symbolSize: 6, itemStyle: { color: '#3b82f6' }, lineStyle: { width: 2.5 },
      data: months.map((m) => (trend.months[m] ? trend.months[m].avg_price : null)),
    }],
  }
}
