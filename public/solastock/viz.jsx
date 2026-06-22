/* SolaStock — advanced visuals: count-up numbers, network map, calendar heatmap */
const { useState: vUseState, useEffect: vUseEffect, useRef: vUseRef } = React;

/* ---- Animated count-up (parses a formatted string, animates the number) ---- */
function AnimatedNumber({ text, dur = 1100 }) {
  const m = String(text).match(/^([^\d\-]*)(-?[\d,]*\.?\d+)(.*)$/);
  const visible = typeof document === "undefined" || document.visibilityState === "visible";
  const zeroStr = m ? m[1] + (m[2].indexOf(".") > -1 ? "0" : "0") + m[3] : text;
  const [out, setOut] = vUseState(visible && m ? zeroStr : text);
  vUseEffect(() => {
    if (!m) { setOut(text); return; }
    if (document.visibilityState !== "visible") { setOut(text); return; }
    const prefix = m[1], raw = m[2], suffix = m[3];
    const target = parseFloat(raw.replace(/,/g, ""));
    const decimals = (raw.split(".")[1] || "").length;
    const hasComma = raw.indexOf(",") > -1;
    let start = null, rafId;
    const ease = t => 1 - Math.pow(1 - t, 3);
    function frame(ts) {
      if (start === null) start = ts;
      const p = Math.min(1, (ts - start) / dur);
      const val = target * ease(p);
      let s = decimals ? val.toFixed(decimals) : Math.round(val).toString();
      if (hasComma) {
        const parts = s.split(".");
        parts[0] = parseInt(parts[0], 10).toLocaleString("en-US");
        s = parts.join(".");
      }
      setOut(prefix + s + suffix);
      if (p < 1) rafId = requestAnimationFrame(frame);
    }
    rafId = requestAnimationFrame(frame);
    return () => cancelAnimationFrame(rafId);
  }, [text]);
  return <span>{out}</span>;
}

/* ---- Animated logistics network map ---- */
const MAP_NODES = {
  "WH-02": { x: 12, y: 50, label: "West Coast" },
  "WH-01": { x: 41, y: 74, label: "Central" },
  "WH-03": { x: 67, y: 40, label: "Midwest" },
  "WH-04": { x: 73, y: 67, label: "Southeast" },
  "WH-05": { x: 87, y: 33, label: "Northeast" },
};
const MAP_ROUTES = [
  ["WH-02", "WH-01"], ["WH-01", "WH-04"], ["WH-03", "WH-04"],
  ["WH-03", "WH-05"], ["WH-01", "WH-03"], ["WH-02", "WH-03"],
];

function curve(a, b) {
  const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
  // bow the control point perpendicular for a nice arc
  const dx = b.x - a.x, dy = b.y - a.y;
  const cx = mx - dy * 0.18, cy = my + dx * 0.18;
  return `M ${a.x} ${a.y} Q ${cx} ${cy} ${b.x} ${b.y}`;
}

function NetworkMap({ warehouses, active, setActive }) {
  function tone(cap) { return cap > 85 ? "var(--red)" : cap > 70 ? "var(--amber)" : "var(--green)"; }
  return (
    <div className="netmap">
      <div className="netmap-glow g1" />
      <div className="netmap-glow g2" />
      <div className="netmap-dots" />
      <svg className="netmap-routes" viewBox="0 0 100 100" preserveAspectRatio="none">
        {MAP_ROUTES.map(([from, to], i) => {
          const a = MAP_NODES[from], b = MAP_NODES[to];
          const hot = active === from || active === to;
          return (
            <g key={i}>
              <path d={curve(a, b)} fill="none" stroke={hot ? "var(--amber)" : "var(--line)"}
                strokeWidth={hot ? 1.1 : 0.7} vectorEffect="non-scaling-stroke" opacity={hot ? 0.9 : 0.5} />
              <path d={curve(a, b)} fill="none" stroke="var(--amber)" strokeWidth="1.4"
                vectorEffect="non-scaling-stroke" strokeDasharray="2 7" className="route-flow"
                style={{ opacity: hot ? 0.95 : 0.4 }} />
            </g>
          );
        })}
      </svg>
      {Object.entries(MAP_NODES).map(([id, n]) => {
        const w = warehouses.find(x => x.id === id);
        const on = active === id;
        const c = tone(w.cap);
        const sz = 14 + (w.cap / 100) * 12;
        return (
          <div key={id} className={"net-node" + (on ? " on" : "")}
            style={{ left: n.x + "%", top: n.y + "%" }}
            onMouseEnter={() => setActive(id)}>
            <span className="net-pulse" style={{ background: c, width: sz + 8, height: sz + 8 }} />
            <span className="net-dot" style={{ background: c, width: sz, height: sz, boxShadow: `0 0 0 4px color-mix(in srgb, ${c} 22%, transparent), 0 6px 16px color-mix(in srgb, ${c} 45%, transparent)` }} />
            <span className="net-label" style={{ borderColor: on ? c : "var(--line)" }}>
              <b>{n.label}</b>
              <span className="mono" style={{ color: c }}>{w.cap}%</span>
            </span>
          </div>
        );
      })}
    </div>
  );
}

/* ---- Calendar heatmap (stock activity intensity) ---- */
function CalendarHeatmap({ weeks = 18 }) {
  const days = ["", "M", "", "W", "", "F", ""];
  const cells = [];
  let s = 7;
  function r() { s = (s * 9301 + 49297) % 233280; return s / 233280; }
  for (let w = 0; w < weeks; w++) {
    const col = [];
    for (let d = 0; d < 7; d++) {
      const v = Math.max(0, Math.min(4, Math.floor(r() * 5)));
      col.push(v);
    }
    cells.push(col);
  }
  const colors = ["var(--surface-3)",
    "color-mix(in srgb, var(--amber) 25%, var(--surface-3))",
    "color-mix(in srgb, var(--amber) 50%, var(--surface-3))",
    "color-mix(in srgb, var(--amber) 75%, var(--surface-3))",
    "var(--amber)"];
  return (
    <div style={{ display: "flex", gap: 6 }}>
      <div style={{ display: "flex", flexDirection: "column", gap: 4, fontSize: 9, color: "var(--text-3)", fontWeight: 700, paddingTop: 2 }}>
        {days.map((d, i) => <div key={i} style={{ height: 15, lineHeight: "15px" }}>{d}</div>)}
      </div>
      <div style={{ display: "flex", gap: 4, flex: 1 }}>
        {cells.map((col, ci) => (
          <div key={ci} style={{ display: "flex", flexDirection: "column", gap: 4, flex: 1 }}>
            {col.map((v, ri) => (
              <div key={ri} title={v + " moves"} style={{ aspectRatio: "1", borderRadius: 3.5, background: colors[v],
                transition: "transform 0.15s", cursor: "pointer" }}
                onMouseEnter={e => e.currentTarget.style.transform = "scale(1.25)"}
                onMouseLeave={e => e.currentTarget.style.transform = "none"} />
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}

Object.assign(window, { AnimatedNumber, NetworkMap, CalendarHeatmap });
