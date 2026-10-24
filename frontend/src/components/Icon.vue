<template>
  <svg
    class="icon"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    :stroke-width="sw"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    v-html="body"
  ></svg>
</template>

<script setup>
import { computed } from 'vue'

// 全站内联 SVG 图标注册表（线性 24×24，stroke=currentColor）
// 新增图标只加在这里，别处一律 <Icon name="..." /> 引用
const ICONS = {
  home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
  doc: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
  box: '<path d="M3 8l9-4 9 4v8l-9 4-9-4z"/><path d="M3 8l9 4 9-4"/><path d="M12 12v8"/>',
  briefcase: '<path d="M3 7h18v13H3z"/><path d="M8 7V4h8v3"/>',
  chart: '<path d="M4 20V10M10 20V4M16 20v-7"/><path d="M2 20h20"/>',
  bar: '<path d="M3 3v18h18"/><path d="M8 15v3M13 10v8M18 6v11"/>',
  trend: '<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
  pie: '<path d="M21 12A9 9 0 1 1 12 3v9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
  pen: '<path d="M17 3a2.8 2.8 0 1 1 4 4L8 20l-5 1 1-5z"/>',
  clipboard: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4a2 2 0 0 1 6 0"/><path d="M9 12l2 2 4-4"/>',
  receipt: '<path d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21z"/><path d="M9 8h6M9 12h6"/>',
  coins: '<circle cx="12" cy="12" r="8"/><path d="M8.5 8.5 12 12.5l3.5-4"/><path d="M12 12.5V16"/><path d="M9.5 14h5"/>',
  money: '<rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.5"/><path d="M6.5 10.5h.01M17.5 14.5h.01"/>',
  calendar: '<rect x="3" y="5" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/>',
  scroll: '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M6 3h8l4 4v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
  check: '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/>',
  users: '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 20c.6-3.4 2.8-5 5.5-5s4.9 1.6 5.5 5"/><path d="M16 11a3 3 0 1 0 0-6"/><path d="M17.5 15.5c1.7.6 2.7 1.9 3 4.5"/>',
  building: '<rect x="4" y="3" width="18" height="18" rx="2"/><path d="M9 21v-4h6v4"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2"/>',
  id: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="11" r="2"/><path d="M5.5 16.5c.5-1.6 1.6-2.3 3-2.3s2.5.7 3 2.3"/><path d="M14 9h5M14 13h5"/>',
  target: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/><circle cx="12" cy="12" r="1"/>',
  book: '<path d="M4 5a2 2 0 0 1 2-2h14v16H6a2 2 0 0 0-2 2z"/><path d="M4 19a2 2 0 0 1 2-2h14"/>',
  wallet: '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><rect x="3" y="7" width="18" height="12" rx="2"/><path d="M16 13h2"/>',
  wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
  flame: '<path d="M12 3s5 4.5 5 9.5a5 5 0 0 1-10 0c0-2 1-3.7 1-3.7s.5 1.2 1.5 1.7C10 8 11 5.5 12 3z"/>',
  lift: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M12 3v18"/><path d="M8 9l1.5-1.5L11 9"/><path d="M13 15l1.5 1.5L16 15"/>',
  link: '<path d="M10 14a4 4 0 0 0 6 0l3-3a4 4 0 0 0-6-6l-1.5 1.5"/><path d="M14 10a4 4 0 0 0-6 0l-3 3a4 4 0 0 0 6 6l1.5-1.5"/>',
  gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>',
  bell: '<path d="M18 9a6 6 0 1 0-12 0c0 6-2.5 7-2.5 7h17S18 15 18 9z"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
  user: '<circle cx="12" cy="8" r="3.4"/><path d="M5 20c.7-3.7 3.3-5.4 7-5.4s6.3 1.7 7 5.4"/>',
  lock: '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
  grid: '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
  'chev-down': '<path d="M6 9l6 6 6-6"/>',
  'chev-right': '<path d="M9 6l6 6-6 6"/>',
  shield: '<path d="M12 3l7 4v5c0 4.4-3 8-7 9-4-1-7-4.6-7-9V7z"/><path d="M9 12l2 2 4-4"/>',
  sparkles: '<path d="M12 4l1.7 4.3L18 10l-4.3 1.7L12 16l-1.7-4.3L6 10l4.3-1.7z"/><path d="M19 15l.8 2.2L21 18l-2.2.8L18 21l-.8-2.2L15 18l2.2-.8z"/>',
  megaphone: '<path d="M3 10v4h3l6 5V5L6 10H3z"/><path d="M15 8.5a4 4 0 0 1 0 7"/>',
  card: '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18"/>',
  bag: '<rect x="4" y="8" width="16" height="12" rx="2"/><path d="M9 8V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M4 13h16"/>',
  calc: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h6"/><path d="M8.5 11h.01M12 11h.01M15.5 11h.01M8.5 14.5h.01M12 14.5h.01M15.5 14.5h.01M8.5 18h.01M12 18h.01M15.5 18h.01"/>',
  folder: '<path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  file: '<path d="M6 2h9l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v5h5"/>',
  clock: '<circle cx="12" cy="13" r="7"/><path d="M12 9.5V13l2.5 1.5"/><path d="M5 3 3.5 5M19 3l1.5 2"/>',
  up: '<path d="M12 16V4"/><path d="M6 10l6-6 6 6"/><path d="M4 20h16"/>',
  down: '<path d="M12 4v12"/><path d="M6 14l6 6 6-6"/><path d="M4 20h16"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  moon: '<path d="M20 13A8 8 0 1 1 11 4a7 7 0 0 0 9 9z"/>',
  refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
  close: '<path d="M6 6l12 12M18 6 6 18"/>',
  back: '<path d="M15 5l-7 7 7 7"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
  key: '<circle cx="8" cy="14" r="4"/><path d="M10.8 11.2 20 2"/><path d="M15 7l3 3"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  minus: '<path d="M5 12h14"/>',
  dot: '<circle cx="12" cy="12" r="3"/>',
  warning: '<path d="M12 3 2.5 20h19z"/><path d="M12 9v5"/><path d="M12 17.5h.01"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7h.01"/>',
  eye: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.5"/>',
  printer: '<path d="M7 8V3h10v5"/><rect x="4" y="8" width="16" height="8" rx="2"/><path d="M7 13h10v8H7z"/>',
}

const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 16 },
  sw: { type: [Number, String], default: 1.8 },
})

const body = computed(() => ICONS[props.name] || ICONS.dot)
</script>

<style scoped>
.icon { display: inline-block; vertical-align: -2px; flex-shrink: 0; }
</style>
