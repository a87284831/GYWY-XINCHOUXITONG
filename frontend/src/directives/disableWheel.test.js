import { describe, it, expect, vi } from 'vitest'
import { handleWheelBlur, vDisableWheel } from './disableWheel.js'

// 问题2：微调弹窗内 number 输入框的鼠标滚轮防护。
// 选择「滚轮时让输入框失焦」而非 preventDefault —— 页面滚动不受影响，只阻止 number 步进。
describe('handleWheelBlur', () => {
  it('事件目标是带 blur 方法的元素时调用其 blur（阻止滚轮步进）', () => {
    const blur = vi.fn()
    handleWheelBlur({ target: { tagName: 'INPUT', type: 'number', blur } })
    expect(blur).toHaveBeenCalledTimes(1)
  })
  it('目标无 blur 方法时安全跳过', () => {
    expect(() => handleWheelBlur({ target: { tagName: 'DIV' } })).not.toThrow()
    expect(() => handleWheelBlur({ target: null })).not.toThrow()
    expect(() => handleWheelBlur(null)).not.toThrow()
  })
})

describe('vDisableWheel 指令', () => {
  it('mounted 挂载 wheel 监听、unmounted 移除（同一函数引用）', () => {
    const added = []
    const removed = []
    const el = {
      addEventListener: (type, fn, opts) => added.push({ type, fn, opts }),
      removeEventListener: (type, fn) => removed.push({ type, fn }),
    }
    vDisableWheel.mounted(el)
    expect(added).toHaveLength(1)
    expect(added[0].type).toBe('wheel')
    expect(added[0].opts).toMatchObject({ passive: true, capture: true })
    vDisableWheel.unmounted(el)
    expect(removed).toHaveLength(1)
    expect(removed[0].fn).toBe(added[0].fn)
  })

  it('挂在容器上时：滚轮来自内部 number 输入则失焦（事件委托）', () => {
    const blur = vi.fn()
    const input = { tagName: 'INPUT', type: 'number', blur }
    const el = {
      addEventListener: (type, fn) => { el._fn = fn },
      removeEventListener: () => {},
    }
    vDisableWheel.mounted(el)
    el._fn({ target: input })
    expect(blur).toHaveBeenCalledTimes(1)
  })
})
