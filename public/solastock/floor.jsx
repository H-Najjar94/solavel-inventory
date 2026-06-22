/* SolaStock — Stock Floor: immersive 3D warehouse */
const { useState: fUseState, useRef: fUseRef, useMemo: fUseMemo } = React;

/* ---- build a rack grid for a warehouse ---- */
function buildRacks(DB, whId) {
  let s = (whId.charCodeAt(3) || 7) * 131 + 17;
  const r = () => { s = (s * 9301 + 49297) % 233280; return s / 233280; };
  const aisles = ["A", "B", "C", "D", "E", "F"];
  const perAisle = 5;
  const racks = [];
  aisles.forEach((a, ai) => {
    for (let i = 0; i < perAisle; i++) {
      const fill = Math.round(r() * 100);
      let status = "ok";
      if (fill < 8) status = "out";
      else if (fill < 28) status = "low";
      else if (fill > 88) status = "full";
      const item = DB.ITEMS[Math.floor(r() * DB.ITEMS.length)];
      racks.push({
        id: a + "-" + String(i + 1).padStart(2, "0"),
        aisle: a, ai, slot: i, fill, status,
        item, units: Math.round(fill * (2 + r() * 4)),
        moves: Math.round(r() * 40),
      });
    }
  });
  return { aisles, perAisle, racks };
}

const STATUS_COLOR = { ok: "#1f9d6b", low: "#e09921", out: "#e05151", full: "#3b7de0", empty: "#9b9893" };

function rackColor(rk, mode) {
  if (mode === "category") return rk.item.catColor;
  if (mode === "activity") {
    const t = rk.moves / 40;
    return t > 0.66 ? "#e05151" : t > 0.33 ? "#e09921" : "#1f9d6b";
  }
  return STATUS_COLOR[rk.status];
}

/* ---- one 3D rack (top + 4 walls) ---- */
function Rack({ rk, x, y, w, d, mode, selected, onHover, onLeave, onClick }) {
  const base = rackColor(rk, mode);
  const h = 26 + (rk.fill / 100) * 78;
  const top = base;
  const wallA = `color-mix(in srgb, ${base} 82%, #000)`;   // north/west (lit)
  const wallB = `color-mix(in srgb, ${base} 64%, #000)`;   // south/east (shade)
  const faces = [
    { k: "top", style: { width: w, height: d, transform: `translateZ(${h}px)`, background: top, borderRadius: 5, boxShadow: "inset 0 0 0 1px rgba(255,255,255,0.18)" } },
    { k: "n", style: { width: w, height: h, transformOrigin: "0 0", transform: `rotateX(-90deg)`, background: wallA } },
    { k: "s", style: { width: w, height: h, transformOrigin: "0 0", transform: `translateY(${d}px) rotateX(-90deg)`, background: wallB } },
    { k: "w", style: { width: h, height: d, transformOrigin: "0 0", transform: `rotateY(-90deg)`, background: wallA } },
    { k: "e", style: { width: h, height: d, transformOrigin: "0 0", transform: `translateX(${w}px) rotateY(-90deg)`, background: wallB } },
  ];
  return (
    <div className={"rack" + (selected ? " sel" : "")} style={{ left: x, top: y, width: w, height: d }}
      onMouseEnter={(e) => onHover(rk, e)} onMouseMove={(e) => onHover(rk, e)} onMouseLeave={onLeave}
      onClick={() => onClick(rk)}>
      <div className="rack-glow" style={{ inset: -3, width: w + 6, height: d + 6 }} />
      <div className="rack-lift">
        {faces.map(f => (
          <div key={f.k} className="rack-face" style={f.style}>
            {(f.k === "n" || f.k === "s" || f.k === "w" || f.k === "e") && <div className="rack-rungs" />}
          </div>
        ))}
      </div>
    </div>
  );
}

function StockFloor({ go, toast }) {
  const DB = window.DB, fmt = window.fmt;
  const [whId, setWhId] = fUseState("WH-01");
  const [mode, setMode] = fUseState("health");
  const [rot, setRot] = fUseState(-32);
  const [zoom, setZoom] = fUseState(1);
  const [tip, setTip] = fUseState(null);
  const [tipPos, setTipPos] = fUseState({ x: 0, y: 0 });
  const [sel, setSel] = fUseState(null);

  const { aisles, perAisle, racks } = fUseMemo(() => buildRacks(DB, whId), [whId]);
  const wh = DB.WAREHOUSES.find(w => w.id === whId);

  // geometry
  const RW = 74, RD = 58, AISLE = 58, ROWGAP = 20, PAD = 70;
  const worldW = aisles.length * RW + (aisles.length - 1) * AISLE + PAD * 2;
  const worldH = perAisle * RD + (perAisle - 1) * ROWGAP + PAD * 2;
  const rackX = ai => PAD + ai * (RW + AISLE);
  const rackY = slot => PAD + slot * (RD + ROWGAP);

  function onHover(rk, e) { setTip(rk); setTipPos({ x: e.clientX, y: e.clientY }); }
  function onLeave() { setTip(null); }

  const occupancy = Math.round(racks.reduce((s, r) => s + r.fill, 0) / racks.length);
  const alerts = racks.filter(r => r.status === "out" || r.status === "low").length;

  const events = [
    ["Picked", "12×", "Aurora Wireless Headphones", "B-03"],
    ["Restocked", "+200", "Forge Cast Iron Pan", "E-01"],
    ["Low stock", "", "Vega 4K Webcam Pro", "C-04"],
    ["Moved", "60×", "Summit Running Shoes", "A-02 → D-05"],
    ["Cycle count", "", "Nimbus Keyboard", "A-01"],
  ];

  return (
    <div className="floor-screen">
      <div className="floor-stage">
        <div className="floor-scene" style={{ "--rot": rot + "deg", "--zoom": zoom }}>
          <div className="floor-world" style={{ width: worldW, height: worldH, marginLeft: -worldW / 2, marginTop: -worldH / 2 }}>
            <div className="floor-slab" style={{ inset: 0 }} />

            {/* aisle floor tags */}
            {aisles.map((a, ai) => (
              <div key={a} className="aisle-tag" style={{ left: rackX(ai) + RW / 2 - 14, top: 22 }}>{a}</div>
            ))}

            {/* inbound / outbound docks */}
            <div className="dock-zone" style={{ left: PAD, top: worldH - 44, width: RW * 2, height: 26,
              borderColor: "var(--green)", color: "var(--green)" }}>INBOUND</div>
            <div className="dock-zone" style={{ left: worldW - PAD - RW * 2, top: worldH - 44, width: RW * 2, height: 26,
              borderColor: "var(--amber)", color: "var(--amber-strong)" }}>OUTBOUND</div>

            {/* moving AGV down aisle between A and B */}
            <div className="agv" style={{ left: rackX(0) + RW + AISLE / 2 - 17, top: PAD,
              animation: "agvMove 7s ease-in-out infinite alternate" }}>
              <div className="agv-body" />
            </div>

            {/* racks */}
            {racks.map(rk => (
              <Rack key={rk.id} rk={rk} x={rackX(rk.ai)} y={rackY(rk.slot)} w={RW} d={RD}
                mode={mode} selected={sel && sel.id === rk.id}
                onHover={onHover} onLeave={onLeave} onClick={(r) => { setSel(r); setTip(null); }} />
            ))}
          </div>
        </div>
      </div>

      {/* keyframes for AGV (depends on world size) */}
      <style dangerouslySetInnerHTML={{ __html: `@keyframes agvMove { from { transform: translateY(0); } to { transform: translateY(${worldH - PAD * 2 - 34}px); } }` }} />

      {/* top controls */}
      <div className="floor-top">
        <div className="floor-title">
          <b>Stock Floor</b>
          <span>{wh.name} · {wh.city}</span>
        </div>
        <div style={{ flex: 1 }} />
        <div className="glass-bar">
          {DB.WAREHOUSES.map(w => (
            <button key={w.id} className={"fbtn" + (whId === w.id ? " on" : "")} onClick={() => { setWhId(w.id); setSel(null); }}>{w.id}</button>
          ))}
        </div>
        <div className="glass-bar">
          {[["health", "Health"], ["category", "Category"], ["activity", "Activity"]].map(m => (
            <button key={m[0]} className={"fbtn" + (mode === m[0] ? " on" : "")} onClick={() => setMode(m[0])}>{m[1]}</button>
          ))}
        </div>
        <div className="glass-bar">
          <button className="fbtn icon" onClick={() => setRot(r => r - 30)} title="Rotate left"><Icon name="refresh" /></button>
          <button className="fbtn icon" onClick={() => setZoom(z => Math.min(1.5, z + 0.15))} title="Zoom in"><Icon name="plus" /></button>
          <button className="fbtn icon" onClick={() => setZoom(z => Math.max(0.6, z - 0.15))} title="Zoom out"><Icon name="adjustments" /></button>
        </div>
      </div>

      {/* floating stats */}
      <div className="floor-stats">
        <div className="fstat">
          <div className="fi metric-ico amber"><Icon name="warehouse" size={17} /></div>
          <div><div className="fl">Floor occupancy</div><div className="fv">{occupancy}%</div></div>
        </div>
        <div className="fstat">
          <div className="fi metric-ico blue"><Icon name="layers" size={17} /></div>
          <div><div className="fl">Active racks</div><div className="fv">{racks.length}<span style={{ fontSize: 12, color: "var(--text-3)", fontWeight: 700 }}> / {aisles.length} aisles</span></div></div>
        </div>
        <div className="fstat">
          <div className="fi metric-ico red"><Icon name="alert" size={17} /></div>
          <div><div className="fl">Need attention</div><div className="fv">{alerts}</div></div>
        </div>
      </div>

      {/* legend */}
      <div className="floor-legend">
        {mode === "health" && [["Full", "#3b7de0"], ["Healthy", "#1f9d6b"], ["Low", "#e09921"], ["Empty", "#e05151"]].map(l => (
          <div className="lg" key={l[0]}><span className="sw" style={{ background: l[1] }} />{l[0]}</div>
        ))}
        {mode === "category" && DB.CATEGORIES.slice(0, 4).map(c => (
          <div className="lg" key={c.name}><span className="sw" style={{ background: c.color }} />{c.name}</div>
        ))}
        {mode === "activity" && [["Hot", "#e05151"], ["Warm", "#e09921"], ["Cool", "#1f9d6b"]].map(l => (
          <div className="lg" key={l[0]}><span className="sw" style={{ background: l[1] }} />{l[0]}</div>
        ))}
      </div>

      {/* live ticker */}
      <div className="floor-ticker">
        <div className="tk-live"><span className="led" />Live floor</div>
        <div style={{ overflow: "hidden", flex: 1 }}>
          <div className="tk-track">
            {[...events, ...events].map((e, i) => (
              <span className="tk-item" key={i}>{e[0]} {e[1] && <b>{e[1]}</b>} {e[2]} <span className="mono">{e[3]}</span></span>
            ))}
          </div>
        </div>
      </div>

      {/* hover tooltip */}
      {tip && (
        <div className="floor-tip" style={{ left: tipPos.x, top: tipPos.y - 14 }}>
          <div className="ft-top">
            <span className="badge" style={{ background: "var(--surface-2)" }}><span className="led" style={{ background: rackColor(tip, mode) }} />{tip.status.toUpperCase()}</span>
            <span className="ft-id">{tip.id}</span>
          </div>
          <b>{tip.item.name}</b>
          <div className="ft-bar"><div style={{ height: "100%", width: tip.fill + "%", background: rackColor(tip, mode), borderRadius: 99 }} /></div>
          <div className="ft-row"><span>{tip.fill}% full</span><span>{tip.units} units</span></div>
          <div style={{ fontSize: 11, color: "var(--text-3)", fontWeight: 700, marginTop: 7 }}>Click to step inside →</div>
        </div>
      )}

      {/* shelf drawer */}
      <div className={"shelf-scrim" + (sel ? " show" : "")} onClick={() => setSel(null)} style={{ pointerEvents: sel ? "auto" : "none" }} />
      <ShelfDrawer rk={sel} mode={mode} onClose={() => setSel(null)} go={go} toast={toast} />
    </div>
  );
}

function ShelfDrawer({ rk, mode, onClose, go, toast }) {
  const DB = window.DB, fmt = window.fmt;
  // build bins for the rack: 4 levels × 5 bins
  const bins = fUseMemo(() => {
    if (!rk) return [];
    let s = rk.id.charCodeAt(0) * 71 + rk.slot * 13 + 5;
    const r = () => { s = (s * 9301 + 49297) % 233280; return s / 233280; };
    const levels = [];
    for (let l = 0; l < 4; l++) {
      const row = [];
      for (let b = 0; b < 5; b++) {
        const empty = r() < (rk.fill < 30 ? 0.5 : 0.15);
        const it = DB.ITEMS[Math.floor(r() * DB.ITEMS.length)];
        row.push({ empty, item: it, qty: empty ? 0 : Math.round(10 + r() * 180), pct: Math.round(r() * 100) });
      }
      levels.push(row);
    }
    return levels;
  }, [rk]);

  const color = rk ? rackColor(rk, mode) : "var(--amber)";
  return (
    <div className={"shelf-drawer" + (rk ? " show" : "")}>
      {rk && (
        <>
          <div className="shelf-head">
            <div style={{ position: "absolute", inset: 0, background: `radial-gradient(120% 120% at 90% -10%, ${color}22, transparent 60%)` }} />
            <div style={{ position: "relative", display: "flex", alignItems: "flex-start" }}>
              <div style={{ flex: 1 }}>
                <div className="eyebrow">AISLE {rk.aisle} · RACK {rk.id}</div>
                <h2>{rk.item.name}</h2>
                <div className="shdesc">Primary SKU on this rack · {rk.units} units · {rk.fill}% full</div>
              </div>
              <button className="icon-btn" onClick={onClose}><Icon name="x" /></button>
            </div>
            <div style={{ position: "relative", display: "flex", gap: 8, marginTop: 14 }}>
              <span className="badge" style={{ background: "var(--surface-2)" }}><span className="led" style={{ background: color }} />{rk.status.toUpperCase()}</span>
              <span className="chip" style={{ color: rk.item.catColor }}><span style={{ width: 7, height: 7, borderRadius: 99, background: rk.item.catColor }} />{rk.item.category}</span>
              <span className="chip mono">{rk.item.sku}</span>
            </div>
          </div>
          <div className="shelf-body">
            {bins.map((row, li) => (
              <div className="shelf-level" key={li}>
                <div className="lvl-label"><span>SHELF {4 - li}</span><span>{row.filter(b => !b.empty).length}/5 bins filled</span></div>
                <div className="bin-row">
                  {row.map((b, bi) => {
                    const st = b.empty ? "out" : b.pct < 25 ? "low" : b.pct > 88 ? "full" : "ok";
                    const c = STATUS_COLOR[st];
                    return (
                      <div key={bi} className={"bin" + (b.empty ? " empty" : "")} style={{ color: c }}
                        title={b.empty ? "Empty bin" : b.item.name + " · " + b.qty}
                        onClick={() => !b.empty && go("item", b.item.id)}>
                        {!b.empty && <div className="bin-fill" style={{ height: b.pct + "%" }} />}
                        {b.empty ? <Icon name="plus" size={16} style={{ color: "var(--text-3)" }} />
                          : <div className="bin-prod" style={{ background: `repeating-linear-gradient(135deg, ${b.item.catColor}, ${b.item.catColor} 4px, color-mix(in srgb,${b.item.catColor} 60%, #000) 4px, color-mix(in srgb,${b.item.catColor} 60%, #000) 8px)` }} />}
                        <span className="bin-q">{b.empty ? "—" : b.qty}</span>
                      </div>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
          <div className="shelf-foot">
            <button className="btn" style={{ flex: 1 }} onClick={() => toast("Pick list started · " + rk.id)}><Icon name="scan" size={17} />Pick</button>
            <button className="btn" style={{ flex: 1 }} onClick={() => toast("Restock drafted · " + rk.id)}><Icon name="arrowdown" size={17} />Restock</button>
            <button className="btn primary" style={{ flex: 1 }} onClick={() => go("item", rk.item.id)}><Icon name="chevright" size={17} />Open SKU</button>
          </div>
        </>
      )}
    </div>
  );
}

window.StockFloor = StockFloor;
