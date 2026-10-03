<template>
  <div style="padding:4px">
    <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
    <div v-else-if="!p">加载中…</div>
    <template v-else>
      <div class="row" style="margin-bottom:10px">
        <button class="btn sm" @click="$emit('back')">← 返回列表</button>
        <h3 style="margin:0;border:none;padding:0">{{ p.employeeName }}（{{ p.line || '' }}）绩效考核</h3>
        <span :class="`tag ${stCol}`">{{ statusLabel }}</span>
        <span style="margin-left:auto;display:flex;gap:8px">
          <button v-if="p.status === 'done'" class="btn sm primary" @click="gotoPrint">🖨 打印考核表（A4）</button>
          <button v-if="p.status === 'done' && isAdmin" class="btn sm danger" @click="actReopen">↩ 撤销归档</button>
          <button class="btn sm" @click="exportPlan">📤 导出考核表</button>
        </span>
      </div>
      <!-- 流程步骤 -->
      <div class="perf-steps">
        <template v-for="(n, i) in PERF_FLOW_NAME" :key="n">
          <div :class="['pf-step', i <= stepIdx ? 'on' : '', i === stepIdx ? 'cur' : '']">
            <span class="pf-dot">{{ i + 1 }}</span><span class="pf-name">{{ n }}</span>
          </div>
          <div v-if="i < PERF_FLOW_NAME.length - 1" class="pf-line"></div>
        </template>
      </div>
      <!-- 单据信息 -->
      <div class="card" style="margin-top:12px">
        <div class="row" style="gap:24px;font-size:13px;color:#475569">
          <span>被考核人：<b>{{ p.employeeName }}</b></span><span>条线：{{ p.line || '-' }}</span><span>项目：{{ p.project || '-' }}</span>
          <span>考核周期：<b>{{ p.periodStart || '-' }} ~ {{ p.periodEnd || '-' }}</b>（第{{ p.quarter || '-' }}季度）</span><span>发起人：{{ p.founderName || '-' }}</span>
          <span>审批链：{{ chainText }}</span>
        </div>
        <div v-if="p.rejectOpinion" class="msg err" style="margin-top:8px">⚠ 最近一次驳回意见：{{ p.rejectOpinion }}</div>
      </div>
      <!-- 状态操作区 -->
      <div v-if="p.status === 'draft' && (isFounder || isAdmin)" class="msg info">
        单据为草稿状态。<button class="btn sm primary" @click="$router.push({ path: '/perfCreate', query: { id: p.id } })">前往编辑/提交</button>
      </div>
      <div v-else-if="p.status === 'confirm' && canActApproval" class="card perf-action">
        <b>① 请核对指标设置与权重，并确认员工提交的考核周期</b>
        <div v-if="editingConfirm" class="msg warn" style="margin-top:8px">
          ✏ 指标修改模式：可直接调整下方指标内容、权重、算分方式与核查/填报人，保存即生效并留痕；员工与周期不可改。
          <div class="row end" style="margin-top:8px;gap:8px">
            <button class="btn sm" :disabled="savingConfirm" @click="reload">取消修改</button>
            <button class="btn sm primary" :disabled="savingConfirm" @click="saveConfirmEdit">💾 保存指标修改</button>
          </div>
        </div>
        <div class="row" style="margin-top:8px;gap:12px;align-items:center;flex-wrap:wrap">
          <span class="tag blue" style="font-size:14px">考核周期：{{ p.periodStart || '-' }} ~ {{ p.periodEnd || '-' }}</span>
          <span class="tag gray">权重合计 {{ perfWeightSum(p) }}</span>
          <span style="font-size:12px;color:#64748b">周期由发起人提交，审核人直接核对确认即可，无需再选择年度/季度。</span>
          <button class="btn sm warn" @click="editingConfirm = true">✏ 直接修改指标</button>
          <button class="btn success" @click="actConfirm">✔ 确认通过（进入考核周期）</button>
          <button class="btn danger" @click="openReject('reject_confirm')">驳回修改</button>
        </div>
      </div>
      <div v-else-if="p.status === 'ongoing'" class="msg info">
        考核周期进行中（{{ p.periodStart }} ~ {{ p.periodEnd }}），周期结束次日将自动转入数据填报。
        <div v-if="isFounder || isAdmin" class="row" style="margin-top:10px;gap:10px;align-items:center">
          <button class="btn warn" @click="actStartReport">⏭ 提前结束周期，立即转入数据填报</button>
          <span class="hint" style="margin:0">仅管理员/发起人可操作，用于需提前启动填报的特殊情况</span>
        </div>
      </div>
      <div v-else-if="p.status === 'report'" class="card perf-action">
        <b>③ 数据填报阶段</b>：各指标填报人填写实际完成值，全部填齐后自动转入发起人自评。
        <div class="row" style="margin-top:8px;gap:10px;align-items:center;flex-wrap:wrap">
          <template v-if="isFounder || isAdmin">
            <button class="btn warn" @click="actUrge">📢 一键催办未填报人</button>
            <button class="btn primary" @click="actFinishReport">✔ 完成填报，进入发起人自评{{ repPending ? `（还差 ${repPending} 项）` : '' }}</button>
          </template>
          <span v-if="isAdmin" class="hint" style="margin:0">管理员可代填任意项</span>
        </div>
      </div>
      <div v-else-if="p.status === 'self' && canSelf" class="card perf-action">
        <b>④ 本人自评</b>：仅「主观评分」项需要打分，客观项（比例/阶梯/达标/核查定分）已在填报阶段锁定、不可更改；可为主观项上传截图等依据附件（每项≤5个，jpg/png/pdf，单个≤10MB）。
        <div class="row end" style="margin-top:8px"><button class="btn primary" @click="actSelfSubmit">提交自评，送上级审批</button></div>
      </div>
      <div v-else-if="p.status === 'approve' && canActApproval" class="card perf-action">
        <b>⑤ 逐级审批（第 {{ (p.currentStep || 0) + 1 }} / {{ (p.approvers || []).length }} 级 · 当前：{{ curAp ? curAp.name : '' }}）</b>
        <div class="hint" style="margin:4px 0 0">仅「主观评分」项需要填写<b>上级评分</b>，系统按「自评{{ sw.self }}% + 上级{{ sw.approver }}%」计算该项最终分（可微调）；客观项已锁定（🔒），不可评分。</div>
        <div class="row end" style="margin-top:8px">
          <button class="btn danger" @click="openReject('reject_approve')">驳回</button>
          <button class="btn success" @click="openApprove">✔ {{ (p.currentStep || 0) + 1 >= p.approvers.length ? '终审通过并归档' : '审批通过，送下一级' }}</button>
        </div>
      </div>
      <div v-else-if="p.status === 'done'" class="msg ok">已归档：最终总分 <b>{{ perfFmt(p.finalTotal) }}</b>，考评等级 <b>{{ p.grade || '' }}</b></div>
      <!-- 指标明细 -->
      <div class="card">
        <div class="row" style="margin-bottom:8px">
          <h3 style="margin:0;border:none;padding:0">考核指标明细</h3>
          <span class="tag gray">权重合计 {{ perfWeightSum(p) }}</span>
          <span class="tag blue">自动总分 {{ perfFmt(p.autoTotal) }}</span>
          <span class="tag orange">自评总分 {{ perfFmt(p.selfTotal) }}</span>
          <span class="tag purple">上级评分 {{ perfFmt(p.approverTotal) }}</span>
          <span class="tag green">最终总分 {{ perfFmt(p.finalTotal) }}{{ p.grade ? ` · ${p.grade}` : '' }}</span>
          <span v-if="meta && meta.scoreWeights" class="hint" style="font-size:11.5px">占比：自评{{ meta.scoreWeights.self }}% + 考核人{{ meta.scoreWeights.approver }}%</span>
        </div>
        <div class="table-wrap" style="max-height:none">
          <table class="tb">
            <thead><tr><th>序号</th><th>指标类别</th><th>指标内容</th><th>定义/扣分规则</th><th>权重</th><th>算分方式/来源/填报人</th><th>完成情况</th><th>自动分</th><th>自评分</th><th>考核人评分</th><th>最终分</th></tr></thead>
            <tbody>
              <template v-for="cat in p.categories || []">
                <tr v-for="(it, ii) in cat.items || []" :key="it.id">
                  <td>{{ seqOf(cat, it) }}</td>
                  <td v-if="ii === 0" :rowspan="(cat.items || []).length" style="vertical-align:top;background:#f8fafc;font-weight:600">
                    <input v-if="editingConfirm" v-model="cat.name" type="text" style="width:90px" />
                    <template v-else>{{ cat.name }}</template>
                  </td>
                  <td style="white-space:normal;min-width:150px">
                    <input v-if="editingConfirm" v-model="it.content" type="text" style="width:150px" />
                    <template v-else>{{ it.content }}</template>
                  </td>
                  <td style="white-space:normal;max-width:260px;font-size:11.5px;color:#64748b">
                    <textarea v-if="editingConfirm" v-model="it.definition" rows="2" style="width:220px"></textarea>
                    <template v-else>{{ it.definition }}</template>
                  </td>
                  <td class="num"><input v-if="editingConfirm" :value="it.weight ?? ''" type="number" style="width:60px" @input="it.weight = $event.target.value" /><template v-else>{{ perfFmt(it.weight) }}</template></td>
                  <td style="font-size:11.5px">
                    <template v-if="editingConfirm">
                      <select v-model="it.calcType" style="margin-bottom:3px" @change="changeCalcEdit(it)">
                        <option v-for="(lbl, k) in CALC_LABEL" :key="k" :value="k">{{ lbl }}</option>
                      </select>
                      <div v-if="it.calcType === 'ratio'" style="color:#64748b">目标 <input v-model.number="it.calcParams.target" type="number" style="width:70px" /></div>
                      <div v-else-if="it.calcType === 'ladder'" style="color:#64748b;line-height:1.9">目标 <input v-model.number="it.calcParams.target" type="number" style="width:60px" /> 每差 <input v-model.number="it.calcParams.stepUnit" type="number" style="width:50px" /> 扣 <input v-model.number="it.calcParams.stepDeduct" type="number" style="width:50px" /><br />低于 <input v-model.number="it.calcParams.zeroThreshold" type="number" style="width:60px" /> 记0</div>
                      <div v-else-if="it.calcType === 'count'" style="color:#64748b">应完成 <input v-model.number="it.calcParams.required" type="number" style="width:60px" /> 每少1扣 <input v-model.number="it.calcParams.deductEach" type="number" style="width:50px" /></div>
                      <div v-else style="color:#c2410c">{{ it.calcType === 'check' ? '核查人直接定分，无参数' : '主观评分，无参数' }}</div>
                      <select :value="it.reporterId ?? ''" style="max-width:170px;margin-top:3px" @change="pickReporterEdit(it, $event.target.value)">
                        <option value="">{{ it.calcType === 'check' ? '选择核查人…' : '选择填报人…' }}</option>
                        <option v-for="s in staffOptions" :key="s.id" :value="String(s.id)" :disabled="!s.hasAccount">{{ s.label }}</option>
                      </select>
                    </template>
                    <template v-else>
                      <span :title="CALC_HELP[it.calcType] || ''" style="cursor:help;border-bottom:1px dashed #94a3b8">{{ CALC_LABEL[it.calcType] || it.calcType }} ⓘ</span>
                      <div class="hint" style="margin:0">{{ it.sourceDept || '' }}<br />{{ it.calcType === 'check' ? '核查人' : '填报' }}:{{ it.reporterName || '-' }}{{ it.reporterId ? '' : ' ·未指派→自动跳过' }}</div>
                    </template>
                  </td>
                  <td style="white-space:normal">
                    <template v-if="p.status === 'report' && (isAdmin || myReportItems.includes(it.id))">
                      <template v-if="it.calcType === 'manual'">
                        <input v-model="it._actualText" type="text" style="width:150px" placeholder="完成情况说明（选填）" />
                        <button class="btn sm" @click="actReport(it, isAdmin && !myReportItems.includes(it.id))">提交</button>
                      </template>
                      <template v-else-if="it.calcType === 'check'">
                        <div style="color:#c2410c;font-weight:600;margin-bottom:2px">核查定分（0~{{ it.weight }}，含0）</div>
                        <input v-model.number="it._checkScore" type="number" min="0" :max="Number(it.weight)" step="0.01" style="width:70px" placeholder="定分" />
                        <input v-model="it._actualText" type="text" style="width:96px" placeholder="核查说明" />
                        <button class="btn sm" @click="actReport(it, isAdmin && !myReportItems.includes(it.id))">保存</button>
                      </template>
                      <template v-else>
                        <input v-model="it._actualValue" type="text" style="width:80px" placeholder="实际值" />
                        <input v-model="it._actualText" type="text" style="width:90px" placeholder="情况说明" />
                        <button class="btn sm" @click="actReport(it, isAdmin && !myReportItems.includes(it.id))">保存</button>
                      </template>
                    </template>
                    <template v-else>
                      <template v-if="it.calcType === 'check'">{{ it.checkScore != null && it.checkScore !== '' ? `核查定分：${it.checkScore}` : '-' }}<span v-if="it.actualText">（{{ it.actualText }}）</span></template>
                      <template v-else>{{ it.actualText || it.actualValue || '-' }}</template>
                    </template>
                    <div v-if="it.reportBy" class="hint" style="margin:0;color:#16a34a">✔ 已提交：{{ it.reportBy }}</div>
                  </td>
                  <td class="num">
                    <template v-if="it.calcType === 'check'">{{ perfFmt(it.checkScore) }}</template>
                    <template v-else>{{ perfFmt(it.autoScore) }}</template>
                  </td>
                  <td class="num">
                    <template v-if="it.calcType === 'manual'">
                      <input v-if="p.status === 'self' && canSelf" v-model.number="it._selfScore" type="number" style="width:64px" step="0.01" />
                      <template v-else>{{ perfFmt(it.selfScore) }}</template>
                    </template>
                    <template v-else><span title="客观项锁定，本人不可评分">🔒</span></template>
                    <!-- 自评依据附件 -->
                    <div v-if="it.calcType === 'manual' && ((it.attachments && it.attachments.length) || canManageAtt)" style="margin-top:4px;display:flex;flex-direction:column;gap:3px;min-width:150px;text-align:left;font-weight:400">
                      <div v-for="att in it.attachments || []" :key="att.file"
                           style="display:flex;align-items:center;gap:4px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:2px 5px;font-size:11px"
                           :title="`${att.uploaderName || ''} 上传于 ${att.ts || ''}，${fmtSize(att.size)}`">
                        <img v-if="isImageAtt(att) && att._url" :src="att._url" alt="" @click="previewAtt(att)"
                             style="width:26px;height:26px;object-fit:cover;border-radius:4px;cursor:pointer" />
                        <span v-else @click="previewAtt(att)" style="font-size:15px;cursor:pointer">📄</span>
                        <span @click="previewAtt(att)" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer">{{ att.name }}</span>
                        <button v-if="canManageAtt" class="btn sm danger" style="padding:0 5px" @click="removeAtt(it, att)">×</button>
                      </div>
                      <label v-if="canManageAtt && (it.attachments || []).length < 5"
                             style="display:inline-block;border:1px dashed #94a3b8;border-radius:6px;padding:2px 8px;font-size:11px;color:#475569;cursor:pointer;width:fit-content">
                        <input type="file" accept=".jpg,.jpeg,.png,.pdf" style="display:none" @change="pickAttach(it, $event)" />＋ 上传依据
                      </label>
                    </div>
                  </td>
                  <td class="num">
                    <template v-if="it.calcType === 'manual'">
                      <input v-if="p.status === 'approve' && canActApproval" v-model.number="it._approverScore" type="number" style="width:64px" step="0.01" placeholder="上级分" @input="mixFinalOf(it)" />
                      <template v-else>{{ perfFmt(it.approverScore) }}</template>
                    </template>
                    <template v-else><span title="客观项锁定，上级不可评分">🔒</span></template>
                  </td>
                  <td class="num">
                    <template v-if="it.calcType === 'manual'">
                      <input v-if="p.status === 'approve' && canActApproval" v-model.number="it._finalScore" type="number" style="width:64px" step="0.01" placeholder="自动/微调" />
                      <template v-else>{{ perfFmt(it.finalScore) }}</template>
                    </template>
                    <template v-else>
                      <span v-if="p.status === 'approve' && canActApproval" title="客观项已锁定">🔒{{ perfFmt(lockedScore(it)) }}</span>
                      <template v-else>{{ perfFmt(it.finalScore) }}</template>
                    </template>
                    <div v-for="(o, oi) in it.overrides || []" :key="oi" class="hint" style="margin:0;color:#c2410c">改:{{ perfFmt(o.from) }}→{{ perfFmt(o.to) }} {{ o.by }}</div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
      <!-- 同项目排名参考 -->
      <div v-if="(p.status === 'approve' || p.status === 'done') && p.project" class="card" style="margin-top:12px">
        <h3>同项目绩效得分排名（已归档，供审批/微调参考）</h3>
        <div style="font-size:12.5px;color:#64748b">
          <div v-if="rankRefErr" class="hint">排名加载失败</div>
          <div v-else-if="!rankRef.length" class="hint">该项目暂无已归档考核</div>
          <div v-else class="table-wrap" style="max-height:240px">
            <table class="tb">
              <thead><tr><th>排名</th><th>员工</th><th>项目</th><th>自评</th><th>考核人</th><th>最终</th><th>等级</th><th></th></tr></thead>
              <tbody>
                <tr v-for="r in rankRef" :key="r.id">
                  <td class="num"><b>{{ r.rank }}</b></td><td>{{ r.employeeName }}</td><td>{{ r.project }}</td>
                  <td class="num">{{ perfFmt(r.selfTotal) }}</td><td class="num">{{ perfFmt(r.approverTotal) }}</td>
                  <td class="num"><b>{{ perfFmt(r.finalTotal) }}</b></td><td><span v-if="r.grade" class="tag purple">{{ r.grade }}</span><template v-else>-</template></td>
                  <td><span v-if="r.employeeName === p.employeeName" class="tag blue">当前单</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <!-- 流程记录 -->
      <div class="card">
        <h3>流程记录</h3>
        <div class="perf-logs">
          <div v-for="(l, li) in logsDesc" :key="li" class="pf-log">
            <span class="pf-log-ts">{{ l.ts }}</span><span class="pf-log-actor">{{ l.actor }}</span>
            <span>{{ l.action }}<template v-if="l.comment">｜<span style="color:#c2410c">{{ l.comment }}</span></template></span>
          </div>
          <span v-if="!logsDesc.length" class="hint">暂无</span>
        </div>
      </div>
      <!-- 驳回弹窗 -->
      <div v-if="modal === 'reject'" class="modal-mask" @click.self="modal = null">
        <div class="modal">
          <h3>驳回（需填写意见）</h3>
          <textarea v-model="rejectOpinion" rows="4" style="width:100%" placeholder="请说明驳回原因，将退回发起人"></textarea>
          <div class="row end" style="margin-top:12px">
            <button class="btn" @click="modal = null">取消</button>
            <button class="btn danger" @click="doReject">确认驳回</button>
          </div>
        </div>
      </div>
      <!-- 审批通过弹窗 -->
      <div v-if="modal === 'approve'" class="modal-mask" @click.self="modal = null">
        <div class="modal">
          <h3>{{ isLastStep ? '终审通过并归档' : '审批通过，送下一级' }}</h3>
          <label style="font-size:13px;color:#595959">审批意见（可留空）</label>
          <textarea v-model="approveOpinion" rows="3" style="width:100%;margin-top:4px" placeholder="通过">通过</textarea>
          <div class="row end" style="margin-top:12px">
            <button class="btn" @click="modal = null">取消</button>
            <button class="btn success" @click="doApprove">确认通过</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'
import { perfFmt, perfNum, perfWeightSum, mixFinal, perfApId, defaultCalcParams, CALC_LABEL, CALC_HELP, OBJECTIVE_TYPES } from './perfLogic'

const PERF_FLOW = ['draft', 'confirm', 'ongoing', 'report', 'self', 'approve', 'done']
const PERF_FLOW_NAME = ['制表', '确认指标', '周期进行', '数据填报', '发起人自评', '逐级审批', '归档']
const PERF_STATUS_LABEL = { draft: '草稿', confirm: '待确认指标', ongoing: '考核进行中', report: '待数据填报', self: '待发起人自评', approve: '待逐级审批', done: '已归档' }
const PERF_STATUS_COLOR = { draft: 'gray', confirm: 'orange', ongoing: 'blue', report: 'orange', self: 'orange', approve: 'purple', done: 'green' }

const ATTACH_MAX_MB = 10
const ATTACH_MAX_PER_ITEM = 5

const props = defineProps({ id: { type: [Number, String], required: true } })
defineEmits(['back'])
const auth = useAuthStore()
const router = useRouter()

const editingConfirm = ref(false)   // confirm 态：审核人直接改指标模式
const staffOptions = ref([])        // confirm 编辑核查人/填报人下拉
const savingConfirm = ref(false)

const meta = ref(null)
const p = ref(null)
const loadErr = ref('')
const rankRef = ref([])
const rankRefErr = ref(false)
const modal = ref(null)
const rejectEndpoint = ref('')
const rejectOpinion = ref('')
const approveOpinion = ref('通过')

const sw = computed(() => (meta.value && meta.value.scoreWeights) || { self: 50, approver: 50 })
const isAdmin = computed(() => !!(meta.value && meta.value.isAdmin) || auth.user?.role === 'admin')
const meInfo = computed(() => (meta.value && meta.value.me) || {})
const stCol = computed(() => PERF_STATUS_COLOR[p.value.status] || 'gray')
const statusLabel = computed(() => PERF_STATUS_LABEL[p.value.status] || p.value.status)
const stepIdx = computed(() => PERF_FLOW.indexOf(p.value.status))
const curAp = computed(() => (p.value.approvers || [])[p.value.currentStep || 0])
const isApprover = computed(() => {
  const a = curAp.value
  return !!(a && (perfApId(a) === meInfo.value.staffId || perfApId(a) === meInfo.value.userId))
})
const isFounder = computed(() => p.value.founderId === meInfo.value.userId)
const myReportItems = computed(() => {
  const ids = []
  ;(p.value.categories || []).forEach((c) => (c.items || []).forEach((it) => {
    if (it.reporterId === meInfo.value.staffId || it.reporterId === meInfo.value.userId) ids.push(it.id)
  }))
  return ids
})
const canActApproval = computed(() => isApprover.value || isAdmin.value)
const canSelf = computed(() => isFounder.value || isAdmin.value)
const repPending = computed(() => {
  let n = 0
  ;(p.value.categories || []).forEach((c) => (c.items || []).forEach((it) => {
    if (!it.reporterId || it.reportBy) return
    n++
  }))
  return n
})
const isLastStep = computed(() => (p.value.currentStep || 0) + 1 >= p.value.approvers.length)
const chainText = computed(() => (p.value.approvers || []).map((a, i) => `${i + 1}.${a.name}${a.state === 'approved' ? '✔' : a.state === 'current' ? '（待审）' : ''}`).join(' → '))
const logsDesc = computed(() => ((p.value.logs || []).slice().reverse()))
// 客观项（比例/阶梯/达标/核查）：本人与上级均不可评分，分数在填报阶段锁定；仅 manual 可主观打分
const isObjective = (it) => OBJECTIVE_TYPES.includes(it.calcType)
// 客观项锁定分：check 取核查定分，其余取自动算分
const lockedScore = (it) => (it.calcType === 'check' ? perfNum(it.checkScore) : perfNum(it.autoScore))
// self 态可管理附件（与 self_submit 同口径：发起人本人/管理员）
const canManageAtt = computed(() => p.value.status === 'self' && canSelf.value)

function fmtSize(n) {
  n = Number(n) || 0
  return n >= 1048576 ? (n / 1048576).toFixed(1) + 'MB' : Math.max(1, Math.round(n / 1024)) + 'KB'
}

// 编辑输入的临时字段（复刻旧版以 DOM 输入框为数据源的方式）
function withEditFields(plan) {
  ;(plan.categories || []).forEach((c) => (c.items || []).forEach((it) => {
    it._actualValue = it.actualValue ?? ''
    it._actualText = it.actualText || ''
    it._checkScore = it.checkScore ?? ''
    // 仅主观项可自评；客观项锁定，输入框不渲染
    it._selfScore = it.calcType === 'manual' ? (it.selfScore ?? '') : ''
    it._approverScore = it.approverScore ?? ''
    it._finalScore = ''
  }))
  return plan
}

onMounted(fetchPlan)

async function fetchPlan() {
  try {
    const [d] = await Promise.all([
      api(`/api/performance/plans/${props.id}`),
      loadStaffOptions(),
    ])
    meta.value = d
    p.value = withEditFields(JSON.parse(JSON.stringify(d.plan)))
    editingConfirm.value = false
    if (p.value.status === 'approve' || p.value.status === 'done') loadRankRef()
    else { rankRef.value = []; rankRefErr.value = false }
    preloadThumbs()
  } catch (e) {
    loadErr.value = e.message
  }
}

// 审核态改指标需要的人员下拉（有启用账号者可选，与发起页口径一致）
async function loadStaffOptions() {
  try {
    const [u, s] = await Promise.all([api('/api/performance/user_options'), api('/api/staff?cat=在职')])
    const accStaff = new Set((u.users || []).filter((x) => x.enabled !== false && x.staffId).map((x) => String(x.staffId)))
    staffOptions.value = (s.staff || []).filter((x) => !x.deleted).map((x) => ({
      id: x.id,
      hasAccount: accStaff.has(String(x.id)),
      label: `${x.name}｜${x.project}｜${x.position || ''}${accStaff.has(String(x.id)) ? '' : '（未绑定账号，不可选）'}`,
    }))
  } catch (e) { staffOptions.value = [] }
}

function pickReporterEdit(it, val) {
  it.reporterId = val ? Number(val) : null
  const s = staffOptions.value.find((x) => String(x.id) === String(val))
  it.reporterName = s ? s.label.split('｜')[0] : ''
}
function changeCalcEdit(it) { it.calcParams = defaultCalcParams(it.calcType) }

// confirm 态审核人直接改指标并保存（指标留痕由后端写入 logs）
async function saveConfirmEdit() {
  const ws = perfWeightSum(p.value)
  if (Math.abs(ws - 100) > 0.01) { alert(`权重合计须为100，当前${ws}`); return }
  for (const c of p.value.categories) for (const it of c.items || []) {
    if (!it.content) { alert('存在未填指标内容的行'); return }
  }
  savingConfirm.value = true
  try {
    await api('/api/performance/confirm_save', {
      body: { id: p.value.id, categories: p.value.categories.map((c) => ({ name: c.name, items: (c.items || []).map(stripEditFields) })) },
    })
    toast('指标修改已保存')
    reload()
  } catch (e) { alert(e.message); savingConfirm.value = false }
}
function stripEditFields(it) {
  const c = { ...it }
  ;['_actualValue', '_actualText', '_checkScore', '_selfScore', '_approverScore', '_finalScore'].forEach((k) => delete c[k])
  return c
}

function seqOf(cat, it) {
  // 按类别×指标顺序生成全局序号（与旧版 seq++ 一致）
  let seq = 1
  for (const c of p.value.categories || []) {
    for (const i2 of c.items || []) {
      if (c === cat && i2 === it) return seq
      seq++
    }
  }
  return seq
}

async function loadRankRef() {
  rankRefErr.value = false
  try {
    const d = await api(`/api/performance/ranking?project=${encodeURIComponent(p.value.project || '')}&quarter=0`)
    rankRef.value = (d.items || []).slice(0, 20)
  } catch (e) { rankRefErr.value = true }
}

// 审批时：填考核人评分自动按占比算该项最终分预览
function mixFinalOf(it) {
  const f = mixFinal(it.selfScore, it._approverScore, sw.value)
  it._finalScore = f === null ? '' : f
}

function reload() { loadErr.value = ''; p.value = null; fetchPlan() }

async function actConfirm() {
  try { await api('/api/performance/confirm', { body: { id: p.value.id } }); toast('已确认，进入考核周期'); reload() } catch (e) { alert(e.message) }
}
async function actStartReport() {
  if (!confirm('确认提前结束考核周期并立即转入数据填报？\n转入后，各指标填报人即可在「待我填报」中填写实际完成值。')) return
  try { await api('/api/performance/start_report', { body: { id: p.value.id } }); toast('已转入数据填报'); reload() } catch (e) { alert(e.message) }
}
async function actFinishReport() {
  const pending = repPending.value
  if (pending > 0 && !confirm(`还有 ${pending} 项指标未填写实际值，确认现在就结束数据填报、进入发起人自评吗？`)) return
  try { await api('/api/performance/finish_report', { body: { id: p.value.id } }); toast(pending ? '已提前进入发起人自评' : '全部填齐，已进入发起人自评'); reload() } catch (e) { alert(e.message) }
}
function openReject(endpoint) {
  rejectEndpoint.value = endpoint
  rejectOpinion.value = ''
  modal.value = 'reject'
}
async function doReject() {
  const opinion = rejectOpinion.value.trim()
  if (!opinion) { alert('请填写驳回意见'); return }
  try {
    await api('/api/performance/' + rejectEndpoint.value, { body: { id: p.value.id, opinion } })
    modal.value = null; toast('已驳回', false); reload()
  } catch (e) { alert(e.message) }
}
async function actReport(it, adminFill) {
  const body = { id: p.value.id, itemId: it.id, actualValue: it._actualValue, actualText: it._actualText }
  if (it.calcType === 'check') body.checkScore = it._checkScore
  try {
    const r = await api(adminFill ? '/api/performance/admin_fill' : '/api/performance/report', { body })
    toast('已提交' + (r.status === 'self' ? '，全部指标填报完成，已转发起人自评' : ''))
    reload()
  } catch (e) { alert(e.message) }
}
async function actUrge() {
  try {
    const r = await api('/api/performance/urge', { body: { id: p.value.id } })
    if (!r.pending.length) toast('已无待填报项')
    else toast('已催办：' + r.pending.map((x) => x.name).join('、'))
  } catch (e) { alert(e.message) }
}
async function actSelfSubmit() {
  const items = []
  // 仅主观项可自评打分；客观项（含核查定分）已锁定，不提交
  for (const c of p.value.categories) for (const it of c.items) {
    if (it.calcType === 'manual' && it._selfScore !== undefined && it._selfScore !== null && it._selfScore !== '') {
      items.push({ id: it.id, selfScore: it._selfScore })
    }
  }
  try { await api('/api/performance/self_submit', { body: { id: p.value.id, items } }); toast('自评已提交，进入审批'); reload() } catch (e) { alert(e.message) }
}
function openApprove() { approveOpinion.value = '通过'; modal.value = 'approve' }
async function doApprove() {
  const items = []
  for (const c of p.value.categories) for (const it of c.items) {
    const item = { id: it.id }
    // 仅主观项接受上级评分/微调；客观项锁定，服务端也会拒绝
    if (it.calcType === 'manual') {
      if (it._approverScore !== '' && it._approverScore != null) item.approverScore = it._approverScore
      if (it._finalScore !== '' && it._finalScore != null) item.finalScore = it._finalScore
    }
    items.push(item)
  }
  const opinion = (approveOpinion.value || '通过').trim()
  modal.value = null
  try {
    const r = await api('/api/performance/approve', { body: { id: p.value.id, items, opinion } })
    toast(r.status === 'done' ? `终审通过，等级：${r.grade}` : '已通过，送下一级')
    reload()
  } catch (e) {
    // 终审拦截：客观数据缺失项由服务端返回 missing（类别/指标）
    if (e.payload && Array.isArray(e.payload.missing) && e.payload.missing.length) {
      alert('以下客观指标尚未填报/核查定分，无法终审归档：\n· ' + e.payload.missing.join('\n· '))
    } else { alert(e.message) }
  }
}
function exportPlan() {
  download(`/api/performance/export/${p.value.id}`, '绩效考核表.xlsx')
}

// ============================ 自评附件 ============================

function attachUrl(att) { return `/api/performance/attachment?id=${p.value.id}&file=${encodeURIComponent(att.file)}` }
const isImageAtt = (att) => /\.(jpe?g|png)$/i.test(att.file || '')

// 图片缩略图：接口需 X-Token，不能直接 <img src>，登录态内 fetch 成 blob 预览
async function ensureAttUrl(att) {
  if (att._url) return att._url
  try {
    const blob = await api(attachUrl(att))
    att._url = URL.createObjectURL(blob)
  } catch (e) { att._url = '' }
  return att._url
}

function preloadThumbs() {
  ;(p.value.categories || []).forEach((c) => (c.items || []).forEach((it) => {
    (it.attachments || []).forEach((att) => { if (isImageAtt(att)) ensureAttUrl(att) })
  }))
}

async function previewAtt(att) {
  const url = await ensureAttUrl(att)
  if (url) window.open(url, '_blank')
}

function pickAttach(it, ev) {
  const file = ev.target.files && ev.target.files[0]
  ev.target.value = ''
  if (!file) return
  if (!/\.(jpe?g|png|pdf)$/i.test(file.name)) { alert('仅支持 jpg/jpeg/png/pdf 格式'); return }
  if (file.size > ATTACH_MAX_MB * 1048576) { alert(`附件不能超过 ${ATTACH_MAX_MB}MB`); return }
  if ((it.attachments || []).length >= ATTACH_MAX_PER_ITEM) { alert(`每项指标最多 ${ATTACH_MAX_PER_ITEM} 个附件`); return }
  uploadAtt(it, file)
}

async function uploadAtt(it, file) {
  const fd = new FormData()
  fd.append('id', p.value.id)
  fd.append('itemId', it.id)
  fd.append('file', file)
  try {
    const r = await api('/api/performance/attachment', { method: 'POST', form: fd })
    it.attachments = r.attachments || []
    const att = it.attachments[it.attachments.length - 1]
    if (att && isImageAtt(att)) ensureAttUrl(att)
    toast('附件已上传')
  } catch (e) { alert(e.message) }
}

async function removeAtt(it, att) {
  if (!confirm(`确认删除附件「${att.name}」？`)) return
  try {
    const r = await api('/api/performance/attachment', { method: 'DELETE', body: { id: p.value.id, itemId: it.id, file: att.file } })
    it.attachments = r.attachments || []
    if (att._url) URL.revokeObjectURL(att._url)
    toast('附件已删除')
  } catch (e) { alert(e.message) }
}

// ============================ 归档后操作 ============================

function gotoPrint() { router.push('/perfPrint/' + p.value.id) }

async function actReopen() {
  if (!confirm('确认撤销归档？\n单据将退回「逐级审批」起点：上级评分全部清空重走，本人自评、客观数据/核查定分与附件保留。')) return
  try {
    await api('/api/performance/reopen', { body: { id: p.value.id } })
    toast('已撤销归档，退回上级评分')
    reload()
  } catch (e) { alert(e.message) }
}
</script>
