<template>
  <div class="card">
    <h3>💰 薪酬设置</h3>
    <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
    <div v-else-if="rules">
      <!-- 1. 自定义薪酬项 -->
      <div style="border:2px solid #10b981;border-radius:8px;padding:16px;margin-bottom:20px;background:#f0fdf4">
        <div style="font-size:16px;font-weight:bold;margin-bottom:4px;color:#059669">📋 自定义薪酬项</div>
        <div class="hint" style="margin-bottom:12px;color:#047857">在此定义的字段会出现在考勤模板和工资表中，字段名可直接在下方核算公式中引用。</div>
        <div v-if="!fields.length" class="hint" style="padding:16px;text-align:center;background:#f9fafb;border-radius:6px">暂无自定义薪酬项，点击"添加薪酬项"创建。</div>
        <table v-else class="tb"><thead><tr>
          <th>字段名称</th><th>类型</th><th>金额来源</th><th>默认值</th><th>参与公式</th><th>启用</th><th>操作</th>
        </tr></thead><tbody>
          <tr v-for="(f, i) in fields" :key="i">
            <td><input type="text" style="width:120px" v-model="f.name"></td>
            <td><select style="width:80px" v-model="f.type"><option value="subsidy">补贴</option><option value="deduction">扣款</option></select></td>
            <td><select style="width:100px" v-model="f.source"><option value="attendance">考勤表导入</option><option value="fixed">固定金额</option></select></td>
            <td><input type="number" step="0.01" style="width:70px" v-model.number="f.default"></td>
            <td style="font-size:12px">
              <span :style="{ display: 'inline-block', padding: '1px 6px', borderRadius: '3px', margin: '1px', background: inFormula(f.name, 'gross') ? '#dbeafe' : '#f3f4f6', color: inFormula(f.name, 'gross') ? '#1e40af' : '#9ca3af' }">应发{{ inFormula(f.name, 'gross') ? ' ✓' : '' }}</span>
              <span :style="{ display: 'inline-block', padding: '1px 6px', borderRadius: '3px', margin: '1px', background: inFormula(f.name, 'net') ? '#dbeafe' : '#f3f4f6', color: inFormula(f.name, 'net') ? '#1e40af' : '#9ca3af' }">实发{{ inFormula(f.name, 'net') ? ' ✓' : '' }}</span>
            </td>
            <td style="text-align:center"><input type="checkbox" v-model="f.enabled"></td>
            <td><button class="btn sm danger" @click="cfDel(i)">删除</button></td>
          </tr>
        </tbody></table>
        <div style="margin-top:12px;display:flex;gap:8px">
          <button class="btn primary" @click="cfAdd">＋ 添加薪酬项</button>
          <button class="btn success" @click="cfSave">💾 保存薪酬项</button>
        </div>
      </div>

      <!-- 2. 基础计算参数 -->
      <details style="margin-bottom:16px;border:1px solid #e5e7eb;border-radius:6px;padding:14px" open>
        <summary style="font-weight:bold;font-size:15px;cursor:pointer;margin-bottom:12px;color:#1e40af">📐 基础计算参数</summary>
        <div v-for="sec in paramSections" :key="sec" style="margin-bottom:10px;padding:10px;border:1px solid #e5e7eb;border-radius:6px">
          <div style="font-weight:600;margin-bottom:6px">{{ sec }}</div>
          <template v-if="sec === '① 应发基本工资'">
            <div class="row">
              <label class="fld"><input type="checkbox" v-model="fm.seg"> 月中调薪按生效日期分段折算</label>
              <label class="fld" style="margin-left:16px">折算基数 <select v-model="fm.prorate" style="width:120px">
                <option value="required">按应出勤天数</option><option value="calendar">按自然天数</option></select></label>
            </div>
          </template>
          <template v-else-if="sec === '② 绩效工资'">
            <div class="row">
              <label class="fld"><input type="checkbox" v-model="fm.perf_on"> 启用绩效工资</label>
              <label class="fld" style="margin-left:16px"><input type="checkbox" v-model="fm.perf_prob"> 试用期不参与</label>
            </div>
          </template>
          <template v-else-if="sec === '③ 病假工资'">
            <div class="row">
              <label class="fld"><input type="checkbox" v-model="fm.sick_on"> 启用</label>
              <label class="fld" style="margin-left:12px">系数 <input type="number" step="0.01" v-model.number="fm.sick_a" style="width:55px"> × <input type="number" step="0.01" v-model.number="fm.sick_b" style="width:55px"></label>
              <label class="fld" style="margin-left:12px">基数 <select v-model="fm.sick_base" style="width:90px">
                <option value="base">基本工资</option><option value="fixed">固定月薪</option></select></label>
            </div>
            <div class="hint" style="margin:4px 0 0">最终计发比例 = 系数A × 系数B = {{ (Number(fm.sick_a) * Number(fm.sick_b) * 100).toFixed(0) }}%</div>
          </template>
          <template v-else-if="sec === '④ 补贴发放'">
            <div class="row">
              <label class="fld">餐补 <select v-model="fm.meal_mode" style="width:110px"><option value="full">全额</option><option value="prorate">按出勤折算</option></select></label>
              <label class="fld" style="margin-left:16px">其他补贴 <select v-model="fm.allow_mode" style="width:110px"><option value="full">全额</option><option value="prorate">按出勤折算</option></select></label>
            </div>
          </template>
          <template v-else-if="sec === '⑤ 奖惩'">
            <label class="fld"><input type="checkbox" v-model="fm.rp"> 月度奖励/扣罚全额计入税前应发</label>
          </template>
          <template v-else-if="sec === '⑥ 考勤扣款'">
            <div class="row">
              <label class="fld"><input type="checkbox" v-model="fm.miss_on"> 缺卡扣款</label>
              <label class="fld" style="margin-left:8px">前3次 <input type="number" v-model.number="fm.miss_f3" style="width:50px"> 元/次</label>
              <label class="fld" style="margin-left:8px">第4次起 <input type="number" v-model.number="fm.miss_a3" style="width:50px"> 元/次</label>
            </div>
            <div class="row" style="margin-top:6px">
              <label class="fld"><input type="checkbox" v-model="fm.abs_on"> 旷工扣款</label>
              <label class="fld" style="margin-left:8px">扣 <input type="number" step="0.5" v-model.number="fm.abs_mult" style="width:50px"> 倍日薪</label>
            </div>
            <div class="row" style="margin-top:6px">
              <label class="fld"><input type="checkbox" v-model="fm.late_on"> 迟到/早退扣款</label>
              <label class="fld" style="margin-left:8px">每次 <input type="number" step="0.5" v-model.number="fm.late_per" style="width:50px"> 元</label>
            </div>
          </template>
          <template v-else-if="sec === '⑦ 个人所得税'">
            <div class="row">
              <label class="fld">基本减除 <input type="number" v-model.number="fm.tax_basic" style="width:70px"> 元/月</label>
              <label class="fld" style="margin-left:16px">累计起算 <select v-model="fm.cum_start" style="width:100px">
                <option value="jan">当年1月</option><option value="month">核算当月</option></select></label>
            </div>
            <div class="hint" style="margin:8px 0 4px">个人所得税税率级距表（累计预扣法）— 累计应纳税所得额落入哪一档即按该档税率计税并减去速算扣除数；最后一级为最高档，不设上限。修改后点下方「保存全部设置」即与薪资核算联动。</div>
            <div>
              <div class="table-wrap" style="overflow-x:auto"><table class="tb" style="min-width:560px">
                <thead><tr><th>级</th><th>累计应纳税所得额上限(元)</th><th>税率</th><th>速算扣除数</th><th>操作</th></tr></thead>
                <tbody>
                  <tr v-for="(b, i) in taxDraft" :key="i">
                    <td style="text-align:center">{{ i + 1 }}</td>
                    <td><span v-if="i === taxDraft.length - 1" style="color:#94a3b8">最高档 · 不设上限</span>
                      <template v-else><input type="number" step="1000" min="1" :value="b[0]" style="width:130px" @change="setCap(b, $event)"> 元</template></td>
                    <td><input type="number" step="0.1" min="0" max="100" :value="taxRatePct(b[1])" style="width:70px" @change="b[1] = (parseFloat($event.target.value) || 0) / 100"> %</td>
                    <td><input type="number" step="10" v-model.number="b[2]" style="width:110px"></td>
                    <td style="text-align:center"><button v-if="i !== taxDraft.length - 1" type="button" class="btn sm warn" @click="taxDelBracket(i)">删除</button><template v-else>—</template></td>
                  </tr>
                </tbody>
              </table></div>
              <div class="row" style="margin-top:6px">
                <button type="button" class="btn sm" @click="taxAdd">+ 增加一级</button>
                <button type="button" class="btn sm" style="margin-left:8px" @click="taxRestoreStd">恢复标准 7 级</button>
              </div>
            </div>
          </template>
          <template v-else>
            <div class="hint" style="margin:0">五险一金：{{ rules.social.formula_text }}<br>专项附加扣除：{{ rules.special_deduction.formula_text }}（{{ rules.special_deduction.items.join('、') }}）</div>
          </template>
        </div>
      </details>

      <!-- 3. 核算公式 -->
      <div style="border:2px solid #6366f1;border-radius:8px;padding:16px;margin-bottom:16px;background:#f8f9ff">
        <div style="font-size:16px;font-weight:bold;margin-bottom:4px;color:#4f46e5">🧮 核算公式</div>
        <div class="hint" style="margin-bottom:12px;color:#4338ca">计算流程：基本工资 → 绩效 → 病假 → 补贴 → 奖惩 → <b>应发合计</b> → 五险一金 → 个税 → <b>实发工资</b></div>
        <div style="margin-bottom:12px"><label style="font-weight:600;display:block;margin-bottom:4px">公式一：应发合计</label>
          <input ref="grossIn" type="text" v-model="fm.gross" style="width:100%;font-size:13px;padding:8px;border:1px solid #c7d2fe;border-radius:4px"></div>
        <div style="margin-bottom:12px"><label style="font-weight:600;display:block;margin-bottom:4px">公式二：实发工资</label>
          <input ref="netIn" type="text" v-model="fm.net" style="width:100%;font-size:13px;padding:8px;border:1px solid #c7d2fe;border-radius:4px"></div>
        <div style="margin-bottom:12px">
          <button class="btn sm" @click="formulaTest">✓ 验证公式</button>
          <button class="btn sm" @click="formulaReset">↺ 恢复默认</button>
          <span :style="{ marginLeft: '10px', fontSize: '13px', color: testRes.ok ? '#16a34a' : '#dc2626' }">{{ testRes.text }}</span>
        </div>
        <div style="padding:10px;background:#f0f0ff;border-radius:6px">
          <div style="font-weight:600;margin-bottom:6px;font-size:13px">可用变量（点击插入到光标位置，先点击目标输入框）：</div>
          <div style="margin-bottom:6px"><span style="font-size:11px;color:#6b7280">内置：</span>
            <span v-for="v in BUILTIN_VARS" :key="v" @click="insertVar(v)" style="display:inline-block;background:#e0e7ff;padding:2px 7px;margin:2px;border-radius:3px;font-size:12px;cursor:pointer">{{ v }}</span>
          </div>
          <div v-if="customVars.length"><span style="font-size:11px;color:#059669">自定义：</span>
            <span v-for="v in customVars" :key="v" @click="insertVar(v)" style="display:inline-block;background:#d1fae5;padding:2px 7px;margin:2px;border-radius:3px;font-size:12px;cursor:pointer;border:1px solid #6ee7b7">{{ v }}</span>
          </div>
        </div>
      </div>

      <!-- 4. 符号库 -->
      <details style="margin-bottom:16px;border:1px solid #e5e7eb;border-radius:6px;padding:14px">
        <summary style="font-weight:bold;font-size:15px;cursor:pointer;margin-bottom:10px;color:#7c3aed">🔣 符号库（考勤符号定义）</summary>
        <div v-if="symErr" class="msg err">{{ symErr }}</div>
        <div v-else-if="symbols">
          <div class="table-wrap"><table class="tb"><thead><tr>
            <th>符号</th><th>释义</th><th>计入实际出勤</th><th>折算出勤天数</th><th>归类统计</th><th>说明</th><th>操作</th></tr></thead><tbody>
            <tr v-for="(s, i) in symbols" :key="i">
              <td><input type="text" style="width:52px;text-align:center" v-model="s.symbol"></td>
              <td><input type="text" style="width:110px" v-model="s.name"></td>
              <td style="text-align:center"><input type="checkbox" v-model="s.in_actual"></td>
              <td><input type="number" step="0.5" style="width:70px" v-model.number="s.value"></td>
              <td><select v-model="s.category"><option v-for="x in SYM_CATEGORIES" :key="x">{{ x }}</option></select></td>
              <td><input type="text" style="width:200px" v-model="s.desc"></td>
              <td><button class="btn sm danger" @click="symDel(i)">删除</button></td>
            </tr>
          </tbody></table></div>
          <div v-if="symFormulas" class="hint" style="margin:10px 0 0">
            <div v-for="(v, k) in symFormulas" :key="k"><b>{{ SYM_FORMULA_CN[k] || k }}</b> = {{ v === '0' ? '0' : '=' + v }}</div>
          </div>
          <div style="margin-top:12px;text-align:right">
            <button class="btn primary" @click="symSave">💾 保存符号库</button>
          </div>
        </div>
        <div v-else class="hint">加载中...</div>
      </details>

      <!-- 5. 保存 -->
      <div class="row" style="margin-top:20px"><button class="btn primary lg" @click="saveAll">💾 保存所有设置</button></div>
    </div>
    <div v-else class="hint">加载中...</div>
  </div>
</template>

<script setup>
// 薪酬设置 — 复刻 pageSalarySettings 群（app.js:3246-3746，符号库仅表格部分，不含死路由页新增卡/导出导入/恢复默认）
import { ref, reactive, computed, nextTick, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { cnFormula, enFormula, GROSS_DEFAULT, NET_DEFAULT, BUILTIN_VARS, STD_TAX_BRACKETS, taxRatePct, taxAddBracket, taxNormalize, evalFormulaSafe, normalizeCustomFields, SYM_CATEGORIES, SYM_FORMULA_CN } from './settingsLogic'

const rules = ref(null)
const fields = ref([])
const loadErr = ref('')
const taxDraft = ref([])
const symbols = ref(null)
const symFormulas = ref(null)
const symErr = ref('')
const testRes = reactive({ ok: true, text: '' })
const grossIn = ref(null)
const netIn = ref(null)
const fm = reactive({ seg: false, prorate: 'required', perf_on: false, perf_prob: false, sick_on: false, sick_a: 0, sick_b: 0, sick_base: 'base', meal_mode: 'full', allow_mode: 'full', rp: false, miss_on: false, miss_f3: 30, miss_a3: 50, abs_on: false, abs_mult: 3, late_on: false, late_per: 10, tax_basic: 5000, cum_start: 'jan', gross: '', net: '' })
const paramSections = ['① 应发基本工资', '② 绩效工资', '③ 病假工资', '④ 补贴发放', '⑤ 奖惩', '⑥ 考勤扣款', '⑦ 个人所得税', '⑧ 五险一金 / 专项附加']

const customVars = computed(() => fields.value.filter((f) => f.enabled).map((f) => f.name))
function inFormula(name, which) {
  const fm2 = (rules.value && rules.value.formula) || {}
  return String(which === 'gross' ? fm2.gross : fm2.net || '').includes(name)
}

function initFm() {
  const R = rules.value
  fm.seg = !!R.base_salary.segment_by_date
  fm.prorate = R.base_salary.prorate_base === 'calendar' ? 'calendar' : 'required'
  fm.perf_on = !!R.performance.enabled
  fm.perf_prob = !!R.performance.probation_excluded
  fm.sick_on = !!R.sick_pay.enabled
  fm.sick_a = R.sick_pay.params.factor_a
  fm.sick_b = R.sick_pay.params.factor_b
  fm.sick_base = R.sick_pay.params.sick_base === 'fixed' ? 'fixed' : 'base'
  fm.meal_mode = R.meal_subsidy.mode
  fm.allow_mode = R.allowances.mode
  fm.rp = !!R.reward_punish.full_in_gross
  const dr = R.deduction_rules || { miss_punch: { enabled: true, first_3: 30, after_3: 50 }, absent: { enabled: true, multiplier: 3 } }
  const le = dr.late_early || { enabled: true, per_time: 10 }
  fm.miss_on = !!dr.miss_punch.enabled
  fm.miss_f3 = dr.miss_punch.first_3
  fm.miss_a3 = dr.miss_punch.after_3
  fm.abs_on = !!dr.absent.enabled
  fm.abs_mult = dr.absent.multiplier
  fm.late_on = !!le.enabled
  fm.late_per = le.per_time
  fm.tax_basic = R.tax.basic_deduction
  fm.cum_start = R.tax.cum_start === 'month' ? 'month' : 'jan'
  fm.gross = cnFormula((R.formula || {}).gross) || GROSS_DEFAULT
  fm.net = cnFormula((R.formula || {}).net) || NET_DEFAULT
  const src = (R.tax && Array.isArray(R.tax.brackets) && R.tax.brackets.length) ? R.tax.brackets : STD_TAX_BRACKETS
  taxDraft.value = src.map((b) => [Number(b[0]), Number(b[1]), Number(b[2])])
}
function setCap(b, e) { b[0] = e.target.value === '' ? 99999999999 : parseFloat(e.target.value) }
function taxAdd() { taxDraft.value = taxAddBracket(taxDraft.value) }
function taxDelBracket(i) {
  if (taxDraft.value.length <= 1) { alert('至少保留一级'); return }
  taxDraft.value.splice(i, 1)
}
function taxRestoreStd() { taxDraft.value = STD_TAX_BRACKETS.map((b) => [Number(b[0]), Number(b[1]), Number(b[2])]) }

function insertVar(v) {
  // BUG-1 修复：优先插入到当前焦点所在的公式输入框
  const active = document.activeElement
  const grossEl = grossIn.value
  const netEl = netIn.value
  let input = null
  let field = ''
  if (active === grossEl) { input = grossEl; field = 'gross' }
  else if (active === netEl) { input = netEl; field = 'net' }
  else if (grossEl) { input = grossEl; field = 'gross' }
  if (!input) return
  const start = input.selectionStart || 0
  const end = input.selectionEnd || 0
  const val = input.value
  fm[field] = val.substring(0, start) + v + val.substring(end)
  nextTick(() => { input.setSelectionRange(start + v.length, start + v.length); input.focus() })
}

function formulaTest() {
  const grossExpr = (fm.gross || '').trim()
  const netExpr = (fm.net || '').trim()
  const sample = { '应发基本工资': 5000, '应发绩效工资': 1000, '病假工资': 0, '夜班话费补贴': 0, '餐补': 0, '其他补贴': 0, '月度奖励': 0, '已发福利': 0, '月度扣罚': 0, '缺卡扣款': 0, '迟到早退扣款': 0, '其他扣款': 0, '工装扣款': 0, '应发合计': 0, '五险一金合计': 500, '本月个税': 30, '养老保险': 300, '医疗保险': 100, '失业保险': 20, '住房公积金': 80, '大病': 0, '附加扣除合计': 0 }
  ;((rules.value && rules.value.custom_fields) || []).filter((f) => f.enabled).forEach((f) => { sample[f.name] = 0 })
  try {
    sample['应发合计'] = evalFormulaSafe(grossExpr, sample)
    const net = evalFormulaSafe(netExpr, sample)
    testRes.ok = true
    testRes.text = `验证通过：应发=${sample['应发合计'].toFixed(2)}，实发=${net.toFixed(2)}`
  } catch (e) {
    testRes.ok = false
    testRes.text = '公式错误：' + e.message
  }
}
function formulaReset() {
  if (!confirm('确认将应发合计和实发工资公式恢复为默认值？')) return
  fm.gross = GROSS_DEFAULT
  fm.net = NET_DEFAULT
  toast('已恢复默认公式，记得点保存')
}

/* ---- 自定义薪酬项 ---- */
function cfAdd() { fields.value.push({ name: '新薪酬项', type: 'subsidy', source: 'fixed', enabled: true, default: 0 }) }
function cfDel(i) {
  const f = fields.value[i]
  const fm2 = (rules.value && rules.value.formula) || {}
  const inF = (fm2.gross || '').includes(f.name) || (fm2.net || '').includes(f.name)
  let msg = '确认删除「' + f.name + '」？'
  if (inF) msg += '\n\n⚠ 该字段正在核算公式中使用，删除后请手动修改公式。'
  if (!confirm(msg)) return
  fields.value.splice(i, 1)
}
async function cfSave() {
  try {
    const cfFields = normalizeCustomFields(fields.value)
    const r = await api('/api/custom_fields/save', { body: { fields: cfFields } })
    fields.value = r.fields || cfFields
    toast('✓ 薪酬项已保存')
  } catch (e) { alert('保存失败：' + e.message) }
}

/* ---- 符号库（表格部分） ---- */
async function loadSymbols() {
  try {
    const data = await api('/api/symbols')
    symbols.value = data.items || []
    const fd = await api('/api/symbols/formulas')
    symFormulas.value = (fd.formulas || {}).categories || {}
  } catch (e) { symErr.value = e.message }
}
async function symSave() {
  for (const s of symbols.value) {
    if (!String(s.symbol).trim()) { toast('存在空符号，请检查', false); return }
    s.value = parseFloat(s.value)
  }
  try {
    await api('/api/symbols/save', { body: { items: symbols.value } })
    toast('符号库已保存，考勤表公式已同步')
    loadSymbols()
  } catch (e) { alert(e.message) }
}
function symDel(i) {
  if (!confirm(`确认删除符号"${symbols.value[i].symbol}"？删除后需点击"保存符号库"生效。`)) return
  symbols.value.splice(i, 1)
}
/* ---- 统一保存 — 复刻 saveAllSalarySettings（app.js:3520-3566） ---- */
async function saveAll() {
  if (!confirm('确认保存所有薪酬设置？修改将在下次核算时生效。')) return
  try {
    const cfFields = normalizeCustomFields(fields.value)
    await api('/api/custom_fields/save', { body: { fields: cfFields } })
    const R = JSON.parse(JSON.stringify(rules.value))
    R.base_salary.segment_by_date = fm.seg
    R.base_salary.prorate_base = fm.prorate
    R.performance.enabled = fm.perf_on
    R.performance.probation_excluded = fm.perf_prob
    R.sick_pay.enabled = fm.sick_on
    R.sick_pay.params.factor_a = parseFloat(fm.sick_a) || 0
    R.sick_pay.params.factor_b = parseFloat(fm.sick_b) || 0
    R.sick_pay.params.sick_base = fm.sick_base
    R.meal_subsidy.mode = fm.meal_mode
    R.allowances.mode = fm.allow_mode
    R.reward_punish.full_in_gross = fm.rp
    R.tax.basic_deduction = parseFloat(fm.tax_basic) || 5000
    R.tax.cum_start = fm.cum_start
    let br = taxNormalize(taxDraft.value)
    if (br.length === 0) { alert('请至少填写一级有效税率（税率需在 0~100% 之间）'); return }
    br[br.length - 1][0] = 99999999999
    R.tax.brackets = br
    R.deduction_rules = R.deduction_rules || {}
    R.deduction_rules.miss_punch = { enabled: fm.miss_on, first_3: parseFloat(fm.miss_f3) || 30, after_3: parseFloat(fm.miss_a3) || 50 }
    R.deduction_rules.absent = { enabled: fm.abs_on, multiplier: parseFloat(fm.abs_mult) || 2 }
    R.deduction_rules.late_early = { enabled: fm.late_on, per_time: parseFloat(fm.late_per) || 10 }
    R.formula = R.formula || {}
    // BUG-3 修复：保存前把中文变量名转回英文键，后端 Expr 只支持英文标识符
    R.formula.gross = enFormula((fm.gross || '').trim())
    R.formula.net = enFormula((fm.net || '').trim())
    if (!R.formula.gross || !R.formula.net) { alert('应发合计和实发工资公式不能为空'); return }
    await api('/api/calc_rules/save', { body: { rules: R } })
    rules.value = R
    fields.value = cfFields
    toast('✓ 所有薪酬设置已保存，下次核算生效')
    initFm()
    loadSymbols()
  } catch (e) { alert('保存失败：' + e.message) }
}

onMounted(async () => {
  loadErr.value = ''
  try {
    const [rulesData, cfData] = await Promise.all([api('/api/calc_rules'), api('/api/custom_fields')])
    rules.value = rulesData.rules
    fields.value = cfData.fields || []
    initFm()
  } catch (e) { loadErr.value = e.message }
  loadSymbols()
})
</script>
