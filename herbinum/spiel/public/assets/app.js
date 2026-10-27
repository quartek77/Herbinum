"use strict";
// Herbinum im Browser: Ressourcen-Ticker, Bau-Countdowns, isometrische Bereichsansicht, Karte.
(() => {
const $ = s => document.querySelector(s);
const esc = s => String(s ?? "").replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const reduceMotion = matchMedia("(prefers-reduced-motion: reduce)").matches;
const NS = "http://www.w3.org/2000/svg";
// Abstand zwischen Server- und Browseruhr, damit Countdowns stimmen
const offset = (+document.body.dataset.jetzt || Date.now() / 1000) - Date.now() / 1000;
const now = () => Date.now() / 1000 + offset;
const t0 = now();
const fmt = n => Math.floor(n).toLocaleString("de-DE");
async function getJSON(u) { const r = await fetch(u, { credentials: "same-origin" }); if (!r.ok) throw new Error(u + " " + r.status); return r.json(); }
function hash(s) { let h = 2166136261; for (const c of s) { h ^= c.charCodeAt(0); h = Math.imul(h, 16777619); } return h >>> 0; }
function rng(seed) { let s = seed >>> 0 || 1; return () => ((s = Math.imul(s ^ (s >>> 15), 2246822507) ^ Math.imul(s ^ (s >>> 13), 3266489909)) >>> 0) / 4294967296; }
function hex2rgb(h) { h = h.replace("#", ""); if (h.length === 3) h = [...h].map(c => c + c).join(""); return [0, 2, 4].map(i => parseInt(h.slice(i, i + 2), 16)); }
function shade(col, k) { const [r, g, b] = col.startsWith("#") ? hex2rgb(col) : col.match(/\d+/g).map(Number); const f = v => Math.max(0, Math.min(255, Math.round(v + (k > 0 ? (255 - v) * k : v * k)))); return `rgb(${f(r)},${f(g)},${f(b)})`; }

// ---------- Ressourcen laufen live hoch ----------
const ticker = [...document.querySelectorAll("[data-rate]")];
function tick() {
  const dt = (now() - t0) / 3600;
  ticker.forEach(el => {
    const w = +el.dataset.wert, r = +el.dataset.rate, max = +el.dataset.max;
    let v = w + r * dt;
    if (max && r > 0) v = w >= max ? w : Math.min(max, v);
    el.textContent = fmt(Math.max(0, v));
  });
}
// ---------- Countdowns + Fortschrittsbalken; bei Fertigstellung neu laden ----------
let reloading = false;
function countdowns() {
  document.querySelectorAll(".countdown[data-ende]").forEach(el => {
    const s = Math.max(0, Math.round(+el.dataset.ende - now()));
    const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
    el.textContent = (d ? d + " T " : "") + `${h}:${String(m).padStart(2, "0")}:${String(sec).padStart(2, "0")}`;
    if (s === 0 && !reloading) { reloading = true; setTimeout(() => location.reload(), 1200); }
  });
  document.querySelectorAll(".fortschritt[data-ende]").forEach(el => {
    const a = +el.dataset.start, e = +el.dataset.ende;
    el.firstElementChild.style.width = Math.max(0, Math.min(100, (now() - a) / Math.max(1, e - a) * 100)) + "%";
  });
}
if (ticker.length || document.querySelector("[data-ende]")) { tick(); countdowns(); setInterval(() => { tick(); countdowns(); }, 1000); }

// =====================================================================
// Isometrische Bereichsansicht
// =====================================================================
const BAUPLAN = ["hofstelle", "holzplatz", "acker", "windrad", "ziegelei", "scheune", "wohnhaus", "gasthof", "markt", "dorfschule", "werkstatt", "schuetzenhalle"];
// Diese Gebäude breiten sich mit steigender Stufe über weitere Parzellen aus
const AUSBREITEN = { holzplatz: 4, acker: 4, windrad: 3, wohnhaus: 3 };
const SLOTS_MAX = 32;

function pip(x, y, rings) { let c = false; for (const r of rings) for (let i = 0, j = r.length - 2; i < r.length; j = i, i += 2) { const xi = r[i], yi = r[i + 1], xj = r[j], yj = r[j + 1]; if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) c = !c; } return c; }
function segDist(px, py, c) { let m = 1e9; for (let i = 0; i + 3 < c.length; i += 2) { const ax = c[i], ay = c[i + 1], dx = c[i + 2] - ax, dy = c[i + 3] - ay; const t = Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy || 1))); m = Math.min(m, Math.hypot(px - ax - t * dx, py - ay - t * dy)); } return m; }

function parzellen(D, b, n) {
  const G = 34, poly = b.poly, rnd = rng(hash(b.id));
  const xs = poly.flatMap(p => p[0].filter((_, i) => i % 2 === 0)), ys = poly.flatMap(p => p[0].filter((_, i) => i % 2 === 1));
  const water = D.areas.filter(a => a[0] === "water").map(a => a[1]);
  const cand = [];
  for (let x = Math.min(...xs); x < Math.max(...xs); x += G) for (let y = Math.min(...ys); y < Math.max(...ys); y += G) {
    const px = x + G / 2, py = y + G / 2;
    if (!poly.some(p => pip(px, py, p)) || water.some(w => pip(px, py, w))) continue;
    if (D.roads.some(r => segDist(px, py, r[2]) < 15)) continue;
    cand.push({ x: px, y: py, d: Math.hypot(px - b.lx, py - b.ly) + rnd() * G * 0.8 });
  }
  return cand.sort((a, c) => a.d - c.d).slice(0, n);
}

// Feste Parzelle je Gebäude(-Teil), damit sich beim Ausbau nichts verschiebt
function belegung(stufen) {
  const out = [];
  BAUPLAN.forEach((typ, i) => { const L = stufen[typ] || 0; if (L) out.push({ typ, lvl: L, slot: i }); });
  const spread = Object.keys(AUSBREITEN);
  for (let k = 1; k < 6; k++) spread.forEach((typ, j) => {
    const L = stufen[typ] || 0, n = Math.min(6, 1 + Math.floor((L - 1) / AUSBREITEN[typ]));
    if (L && k < n) out.push({ typ, lvl: Math.max(1, L - k * AUSBREITEN[typ]), slot: BAUPLAN.length + (k - 1) * spread.length + j });
  });
  return out;
}

async function isoAnsicht(cv) {
  const D = await getJSON(`data/ort/${cv.dataset.ort}.json`);
  const b = D.bereiche[+cv.dataset.nr - 1];
  const stufen = JSON.parse(cv.dataset.stufen || "{}");
  const baut = cv.dataset.baut;
  const plots = parzellen(D, b, SLOTS_MAX);
  const teile = belegung(stufen).filter(t => t.slot < plots.length);
  const ctx = cv.getContext("2d"), st = cv.parentElement;
  const C = Math.cos(Math.PI / 6), Sn = 0.5, ox = b.lx, oy = b.ly;
  // Ausschnitt: alle Parzellen bis zur jeweils höchsten belegten plus Rand
  const sichtbar = plots.slice(0, Math.max(10, ...teile.map(t => t.slot + 1)));
  let minX = 1e9, maxX = -1e9, minY = 1e9, maxY = -1e9;
  const M = /wallhecke|hochsitz/.test(cv.dataset.einheiten || "") || stufen.graben ? 66 : 50;  // Platz für Hecken und Wachtürme
  sichtbar.forEach(p => [[-M, -M], [M, -M], [M, M], [-M, M]].forEach(([dx, dy]) => {
    const u = p.x + dx - ox, v = p.y + dy - oy, X = (u - v) * C, Y = (u + v) * Sn;
    minX = Math.min(minX, X); maxX = Math.max(maxX, X); minY = Math.min(minY, Y); maxY = Math.max(maxY, Y);
  }));
  const rnd = rng(hash(b.id + "t"));
  const forests = D.areas.filter(a => a[0] === "forest" || a[0] === "wood").map(a => a[1]);
  const mixWald = b.mix ? b.mix.wald : 0.1;
  const trees = [], span = Math.max(maxX - minX, maxY - minY) * 1.2;
  for (let i = 0; i < 900 && trees.length < 160; i++) {
    const x = b.lx + (rnd() - .5) * span, y = b.ly + (rnd() - .5) * span;
    if (!b.poly.some(p => pip(x, y, p))) continue;
    if (!forests.some(f => pip(x, y, f)) && rnd() > 0.08 + mixWald * 0.4) continue;
    if (plots.some(p => Math.abs(p.x - x) < 22 && Math.abs(p.y - y) < 22)) continue;
    if (D.roads.some(r => segDist(x, y, r[2]) < 9)) continue;
    trees.push({ x, y, s: 0.8 + rnd() * 0.6 });
  }
  let W = 0, H = 0, s = 1, X0 = 0, Y0 = 0, raf = 0; const start = performance.now();
  const P = (x, y, h = 0) => { const u = x - ox, v = y - oy; return [X0 + (u - v) * C * s, Y0 + (u + v) * Sn * s - h * s]; };
  function size() {
    const dpr = Math.min(2, devicePixelRatio || 1); W = st.clientWidth; H = st.clientHeight;
    cv.width = W * dpr; cv.height = H * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    s = Math.min((W - 40) / (maxX - minX), (H - 60) / (maxY - minY + 30));
    X0 = W / 2 - (minX + maxX) / 2 * s; Y0 = H / 2 - (minY + maxY) / 2 * s + 12 * s;
  }
  const quad = (pts, fill) => { ctx.beginPath(); pts.forEach(([x, y], i) => i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)); ctx.closePath(); ctx.fillStyle = fill; ctx.fill(); };
  function box(x, y, w, d, h, top, left, right, z = 0) {
    const A = P(x, y, z + h), B = P(x + w, y, z + h), Cc = P(x + w, y + d, z + h), Dd = P(x, y + d, z + h);
    quad([Dd, Cc, P(x + w, y + d, z), P(x, y + d, z)], left); quad([B, Cc, P(x + w, y + d, z), P(x + w, y, z)], right); quad([A, B, Cc, Dd], top);
  }
  function gable(x, y, w, d, h, rh, c1, c2, gc) {
    const r0 = P(x, y + d / 2, h + rh), r1 = P(x + w, y + d / 2, h + rh);
    quad([P(x, y, h), P(x + w, y, h), r1, r0], c2); quad([P(x + w, y, h), P(x + w, y + d, h), r1], gc); quad([P(x, y + d, h), P(x + w, y + d, h), r1, r0], c1);
  }
  function house(x, y, w, d, h, lvl, opt = {}) {
    const klinker = lvl >= 4 || opt.klinker;
    const wall = klinker ? css("--brick") : css("--plaster"), wall2 = klinker ? css("--brick-dark") : shade(css("--plaster"), -.12);
    box(x, y, w, d, h, wall, wall, wall2);
    if (!klinker) {
      ctx.strokeStyle = css("--beam"); ctx.lineWidth = Math.max(.6, s * .5);
      for (let k = 0; k <= 3; k++) { ctx.beginPath(); ctx.moveTo(...P(x + w * k / 3, y + d, 0)); ctx.lineTo(...P(x + w * k / 3, y + d, h)); ctx.stroke(); }
      ctx.beginPath(); ctx.moveTo(...P(x, y + d, h * .55)); ctx.lineTo(...P(x + w, y + d, h * .55)); ctx.stroke();
    }
    ctx.fillStyle = shade(css("--water"), -.25);
    const nw = Math.max(1, Math.floor(w / 5));
    for (let k = 0; k < nw; k++) { const p = P(x + (k + .5) * w / nw, y + d, h * .45); ctx.fillRect(p[0] - s * .9, p[1] - s * 1.6, s * 1.8, s * 1.6); }
    const roof = opt.roof || (klinker ? css("--roof-dark") : css("--roof"));
    gable(x, y, w, d, h, opt.rh ?? Math.min(w, d) * .55, roof, shade(roof, -.18), wall2);
  }
  function tree(x, y, k) {
    const base = P(x, y), top = P(x, y, 7 * k);
    ctx.strokeStyle = css("--beam"); ctx.lineWidth = Math.max(1, s * .7); ctx.beginPath(); ctx.moveTo(...base); ctx.lineTo(top[0], top[1] + 2 * s); ctx.stroke();
    ctx.fillStyle = css("--tree-dark"); ctx.beginPath(); ctx.ellipse(top[0], top[1], 4.4 * k * s, 4.8 * k * s, 0, 0, 7); ctx.fill();
    ctx.fillStyle = css("--tree"); ctx.beginPath(); ctx.ellipse(top[0] - k * s, top[1] - k * s, 3.2 * k * s, 3.4 * k * s, 0, 0, 7); ctx.fill();
  }
  function field(x, y, sz, lvl) {
    quad([P(x, y), P(x + sz, y), P(x + sz, y + sz), P(x, y + sz)], css("--field"));
    ctx.strokeStyle = css("--field-row"); ctx.lineWidth = Math.max(.6, s * .5);
    const rows = 4 + Math.min(lvl, 8);
    for (let k = 1; k < rows; k++) { ctx.beginPath(); ctx.moveTo(...P(x + sz * k / rows, y)); ctx.lineTo(...P(x + sz * k / rows, y + sz)); ctx.stroke(); }
  }
  function geruest(cx, cy) {
    ctx.strokeStyle = css("--warn") || "#b45309"; ctx.lineWidth = Math.max(1, s * .6); ctx.setLineDash([3, 2]);
    const h = 10, w = 10;
    [[-w, -w], [w, -w], [w, w], [-w, w]].forEach(([dx, dy]) => { ctx.beginPath(); ctx.moveTo(...P(cx + dx, cy + dy)); ctx.lineTo(...P(cx + dx, cy + dy, h)); ctx.stroke(); });
    ctx.beginPath(); [[-w, -w], [w, -w], [w, w], [-w, w], [-w, -w]].forEach(([dx, dy], i) => i ? ctx.lineTo(...P(cx + dx, cy + dy, h)) : ctx.moveTo(...P(cx + dx, cy + dy, h))); ctx.stroke();
    ctx.setLineDash([]);
  }
  function gebaeude(t, cx, cy, sec) {
    const L = Math.min(t.lvl, 12), g = 1 + L * 0.07;
    switch (t.typ) {
      case "hofstelle": {
        const w = 13 * g, d = 9 * g, h = 5 + L * .6;
        house(cx - w / 2, cy - d / 2, w, d, h, L, { rh: d * .7 });
        if (L >= 5) house(cx - w / 2 - 7, cy + d / 2 - 2, 7, 10, h * .8, L);
        if (L >= 8) box(cx + w / 2 - 3, cy - d / 2 - 3, 4, 4, h + 9, css("--roof-dark"), css("--brick"), css("--brick-dark"));
        break;
      }
      case "holzplatz":
        for (let k = 0; k < Math.min(6, 1 + Math.ceil(L / 2)); k++) box(cx - 9 + (k % 3) * 5.5, cy + 2 + Math.floor(k / 3) * 4.5, 5, 3.6, 2.4 + (k % 2), shade(css("--beam"), .55), shade(css("--beam"), .3), shade(css("--beam"), .15));
        house(cx - 6, cy - 9, 9, 7, 3.5, 1, { roof: css("--roof-dark") });
        break;
      case "ziegelei": {
        house(cx - 9, cy - 6, 14 * g, 9, 4 + L * .3, 5);
        const ch = 12 + L * 1.6; box(cx + 6, cy - 3, 3, 3, ch, css("--brick-dark"), css("--brick"), css("--brick-dark"));
        if (!reduceMotion) { const top = P(cx + 7.5, cy - 1.5, ch); for (let k = 0; k < 3; k++) { const ph = (sec * .5 + k / 3) % 1; ctx.fillStyle = `rgba(160,160,160,${.45 * (1 - ph)})`; ctx.beginPath(); ctx.arc(top[0] + ph * 10 * s, top[1] - ph * 16 * s, (2 + ph * 4) * s, 0, 7); ctx.fill(); } }
        break;
      }
      case "acker":
        field(cx - 15, cy - 15, 30, L);
        if (L >= 3) house(cx - 14, cy - 14, 8, 6, 3.2, L, { roof: css("--roof") });
        if (L >= 6) box(cx + 8, cy - 14, 4.5, 4.5, 11, css("--asphalt"), shade(css("--asphalt"), .25), shade(css("--asphalt"), -.1));
        break;
      case "windrad": {
        const hh = 24 + L * 3, base = P(cx, cy), hub = P(cx, cy, hh);
        ctx.strokeStyle = shade(css("--panel"), -.05); ctx.lineWidth = Math.max(1.5, 1.6 * s); ctx.beginPath(); ctx.moveTo(...base); ctx.lineTo(...hub); ctx.stroke();
        ctx.strokeStyle = css("--ink-soft"); ctx.lineWidth = .6; ctx.stroke();
        const a0 = reduceMotion ? .3 : sec * 1.4 + cx;
        ctx.fillStyle = shade(css("--panel"), -.04);
        for (let k = 0; k < 3; k++) { const a = a0 + k * 2.094, len = (9 + L) * s; ctx.beginPath(); ctx.moveTo(...hub); ctx.lineTo(hub[0] + Math.cos(a) * len - Math.sin(a) * 1.2 * s, hub[1] + Math.sin(a) * len * .9 + Math.cos(a) * 1.2 * s); ctx.lineTo(hub[0] + Math.cos(a) * len, hub[1] + Math.sin(a) * len * .9); ctx.closePath(); ctx.fill(); ctx.stroke(); }
        ctx.fillStyle = css("--ink-soft"); ctx.beginPath(); ctx.arc(hub[0], hub[1], 1.4 * s, 0, 7); ctx.fill();
        break;
      }
      case "scheune": house(cx - 9 * g, cy - 6, 18 * g, 12, 5 + L * .4, 1, { roof: css("--roof-dark"), rh: 8 }); break;
      case "wohnhaus": house(cx - 5.5, cy - 4.5, 11, 9, 4.2 + Math.min(L, 6) * .5, L); if (L >= 3) house(cx + 1, cy + 1, 7, 7, 4, L); break;
      case "dorfschule": house(cx - 9, cy - 5, 16, 10, 5.5, Math.max(L, 4)); box(cx + 3, cy - 3, 3, 3, 12, css("--roof-dark"), css("--plaster"), shade(css("--plaster"), -.12)); break;
      case "gasthof": { house(cx - 7, cy - 6, 13, 10, 5, L, { roof: css("--roof") }); const p = P(cx + 7, cy + 4, 6); ctx.fillStyle = css("--field"); ctx.fillRect(p[0], p[1], 4 * s, 3 * s); break; }
      case "werkstatt": { box(cx - 9, cy - 7, 18, 13, 5 + L * .3, css("--asphalt"), shade(css("--asphalt"), .15), shade(css("--asphalt"), -.15)); const p = P(cx - 3, cy + 6, 0); ctx.fillStyle = css("--beam"); ctx.fillRect(p[0], p[1] - 4 * s, 6 * s, 4 * s); box(cx + 4, cy + 8, 4, 2.5, 2.4, css("--ok"), css("--ok"), shade(css("--ok"), -.2)); break; }
      case "schuetzenhalle": { house(cx - 11, cy - 5, 20, 10, 5, Math.max(L, 4), { roof: css("--roof-dark") }); const b0 = P(cx + 11, cy - 5), b1 = P(cx + 11, cy - 5, 20); ctx.strokeStyle = css("--ink"); ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(...b0); ctx.lineTo(...b1); ctx.stroke(); const wv = reduceMotion ? 0 : Math.sin(sec * 3) * 1.5; ctx.fillStyle = css("--ok"); ctx.beginPath(); ctx.moveTo(...b1); ctx.lineTo(b1[0] + 8 * s, b1[1] + 2 * s + wv); ctx.lineTo(b1[0], b1[1] + 5 * s); ctx.fill(); break; }
      case "markt": { const cols = ["--bad", "--warn", "--accent", "--ok"]; for (let k = 0; k < Math.min(4, 1 + L); k++) { const xx = cx - 10 + (k % 2) * 11, yy = cy - 8 + Math.floor(k / 2) * 10; box(xx, yy, 7, 6, 2.5, css("--plaster"), css("--plaster"), shade(css("--plaster"), -.1)); gable(xx, yy, 7, 6, 3.5, 2, css(cols[k]), shade(css(cols[k]), -.2), css(cols[k])); } break; }
    }
  }
  const linie = (p, w, col) => { ctx.beginPath(); for (let i = 0; i < p.length; i += 2) { const q = P(p[i], p[i + 1]); i ? ctx.lineTo(...q) : ctx.moveTo(...q); } ctx.strokeStyle = col; ctx.lineWidth = w * s; ctx.lineCap = "round"; ctx.lineJoin = "round"; ctx.stroke(); };
  const flaeche = (rr, col) => { ctx.beginPath(); rr.forEach(r => { for (let i = 0; i < r.length; i += 2) { const p = P(r[i], r[i + 1]); i ? ctx.lineTo(...p) : ctx.moveTo(...p); } ctx.closePath(); }); ctx.fillStyle = col; ctx.fill("evenodd"); };
  function draw(ts) {
    const sec = (ts - start) / 1000;
    ctx.fillStyle = css("--ground"); ctx.fillRect(0, 0, W, H);
    ctx.save();
    ctx.beginPath(); b.poly.forEach(pp => pp.forEach(r => { for (let i = 0; i < r.length; i += 2) { const p = P(r[i], r[i + 1]); i ? ctx.lineTo(...p) : ctx.moveTo(...p); } ctx.closePath(); }));
    ctx.fillStyle = css("--grass"); ctx.fill("evenodd"); ctx.strokeStyle = css("--grass-edge"); ctx.lineWidth = 2; ctx.stroke(); ctx.clip("evenodd");
    D.areas.forEach(([k, rr]) => { if (k === "forest" || k === "wood") flaeche(rr, shade(css("--grass"), -.12)); if (k === "water" || k === "wetland") flaeche(rr, css("--water")); });
    D.water.forEach(([c, , p]) => linie(p, c === 1 ? 9 : 3.5, css("--waterline")));
    const RW = { 1: 20, 2: 11, 3: 9, 4: 8, 5: 6 };
    D.roads.forEach(([c, , p]) => linie(p, RW[c], css("--asphalt")));
    // Schutzwall um die Hofanlage, Breite wächst mit der Stufe
    if (graben && ring) {
      const r = rechteck(ring, 14);
      ctx.beginPath(); r.forEach(([x, y], i) => i ? ctx.lineTo(...P(x, y)) : ctx.moveTo(...P(x, y))); ctx.closePath();
      ctx.strokeStyle = css("--waterline"); ctx.lineWidth = (4 + graben * 1.5) * s; ctx.lineJoin = "round"; ctx.stroke();
      ctx.strokeStyle = css("--water"); ctx.lineWidth = (2 + graben * 1.2) * s; ctx.stroke();
      const br = [(r[2][0] + r[3][0]) / 2, r[2][1]];   // Brücke auf der Vorderseite
      ctx.strokeStyle = css("--beam"); ctx.lineWidth = 5 * s; ctx.lineCap = "butt";
      ctx.beginPath(); ctx.moveTo(...P(br[0], br[1] - 5 - graben)); ctx.lineTo(...P(br[0], br[1] + 5 + graben)); ctx.stroke();
    }
    // Pättkes zwischen den Gebäuden
    ctx.strokeStyle = css("--path"); ctx.lineWidth = 4 * s; ctx.lineCap = "round";
    const gebaut = teile.map(t => plots[t.slot]);
    gebaut.forEach((p, i) => { if (!i) return; let best = gebaut[0], bd = 1e9; for (let j = 0; j < i; j++) { const q = gebaut[j], dd = Math.hypot(p.x - q.x, p.y - q.y); if (dd < bd) { bd = dd; best = q; } } ctx.beginPath(); ctx.moveTo(...P(p.x, p.y)); ctx.lineTo(...P(p.x, best.y)); ctx.lineTo(...P(best.x, best.y)); ctx.stroke(); });
    // freie Parzellen
    ctx.setLineDash([3, 3]); ctx.strokeStyle = css("--grass-edge"); ctx.lineWidth = 1;
    sichtbar.forEach((p, i) => { if (teile.some(t => t.slot === i)) return; ctx.beginPath(); [[-13, -13], [13, -13], [13, 13], [-13, 13]].forEach(([dx, dy], k) => k ? ctx.lineTo(...P(p.x + dx, p.y + dy)) : ctx.moveTo(...P(p.x + dx, p.y + dy))); ctx.closePath(); ctx.stroke(); });
    ctx.setLineDash([]);
    const items = trees.map(tr => ({ d: tr.x + tr.y, f: () => tree(tr.x, tr.y, tr.s) }));
    teile.forEach(t => { const p = plots[t.slot]; items.push({ d: p.x + p.y, f: () => gebaeude(t, p.x, p.y, sec) }); });
    if (baut) { const slot = BAUPLAN.indexOf(baut), p = plots[slot]; if (p) items.push({ d: p.x + p.y + .1, f: () => geruest(p.x, p.y) }); }
    verteidigung.forEach(v => items.push(v));
    items.sort((a, c) => a.d - c.d).forEach(it => it.f());
    ctx.restore();
    if (!reduceMotion) raf = requestAnimationFrame(draw);
  }
  // ---- Verteidigung: Schutzwall, Barrikaden und Wachtürme rund um die bebauten Parzellen ----
  const einheiten = JSON.parse(cv.dataset.einheiten || "{}");
  const graben = stufen.graben || 0;
  const bebaut = teile.map(t => plots[t.slot]);
  const ring = bebaut.length ? {
    x0: Math.min(...bebaut.map(p => p.x)) - 20, x1: Math.max(...bebaut.map(p => p.x)) + 20,
    y0: Math.min(...bebaut.map(p => p.y)) - 20, y1: Math.max(...bebaut.map(p => p.y)) + 20,
  } : null;
  function rechteck(r, pad) { return [[r.x0 - pad, r.y0 - pad], [r.x1 + pad, r.y0 - pad], [r.x1 + pad, r.y1 + pad], [r.x0 - pad, r.y1 + pad]]; }
  // Punkt auf dem Umfang eines Rechtecks, t von 0 bis 1
  function umfang(ecken, t) {
    const seg = ecken.map((a, i) => { const b = ecken[(i + 1) % 4]; return [a, b, Math.hypot(b[0] - a[0], b[1] - a[1])]; });
    let rest = t * seg.reduce((s, x) => s + x[2], 0);
    for (const [a, b, l] of seg) { if (rest <= l) { const f = rest / l; return [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f]; } rest -= l; }
    return ecken[0];
  }
  function heckenstueck(x, y) {
    for (let k = -1; k <= 1; k++) {
      const p = P(x + k * 3, y - k * 3, 2.5);
      ctx.fillStyle = css("--tree-dark"); ctx.beginPath(); ctx.ellipse(p[0], p[1], 3.2 * s, 2.6 * s, 0, 0, 7); ctx.fill();
      ctx.fillStyle = css("--tree"); ctx.beginPath(); ctx.ellipse(p[0] - .8 * s, p[1] - .8 * s, 2 * s, 1.6 * s, 0, 0, 7); ctx.fill();
    }
  }
  function hochsitz(x, y) {
    ctx.strokeStyle = css("--beam"); ctx.lineWidth = Math.max(1, s * .6);
    [[-2, -2], [2, -2], [2, 2], [-2, 2]].forEach(([dx, dy]) => { ctx.beginPath(); ctx.moveTo(...P(x + dx, y + dy)); ctx.lineTo(...P(x + dx * .6, y + dy * .6, 11)); ctx.stroke(); });
    box(x - 2.4, y - 2.4, 4.8, 4.8, 3, shade(css("--beam"), .35), shade(css("--beam"), .2), css("--beam"), 11);
    gable(x - 2.6, y - 2.6, 5.2, 5.2, 14, 2, css("--roof-dark"), shade(css("--roof-dark"), -.2), css("--beam"));
  }
  const verteidigung = [];
  if (ring) {
    const hecken = Math.min(80, einheiten.wallhecke || 0), sitze = Math.min(12, einheiten.hochsitz || 0);
    const heckenRing = rechteck(ring, 28);
    const lang = 2 * ((ring.x1 - ring.x0) + (ring.y1 - ring.y0) + 112);
    // jede Barrikade ist ein Stück von ca. 10 m; bei vielen schließt sich der Ring
    const stuecke = Math.min(Math.floor(lang / 10), hecken);
    for (let i = 0; i < stuecke; i++) { const [x, y] = umfang(heckenRing, (i + .5) / Math.max(stuecke, Math.floor(lang / 10))); verteidigung.push({ d: x + y, f: () => heckenstueck(x, y) }); }
    for (let i = 0; i < sitze; i++) { const [x, y] = umfang(rechteck(ring, 36), i / sitze); verteidigung.push({ d: x + y, f: () => hochsitz(x, y) }); }
  }

  size(); draw(performance.now());
  new ResizeObserver(() => { size(); if (reduceMotion) draw(performance.now()); }).observe(st);
}
const iso = $("#iso");
if (iso) isoAnsicht(iso).catch(e => { iso.parentElement.insertAdjacentHTML("beforeend", `<div class="laden">Ansicht konnte nicht geladen werden (${esc(e.message)}).</div>`); });

// =====================================================================
// Karte: Region → Ort, mit echten Besitzern
// =====================================================================
function el(tag, attrs = {}, parent) { const e = document.createElementNS(NS, tag); for (const k in attrs) e.setAttribute(k, attrs[k]); if (parent) parent.appendChild(e); return e; }
function dLine(c) { let d = "M" + c[0] + " " + c[1]; for (let i = 2; i < c.length; i += 2) d += "L" + c[i] + " " + c[i + 1]; return d; }
function dPoly(rings) { return rings.map(r => dLine(r) + "Z").join(""); }
function panZoom(svg, vb, onZoom) {
  const set = () => { svg.setAttribute("viewBox", `${vb.x} ${vb.y} ${vb.w} ${vb.h}`); onZoom && onZoom(vb.w / Math.max(1, svg.clientWidth || 800)); };
  const fit = () => { if (!svg.clientWidth || !svg.clientHeight) return; const r = svg.clientWidth / svg.clientHeight, cx = vb.x + vb.w / 2, cy = vb.y + vb.h / 2; if (vb.w / vb.h < r) vb.w = vb.h * r; else vb.h = vb.w / r; vb.x = cx - vb.w / 2; vb.y = cy - vb.h / 2; set(); };
  const zoomAt = (f, px, py) => { const rect = svg.getBoundingClientRect(), mx = vb.x + (px - rect.left) / rect.width * vb.w, my = vb.y + (py - rect.top) / rect.height * vb.h; vb.w *= f; vb.h *= f; vb.x = mx - (mx - vb.x) * f; vb.y = my - (my - vb.y) * f; set(); };
  let drag = null, moved = false;
  svg.addEventListener("pointerdown", e => { drag = { x: e.clientX, y: e.clientY, vx: vb.x, vy: vb.y }; moved = false; });
  svg.addEventListener("pointermove", e => { if (!drag) return; const dx = e.clientX - drag.x, dy = e.clientY - drag.y; if (!moved && Math.hypot(dx, dy) < 5) return; if (!moved) { moved = true; svg.setPointerCapture(e.pointerId); } const k = vb.w / svg.clientWidth; vb.x = drag.vx - dx * k; vb.y = drag.vy - dy * k; set(); });
  const end = () => { drag = null; };
  svg.addEventListener("pointerup", end); svg.addEventListener("pointercancel", end);
  svg.addEventListener("click", e => { if (moved) { e.stopPropagation(); e.preventDefault(); } }, true);
  svg.addEventListener("wheel", e => { e.preventDefault(); zoomAt(e.deltaY > 0 ? 1.18 : 1 / 1.18, e.clientX, e.clientY); }, { passive: false });
  new ResizeObserver(fit).observe(svg);
  const mid = f => { const r = svg.getBoundingClientRect(); zoomAt(f, r.left + r.width / 2, r.top + r.height / 2); };
  return { zoomIn: () => mid(1 / 1.4), zoomOut: () => mid(1.4) };
}
function zoomKnoepfe(st, pz) { const z = document.createElement("div"); z.className = "zoom"; z.innerHTML = `<button type="button" aria-label="Hineinzoomen">+</button><button type="button" aria-label="Herauszoomen">−</button>`; z.children[0].onclick = pz.zoomIn; z.children[1].onclick = pz.zoomOut; st.appendChild(z); }
const AREA_FILL = { residential: "--settle", farmyard: "--settle", commercial: "--industry", industrial: "--industry", retail: "--industry", forest: "--forest", wood: "--forest", water: "--water", wetland: "--water" };
const AREA_ORDER = ["residential", "farmyard", "commercial", "industrial", "retail", "meadow", "grass", "park", "village_green", "recreation_ground", "allotments", "orchard", "golf_course", "cemetery", "grave_yard", "pitch", "sports_centre", "stadium", "forest", "wood", "wetland", "water"];

// Allianz-Kürzel mit Wappen beim Überfahren, Online-Lampe
const kuerzelTip = bes => bes && bes.gen ? ` <a class="kuerzel kuerzel-tip" href="?s=allianz&amp;id=${bes.gid}">[${esc(bes.gen)}]<span class="tip" role="tooltip">` +
  (bes.wappen ? `<img class="wappen tipbild" src="uploads/wappen/${esc(bes.wappen)}" alt="">` : `<span class="wappen tipbild wappen-leer">${esc(bes.gen.slice(0, 3))}</span>`) +
  `<b>${esc(bes.gname || "")}</b></span></a>` : "";
const lampe = an => `<i class="lampe ${an ? "an" : "aus"}" title="${an ? "online" : "offline"}"></i>`;

// Passende Aufträge für einen Bereich auf der Karte
function aktionen(b, bes) {
  const id = encodeURIComponent(b.id);
  const zug = (auftrag, text, haupt) => `<a class="knopf${haupt ? "" : " leicht"}" href="?s=zuege&amp;ziel=${id}&amp;auftrag=${auftrag}">${text}</a>`;
  if (bes && bes.eigen) return `<a class="knopf" href="?s=uebersicht&amp;b=${id}">Zum Bereich</a>` + zug("verlegen", "Verlegen") + zug("transport", "Transport");
  if (bes && bes.verbuendet) return zug("transport", "Transport", true);
  if (bes) return zug("erkunden", "Erkunden", true) + zug("angriff", "Raubzug") + zug("transport", "Transport");
  return zug("kolonisieren", "Besiedeln", true) + zug("erkunden", "Erkunden");
}

async function karte(root) {
  const flaeche = $("#kartenflaeche"), panel = $("#kartenpanel"), heimat = root.dataset.heimat;
  const [R, besitz] = await Promise.all([getJSON("data/region.json"), getJSON("?api=besitz")]);
  const ORT = {};
  const besitzer = id => besitz[id];
  const zeigeRegion = () => {
    flaeche.innerHTML = ""; const Q = R.q;
    const svg = el("svg", { role: "img", "aria-label": "Regionskarte Herbinum" }, flaeche);
    const xs = R.orte.map(o => o.x), ys = R.orte.map(o => o.y);
    const vb = { x: Math.min(...xs) - 260, y: Math.min(...ys) - 220, w: Math.max(...xs) - Math.min(...xs) + 520, h: Math.max(...ys) - Math.min(...ys) + 440 };
    el("rect", { x: -10000, y: -10000, width: 20000, height: 20000, fill: "var(--ground)" }, svg);
    const g = el("g", {}, svg);
    const poly = (list, fill) => list.forEach(rr => el("path", { d: dPoly(rr), fill, "fill-rule": "evenodd" }, g));
    poly(R.forest, "var(--forest)"); poly(R.settle, "var(--settle)"); poly(R.water, "var(--water)");
    R.rivers.forEach(([c, , p]) => el("path", { d: dLine(p), fill: "none", stroke: "var(--waterline)", "stroke-width": c === 1 ? 2.4 : 1.1, "vector-effect": "non-scaling-stroke" }, g));
    const W = { 1: 4.2, 2: 3, 3: 2.2, 4: 1.2 };
    for (const pass of ["case", "fill"]) for (const cls of [4, 3, 2, 1]) R.roads.filter(r => r[0] === cls).forEach(([c, , p]) => el("path", { d: dLine(p), fill: "none", stroke: pass === "case" ? (c === 4 ? "var(--roadcase)" : "var(--majorcase)") : (c === 1 ? "var(--motorway)" : c <= 3 ? "var(--major)" : "var(--road)"), "stroke-width": W[c] + (pass === "case" ? 1.4 : 0), "vector-effect": "non-scaling-stroke", "stroke-linecap": "round" }, g));
    R.rail.forEach(p => el("path", { d: dLine(p), fill: "none", stroke: "var(--rail)", "stroke-width": 2, "stroke-dasharray": "6 4", "vector-effect": "non-scaling-stroke" }, g));
    const labels = el("g", {});
    R.orte.forEach(o => {
      const gg = el("g", { tabindex: 0, role: "button", "aria-label": `${o.name} öffnen`, style: "cursor:pointer" }, svg);
      const eigen = heimat.startsWith(o.id + "-");
      el("circle", { cx: o.x, cy: o.y, r: o.r / Q, fill: "var(--accent)", "fill-opacity": eigen ? .2 : .06, stroke: "var(--accent)", "stroke-width": eigen ? 2.4 : 1.2, "stroke-dasharray": "4 3", "vector-effect": "non-scaling-stroke" }, gg);
      const rr = o.r / Q * 0.42;
      for (let i = 0; i < o.n; i++) {
        const bes = besitzer(`${o.id}-${i + 1}`), a0 = -Math.PI / 2 + i / o.n * 2 * Math.PI, a1 = a0 + 2 * Math.PI / o.n * .82;
        el("path", { d: `M${o.x + Math.cos(a0) * rr} ${o.y + Math.sin(a0) * rr}A${rr} ${rr} 0 0 1 ${o.x + Math.cos(a1) * rr} ${o.y + Math.sin(a1) * rr}`, fill: "none", stroke: bes ? bes.farbe : "var(--ink-soft)", "stroke-opacity": bes ? 1 : .3, "stroke-width": 4, "vector-effect": "non-scaling-stroke" }, gg);
      }
      el("circle", { cx: o.x, cy: o.y, r: 3, fill: "var(--ink)" }, gg);
      gg.addEventListener("click", () => zeigeOrt(o.id));
      gg.addEventListener("keydown", e => { if (e.key === "Enter") zeigeOrt(o.id); });
      const t = el("text", { x: o.x, y: o.y - o.r / Q - 8, "text-anchor": "middle", "font-weight": 700, "data-base": o.typ === "Stadt" ? 17 : 14 }, labels); t.textContent = o.name;
    });
    svg.appendChild(labels);
    zoomKnoepfe(flaeche, panZoom(svg, vb, k => labels.querySelectorAll("text").forEach(t => { t.setAttribute("font-size", t.dataset.base * k); t.setAttribute("stroke-width", 3 * k); })));
    const zaehl = o => { let n = 0; for (let i = 1; i <= o.n; i++) if (besitzer(`${o.id}-${i}`)) n++; return n; };
    panel.innerHTML = `<div><div class="eyebrow">Region</div><h1>Herbinum</h1><p class="leise">Tippe einen Ort an, um seine Bereiche zu sehen. Die farbigen Kränze zeigen, wer wo siedelt.</p></div>
      <div class="liste">${[...R.orte].sort((a, b) => a.name.localeCompare(b.name, "de")).map(o => `<button class="zeile" type="button" data-id="${o.id}"><span class="nr">${zaehl(o)}</span><span class="nm">${esc(o.name)}<span class="sub">${o.n} Bereiche</span></span><span class="sub">${heimat.startsWith(o.id + "-") ? "dein Ort" : ""}</span></button>`).join("")}</div>`;
    panel.querySelectorAll(".zeile").forEach(b => b.onclick = () => zeigeOrt(b.dataset.id));
  };
  const zeigeOrt = async (id, sel) => {
    const D = ORT[id] ??= await getJSON(`data/ort/${id}.json`);
    flaeche.innerHTML = ""; const R2 = D.r + 180;
    const svg = el("svg", { role: "img", "aria-label": `Ortsplan ${D.name}` }, flaeche);
    el("rect", { x: -R2 * 3, y: -R2 * 3, width: R2 * 6, height: R2 * 6, fill: "var(--ground)" }, svg);
    const g = el("g", {}, svg), by = {};
    D.areas.forEach(([t, rr]) => (by[t] ??= []).push(rr));
    AREA_ORDER.forEach(t => (by[t] || []).forEach(rr => el("path", { d: dPoly(rr), fill: `var(${AREA_FILL[t] || "--green"})`, "fill-rule": "evenodd" }, g)));
    D.water.forEach(([c, , p]) => el("path", { d: dLine(p), fill: "none", stroke: "var(--waterline)", "stroke-width": c === 1 ? 10 : 3 }, g));
    const RW = { 1: 22, 2: 14, 3: 12, 4: 10, 5: 7 };
    for (const pass of ["case", "fill"]) for (const cls of [5, 4, 3, 2, 1]) D.roads.filter(r => r[0] === cls).forEach(([c, , p]) => el("path", { d: dLine(p), fill: "none", stroke: pass === "case" ? (c <= 3 ? "var(--majorcase)" : "var(--roadcase)") : (c === 1 ? "var(--motorway)" : c <= 3 ? "var(--major)" : "var(--road)"), "stroke-width": RW[c] + (pass === "case" ? 2.5 : 0), "stroke-linecap": "round", "stroke-linejoin": "round" }, g));
    el("path", { d: D.buildings.map(dPoly).join(""), fill: "var(--bld)", "fill-rule": "evenodd" }, g);
    const labels = el("g", {});
    const formen = D.bereiche.map(b => {
      const bes = besitzer(b.id);
      const p = el("path", { d: b.poly.map(dPoly).join(""), "fill-rule": "evenodd", fill: bes ? bes.farbe : "transparent", "fill-opacity": bes ? .28 : 0, stroke: bes ? bes.farbe : "var(--ink)", "stroke-dasharray": bes ? "" : "6 5", "stroke-width": 2, "vector-effect": "non-scaling-stroke", style: "cursor:pointer", tabindex: 0, role: "button", "aria-label": `Bereich ${b.nr}: ${b.name}` }, svg);
      p.addEventListener("click", () => waehle(b.nr));
      p.addEventListener("keydown", e => { if (e.key === "Enter") waehle(b.nr); });
      const t1 = el("text", { x: b.lx, y: b.ly, "text-anchor": "middle", "dominant-baseline": "central", "font-weight": 700, "data-base": 13 }, labels); t1.textContent = b.nr;
      const t2 = el("text", { x: b.lx, y: b.ly + 22, "text-anchor": "middle", "data-base": 12, "data-dy": 22, "data-y0": b.ly }, labels); t2.textContent = b.name;
      return { b, p };
    });
    svg.appendChild(labels);
    zoomKnoepfe(flaeche, panZoom(svg, { x: -R2, y: -R2, w: 2 * R2, h: 2 * R2 }, k => labels.querySelectorAll("text").forEach(t => { t.setAttribute("font-size", t.dataset.base * k); t.setAttribute("stroke-width", 3 * k); if (t.dataset.dy) t.setAttribute("y", +t.dataset.y0 + t.dataset.dy * k); })));
    function waehle(nr) {
      const b = D.bereiche[nr - 1], bes = besitzer(b.id);
      formen.forEach(({ b: x, p }) => p.setAttribute("stroke-width", x.nr === nr ? 4.5 : 2));
      panel.querySelectorAll(".zeile").forEach(z => z.classList.toggle("sel", +z.dataset.nr === nr));
      $("#bereichinfo").innerHTML = `<div class="karte-box"><div class="eyebrow">Bereich ${b.nr} · ${esc(b.typ)} · ${String(b.ha).replace(".", ",")} ha</div><h2>${esc(b.name)}</h2>
        <div>${bes ? `${lampe(bes.online)}<span class="punkt" style="background:${esc(bes.farbe)}"></span> <b>${esc(bes.name)}</b>${kuerzelTip(bes)}${bes.schutz && !bes.eigen ? ` <span class="chip rel-schutz">🛡 bis ${esc(bes.schutz)}</span>` : ""}${bes.eigen ? " (du)" : bes.verbuendet ? " (deine Allianz)" : ""}` : "frei – besiedelbar, sobald es Siedlerkonvois gibt"}</div>
        <div class="werte">${[["Bauplätze", (b.felder - 12) / 28 * 100, b.felder], ["Lebensqualität", b.score.lebensqualitaet], ["Wehrkraft", b.score.wehr], ["Einkommen", b.score.einkommen]].map(([n, v, z]) => `<div class="wert"><span>${n}</span><div class="balken"><i style="width:${Math.max(3, v)}%"></i></div><b>${z ?? v}</b></div>`).join("")}</div>
        <div class="boni">${[["Holz", b.mod.holz], ["Baustoffe", b.mod.ziegel], ["Nahrung", b.mod.korn], ["Strom", b.mod.strom]].map(([n, v]) => `<div><span>${n}</span><b>×${v.toFixed(2).replace(".", ",")}</b></div>`).join("")}</div>
        <div class="aktionen">${aktionen(b, bes)}</div></div>`;
    }
    panel.innerHTML = `<div><button class="link" type="button" id="zurRegion">← Region</button><h1>${esc(D.name)}</h1></div><div id="bereichinfo"></div>
      <div class="liste">${D.bereiche.map(b => { const bes = besitzer(b.id); return `<button class="zeile" type="button" data-nr="${b.nr}"><span class="nr">${b.nr}</span><span class="nm">${esc(b.name)}<span class="sub">${esc(b.typ)} · ${b.felder} Bauplätze</span></span><span class="sub">${bes ? `<span class="punkt" style="background:${esc(bes.farbe)}"></span> ${esc(bes.name)}${bes.gen ? ` [${esc(bes.gen)}]` : ""}` : "frei"}</span></button>`; }).join("")}</div>`;
    $("#zurRegion").onclick = zeigeRegion;
    panel.querySelectorAll(".zeile").forEach(z => z.onclick = () => waehle(+z.dataset.nr));
    waehle(sel || (heimat.startsWith(id + "-") ? +heimat.split("-").pop() : 1));
  };
  zeigeOrt(heimat.replace(/-\d+$/, ""));
}
const k = $("#karte");
if (k) karte(k).catch(e => { $("#kartenflaeche").innerHTML = `<div class="laden">Karte konnte nicht geladen werden (${esc(e.message)}).</div>`; });
})();

// ---------- Züge: Live-Vorschau (gleiche Formeln wie rules.php) ----------
(() => {
  const f = document.getElementById("zugform");
  if (!f) return;
  const start = JSON.parse(f.dataset.start), koord = JSON.parse(f.dataset.koord), E = JSON.parse(f.dataset.einheiten);
  const tempo = +f.dataset.tempo, umweg = +f.dataset.umweg, minKm = +f.dataset.min, faktor = +f.dataset.faktor || 1;
  const out = document.getElementById("vorschau");
  const fmt = n => Math.floor(n).toLocaleString("de-DE");
  const dauer = s => { s = Math.max(10, Math.round(s)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return h ? `${h} h ${m} min` : `${m} min ${s % 60} s`; };
  function rechne() {
    const ziel = f.ziel.value, auftrag = (f.querySelector("[name=auftrag]:checked") || {}).value;
    const n = {}; f.querySelectorAll("[data-einheit]").forEach(i => { const v = Math.max(0, +i.value || 0); if (v) n[i.dataset.einheit] = v; });
    const typen = Object.keys(n);
    if (!ziel || !typen.length) { out.textContent = "Ziel und Einheiten wählen."; return; }
    const [zx, zy] = koord[ziel];
    const km = Math.max(minKm, Math.hypot(zx - start[0], zy - start[1]) / 1000 * umweg);
    const kmh = Math.min(...typen.map(t => E[t].kmh));
    const sek = km / kmh * 3600 / tempo * faktor;
    const einfach = auftrag === "verlegen" || auftrag === "kolonisieren";
    const korn = Math.ceil(typen.reduce((s, t) => s + E[t].korn_km * n[t] * km, 0) * (einfach ? 1 : 2));
    const kap = typen.reduce((s, t) => s + E[t].ladung * n[t], 0);
    let last = 0; f.querySelectorAll("[data-ladung]").forEach(i => last += Math.max(0, +i.value || 0));
    out.innerHTML = `Strecke ${km.toFixed(1).replace(".", ",")} km · ${fmt(kmh)} km/h<br>Hinfahrt ${dauer(sek)}${einfach ? "" : ` · zurück nach ${dauer(sek * 2)}`}<br>` +
      `Verbrauch ${fmt(korn)} Nahrung · Ladung <span class="${last > kap ? "warn" : ""}">${fmt(last)} / ${fmt(kap)}</span>` +
      (faktor > 1.001 ? `<br><span class="warn">Unwetter am Start: Die Fahrt dauert ${Math.round((faktor - 1) * 100)} % länger.</span>` : "");
  }
  f.addEventListener("input", rechne); f.addEventListener("change", rechne); rechne();
})();

// ---------- Hintergrundmusik: lückenloser Loop über Web Audio ----------
(() => {
  const knopf = document.getElementById("musik");
  if (!knopf) return;
  const regler = document.getElementById("musik-vol");
  const lies = (k, d) => { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } };
  const schreib = (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} };
  const posKey = "hb-musik-pos-" + knopf.dataset.id;
  let an = lies("hb-musik", "aus") === "an";
  let lautstaerke = +lies("hb-musik-vol", +knopf.dataset.vol / 100);
  let ctx = null, gain = null, puffer = null, quelle = null, startZeit = 0, laden = null;

  function vorbereiten() {
    laden ??= (async () => {
      const AC = window.AudioContext || window.webkitAudioContext;
      ctx = new AC();
      gain = ctx.createGain();
      gain.gain.value = lautstaerke;
      gain.connect(ctx.destination);
      const antwort = await fetch(knopf.dataset.url);
      if (!antwort.ok) throw new Error("Datei nicht gefunden");
      puffer = await ctx.decodeAudioData(await antwort.arrayBuffer());
    })();
    return laden;
  }
  async function starten() {
    await vorbereiten();
    if (ctx.state !== "running") await ctx.resume();
    if (quelle || !an) return;
    quelle = ctx.createBufferSource();
    quelle.buffer = puffer;
    quelle.loop = true;
    quelle.connect(gain);
    // dort weitermachen, wo die vorige Seite aufgehört hat
    let pos = 0;
    try { pos = (+sessionStorage.getItem(posKey) || 0) % puffer.duration; } catch (e) {}
    quelle.start(0, pos);
    startZeit = ctx.currentTime - pos;
  }
  function stoppen() {
    if (!quelle) return;
    quelle.stop();
    quelle.disconnect();
    quelle = null;
  }
  function zeigen() {
    knopf.setAttribute("aria-pressed", String(an));
    knopf.textContent = an ? "♪ Musik an" : "♪ Musik aus";
    regler.hidden = !an;
  }
  knopf.addEventListener("click", async () => {
    an = !an;
    schreib("hb-musik", an ? "an" : "aus");
    zeigen();
    if (!an) return stoppen();
    try { await starten(); } catch (e) { knopf.textContent = "♪ nicht abspielbar"; }
  });
  regler.value = Math.round(lautstaerke * 100);
  regler.addEventListener("input", () => {
    lautstaerke = regler.value / 100;
    schreib("hb-musik-vol", String(lautstaerke));
    if (gain) gain.gain.value = lautstaerke;
  });
  addEventListener("pagehide", () => {
    if (!quelle || !puffer) return;
    try { sessionStorage.setItem(posKey, String((ctx.currentTime - startZeit) % puffer.duration)); } catch (e) {}
  });
  zeigen();
  if (an) {
    // Browser spielen Ton erst nach einer Interaktion ab: wenn nötig, beim ersten Klick oder Tastendruck starten
    const los = () => { if (an) starten().catch(() => {}); removeEventListener("pointerdown", los); removeEventListener("keydown", los); };
    vorbereiten().then(() => ctx.state === "running" ? starten() : (addEventListener("pointerdown", los), addEventListener("keydown", los)))
      .catch(() => { knopf.textContent = "♪ nicht abspielbar"; });
  }
})();

// ---------- Züge: Ziel in zwei Schritten wählen (Ort nach Entfernung, dann Bereich) ----------
(() => {
  const box = document.getElementById("zielwahl");
  if (!box) return;
  const ziele = JSON.parse(box.dataset.ziele), ortWahl = document.getElementById("zielort"), wahl = document.getElementById("ziel");
  const info = document.getElementById("zielinfo"), knoepfe = [...box.querySelectorAll(".filterknopf")];
  const auftragFilter = { kolonisieren: "frei", angriff: "fremd", erkunden: "fremd", verlegen: "eigen" };
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  const relText = { eigen: "dein Bereich", allianz: "Allianz", fremd: "anderer Spieler", frei: "frei" };
  let filter = "alle";
  box.querySelector(".ziel-schritte").hidden = false;
  const passt = z => filter === "alle" || (filter === "eigen" ? z.rel === "eigen" || z.rel === "allianz" : z.rel === filter);
  function fuellen(behalten) {
    const ort = ortWahl.value, alt = behalten ?? wahl.value;
    const liste = ziele.filter(z => (ort === "*" ? z.rel === "eigen" || z.rel === "allianz" : z.ort === ort) && passt(z));
    if (ort !== "*") liste.sort((a, b) => a.nr - b.nr);
    wahl.innerHTML = `<option value="">${liste.length ? "– Bereich wählen –" : "– nichts passt zum Filter –"}</option>` + liste.map(z =>
      `<option value="${esc(z.id)}"${z.id === alt ? " selected" : ""}>${z.nr} · ${esc(z.name)} – ${esc(z.besitzer || "frei")}${z.rel === "eigen" ? " (du)" : ""} · ${String(z.km).replace(".", ",")} km${z.schutz ? " · 🛡 bis " + z.schutz : ""}</option>`).join("");
    zeigeInfo();
    wahl.dispatchEvent(new Event("change", { bubbles: true }));
  }
  function zeigeInfo() {
    const z = ziele.find(x => x.id === wahl.value);
    info.innerHTML = z ? `<span class="chip rel-${z.rel}">${esc(relText[z.rel])}</span> ${esc(z.name)}${z.besitzer ? " · " + esc(z.besitzer) : ""} · ${String(z.km).replace(".", ",")} km Fahrweg` +
      (z.schutz ? `<br><span class="chip rel-schutz">🛡 geschützt bis ${esc(z.schutz)}</span> Raubzüge erst danach möglich.` : "") +
      (z.sperre ? `<br><span class="chip">Erkundung erst ab ${esc(z.sperre)}</span>` : "") : "";
  }
  function setzeFilter(f) { filter = f; knoepfe.forEach(k => k.setAttribute("aria-pressed", String(k.dataset.filter === f))); }
  knoepfe.forEach(k => k.onclick = () => { setzeFilter(k.dataset.filter); fuellen(); });
  ortWahl.onchange = () => fuellen();
  wahl.addEventListener("change", zeigeInfo);
  // Der Auftrag wählt einen passenden Filter vor (Besiedeln → frei, Raubzug → andere Spieler …)
  document.querySelectorAll("[name=auftrag]").forEach(r => r.addEventListener("change", () => { setzeFilter(auftragFilter[r.value] || "alle"); fuellen(); }));
  const vor = ziele.find(z => z.id === box.dataset.vorwahl);
  if (vor) { ortWahl.value = vor.ort; fuellen(vor.id); }
  else { ortWahl.selectedIndex = 1; const a = document.querySelector("[name=auftrag]:checked"); if (a) setzeFilter(auftragFilter[a.value] || "alle"); fuellen(); }
})();

// ---------- Markt: Händlervorschau ----------
(() => {
  const f = document.getElementById("haendler");
  if (!f) return;
  const kurse = JSON.parse(f.dataset.kurse), wert = JSON.parse(f.dataset.wert), rest = +f.dataset.rest;
  const out = document.getElementById("haendler-vorschau"), namen = { holz: "Holz", ziegel: "Baustoffe", korn: "Nahrung", taler: "Euro" };
  function rechne() {
    const von = f.von.value, nach = f.nach.value, n = Math.max(0, +f.menge.value || 0);
    if (von === nach) { out.innerHTML = `<span class="warn">Bitte zwei verschiedene Rohstoffe wählen.</span>`; return; }
    const bekommt = Math.floor(n * kurse[von][nach]), zuviel = n * wert[von] > rest;
    out.innerHTML = `${n.toLocaleString("de-DE")} ${namen[von]} → <b>${bekommt.toLocaleString("de-DE")} ${namen[nach]}</b>` +
      (zuviel ? `<br><span class="warn">Über dem Tageslimit – höchstens ${Math.floor(rest / wert[von]).toLocaleString("de-DE")} ${namen[von]}.</span>` : "");
  }
  f.addEventListener("input", rechne); f.addEventListener("change", rechne); rechne();
})();

// ---------- Bunker: belegte Plätze mitzählen ----------
document.querySelectorAll(".bunkerform[data-frei]").forEach(f => {
  const out = f.querySelector(".bunker-vorschau"), frei = +f.dataset.frei;
  f.addEventListener("input", () => {
    let n = 0; f.querySelectorAll("[data-platz]").forEach(i => n += (+i.value || 0) * +i.dataset.platz);
    out.innerHTML = n > frei ? `<span class="warn">${n} Plätze – nur ${frei} frei.</span>` : `${n} von ${frei} freien Plätzen.`;
  });
});

// ---------- Chat: alle 4 Sekunden nachladen ----------
(() => {
  const box = document.getElementById("chat");
  if (!box) return;
  const form = document.getElementById("chat-form"), feld = document.getElementById("chat-text");
  const kanal = box.dataset.kanal, partner = box.dataset.partner, admin = box.dataset.admin === "1" && kanal === "alle";
  const csrf = document.body.dataset.csrf;
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  let seit = 0, erstes = true, tag = "";
  async function laden() {
    try {
      const r = await fetch(`?api=chat&kanal=${kanal}&partner=${partner}&seit=${seit}`, { credentials: "same-origin" });
      const j = await r.json();
      if (erstes) { box.innerHTML = j.nachrichten.length ? "" : `<p class="leise leer">Noch ist es still hier. Schreib die erste Nachricht!</p>`; erstes = false; }
      if (j.nachrichten.length) {
        box.querySelector(".leer")?.remove();
        for (const m of j.nachrichten) {
          if (m.datum !== tag) { tag = m.datum; box.insertAdjacentHTML("beforeend", `<div class="chat-tag">${esc(m.datum)}</div>`); }
          box.insertAdjacentHTML("beforeend", `<div class="chat-msg${m.ich ? " ich" : ""}" data-id="${m.id}"><span class="chat-name" style="--farbe:${esc(m.farbe)}"><i class="lampe ${m.online ? "an" : "aus"}"></i>${esc(m.name)}</span>` +
            `<span class="chat-text">${esc(m.text)}</span><time>${esc(m.zeit)}</time>${admin ? `<button type="button" class="chat-weg" title="Nachricht entfernen" aria-label="Nachricht entfernen">×</button>` : ""}</div>`);
          seit = Math.max(seit, m.id);
        }
        box.scrollTop = box.scrollHeight;
      }
    } catch (e) { /* nächster Versuch in ein paar Sekunden */ }
  }
  form.addEventListener("submit", async e => {
    e.preventDefault();
    const text = feld.value.trim();
    if (!text) return;
    feld.disabled = true;
    try {
      const r = await fetch("?api=chat_senden", { method: "POST", body: new URLSearchParams({ csrf, kanal, partner, text }), credentials: "same-origin" });
      const j = await r.json();
      if (j.fehler) alert(j.fehler); else feld.value = "";
    } finally { feld.disabled = false; feld.focus(); laden(); }
  });
  if (admin) box.addEventListener("click", e => {
    const b = e.target.closest(".chat-weg");
    if (!b || !confirm("Diese Nachricht für alle entfernen?")) return;
    const f = document.getElementById("chat-loeschen");
    f.chat.value = b.parentElement.dataset.id; f.submit();
  });
  laden();
  setInterval(() => { if (!document.hidden) laden(); }, 4000);
})();

// ---------- Zeitung: KI-Ausgabe anstoßen, wenn heute erst die Kurzausgabe da ist ----------
(() => {
  const z = document.getElementById("zeitung");
  if (!z || z.dataset.ki !== "1") return;
  const status = document.getElementById("zeitung-status");
  fetch("?api=zeitung_ki", { method: "POST", body: new URLSearchParams({ csrf: document.body.dataset.csrf }), credentials: "same-origin" })
    .then(r => r.json())
    .then(j => { if (j.ok) location.reload(); else if (status) status.textContent = "Heute gibt es nur die Kurzausgabe. (" + j.fehler + ")"; })
    .catch(() => { if (status) status.textContent = "Heute gibt es nur die Kurzausgabe."; });
})();

// ---------- KI-Berater ----------
(() => {
  const form = document.getElementById("berater-form");
  if (!form) return;
  const verlauf = document.getElementById("berater-verlauf"), feld = document.getElementById("berater-frage"), rest = document.getElementById("berater-rest");
  const knopf = form.querySelector("button");
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  const blase = (klasse, html) => { const d = document.createElement("div"); d.className = "berater-msg " + klasse; d.innerHTML = html; verlauf.appendChild(d); verlauf.scrollTop = verlauf.scrollHeight; return d; };
  verlauf.scrollTop = verlauf.scrollHeight;
  document.querySelectorAll(".beispiel").forEach(b => b.addEventListener("click", () => { feld.value = b.dataset.frage; feld.focus(); }));
  form.addEventListener("submit", async e => {
    e.preventDefault();
    const frage = feld.value.trim();
    if (!frage) return;
    blase("frage", `<p>${esc(frage)}</p>`);
    const warte = blase("antwort denkt", "<p>Ich schaue mir deine Daten an …</p>");
    feld.value = ""; feld.disabled = knopf.disabled = true;
    try {
      const r = await fetch("?api=berater", { method: "POST", body: new URLSearchParams({ csrf: document.body.dataset.csrf, frage }), credentials: "same-origin" });
      const j = await r.json();
      warte.classList.remove("denkt");
      if (j.fehler) { warte.classList.add("fehler"); warte.innerHTML = `<p>${esc(j.fehler)}</p>`; }
      else warte.innerHTML = j.html;
      if (typeof j.rest === "number") { rest.firstChild.textContent = rest.firstChild.textContent.replace(/^\d+/, j.rest); if (!j.rest) { feld.disabled = knopf.disabled = true; return; } }
    } catch (err) {
      warte.classList.remove("denkt"); warte.classList.add("fehler"); warte.innerHTML = "<p>Keine Verbindung. Bitte noch einmal versuchen.</p>";
    }
    feld.disabled = knopf.disabled = false; feld.focus(); verlauf.scrollTop = verlauf.scrollHeight;
  });
})();

// ---------- Wappen-Vorschau am Allianz-Kürzel: am Fenster ausrichten, damit Tabellen sie nicht abschneiden ----------
(() => {
  const zeigen = a => {
    const tip = a.querySelector(".tip");
    if (!tip) return;
    const r = a.getBoundingClientRect(), oben = r.top > 170;
    Object.assign(tip.style, { position: "fixed", left: r.left + r.width / 2 + "px", top: (oben ? r.top - 8 : r.bottom + 8) + "px", bottom: "auto",
      transform: oben ? "translate(-50%, -100%)" : "translate(-50%, 0)" });
  };
  ["mouseover", "focusin"].forEach(ev => document.addEventListener(ev, e => { const a = e.target.closest && e.target.closest(".kuerzel-tip"); if (a) zeigen(a); }));
})();
