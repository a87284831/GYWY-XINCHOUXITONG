// SVG 线性图标（复刻旧版 app.js svgIco，lucide 风格 stroke 渲染），模板中以 v-html 使用
const PATHS = {
  building: 'M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M14 8h4a2 2 0 0 1 2 2v11M2 21h20M7 7h2M11 7h2M7 11h2M11 11h2M7 15h2M11 15h2M7 19h2M11 19h2',
  pin: 'M12 21s-7-5.1-7-11a7 7 0 1 1 14 0c0 5.9-7 11-7 11zM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  folder: 'M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9L9.2 3.9A2 2 0 0 0 7.5 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z',
  plus: 'M12 5v14M5 12h14',
  pencil: 'M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z',
  trash: 'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6h14zM10 11v6M14 11v6',
  more: 'M5 12h.01M12 12h.01M19 12h.01',
  search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35',
  network: 'M12 3a2 2 0 0 1 2 2c0 .4-.1.7-.3 1H17a3 3 0 0 1 3 3v.5M12 3a2 2 0 0 0-2 2c0 .4.1.7.3 1H7a3 3 0 0 0-3 3v.5M12 3v18M12 21a2 2 0 0 1-2-2c0-.4.1-.7.3-1M12 21a2 2 0 0 0 2-2c0-.4-.1-.7-.3-1M4 9.5A2.5 2.5 0 0 1 4 14.5M20 9.5a2.5 2.5 0 0 0 0 5M4 9.5V11a2 2 0 0 0 2 2h1.3M20 9.5V11a2 2 0 0 1-2 2h-1.3M6 13h1.3a2 2 0 0 1 1.4.6l2 2a2 2 0 0 0 1.4.6h2.6a2 2 0 0 0 2-2v-.2M12 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM12 21.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
  printer: 'M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z',
  arrow: 'M19 12H5M12 19l-7-7 7-7',
  zoomout: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35M8 11h6',
  zoomin: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35M11 8v6M8 11h6',
  x: 'M18 6L6 18M6 6l12 12',
  pause: 'M10 4H6v16h4zM18 4h-4v16h4z',
  play: 'M6 4l14 8-14 8z',
  user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  home: 'M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1V11z',
  dot: 'M12 12m-3 0a3 3 0 1 0 6 0 3 3 0 1 0-6 0',
}

export function svgIco(name, size, color, sw) {
  const d = PATHS[name] || PATHS.dot
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="${d}"/></svg>`
}
