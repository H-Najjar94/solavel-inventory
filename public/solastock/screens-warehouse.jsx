/* SolaStock — Warehouse Overview */
const { useState: wUseState } = React;

function MiniMap({ warehouses, active, setActive }) {
  // zone layout (percentage-based) for a stylized floor map
  const zones = [
    { id: "WH-01", x: 6, y: 10, w: 40, h: 38 },
    { id: "WH-02", x: 52, y: 8, w: 42, h: 30 },
    { id: "WH-03", x: 6, y: 54, w: 28, h: 38 },
    { id: "WH-04", x: 40, y: 44, w: 26, h: 26 },
    { id: "WH-05", x: 70, y: 46, w: 24, h: 46 },
  ];
  return (
    <div className="wh-map" style={{ minHeight: 280 }}>
      {zones.map(z => {
        const w = warehouses.find(x => x.id === z.id);
        const tone = w.cap > 85 ? "var(--red)" : w.cap > 70 ? "var(--amber)" : "var(--green)";
        const on = active === z.id;
        return (
          <div key={z.id} className="wh-zone"
            style={{ left: z.x + "%", top: z.y + "%", width: z.w + "%", height: z.h + "%",
              borderColor: tone, background: on ? tone : tone.replace(")", " / 0.1)").replace("var(--", "color-mix(in srgb, var(--"),
              color: on ? "#fff" : tone, cursor: "pointer",
              boxShadow: on ? "0 8px 20px " + tone.replace(")", " / 0.4)").replace("var(--","color-mix(in srgb, var(--") : "none",
              transition: "all 0.2s", transform: on ? "scale(1.02)" : "none" }}
            onMouseEnter={() => setActive(z.id)} >
            <span>{z.id}</span>
            <span className="cap">{w.cap}% full</span>
          </div>
        );
      })}
    </div>
  );
}

function WarehouseOverview({ go, toast }) {
  const DB = window.DB, fmt = window.fmt;
  const [active, setActive] = wUseState("WH-01");
  const totalValue = DB.WAREHOUSES.reduce((s, w) => s + w.value, 0);

  return (
    <Page>
      <div className="page-head">
        <div>
          <h1 className="page-title">Warehouses</h1>
          <p className="page-sub">{DB.WAREHOUSES.length} active locations · {fmt.money(totalValue)} total stock value</p>
        </div>
        <div className="head-actions">
          <button className="btn"><Icon name="transfers" size={17} />New Transfer</button>
          <button className="btn primary" onClick={() => toast("Add warehouse")}><Icon name="plus" size={18} />Add Warehouse</button>
        </div>
      </div>

      <div className="grid">
        {/* summary metrics */}
        <div className="w-3"><MetricWidget icon="warehouse" color="amber" label="Total Capacity Used" value="72%" foot="across 5 sites" spark={[68,70,69,71,70,72,71,72]} /></div>
        <div className="w-3"><MetricWidget icon="package" color="blue" label="Total Units" value={fmt.num(12840)} foot="on hand" /></div>
        <div className="w-3"><MetricWidget icon="dollar" color="green" label="Total Stock Value" value={fmt.money(totalValue)} chip="+4.2%" chipDir="up" foot="vs last month" /></div>
        <div className="w-3"><MetricWidget icon="alert" color="red" label="Low Stock (all sites)" value={DB.WAREHOUSES.reduce((s,w)=>s+w.low,0)} foot="needs attention" /></div>

        {/* map */}
        <div className="w-7">
          <div className="card" style={{ height: "100%" }}>
            <CardHead title="Network Map" sub="Hover a zone to inspect · color = capacity">
              <div style={{ display: "flex", gap: 12, fontSize: 12, fontWeight: 700 }}>
                <span><span style={{ color: "var(--green)" }}>●</span> Healthy</span>
                <span><span style={{ color: "var(--amber)" }}>●</span> Filling</span>
                <span><span style={{ color: "var(--red)" }}>●</span> Near full</span>
              </div>
            </CardHead>
            <div className="card-body"><NetworkMap warehouses={DB.WAREHOUSES} active={active} setActive={setActive} /></div>
          </div>
        </div>

        {/* active warehouse detail */}
        <div className="w-5">
          {(() => {
            const w = DB.WAREHOUSES.find(x => x.id === active);
            return (
              <div className="card" style={{ height: "100%" }}>
                <CardHead title={w.name} sub={w.city}>
                  <span className="chip mono">{w.id}</span>
                </CardHead>
                <div className="card-body" style={{ display: "flex", flexDirection: "column", gap: 16 }}>
                  <div style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 8 }}>
                    <Gauge value={w.cap} label={w.cap + "%"} sub="capacity" size={140} />
                  </div>
                  <div className="grid" style={{ gap: 12 }}>
                    <div className="w-6" style={{ padding: 14, background: "var(--surface-2)", borderRadius: 14 }}>
                      <div style={{ fontSize: 11.5, color: "var(--text-3)", fontWeight: 700 }}>STOCK VALUE</div>
                      <div style={{ fontSize: 22, fontWeight: 800 }}>{fmt.moneyK(w.value)}</div>
                    </div>
                    <div className="w-6" style={{ padding: 14, background: "var(--surface-2)", borderRadius: 14 }}>
                      <div style={{ fontSize: 11.5, color: "var(--text-3)", fontWeight: 700 }}>UNITS</div>
                      <div style={{ fontSize: 22, fontWeight: 800 }}>{fmt.num(w.items)}</div>
                    </div>
                  </div>
                  <div style={{ display: "flex", alignItems: "center", gap: 10, padding: 13, borderRadius: 14, background: "var(--amber-tint)", border: "1px solid var(--line)" }}>
                    <div className="metric-ico amber" style={{ width: 34, height: 34 }}><Icon name="alert" size={16} /></div>
                    <div style={{ fontSize: 13, fontWeight: 700 }}>{w.low} items low on stock here</div>
                    <button className="btn sm ghost" style={{ marginLeft: "auto", color: "var(--amber-strong)" }} onClick={() => go("items")}>Review</button>
                  </div>
                  <button className="btn primary" style={{ width: "100%" }} onClick={() => toast("Transfer from " + w.id)}><Icon name="transfers" size={17} />Transfer Stock</button>
                </div>
              </div>
            );
          })()}
        </div>

        {/* warehouse cards */}
        {DB.WAREHOUSES.map(w => {
          const tone = w.cap > 85 ? "var(--red)" : w.cap > 70 ? "var(--amber)" : "var(--green)";
          return (
            <div className="w-4" key={w.id}>
              <div className="card card-pad" style={{ height: "100%", cursor: "pointer" }} onClick={() => setActive(w.id)}>
                <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 16 }}>
                  <div className="metric-ico amber" style={{ width: 42, height: 42, borderRadius: 13 }}><Icon name="warehouse" /></div>
                  <div style={{ flex: 1 }}>
                    <b style={{ fontSize: 15, fontWeight: 800, display: "block" }}>{w.name}</b>
                    <span style={{ fontSize: 12.5, color: "var(--text-2)", fontWeight: 600 }}><Icon name="pin" size={12} style={{ verticalAlign: -1 }} /> {w.city}</span>
                  </div>
                  <span className="chip mono">{w.id}</span>
                </div>
                <div style={{ marginBottom: 14 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 6, fontSize: 12.5, fontWeight: 700 }}>
                    <span style={{ color: "var(--text-2)" }}>Capacity</span>
                    <span className="mono" style={{ color: tone, fontWeight: 800 }}>{w.cap}%</span>
                  </div>
                  <div className="bar-track"><div className="bar-fill" style={{ width: w.cap + "%", background: tone }} /></div>
                </div>
                <div style={{ display: "flex", justifyContent: "space-between", paddingTop: 14, borderTop: "1px solid var(--line-soft)" }}>
                  <div><div style={{ fontSize: 11, color: "var(--text-3)", fontWeight: 700 }}>VALUE</div><div style={{ fontWeight: 800 }}>{fmt.moneyK(w.value)}</div></div>
                  <div><div style={{ fontSize: 11, color: "var(--text-3)", fontWeight: 700 }}>UNITS</div><div style={{ fontWeight: 800 }}>{fmt.num(w.items)}</div></div>
                  <div><div style={{ fontSize: 11, color: "var(--text-3)", fontWeight: 700 }}>LOW</div><div style={{ fontWeight: 800, color: w.low > 8 ? "var(--amber-strong)" : "inherit" }}>{w.low}</div></div>
                </div>
              </div>
            </div>
          );
        })}
      </div>
    </Page>
  );
}

window.WarehouseOverview = WarehouseOverview;
