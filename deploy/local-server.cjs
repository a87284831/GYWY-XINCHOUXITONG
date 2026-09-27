// 本地「生产形态」预览服务器：行为对齐 Task 19 未来 Nginx 切换配置
//   /            → 302 /app/
//   /app/*       → public/app/ 静态文件；未命中且无扩展名 → SPA 回退 /app/index.html
//   /app/assets/ → 长缓存 immutable（与线上一致）
//   /api/*       → 反代 https://oa.haoxuanyunshu.cn（读写直达生产，测试时注意！）
//   /purchase/ /static/ /finance/ /payslip.html → 旧版回滚锚点（不自动补目录索引，与线上一致需带 index.html）
// 用法: node deploy/local-server.js   （默认 127.0.0.1:8899，可用环境变量 PORT 覆盖）
const http = require('node:http')
const https = require('node:https')
const fs = require('node:fs')
const path = require('node:path')

const ROOT = path.join(__dirname, '..', 'public')
const PORT = Number(process.env.PORT || 8899)
const API_HOST = 'oa.haoxuanyunshu.cn'

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.eot': 'application/vnd.ms-fontobject',
  '.txt': 'text/plain; charset=utf-8',
  '.pdf': 'application/pdf',
  '.xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
}

function send(res, code, body, headers) {
  res.writeHead(code, Object.assign({ 'Content-Type': 'text/plain; charset=utf-8' }, headers))
  res.end(body)
}

function serveFile(res, filePath) {
  const ext = path.extname(filePath).toLowerCase()
  const headers = { 'Content-Type': MIME[ext] || 'application/octet-stream' }
  if (filePath.replace(/\\/g, '/').includes('/app/assets/')) {
    headers['Cache-Control'] = 'public, max-age=31536000, immutable'
  } else if (ext === '.html') {
    headers['Cache-Control'] = 'no-cache'
  }
  // 构建重建 public/app 的瞬间文件可能暂时不存在：先检查，读流再兜底，避免整个服务器崩溃
  if (!fs.existsSync(filePath)) {
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' })
    res.end('Not Found (file temporarily unavailable during rebuild)')
    return
  }
  res.writeHead(200, headers)
  const rs = fs.createReadStream(filePath)
  rs.on('error', () => {
    if (!res.headersSent) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' })
      res.end('Not Found')
    } else {
      res.destroy()
    }
  })
  rs.pipe(res)
}

function proxyApi(req, res) {
  const headers = Object.assign({}, req.headers)
  headers.host = API_HOST
  delete headers.referer
  delete headers.origin
  const upstream = https.request(
    { host: API_HOST, port: 443, method: req.method, path: req.url, headers },
    (ur) => {
      res.writeHead(ur.statusCode || 502, ur.headers)
      ur.pipe(res)
    }
  )
  upstream.on('error', (e) => send(res, 502, 'API 反代失败: ' + e.message))
  req.pipe(upstream)
}

const server = http.createServer((req, res) => {
  let urlPath
  try {
    urlPath = decodeURIComponent((req.url || '/').split('?')[0])
  } catch {
    return send(res, 400, 'Bad Request')
  }

  // /api 反代（与 Nginx 线上行为一致：原样转发，含 POST/PUT/DELETE 与下载流）
  if (urlPath === '/api' || urlPath.startsWith('/api/')) return proxyApi(req, res)

  if (req.method !== 'GET' && req.method !== 'HEAD') return send(res, 405, 'Method Not Allowed')

  // / 与 /app 入口重定向
  if (urlPath === '/' || urlPath === '/app') {
    res.writeHead(302, { Location: '/app/' })
    return res.end()
  }

  // 新版 SPA
  if (urlPath === '/app/' || urlPath.startsWith('/app/')) {
    const rel = urlPath.replace(/^\/app\/?/, '')
    const appRoot = path.join(ROOT, 'app')
    const target = path.normalize(path.join(appRoot, rel || 'index.html'))
    if (!target.startsWith(appRoot)) return send(res, 403, 'Forbidden')
    if (rel && fs.existsSync(target) && fs.statSync(target).isFile()) return serveFile(res, target)
    // history 深链回退
    return serveFile(res, path.join(appRoot, 'index.html'))
  }

  // 旧版回滚锚点与其他静态文件（不做目录索引，与线上一致）
  const target = path.normalize(path.join(ROOT, urlPath))
  if (!target.startsWith(ROOT)) return send(res, 403, 'Forbidden')
  if (fs.existsSync(target) && fs.statSync(target).isFile()) return serveFile(res, target)
  send(res, 404, 'Not Found')
})

server.listen(PORT, '127.0.0.1', () => {
  console.log(`本地生产形态服务器已启动: http://127.0.0.1:${PORT}/  (root=${ROOT})`)
})
