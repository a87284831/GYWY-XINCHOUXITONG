<template>
  <div>
    <h4 style="margin:0 0 10px">备份策略</h4>
    <div class="form-grid" style="max-width:560px">
      <label><input type="checkbox" v-model="bk.auto_backup"> 启用自动备份（预留）</label>
      <label>备份频率<select v-model="bk.frequency"><option value="daily">每日</option><option value="weekly">每周</option><option value="monthly">每月</option></select></label>
      <label>保留份数<input type="number" v-model="bk.keep_count" min="1" max="100"></label>
    </div>
    <div class="row" style="margin:10px 0 18px"><button class="btn primary" @click="savePolicy">保存策略</button></div>
    <h4 style="margin:0 0 10px">立即备份</h4>
    <div class="row"><button class="btn success" @click="doBackup">立即备份全部业务数据</button></div>
    <div v-if="bkMsg" class="msg ok">备份完成：{{ bkMsg }}</div>
    <h4 style="margin:18px 0 10px;color:#dc2626">一键清除数据（危险操作）</h4>
    <div class="hint" style="color:#b91c1c">清除前请先确认已备份。将清除人员档案、考勤、工资核算、工资调整、预算、绩效、审批单、入职办理、维保合同/合作方、调动日志、系统消息、项目档案表；采购系统：填报明细、报价存档、清单外审核、采购预算、月度归档、采购通知、操作日志、标准商品库。保留管理员账号、角色权限、工资规则、考勤符号库、字段设置、审批流程设计、组织架构、采购项目/账号/填报窗口。项目将按组织架构自动重建。</div>
    <div class="row"><button class="btn danger" @click="wizardShow = true">⚠ 一键清除数据</button></div>
    <div v-if="cdMsg" class="msg err">{{ cdMsg }}</div>
    <div style="margin-top:12px">
      <div v-if="bkErr" class="msg err">{{ bkErr }}</div>
      <div v-else-if="!backups.length" class="msg info">暂无备份</div>
      <div v-else class="table-wrap"><table class="tb">
        <thead><tr><th>备份文件</th><th>时间</th><th>大小</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="b in backups" :key="b.name">
            <td>{{ b.name }}</td><td>{{ b.ts }}</td><td>{{ fmtSize(b.size) }}</td>
            <td><a href="javascript:void(0)" @click="dl(b)">下载</a></td>
          </tr>
        </tbody>
      </table></div>
    </div>
    <div class="hint">备份内容：人员档案、调薪记录、考勤、工资核算、预算、符号库、计算规则、账号、角色、设置等全部JSON数据；采购系统：填报明细、报价存档、清单外审核、采购预算、月度归档、采购通知、操作日志、标准商品库。建议每月归档后备份一次，并将备份文件另存到U盘/网盘。</div>

    <Modal title="⚠ 一键清除数据" :show="wizardShow" @close="wizardShow = false">
      <div class="msg warn" style="margin:10px 0">此操作<b>不可恢复</b>！将清除以下全部业务数据：</div>
      <div class="hint" style="margin-bottom:10px">人员档案、考勤记录、工资核算、工资调整、预算数据、绩效考核、审批单（含草稿/分享）、入职办理清单、维保合同/合作方、调动日志、系统消息、项目档案表（项目将按组织架构自动重建）；采购系统：填报明细、报价存档、清单外审核、采购预算、月度归档、采购通知、操作日志、标准商品库。</div>
      <div class="hint" style="margin-bottom:14px">保留：管理员账号、角色权限、工资计算规则、考勤符号库、人员字段设置、审批流程设计、组织架构、采购项目/账号/填报窗口/系统设置。</div>
      <label style="display:block;margin-bottom:12px"><input type="checkbox" v-model="cdBackup"> 清除前自动备份全部业务数据（强烈建议勾选）</label>
      <label style="display:block;margin-bottom:12px">请输入「确认清除」四个字以继续：<input type="text" v-model="cdKw" placeholder="确认清除" style="margin-top:4px"></label>
      <template #foot>
        <button class="btn" @click="wizardShow = false">取消</button>
        <button class="btn danger" @click="clearDataRun">执行清除</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
// 数据备份 — 复刻 settingsBackup/backupSetSave/doBackup/loadBackups/clearDataWizard/clearDataRun（app.js:4089-4144, 4247-4265）
// Ruling：清除完成后不主动刷新其他页面数据（忠实新壳约定）
import { ref, reactive, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { fmtSize } from './settingsLogic'
import Modal from '@/components/Modal.vue'

const auth = useAuthStore()
const bk = reactive({ auto_backup: false, frequency: 'daily', keep_count: 10 })
const bkMsg = ref('')
const bkErr = ref('')
const backups = ref([])
const wizardShow = ref(false)
const cdBackup = ref(true)
const cdKw = ref('')
const cdMsg = ref('')

const CD_NAMES = { payroll_staff: '人员档案', payroll_attendance: '考勤记录', payroll_results: '工资核算', payroll_salary_adjustments: '工资调整', payroll_budgets: '预算数据', payroll_projects: '项目档案', performance_plans: '绩效考核', maintenance_partners: '维保合作方', maintenance_contracts: '维保合同', approval_instances: '审批单', approval_drafts: '审批草稿', approval_shares: '审批分享', onboard_checklists: '入职办理清单', org_transfer_logs: '调动日志', app_messages: '系统消息', proc_purchase_items: '采购填报明细', proc_archived_purchases: '采购报价存档', proc_audit_records: '清单外审核', proc_budget_plan: '采购预算', proc_monthly_archive: '月度归档', proc_notifications: '采购通知', proc_op_logs: '采购操作日志', proc_products: '标准商品库', proc_product_synonyms: '商品别名' }

async function savePolicy() {
  try {
    await api('/api/settings/save', { body: { settings: { backup: { auto_backup: bk.auto_backup, frequency: bk.frequency, keep_count: parseInt(bk.keep_count) } } } })
    const d = await api('/api/settings')
    auth.settings = d.settings
    toast('备份策略已保存')
  } catch (e) { alert(e.message) }
}

async function doBackup() {
  try {
    const r = await api('/api/backup', { body: {} })
    bkMsg.value = r.file
    loadBackups()
  } catch (e) { alert(e.message) }
}

function dl(b) { download('/api/backups/download?f=' + encodeURIComponent(b.name), b.name) }

async function clearDataRun() {
  if (cdKw.value.trim() !== '确认清除') { toast('请输入「确认清除」四个字', false); return }
  try {
    const data = await api('/api/admin/clear-data', { body: { backup: cdBackup.value } })
    wizardShow.value = false
    let msg = '✅ 清除完成'
    if (data.backup) msg += '\n已自动备份：' + data.backup
    msg += '\n\n清除明细：'
    for (const k in CD_NAMES) { if (typeof data.cleared[k] === 'number') msg += '\n' + CD_NAMES[k] + '：' + data.cleared[k] + ' 条' }
    alert(msg)
  } catch (e) { cdMsg.value = '清除失败：' + e.message }
}

async function loadBackups() {
  bkErr.value = ''
  try {
    const data = await api('/api/backups')
    backups.value = data.backups || []
  } catch (e) { bkErr.value = e.message }
}

onMounted(() => {
  const b = (auth.settings && auth.settings.backup) || {}
  bk.auto_backup = !!b.auto_backup
  bk.frequency = b.frequency || 'daily'
  bk.keep_count = b.keep_count || 10
  loadBackups()
})
</script>
