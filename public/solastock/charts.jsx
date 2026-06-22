/* SolaStock — chart primitives (pure SVG, soft & rounded) */

// Catmull-Rom -> cubic bezier smoothing
function smoothPath(pts) {
  if (pts.length < 2) return "";
  let d = `M ${pts[0][0]},${pts[0][1]}`;
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i - 1] || pts[i];
    const p1 = pts[i];
    const p2 = pts[i + 1];
    const p3 = pts[i + 2] || p2;
    const t = 0.16;
    const c1x = p1[0] + (p2[0] - p0[0]) * t;
    const c1y = p1[1] + (p2[1] - p0[1]) * t;
    const c2x = p2[0] - (p3[0] - p1[0]) * t;
    const c2y = p2[1] - (p3[1] - p1[1]) * t;
    d += ` C ${c1x},${c1y} ${c2x},${c2y} ${p2[0]},${p2[1]}`;
  }
  return d;
}

function useId(prefix) {
  const ref = React.useRef(null);
  if (ref.current === null) ref.current = prefix + "-" + Math.random().toString(36).slice(2, 8);
  return ref.current;
}

/* ---- Area chart (single series, gradient fill) ---- */
function AreaChart({ data, color = "var(--amber)", height = 200, fmtY }) {
  const gid = useId("ag");
  const W = 600, H = height, padX = 8, padTop = 18, padBot = 26;
  const ys = data.map(d => d.y);
  const min = Math.min(...ys) * 0.92, max = Math.max(...ys) * 1.04;
  const innerW = W - padX * 2, innerH = H - padTop - padBot;
  const x = i => padX + (i / (data.length - 1)) * innerW;
  const y = v => padTop + innerH - ((v - min) / (max - min)) * innerH;
  const pts = data.map((d, i) => [x(i), y(d.y)]);
  const line = smoothPath(pts);
  const area = line + ` L ${x(data.length - 1)},${padTop + innerH} L ${x(0)},${padTop + innerH} Z`;
  const [hover, setHover] = React.useState(null);
  return (
    <div style={{ position: "relative", width: "100%" }}>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} preserveAspectRatio="none"
        onMouseLeave={() => setHover(null)}
        onMouseMove={(e) => {
          const r = e.currentTarget.getBoundingClientRect();
          const rx = ((e.clientX - r.left) / r.width) * W;
          let idx = Math.round(((rx - padX) / innerW) * (data.length - 1));
          idx = Math.max(0, Math.min(data.length - 1, idx));
          setHover(idx);
        }}>
        <defs>
          <linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={color} stopOpacity="0.34" />
            <stop offset="100%" stopColor={color} stopOpacity="0.01" />
          </linearGradient>
        </defs>
        {[0.25, 0.5, 0.75].map((g, i) => (
          <line key={i} x1={padX} x2={W - padX} y1={padTop + innerH * g} y2={padTop + innerH * g}
            stroke="var(--chart-grid)" strokeWidth="1" />
        ))}
        <path d={area} fill={`url(#${gid})`} />
        <path d={line} fill="none" stroke={color} strokeWidth="2.6" strokeLinecap="round" />
        {hover != null && (
          <g>
            <line x1={x(hover)} x2={x(hover)} y1={padTop} y2={padTop + innerH} stroke={color} strokeWidth="1.4" strokeDasharray="4 4" opacity="0.5" />
            <circle cx={x(hover)} cy={y(data[hover].y)} r="5.5" fill="var(--surface)" stroke={color} strokeWidth="3" />
          </g>
        )}
      </svg>
      <div style={{ display: "flex", justifyContent: "space-between", padding: "0 8px", marginTop: -18 }}>
        {data.map((d, i) => (
          <span key={i} style={{ fontSize: 10.5, color: "var(--text-3)", fontWeight: 700,
            display: data.length > 8 && i % 2 ? "none" : "block" }}>{d.x}</span>
        ))}
      </div>
      {hover != null && (
        <div style={{ position: "absolute", top: 6, left: `${(x(hover) / W) * 100}%`,
          transform: "translateX(-50%)", background: "var(--text)", color: "var(--surface)",
          padding: "5px 10px", borderRadius: 9, fontSize: 12, fontWeight: 800, whiteSpace: "nowrap",
          pointerEvents: "none", boxShadow: "var(--shadow-md)" }}>
          {fmtY ? fmtY(data[hover].y) : data[hover].y} · {data[hover].x}
        </div>
      )}
    </div>
  );
}

/* ---- Grouped bar chart (in vs out) ---- */
function BarChart({ data, keys, colors, height = 200, fmtY }) {
  const W = 600, H = height, padX = 12, padTop = 14, padBot = 26;
  const innerW = W - padX * 2, innerH = H - padTop - padBot;
  const allVals = data.flatMap(d => keys.map(k => d[k]));
  const max = Math.max(...allVals) * 1.1;
  const groupW = innerW / data.length;
  const barW = Math.min(13, (groupW * 0.6) / keys.length);
  const gap = 3;
  return (
    <div style={{ width: "100%" }}>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} preserveAspectRatio="none">
        {[0.33, 0.66, 1].map((g, i) => (
          <line key={i} x1={padX} x2={W - padX} y1={padTop + innerH * (1 - g)} y2={padTop + innerH * (1 - g)}
            stroke="var(--chart-grid)" strokeWidth="1" />
        ))}
        {data.map((d, i) => {
          const cx = padX + groupW * i + groupW / 2;
          const totalW = keys.length * barW + (keys.length - 1) * gap;
          return keys.map((k, j) => {
            const h = (d[k] / max) * innerH;
            const bx = cx - totalW / 2 + j * (barW + gap);
            return <rect key={k} x={bx} y={padTop + innerH - h} width={barW} height={Math.max(h, 2)}
              rx={barW / 2.4} fill={colors[j]} opacity={0.92} />;
          });
        })}
      </svg>
      <div style={{ display: "flex", justifyContent: "space-between", padding: "0 12px", marginTop: -16 }}>
        {data.map((d, i) => (
          <span key={i} style={{ fontSize: 10.5, color: "var(--text-3)", fontWeight: 700,
            display: data.length > 8 && i % 2 ? "none" : "block" }}>{d.x}</span>
        ))}
      </div>
    </div>
  );
}

/* ---- Donut chart ---- */
function DonutChart({ data, size = 168, thickness = 26 }) {
  const r = (size - thickness) / 2;
  const c = size / 2;
  const circ = 2 * Math.PI * r;
  let offset = 0;
  const total = data.reduce((s, d) => s + d.pct, 0);
  const [hover, setHover] = React.useState(null);
  return (
    <div className="gauge-wrap" style={{ width: size, height: size }}>
      <svg width={size} height={size} style={{ transform: "rotate(-90deg)" }}>
        <circle cx={c} cy={c} r={r} fill="none" stroke="var(--surface-3)" strokeWidth={thickness} />
        {data.map((d, i) => {
          const len = (d.pct / total) * circ;
          const seg = (
            <circle key={i} cx={c} cy={c} r={r} fill="none" stroke={d.color}
              strokeWidth={hover === i ? thickness + 4 : thickness}
              strokeDasharray={`${len - 3} ${circ - len + 3}`} strokeDashoffset={-offset}
              strokeLinecap="round"
              style={{ transition: "stroke-width 0.2s", cursor: "pointer" }}
              onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} />
          );
          offset += len;
          return seg;
        })}
      </svg>
      <div className="gauge-val">
        <b>{hover != null ? data[hover].pct + "%" : total + "%"}</b>
        <span>{hover != null ? data[hover].name : "Categories"}</span>
      </div>
    </div>
  );
}

/* ---- Radial gauge (score) ---- */
function Gauge({ value, size = 168, thickness = 16, color = "var(--amber)", label, sub }) {
  const gid = useId("gg");
  const r = (size - thickness) / 2;
  const c = size / 2;
  const circ = 2 * Math.PI * r;
  const pct = Math.max(0, Math.min(100, value));
  const len = (pct / 100) * circ;
  return (
    <div className="gauge-wrap" style={{ width: size, height: size }}>
      <svg width={size} height={size} style={{ transform: "rotate(-90deg)" }}>
        <defs>
          <linearGradient id={gid} x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stopColor="var(--amber-soft)" />
            <stop offset="100%" stopColor="var(--amber-strong)" />
          </linearGradient>
        </defs>
        <circle cx={c} cy={c} r={r} fill="none" stroke="var(--surface-3)" strokeWidth={thickness} />
        <circle cx={c} cy={c} r={r} fill="none" stroke={`url(#${gid})`} strokeWidth={thickness}
          strokeDasharray={`${len} ${circ - len}`} strokeLinecap="round"
          style={{ transition: "stroke-dasharray 1s var(--ease)" }} />
      </svg>
      <div className="gauge-val">
        <b>{label != null ? label : pct}</b>
        <span>{sub || "Score"}</span>
      </div>
    </div>
  );
}

/* ---- Sparkline ---- */
function Sparkline({ values, color = "var(--amber)", width = 90, height = 32, fill = true }) {
  const gid = useId("sp");
  const min = Math.min(...values), max = Math.max(...values);
  const x = i => (i / (values.length - 1)) * width;
  const y = v => height - 3 - ((v - min) / (max - min || 1)) * (height - 6);
  const pts = values.map((v, i) => [x(i), y(v)]);
  const line = smoothPath(pts);
  const area = line + ` L ${width},${height} L 0,${height} Z`;
  return (
    <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`}>
      <defs>
        <linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity="0.28" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      {fill && <path d={area} fill={`url(#${gid})`} />}
      <path d={line} fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}

/* ---- Mini flow chart (purchase vs sales, two stacked areas) ---- */
function FlowChart({ data, height = 150 }) {
  const W = 500, H = height, padX = 8, padTop = 12, padBot = 24;
  const innerW = W - padX * 2, innerH = H - padTop - padBot;
  const all = data.flatMap(d => [d.purchase, d.sales]);
  const max = Math.max(...all) * 1.1;
  const x = i => padX + (i / (data.length - 1)) * innerW;
  const yP = v => padTop + innerH - (v / max) * innerH;
  const lineP = smoothPath(data.map((d, i) => [x(i), yP(d.purchase)]));
  const lineS = smoothPath(data.map((d, i) => [x(i), yP(d.sales)]));
  const pg = useId("fp"), sg = useId("fs");
  return (
    <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} preserveAspectRatio="none">
      <defs>
        <linearGradient id={pg} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="var(--blue)" stopOpacity="0.2" /><stop offset="100%" stopColor="var(--blue)" stopOpacity="0" />
        </linearGradient>
        <linearGradient id={sg} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="var(--amber)" stopOpacity="0.22" /><stop offset="100%" stopColor="var(--amber)" stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={lineP + ` L ${x(data.length-1)},${padTop+innerH} L ${x(0)},${padTop+innerH} Z`} fill={`url(#${pg})`} />
      <path d={lineS + ` L ${x(data.length-1)},${padTop+innerH} L ${x(0)},${padTop+innerH} Z`} fill={`url(#${sg})`} />
      <path d={lineP} fill="none" stroke="var(--blue)" strokeWidth="2.4" strokeLinecap="round" />
      <path d={lineS} fill="none" stroke="var(--amber)" strokeWidth="2.4" strokeLinecap="round" />
    </svg>
  );
}

Object.assign(window, { AreaChart, BarChart, DonutChart, Gauge, Sparkline, FlowChart, smoothPath });
