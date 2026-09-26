import { useAuthStore } from '@/stores/auth'
import router from '@/router'

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))
}

export async function api(path, opts = {}) {
  const auth = useAuthStore()
  const headers = { 'X-Token': auth.token }
  let body
  if (opts.form) {
    body = opts.form
  } else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(opts.body)
  }
  const res = await fetch(path, { method: opts.method || (body ? 'POST' : 'GET'), headers, body })
  const ctype = res.headers.get('Content-Type') || ''
  if (ctype.includes('application/json')) {
    const data = await res.json()
    if (res.status === 401 && path !== '/api/login') {
      // 会话失效：清除登录态后跳登录页（守卫依据 token 放行，故必须先清）
      auth.token = ''
      sessionStorage.removeItem('gw_token')
      router.push('/login')
      throw new Error('未登录')
    }
    if (!data.ok) throw new Error(data.error || data.msg || '操作失败')
    return data
  }
  if (!res.ok) throw new Error('下载失败')
  return await res.blob()
}

export async function download(path, fallbackName) {
  // 导出进度提示：生成中 → 完成/失败（复刻旧版 app.js 的浮层交互）
  const tip = document.createElement('div')
  tip.style.cssText =
    'position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:999;padding:10px 22px;border-radius:6px;font-size:13.5px;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.18);display:flex;gap:9px;align-items:center;background:#1e3a8a'
  tip.innerHTML = `<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:dlspin .8s linear infinite"></span><span>正在生成导出文件，请稍候…</span>`
  document.body.appendChild(tip)
  if (!document.getElementById('dlSpin')) {
    const st = document.createElement('style')
    st.id = 'dlSpin'
    st.textContent = '@keyframes dlspin{to{transform:rotate(360deg)}}'
    document.head.appendChild(st)
  }
  try {
    const blob = await api(path)
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = fallbackName
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(a.href), 4000)
    tip.innerHTML = `<span style="font-weight:600">✓ 导出完成，已开始下载</span>`
    tip.style.background = '#16a34a'
  } catch (e) {
    tip.innerHTML = `<span style="font-weight:600">导出失败：${esc(e.message)}</span>`
    tip.style.background = '#dc2626'
  }
  setTimeout(() => tip.remove(), 3200)
}
