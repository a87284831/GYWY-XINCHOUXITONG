import { describe, it, expect } from 'vitest'
import {
  qs, gateLabel, normalizePlate, isValidPlate, isValidPhone, reasonsOf,
  validateRegistration, kpiCards, pageList, qrFileName,
} from './parkingLogic'

const config = {
  reasons: ['拜访', '送货'],
  fields: [
    { key: 'ab12cd34', label: '随车人数', type: 'number', required: false, enabled: true, options: [] },
    { key: 'ef56gh78', label: '来访目的', type: 'radio', required: true, enabled: true, options: ['家庭访问', '装修施工'] },
    { key: 'zz000000', label: '已停用字段', type: 'text', required: true, enabled: false, options: [] },
  ],
}

function form(over = {}) {
  return {
    plate: '鲁Q12345',
    name: '张三',
    phone: '13800001111',
    visit_target: '3-2-101 王先生',
    reason: '拜访',
    extra: { ab12cd34: '2', ef56gh78: '家庭访问' },
    ...over,
  }
}

describe('qs（筛选拼参）', () => {
  it('拼接并跳过空值', () => {
    expect(qs({ from: '2026-11-01', to: '', project_id: null, gate_id: undefined })).toBe('?from=2026-11-01')
    expect(qs({ from: '2026-11-01', project_id: 3 })).toBe('?from=2026-11-01&project_id=3')
  })
  it('无有效参数返回空串', () => {
    expect(qs({})).toBe('')
    expect(qs(null)).toBe('')
  })
})

describe('gateLabel（入口 URL 标签）', () => {
  it('小区名 · 门名', () => {
    expect(gateLabel('罗庄春暖花开', '东门')).toBe('罗庄春暖花开 · 东门')
    expect(gateLabel('', '东门')).toBe('东门')
    expect(gateLabel('罗庄春暖花开', '')).toBe('罗庄春暖花开')
    expect(gateLabel(null, null)).toBe('')
  })
})

describe('车牌 / 手机号校验', () => {
  it('普通与新能源车牌', () => {
    expect(isValidPlate('鲁Q12345')).toBe(true)
    expect(isValidPlate('京AD12345')).toBe(true)
    expect(isValidPlate('鲁q·12345')).toBe(true)
    expect(isValidPlate('鲁Q12')).toBe(false)
    expect(normalizePlate('鲁q·12345')).toBe('鲁Q12345')
  })
  it('手机号 11 位', () => {
    expect(isValidPhone('13800001111')).toBe(true)
    expect(isValidPhone('12345')).toBe(false)
    expect(isValidPhone('23800001111')).toBe(false)
  })
})

describe('reasonsOf', () => {
  it('空配置回退默认事由', () => {
    expect(reasonsOf({})).toEqual(['拜访', '送货', '施工', '快递', '其他'])
    expect(reasonsOf({ reasons: ['拜访'] })).toEqual(['拜访'])
  })
})

describe('validateRegistration（与后端校验链一致）', () => {
  it('完整表单通过', () => {
    expect(validateRegistration(form(), config)).toBe('')
  })
  it('固定字段校验', () => {
    expect(validateRegistration(form({ plate: '鲁Q12' }), config)).toBe('请填写正确的车牌号')
    expect(validateRegistration(form({ name: '' }), config)).toBe('请填写司机姓名')
    expect(validateRegistration(form({ phone: '12345' }), config)).toBe('请填写正确的手机号')
    expect(validateRegistration(form({ visit_target: ' ' }), config)).toBe('请填写访问户号/受访人')
    expect(validateRegistration(form({ reason: '不明' }), config)).toBe('请选择事由')
  })
  it('自定义字段：必填缺失 / 数字 / 单选 / 停用字段忽略', () => {
    const miss = form()
    delete miss.extra.ef56gh78
    expect(validateRegistration(miss, config)).toBe('请填写「来访目的」')
    expect(validateRegistration(form({ extra: { ab12cd34: '两', ef56gh78: '家庭访问' } }), config)).toBe('「随车人数」必须为数字')
    expect(validateRegistration(form({ extra: { ab12cd34: '2', ef56gh78: '看风景' } }), config)).toBe('「来访目的」选项不正确')
    // 停用字段即使必填也不校验
    expect(validateRegistration(form({ extra: { ab12cd34: '2', ef56gh78: '家庭访问' } }), config)).toBe('')
  })
})

describe('驾驶舱取数', () => {
  it('kpiCards 六张卡（含昨日对比/门/高峰）', () => {
    expect(kpiCards({
      today: 3, yesterday: 2, today_delta: 50, week: '5', month: null, total: 10,
      gates_active: 2, gates_total: 3, peak_label: '09:00', peak_count: 2,
    })).toEqual([
      { key: 'today', label: '今日登记', value: 3, color: '#2563eb', delta: 50, sub: '昨日 2 次' },
      { key: 'week', label: '近 7 日', value: 5, sub: '滚动 7 天', color: '#0ea5e9' },
      { key: 'month', label: '本月登记', value: 0, sub: '自然月', color: '#16a34a' },
      { key: 'total', label: '累计登记', value: 10, sub: '历史总量', color: '#8b5cf6' },
      { key: 'gates', label: '启用门', value: '2/3', sub: '停用后二维码失效', color: '#f97316' },
      { key: 'peak', label: '今日高峰', value: '09:00', sub: '2 次', color: '#d97706' },
    ])
  })
  it('kpiCards 空值兜底', () => {
    const cards = kpiCards()
    expect(cards[0].value).toBe(0)
    expect(cards[0].delta).toBe(null)
    expect(cards[0].sub).toBe('昨日 0 次')
    expect(cards[4].value).toBe('0/0')
    expect(cards[5].value).toBe('—')
  })
})

describe('pageList（分页器）', () => {
  it('页数、当前页与窗口', () => {
    expect(pageList(0, 1)).toEqual({ pages: 1, current: 1, items: [1] })
    expect(pageList(260, 2)).toEqual({ pages: 6, current: 2, items: [1, 2, 3, 4, 5] })
    expect(pageList(260, 6)).toEqual({ pages: 6, current: 6, items: [2, 3, 4, 5, 6] })
  })
})

describe('qrFileName（二维码下载名）', () => {
  it('带小区·门标签', () => {
    expect(qrFileName('罗庄春暖花开 · 东门')).toBe('停车登记二维码-罗庄春暖花开 · 东门')
  })
  it('替换非法字符并兜底', () => {
    expect(qrFileName('A/B:C*D?')).toBe('停车登记二维码-A_B_C_D_')
    expect(qrFileName('')).toBe('停车登记二维码-停车场')
  })
})
