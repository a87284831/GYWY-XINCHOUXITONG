// 复刻旧版 initStickyCols（app.js:1168-1183）：工资表前 n 列横向滚动时吸附
export function initStickyCols(table, n) {
  if (!table) return
  const firstRow = table.querySelector('tbody tr')
  if (!firstRow) return
  let acc = 0
  const counts = Math.min(n, firstRow.children.length)
  for (let i = 0; i < counts; i++) {
    const w = firstRow.children[i].offsetWidth
    table.querySelectorAll(`thead th:nth-child(${i + 1}), tbody td:nth-child(${i + 1}), tfoot td:nth-child(${i + 1})`).forEach((cell) => {
      cell.classList.add('sticky-col')
      cell.style.left = acc + 'px'
    })
    acc += w
  }
}
