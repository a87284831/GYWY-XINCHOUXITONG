// 财务图表配置（逐项复刻旧财务页的 ECharts option；实例管理统一 destroy/resize）
import * as echarts from 'echarts'
import { topN, cumVals, fmtMoney } from './financeLogic'

const wan = (v) => (v / 10000).toFixed(0) + '万'
const wan1 = (v) => (v / 10000).toFixed(1) + 'w'
const moneyTip = { valueFormatter: (v) => fmtMoney(v) + ' 元' }
const grad = (x0, y0, x2, y2, from, to) =>
  new echarts.graphic.LinearGradient(x0, y0, x2, y2, [{ offset: 0, color: from }, { offset: 1, color: to }])

const instances = new Map()
let resizeHandler = null

export const charts = {
  init(id, option) {
    const el = document.getElementById(id)
    if (!el) return null
    const c = echarts.init(el)
    c.setOption(option)
    instances.set(id, c)
    if (!resizeHandler) {
      resizeHandler = () => instances.forEach((x) => x.resize())
      window.addEventListener('resize', resizeHandler)
    }
    return c
  },
  dispose() {
    instances.forEach((c) => c.dispose())
    instances.clear()
  },
}

export function trendAreaOpt(months, vals, cum) {
  return {
    color: ['#3b82f6', '#06b6d4'],
    tooltip: { trigger: 'axis', confine: true, ...moneyTip },
    legend: { top: 0, textStyle: { fontSize: 11 }, data: ['月度应收', '累计应收'] },
    grid: { left: 70, right: 70, top: 34, bottom: 26 },
    xAxis: { type: 'category', data: months, axisLabel: { fontSize: 11, formatter: (m) => (m || '').slice(5) + '月' }, axisTick: { show: false } },
    yAxis: [
      { type: 'value', name: '月度', axisLabel: { fontSize: 10, formatter: wan }, splitLine: { lineStyle: { color: '#eef2f7' } } },
      { type: 'value', name: '累计', axisLabel: { fontSize: 10, formatter: wan }, splitLine: { show: false } },
    ],
    series: [
      { name: '月度应收', type: 'line', smooth: true, symbolSize: 5, data: vals, itemStyle: { color: '#3b82f6' }, lineStyle: { width: 2.5 }, areaStyle: { opacity: 0.08 } },
      { name: '累计应收', type: 'line', smooth: true, symbolSize: 4, yAxisIndex: 1, data: cum, itemStyle: { color: '#06b6d4' }, lineStyle: { width: 2, type: 'dashed' }, areaStyle: { color: 'rgba(6,182,212,0.12)' } },
    ],
  }
}

export function sankeyOpt(data) {
  return {
    tooltip: { trigger: 'item', confine: true, ...moneyTip },
    series: [{
      type: 'sankey', left: 10, right: 130, top: 16, bottom: 10,
      nodeWidth: 12, nodeGap: 10, draggable: true,
      emphasis: { focus: 'adjacency' },
      data: data.nodes,
      links: data.links,
      label: { fontSize: 10, color: '#475569' },
      lineStyle: { color: 'gradient', opacity: 0.25, curveness: 0.5 },
    }],
  }
}

export function roseOpt(data) {
  const sum = data.reduce((a, x) => a + x.value, 0)
  return {
    color: ['#3b82f6', '#06b6d4', '#8b5cf6', '#f43f5e', '#14b8a6', '#f59e0b', '#0ea5e9', '#a855f7', '#ec4899', '#10b981', '#64748b'],
    tooltip: { trigger: 'item', confine: true, formatter: (p) => p.name + '：' + fmtMoney(p.value) + ' 元（' + (sum ? ((p.value / sum) * 100).toFixed(1) : 0) + '%）' },
    legend: { bottom: 0, type: 'scroll', textStyle: { fontSize: 10 }, itemWidth: 12, itemHeight: 8 },
    series: [{
      type: 'pie', roseType: 'radius', radius: ['18%', '72%'], center: ['50%', '46%'],
      itemStyle: { borderRadius: 6, borderColor: '#fff', borderWidth: 2 },
      label: { fontSize: 9, formatter: (p) => p.name + '\n' + (p.value / 10000).toFixed(1) + 'w' },
      labelLine: { length: 6, length2: 6 },
      data,
    }],
  }
}

export function hbarOpt(data, colors) {
  const top = topN(data, 12)
  return {
    tooltip: { trigger: 'axis', confine: true, ...moneyTip },
    grid: { left: 10, right: 60, top: 16, bottom: 24, containLabel: true },
    xAxis: { type: 'value', axisLabel: { fontSize: 10, formatter: wan }, splitLine: { lineStyle: { color: '#eef2f7' } } },
    yAxis: { type: 'category', data: top.map((x) => x.name).reverse(), axisLabel: { fontSize: 10, width: 92, overflow: 'truncate' }, axisTick: { show: false } },
    series: [{
      type: 'bar', data: top.map((x) => x.value).reverse(), barWidth: 13,
      itemStyle: { borderRadius: [0, 7, 7, 0], color: grad(0, 0, 1, 0, colors[0], colors[1]) },
      label: { show: true, position: 'right', fontSize: 9, formatter: (p) => wan1(p.value) },
    }],
  }
}

export function heatOpt(heat) {
  return {
    tooltip: { confine: true, formatter: (p) => {
      const nm = heat.projects[p.value[1]] || ''
      const mm = (heat.months[p.value[0]] || '').slice(5) + '月'
      return nm + ' · ' + mm + '：' + fmtMoney(p.value[2]) + ' 元'
    } },
    grid: { left: 110, right: 20, top: 16, bottom: 56 },
    xAxis: { type: 'category', data: heat.months.map((m) => (m || '').slice(5) + '月'), axisLabel: { fontSize: 10 }, splitArea: { show: true }, axisTick: { show: false } },
    yAxis: { type: 'category', data: heat.projects, axisLabel: { fontSize: 10, width: 100, overflow: 'truncate' }, splitArea: { show: true }, axisTick: { show: false } },
    visualMap: {
      min: 0, max: heat.max || 1, calculable: true, orient: 'horizontal', left: 'center', bottom: 6,
      text: ['高', '低'], textStyle: { fontSize: 10 }, itemWidth: 12, itemHeight: 110,
      inRange: { color: ['#eef4ff', '#93c5fd', '#3b82f6', '#1d4ed8'] },
    },
    series: [{
      type: 'heatmap', data: heat.points,
      label: { show: false },
      itemStyle: { borderColor: '#fff', borderWidth: 2, borderRadius: 4 },
      emphasis: { itemStyle: { shadowBlur: 8, shadowColor: 'rgba(0,0,0,0.2)' } },
    }],
  }
}

export function histOpt(hist) {
  const vals = hist.map((x) => x.value)
  return {
    tooltip: { trigger: 'axis', confine: true, ...moneyTip },
    legend: { top: 0, textStyle: { fontSize: 11 }, data: ['年度应收', '累计'] },
    grid: { left: 70, right: 70, top: 34, bottom: 26 },
    xAxis: { type: 'category', data: hist.map((x) => x.name + '年'), axisLabel: { fontSize: 11 }, axisTick: { show: false } },
    yAxis: [
      { type: 'value', axisLabel: { fontSize: 10, formatter: wan }, splitLine: { lineStyle: { color: '#eef2f7' } } },
      { type: 'value', axisLabel: { fontSize: 10, formatter: wan }, splitLine: { show: false } },
    ],
    series: [
      { name: '年度应收', type: 'bar', barWidth: 34, data: vals, itemStyle: { borderRadius: [8, 8, 0, 0], color: grad(0, 0, 0, 1, '#6366f1', '#a5b4fc') }, label: { show: true, position: 'top', fontSize: 9, formatter: (p) => wan1(p.value) } },
      { name: '累计', type: 'line', smooth: true, yAxisIndex: 1, data: cumVals(vals), itemStyle: { color: '#f59e0b' }, lineStyle: { width: 2.5 } },
    ],
  }
}
