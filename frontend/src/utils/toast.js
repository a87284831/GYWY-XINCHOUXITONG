// 复刻旧版 toast（app.js:66-73）：顶部居中浮层，3.2s 自动消失
export function toast(msg, ok = true) {
  const el = document.createElement('div')
  el.style.cssText =
    'position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:999;padding:10px 22px;border-radius:6px;font-size:13.5px;box-shadow:0 4px 16px rgba(0,0,0,.15);' +
    (ok ? 'background:#16a34a;color:#fff;' : 'background:#dc2626;color:#fff;')
  el.textContent = msg
  document.body.appendChild(el)
  setTimeout(() => el.remove(), 3200)
}
