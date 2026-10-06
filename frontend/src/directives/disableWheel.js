// 鼠标滚轮防护指令：聚焦的 <input type="number"> 在滚动滚轮时会被浏览器步进改值，
// 薪资微调弹窗曾因此误改金额。策略是滚轮落在 number 输入框上时令其失焦——
// 不阻止页面滚动（不 preventDefault），只取消数字步进；text 输入框不受影响。
// 用法：挂在弹窗容器上即可（wheel 冒泡，事件委托内部所有 number 框），v-disable-wheel
export function handleWheelBlur(e) {
  const t = e && e.target
  if (t && t.tagName === 'INPUT' && t.type === 'number' && typeof t.blur === 'function') {
    t.blur()
  }
}

export const vDisableWheel = {
  mounted(el) {
    el.__disableWheelHandler = (e) => handleWheelBlur(e)
    // passive：不调 preventDefault；capture：在目标默认行为前失焦
    el.addEventListener('wheel', el.__disableWheelHandler, { passive: true, capture: true })
  },
  unmounted(el) {
    if (el.__disableWheelHandler) {
      el.removeEventListener('wheel', el.__disableWheelHandler, { capture: true })
      delete el.__disableWheelHandler
    }
  },
}
