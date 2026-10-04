// 钉钉同步流程（复刻旧版 app.js dingtalkSyncNow/showSyncResult/showSyncError）
// Staff/Org 两页共用；onDone 为结果弹窗确定后的回调（旧版为 refreshPage）
import { api } from '@/api/client'
import { syncReportModel } from './dingtalkSyncReport'

export async function dingtalkSyncNow(onDone) {
  if (!window.confirm('立即从钉钉全量同步组织架构和人员？')) return

  const overlay = document.createElement('div')
  overlay.id = 'syncOverlay'
  overlay.style.cssText =
    'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center'
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:14px;padding:32px 40px;min-width:340px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="font-size:16px;font-weight:600;color:#333;margin-bottom:18px">钉钉数据同步中</div>
      <div class="sync-spinner" style="width:44px;height:44px;border:4px solid #e8e8e8;border-top-color:#1890ff;border-radius:50%;animation:syncSpin .8s linear infinite;margin:0 auto 18px"></div>
      <div class="sync-stage" style="font-size:14px;color:#888;margin-bottom:6px">正在连接钉钉...</div>
      <div class="sync-timer" style="font-size:13px;color:#bbb">已用时 0s</div>
    </div>
  `
  document.body.appendChild(overlay)

  if (!document.getElementById('syncSpinStyle')) {
    const style = document.createElement('style')
    style.id = 'syncSpinStyle'
    style.textContent = '@keyframes syncSpin{to{transform:rotate(360deg)}}'
    document.head.appendChild(style)
  }

  // 阶段提示轮播（4s 一换）+ 计时
  const stages = ['正在连接钉钉...', '正在拉取部门树...', '正在同步组织架构...', '正在拉取在职人员...', '正在同步人员数据...', '正在拉取离职名单...', '正在同步花名册...', '即将完成...']
  let stageIdx = 0
  const stageEl = () => overlay.querySelector('.sync-stage')
  const timerEl = () => overlay.querySelector('.sync-timer')
  const startTs = Date.now()
  const stageTimer = setInterval(() => {
    if (stageEl()) stageEl().textContent = stages[stageIdx % stages.length]
    stageIdx++
    if (timerEl()) timerEl().textContent = '已用时 ' + Math.round((Date.now() - startTs) / 1000) + 's'
  }, 4000)

  try {
    const r = await api('/api/dingtalk/sync-now', { method: 'POST' })
    clearInterval(stageTimer)
    overlay.remove()
    const rep = r.report || {}
    showSyncResult(rep, r.elapsed || rep.elapsed || 0, onDone)
  } catch (e) {
    clearInterval(stageTimer)
    overlay.remove()
    showSyncError(e.message)
  }
}

function showSyncResult(rep, elapsed, onDone) {
  const m = syncReportModel(rep)
  const overlay = document.createElement('div')
  overlay.style.cssText =
    'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center'
  const titleColor = m.hasWarning ? '#d97706' : '#52c41a'
  const warnBlock = m.warningLines.length ? `
    <div style="margin-top:16px;padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;color:#92400e;max-height:200px;overflow-y:auto">
      <div style="font-weight:600;margin-bottom:6px">以下人员的花名册薪资字段不是有效数字，本次未覆盖其本地薪资（保留原值），其余字段和其他人员均已正常同步。请在钉钉智能人事修正后重新同步：</div>
      ${m.warningLines.map((l) => `<div style="padding:2px 0;word-break:break-all">${l}</div>`).join('')}
      ${m.warningTruncated ? `<div style="padding-top:4px;color:#b45309">……等 ${m.warningTruncated} 项未显示</div>` : ''}
    </div>` : ''
  const missingBlock = m.missingCount ? `
    <div style="margin-top:10px;font-size:12px;color:#b45309">另有 ${m.missingCount} 名在职人员在钉钉花名册无档案返回（多半未办理智能人事入职登记），花名册字段未同步。</div>` : ''
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:14px;padding:28px 36px;min-width:380px;max-width:560px;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="text-align:center;margin-bottom:20px">
        <div style="font-size:20px;font-weight:700;color:${titleColor}">${m.title}</div>
        <div style="font-size:13px;color:#999;margin-top:4px">耗时 ${elapsed} 秒</div>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        ${m.rows.map(([k, v]) => `<tr><td style="padding:6px 0;color:#666">${k}</td><td style="padding:6px 0;text-align:right;font-weight:600;color:${k.includes('异常') && v > 0 ? '#d97706' : '#333'}">${v}</td></tr>`).join('')}
      </table>
      ${warnBlock}
      ${missingBlock}
      <div style="text-align:center;margin-top:22px">
        <button class="sync-ok-btn" style="padding:8px 36px;font-size:15px;border-radius:8px;border:none;background:#1890ff;color:#fff;cursor:pointer">确定</button>
      </div>
    </div>
  `
  document.body.appendChild(overlay)
  overlay.querySelector('.sync-ok-btn').onclick = () => {
    overlay.remove()
    if (onDone) onDone()
  }
}

function showSyncError(msg) {
  const overlay = document.createElement('div')
  overlay.style.cssText =
    'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center'
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:14px;padding:28px 36px;min-width:320px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="font-size:20px;font-weight:700;color:#ff4d4f;margin-bottom:12px">同步失败</div>
      <div style="font-size:14px;color:#666;margin-bottom:22px;word-break:break-all"></div>
      <button style="padding:8px 36px;font-size:15px;border-radius:8px;border:1px solid #d9d9d9;background:#fff;cursor:pointer">关闭</button>
    </div>
  `
  overlay.querySelector('div[style*="word-break"]').textContent = msg
  document.body.appendChild(overlay)
  overlay.querySelector('button').onclick = () => overlay.remove()
}
