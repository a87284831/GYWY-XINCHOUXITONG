<template>
  <div class="inv-settings">
    <div class="page-head">
      <div>
        <h2>账套配置</h2>
        <p class="muted">一项目一账套；凭据按账套独立，appSecret 只写不读，轮换保留 24 小时回滚窗口。</p>
      </div>
      <div class="head-actions">
        <button class="btn" @click="startNew">＋ 新建账套</button>
        <button class="btn" @click="load">刷新</button>
      </div>
    </div>

    <div v-if="error" class="err">{{ error }}</div>
    <div v-if="okMsg" class="okbar">{{ okMsg }}</div>

    <div class="layout">
      <aside class="card list">
        <div v-for="s in sets" :key="s.id" class="list-item" :class="{ on: form.id === s.id }" @click="select(s)">
          <span class="dot" :class="{ off: s.status !== 'enabled' }"></span>
          <div style="min-width:0">
            <div class="nm">{{ s.name }}</div>
            <div class="muted">{{ s.code }} · {{ s.seller_tax_no }}</div>
          </div>
          <span class="spacer"></span>
          <span class="badge" :class="{ gray: s.status !== 'enabled' }">{{ s.status === 'enabled' ? '启用' : '停用' }}</span>
        </div>
        <div v-if="!sets.length" class="empty">暂无账套，请新建</div>
      </aside>

      <div class="card form-card" v-if="form.code !== undefined">
        <div class="section-head">
          <h3>{{ form.id ? '编辑账套' : '新建账套' }}</h3>
          <span class="spacer"></span>
          <span v-if="form.id" class="badge" :class="{ gray: form.status !== 'enabled' }">{{ form.status === 'enabled' ? '已启用' : '已停用' }}</span>
        </div>

        <h4>主体信息</h4>
        <div class="grid">
          <label>账套名称<input v-model="form.name"></label>
          <label>账套编码（回调路由）<input v-model="form.code" placeholder="AS-LINYI-01"></label>
          <label>开票主体<input v-model="form.seller_name"></label>
          <label>统一社会信用代码<input v-model="form.seller_tax_no"></label>
          <label>地址<input v-model="form.seller_address"></label>
          <label>电话<input v-model="form.seller_phone"></label>
          <label>开户行<input v-model="form.bank_name"></label>
          <label>银行账号<input v-model="form.bank_account"></label>
          <label>状态
            <select v-model="form.status"><option value="enabled">启用</option><option value="disabled">停用</option></select>
          </label>
          <label>单笔金额上限<input v-model.number="form.amount_limit" type="number" min="0.01" step="0.01"></label>
        </div>

        <h4>商品编码与税率</h4>
        <div class="grid">
          <label>商品名称<input v-model="form.default_goods_name"></label>
          <label>税收分类编码<input v-model="form.default_goods_code"></label>
          <label>税率（小数）<input v-model.number="form.default_tax_rate" type="number" min="0" max="1" step="0.0001"></label>
          <label>税率显示<input :value="(Number(form.default_tax_rate) * 100).toFixed(2) + '%'" disabled></label>
        </div>

        <h4>诺诺凭据（按账套独立）</h4>
        <div class="grid">
          <label>appKey<input v-model="form.nuonuo_app_key" placeholder="留空则使用本地模拟开票"></label>
          <label>appSecret（只写不读）<input v-model="secret" type="password" placeholder="已配置则留空；填写即轮换"></label>
          <label>多主体授权标识 orgId<input v-model="form.nuonuo_org_id"></label>
          <label>环境<select v-model="form.nuonuo_env"><option value="sandbox">沙箱</option><option value="prod">正式</option></select></label>
        </div>
        <div class="inline-actions">
          <button class="btn primary" @click="saveCredentials" :disabled="!form.id || busy">保存凭据</button>
          <button class="btn" @click="test" :disabled="!form.id || busy">测试连接</button>
          <button class="btn" @click="rollback" :disabled="!form.id || busy">回滚旧凭据</button>
          <span v-if="testMsg" class="muted">{{ testMsg }}</span>
          <span class="spacer"></span>
          <span class="muted">密钥缺失时自动走本地模拟，并在单据标记「模拟」</span>
        </div>

        <h4>交付方式与 SMTP</h4>
        <div class="grid">
          <label>交付方式
            <select v-model="form.email_provider"><option value="smtp">本站 SMTP（默认）</option><option value="nuonuo">诺诺代发（需确认是否收费）</option></select>
          </label>
          <label>发件邮箱<input v-model="form.email_from"></label>
          <label>发件显示名<input v-model="form.email_from_name"></label>
          <label>Reply-To<input v-model="form.email_reply_to"></label>
          <label>SMTP 主机（留空用全局）<input v-model="form.smtp_host"></label>
          <label>端口<input v-model.number="form.smtp_port" type="number"></label>
          <label>SMTP 账号<input v-model="form.smtp_username"></label>
          <label>SMTP 授权码<input v-model="smtpPassword" type="password" placeholder="已配置则留空"></label>
          <label>加密<select v-model="form.smtp_encryption"><option value="">默认</option><option value="tls">tls</option><option value="ssl">ssl</option><option value="starttls">starttls</option></select></label>
        </div>

        <div class="inline-actions">
          <button class="btn primary" @click="save" :disabled="busy">保存账套</button>
          <span class="muted">SMTP 密码与 appSecret 均加密存储，接口只返回「已配置」。</span>
        </div>

        <template v-if="form.id">
          <h4>审核人分配（增删、指定主审；保存即生效）</h4>
          <div class="chips">
            <span v-for="(r, i) in reviewers" :key="r.account_id" class="chip" :class="{ primary: r.is_primary }">
              {{ r.name || r.username }}
              <b v-if="r.is_primary">主审</b>
              <a v-if="!r.is_primary" @click="reviewers[i].is_primary = true; makePrimary(i)">设为主审</a>
              <span class="x" @click="reviewers.splice(i, 1)">×</span>
            </span>
            <span v-if="!reviewers.length" class="muted">未分配审核人（仅 scope=all + 开票审核权限可见）</span>
          </div>
          <div class="inline-actions">
            <select v-model="newReviewer" class="mini-select">
              <option value="">选择账号</option>
              <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name || u.username }} · {{ u.project_name || '总部' }}</option>
            </select>
            <button class="btn" @click="addReviewer">添加审核人</button>
            <button class="btn primary" @click="saveReviewers" :disabled="busy">保存审核人</button>
          </div>

          <h4>项目绑定（一项目一账套）</h4>
          <table class="mini-table">
            <thead><tr><th>项目</th><th>当前账套</th><th>业主入口</th><th>状态</th></tr></thead>
            <tbody>
              <tr v-for="b in bindings" :key="b.project_id">
                <td>{{ b.project_name }}</td>
                <td>{{ b.account_set_name }}</td>
                <td><a :href="b.entry_url" target="_blank" class="muted">{{ b.entry_url }}</a></td>
                <td>{{ b.enabled ? '启用' : '停用' }}</td>
              </tr>
            </tbody>
          </table>
          <div class="inline-actions">
            <select v-model="bindProject" class="mini-select">
              <option value="">选择项目</option>
              <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <button class="btn" @click="saveBinding" :disabled="!bindProject || busy">绑定到当前账套</button>
            <span class="muted">更换账套只影响新单，在途单按快照处理。</span>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client'

const sets = ref([])
const bindings = ref([])
const users = ref([])
const projects = ref([])
const error = ref('')
const okMsg = ref('')
const busy = ref(false)
const secret = ref('')
const smtpPassword = ref('')
const testMsg = ref('')
const reviewers = ref([])
const newReviewer = ref('')
const bindProject = ref('')

const emptyForm = () => ({
  id: null, code: '', name: '', seller_name: '', seller_tax_no: '', seller_address: '', seller_phone: '',
  bank_name: '', bank_account: '', default_goods_code: '', default_goods_name: '物业管理服务费',
  default_tax_rate: 0.06, amount_limit: 50000, email_from: '', email_from_name: '', email_reply_to: '',
  email_provider: 'smtp', smtp_host: '', smtp_port: null, smtp_username: '', smtp_encryption: '',
  nuonuo_app_key: '', nuonuo_org_id: '', nuonuo_env: 'sandbox', status: 'enabled',
})
const form = reactive(emptyForm())

function flash(msg) { okMsg.value = msg; setTimeout(() => { okMsg.value = '' }, 3000) }
function select(s) {
  Object.assign(form, emptyForm(), s)
  form.default_tax_rate = Number(s.default_tax_rate)
  form.amount_limit = Number(s.amount_limit)
  reviewers.value = (s.reviewers || []).map((r) => ({ ...r }))
  secret.value = ''; smtpPassword.value = ''; testMsg.value = ''
}
function startNew() {
  Object.assign(form, emptyForm())
  reviewers.value = []; secret.value = ''; smtpPassword.value = ''; testMsg.value = ''
}

async function load() {
  error.value = ''
  try {
    const data = await api('/api/invoice/admin/account-sets')
    sets.value = data.items || []
    bindings.value = data.bindings || []
    if (form.id) {
      const cur = sets.value.find((s) => s.id === form.id)
      if (cur) select(cur)
    } else if (sets.value.length) {
      select(sets.value[0])
    }
    const u = await api('/api/invoice/admin/users')
    users.value = u.items || []
    const p = await api('/api/projects')
    projects.value = p.projects || []
  } catch (e) { error.value = e.message }
}
async function save() {
  busy.value = true; error.value = ''
  try {
    const payload = { ...form }
    delete payload.id
    if (smtpPassword.value) payload.smtp_password = smtpPassword.value
    const data = form.id
      ? await api('/api/invoice/admin/account-sets/' + form.id, { method: 'PUT', body: payload })
      : await api('/api/invoice/admin/account-sets', { body: payload })
    flash('账套已保存')
    form.id = data.item.id
    smtpPassword.value = ''
    await load()
    select(sets.value.find((s) => s.id === data.item.id) || data.item)
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
async function saveCredentials() {
  busy.value = true; error.value = ''; testMsg.value = ''
  const rotated = !!secret.value
  try {
    await api('/api/invoice/admin/account-sets/' + form.id + '/credentials', {
      method: 'PUT',
      body: { app_key: form.nuonuo_app_key, app_secret: secret.value, org_id: form.nuonuo_org_id, env: form.nuonuo_env },
    })
    secret.value = ''
    flash('凭据已保存' + (rotated ? '（已轮换，24 小时内可回滚）' : ''))
    await load()
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
async function test() {
  busy.value = true; testMsg.value = ''
  try {
    const d = await api('/api/invoice/admin/account-sets/' + form.id + '/test', { method: 'POST', body: {} })
    testMsg.value = (d.simulated ? '未配置凭据：' : '连通正常：') + (d.message || '')
  } catch (e) { testMsg.value = '连接失败：' + e.message } finally { busy.value = false }
}
async function rollback() {
  if (!confirm('确认回滚到上一版 appSecret？仅在 24 小时窗口内可用。')) return
  busy.value = true
  try { await api('/api/invoice/admin/account-sets/' + form.id + '/credentials/rollback', { method: 'POST', body: {} }); flash('已回滚旧凭据'); await load() }
  catch (e) { error.value = e.message } finally { busy.value = false }
}
function addReviewer() {
  if (!newReviewer.value) return
  if (reviewers.value.some((r) => String(r.account_id) === String(newReviewer.value))) return
  const u = users.value.find((x) => x.id === Number(newReviewer.value))
  reviewers.value.push({ account_id: Number(newReviewer.value), name: u?.name || u?.username, can_review: true, is_primary: reviewers.value.length === 0 })
  newReviewer.value = ''
}
function makePrimary(i) { reviewers.value.forEach((r, j) => { r.is_primary = i === j }) }
async function saveReviewers() {
  busy.value = true
  try {
    await api('/api/invoice/admin/account-sets/' + form.id + '/reviewers', { method: 'PUT', body: { reviewers: reviewers.value } })
    flash('审核人已保存，立即生效'); await load()
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
async function saveBinding() {
  busy.value = true
  try {
    const d = await api('/api/invoice/admin/bindings', { body: { project_id: Number(bindProject.value), account_set_id: form.id, enabled: true } })
    flash('项目已绑定：' + d.entry_url); bindProject.value = ''; await load()
  } catch (e) { error.value = e.message } finally { busy.value = false }
}

onMounted(load)
</script>

<style scoped>
.inv-settings{padding:18px 22px 40px}
.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px}
.page-head h2{margin:0;font-size:19px}
.muted{color:#94a3b8;font-size:12px}
.head-actions{display:flex;gap:8px}
.layout{display:grid;grid-template-columns:280px 1fr;gap:12px;align-items:start}
.card{background:#fff;border:1px solid #edf0f5;border-radius:12px;padding:14px}
.list{padding:6px}
.list-item{display:flex;gap:8px;align-items:center;padding:11px 10px;border-bottom:1px solid #f1f4f8;cursor:pointer;border-radius:8px}
.list-item:last-child{border-bottom:0}
.list-item.on{background:#f0f6ff}
.nm{font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dot{width:8px;height:8px;border-radius:50%;background:#16a34a;flex:0 0 8px}
.dot.off{background:#cbd5e1}
.spacer{flex:1}
.badge{font-size:10px;background:#e8f7ee;color:#15803d;border-radius:999px;padding:2px 7px}
.badge.gray{background:#f1f5f9;color:#64748b}
.form-card h4{font-size:12px;margin:16px 0 8px;color:#334155;border-left:3px solid #3b82f6;padding-left:7px}
.section-head{display:flex;align-items:center;gap:8px}
.section-head h3{margin:0;font-size:15px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:9px 12px}
.grid label{display:flex;flex-direction:column;gap:4px;font-size:11px;color:#64748b}
.grid input,.grid select{height:33px;border:1px solid #dbe0e8;border-radius:8px;padding:0 9px;font-size:12px;background:#fff}
.inline-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}
.btn{height:32px;padding:0 12px;border-radius:8px;border:1px solid #dbe0e8;background:#fff;font-size:12px;cursor:pointer}
.btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}
.btn:disabled{opacity:.55}
.mini-select{height:32px;border:1px solid #dbe0e8;border-radius:8px;padding:0 8px;background:#fff;font-size:12px}
.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:9px 12px;border-radius:8px;font-size:12px;margin-bottom:10px}
.okbar{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:9px 12px;border-radius:8px;font-size:12px;margin-bottom:10px}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.chip{background:#eef4ff;color:#1d4ed8;border-radius:999px;padding:5px 10px;font-size:11px;display:flex;gap:6px;align-items:center}
.chip.primary{background:#dcfce7;color:#15803d}
.chip b{font-weight:600}
.chip a{color:#2563eb;cursor:pointer;text-decoration:underline}
.chip .x{color:#94a3b8;cursor:pointer;font-size:13px}
.mini-table{width:100%;border-collapse:collapse;font-size:11px;margin-top:6px}
.mini-table th{text-align:left;color:#94a3b8;font-weight:500;padding:6px;border-bottom:1px solid #f1f4f8}
.mini-table td{padding:7px 6px;border-bottom:1px solid #f5f7fa;word-break:break-all}
.empty{padding:16px;text-align:center;color:#94a3b8;font-size:12px}
@media(max-width:1000px){.layout{grid-template-columns:1fr}.grid{grid-template-columns:1fr}}
</style>
