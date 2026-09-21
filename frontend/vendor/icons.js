/* Local Lucide icon subset (ISC license, https://lucide.dev) — no CDN dependency.
   Mimics lucide.createIcons(): replaces <i data-lucide="name"> with inline SVG,
   carrying over the placeholder classNAME so existing size/color utility classes still apply. */
window.lucide = { icons: {
  "arrow-right": "<path d=\"M5 12h14\" />\n  <path d=\"m12 5 7 7-7 7\" />",
  "boxes": "<path d=\"M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l3 1.8a2 2 0 0 0 2.06 0L12 19v-5.5l-5-3-4.03 2.42Z\" />\n  <path d=\"m7 16.5-4.74-2.85\" />\n  <path d=\"m7 16.5 5-3\" />\n  <path d=\"M7 16.5v5.17\" />\n  <path d=\"M12 13.5V19l3.97 2.38a2 2 0 0 0 2.06 0l3-1.8a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71L17 10.5l-5 3Z\" />\n  <path d=\"m17 16.5-5-3\" />\n  <path d=\"m17 16.5 4.74-2.85\" />\n  <path d=\"M17 16.5v5.17\" />\n  <path d=\"M7.97 4.42A2 2 0 0 0 7 6.13v4.37l5 3 5-3V6.13a2 2 0 0 0-.97-1.71l-3-1.8a2 2 0 0 0-2.06 0l-3 1.8Z\" />\n  <path d=\"M12 8 7.26 5.15\" />\n  <path d=\"m12 8 4.74-2.85\" />\n  <path d=\"M12 13.5V8\" />",
  "building-2": "<path d=\"M10 12h4\" />\n  <path d=\"M10 8h4\" />\n  <path d=\"M14 21v-3a2 2 0 0 0-4 0v3\" />\n  <path d=\"M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2\" />\n  <path d=\"M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16\" />",
  "chevron-down": "<path d=\"m6 9 6 6 6-6\" />",
  "hammer": "<path d=\"m15 12-9.373 9.373a1 1 0 0 1-3.001-3L12 9\" />\n  <path d=\"m18 15 4-4\" />\n  <path d=\"m21.5 11.5-1.914-1.914A2 2 0 0 1 19 8.172v-.344a2 2 0 0 0-.586-1.414l-1.657-1.657A6 6 0 0 0 12.516 3H9l1.243 1.243A6 6 0 0 1 12 8.485V10l2 2h1.172a2 2 0 0 1 1.414.586L18.5 14.5\" />",
  "layout-dashboard": "<rect width=\"7\" height=\"9\" x=\"3\" y=\"3\" rx=\"1\" />\n  <rect width=\"7\" height=\"5\" x=\"14\" y=\"3\" rx=\"1\" />\n  <rect width=\"7\" height=\"9\" x=\"14\" y=\"12\" rx=\"1\" />\n  <rect width=\"7\" height=\"5\" x=\"3\" y=\"16\" rx=\"1\" />",
  "log-out": "<path d=\"m16 17 5-5-5-5\" />\n  <path d=\"M21 12H9\" />\n  <path d=\"M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4\" />",
  "plus": "<path d=\"M5 12h14\" />\n  <path d=\"M12 5v14\" />",
  "search": "<path d=\"m21 21-4.34-4.34\" />\n  <circle cx=\"11\" cy=\"11\" r=\"8\" />",
  "smartphone": "<rect width=\"14\" height=\"20\" x=\"5\" y=\"2\" rx=\"2\" ry=\"2\" />\n  <path d=\"M12 18h.01\" />",
  "trash-2": "<path d=\"M10 11v6\" />\n  <path d=\"M14 11v6\" />\n  <path d=\"M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6\" />\n  <path d=\"M3 6h18\" />\n  <path d=\"M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2\" />",
  "truck": "<path d=\"M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2\" />\n  <path d=\"M15 18H9\" />\n  <path d=\"M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14\" />\n  <circle cx=\"17\" cy=\"18\" r=\"2\" />\n  <circle cx=\"7\" cy=\"18\" r=\"2\" />",
  "box": "<path d=\"M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z\" />\n  <path d=\"m3.3 7 8.7 5 8.7-5\" />\n  <path d=\"M12 22V12\" />",
  "briefcase": "<path d=\"M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16\" />\n  <rect width=\"20\" height=\"14\" x=\"2\" y=\"6\" rx=\"2\" />",
  "file-spreadsheet": "<path d=\"M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z\" />\n  <path d=\"M14 2v5a1 1 0 0 0 1 1h5\" />\n  <path d=\"M8 13h2\" />\n  <path d=\"M14 13h2\" />\n  <path d=\"M8 17h2\" />\n  <path d=\"M14 17h2\" />",
  "layers": "<path d=\"M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z\" />\n  <path d=\"M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12\" />\n  <path d=\"M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17\" />",
  "trending-up": "<path d=\"M16 7h6v6\" />\n  <path d=\"m22 7-8.5 8.5-5-5L2 17\" />",
  "users": "<path d=\"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\" />\n  <path d=\"M16 3.128a4 4 0 0 1 0 7.744\" />\n  <path d=\"M22 21v-2a4 4 0 0 0-3-3.87\" />\n  <circle cx=\"9\" cy=\"7\" r=\"4\" />",
  "history": "<path d=\"M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8\" />\n  <path d=\"M3 3v5h5\" />\n  <path d=\"M12 7v5l4 2\" />",
  "file-down": "<path d=\"M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z\" />\n  <path d=\"M14 2v5a1 1 0 0 0 1 1h5\" />\n  <path d=\"M12 18v-6\" />\n  <path d=\"m9 15 3 3 3-3\" />",
  "printer": "<path d=\"M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2\" />\n  <path d=\"M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6\" />\n  <rect x=\"6\" y=\"14\" width=\"12\" height=\"8\" rx=\"1\" />",
  "menu": "<path d=\"M4 5h16\" />\n  <path d=\"M4 12h16\" />\n  <path d=\"M4 19h16\" />",
  "x": "<path d=\"M18 6 6 18\" />\n  <path d=\"m6 6 12 12\" />"
},
  createIcons: function(){
    document.querySelectorAll("[data-lucide]").forEach(function(el){
      var name = el.getAttribute("data-lucide");
      var inner = window.lucide.icons[name];
      if(!inner) return;
      var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      svg.setAttribute("viewBox", "0 0 24 24");
      svg.setAttribute("fill", "none");
      svg.setAttribute("stroke", "currentColor");
      svg.setAttribute("stroke-width", "2");
      svg.setAttribute("stroke-linecap", "round");
      svg.setAttribute("stroke-linejoin", "round");
      svg.className.baseVal = el.className.baseVal !== undefined ? el.className.baseVal : (el.getAttribute("class")||"");
      svg.innerHTML = inner;
      el.replaceWith(svg);
    });
  }
};
