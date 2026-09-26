// Chart.js 封装 — 复刻旧版 app.js:2178-2235（rc/rcBar/rcHBar/rcLine/rcDoughnut 的配置逐项对齐）
import Chart from 'chart.js/auto'

export const PALETTE = ['#2563eb', '#f59e0b', '#16a34a', '#ef4444', '#8b5cf6', '#0ea5e9', '#f97316', '#10b981', '#e11d48', '#6366f1', '#14b8a6', '#d97706']

// 页面级图表管理器：复刻旧 REPORT.charts 的 destroy 逻辑，卸载时统一销毁
export function createCharts() {
  const map = {}
  return {
    render(key, el, cfg) {
      if (!el) return null
      if (map[key]) { map[key].destroy(); delete map[key] }
      map[key] = new Chart(el, cfg)
      return map[key]
    },
    destroyAll() {
      Object.values(map).forEach((c) => c.destroy())
      Object.keys(map).forEach((k) => delete map[k])
    },
  }
}

export function rBar(charts, key, el, labels, data, opt) {
  return charts.render(key, el, {
    type: 'bar',
    data: { labels, datasets: [{ label: (opt && opt.label) || '', data, backgroundColor: (opt && opt.color) || '#2563eb', borderRadius: 4, barThickness: (opt && opt.thick) || undefined }] },
    options: Object.assign({
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: !!(opt && opt.label) }, tooltip: (opt && opt.tooltip) || undefined },
      scales: { x: { ticks: { color: '#64748b', font: { size: 10 } }, grid: { color: 'rgba(148,163,184,.15)' } }, y: { beginAtZero: true, ticks: { color: '#64748b', font: { size: 10 } }, grid: { color: 'rgba(148,163,184,.15)' } } },
    }, (opt && opt.opts) || {}),
  })
}

export function rHBar(charts, key, el, labels, data, color, fmt) {
  return charts.render(key, el, {
    type: 'bar',
    data: { labels, datasets: [{ label: '', data, backgroundColor: color, borderRadius: 4, barThickness: 18 }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: fmt ? { callbacks: { label: (c) => fmt(c.raw) } } : undefined },
      scales: { x: { beginAtZero: true, ticks: { color: '#64748b', font: { size: 10 } }, grid: { color: 'rgba(148,163,184,.15)' } }, y: { ticks: { color: '#475569', font: { size: 11 } }, grid: { display: false } } } },
  })
}

export function rLine(charts, key, el, labels, series, extra) {
  const datasets = series.map((s, i) => ({
    label: s.label, data: s.data, borderColor: s.color || PALETTE[i % PALETTE.length],
    backgroundColor: s.color || PALETTE[i % PALETTE.length], tension: 0.3, borderWidth: 2, pointRadius: 3, fill: s.fill || false,
  }))
  return charts.render(key, el, {
    type: 'line', data: { labels, datasets },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10 }, color: '#475569' } } },
      scales: { x: { ticks: { color: '#64748b', font: { size: 10 } }, grid: { color: 'rgba(148,163,184,.15)' } }, y: { beginAtZero: true, ticks: { color: '#64748b', font: { size: 10 }, callback: (v) => (extra && extra.fmt) ? extra.fmt(v) : v }, grid: { color: 'rgba(148,163,184,.15)' } } } },
  })
}

export function rDoughnut(charts, key, el, labels, data, colors, centerLabel, total, fmt) {
  return charts.render(key, el, {
    type: 'doughnut',
    data: { labels, datasets: [{ data, backgroundColor: colors.slice(0, labels.length), borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '58%',
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 }, color: '#475569', padding: 6 } },
        tooltip: { callbacks: { label: (c) => ' ' + c.label + '：' + (fmt ? fmt(c.parsed) : c.parsed) } } } },
    plugins: centerLabel && total !== undefined ? [{ id: 'centerText', afterDraw(chart) {
      const ctx = chart.ctx, cx = (chart.chartArea.left + chart.chartArea.right) / 2, cy = (chart.chartArea.top + chart.chartArea.bottom) / 2
      ctx.save(); ctx.textAlign = 'center'; ctx.textBaseline = 'middle'
      ctx.fillStyle = '#94a3b8'; ctx.font = '600 10px "Microsoft YaHei"'; ctx.fillText(centerLabel, cx, cy - 10)
      ctx.fillStyle = '#1e293b'; ctx.font = '800 15px "Microsoft YaHei"'; ctx.fillText(fmt ? fmt(total) : total, cx, cy + 10); ctx.restore()
    } }] : [],
  })
}

// 万/元简写（薪酬报表 y 轴）
export function fmtWan(v) { return v >= 10000 ? (v / 10000).toFixed(1) + '万' : v }
