/* SolaStock — Dashboard screen with real drag-reorder + customize mode */
const { useState: dashUseState, useEffect: dashUseEffect, useRef: dashUseRef } = React;

const DEFAULT_LAYOUT = [
  "invValue", "lowStock", "outStock", "salesToday",
  "valueTrend", "health",
  "aiIntelligence", "smartAlerts",
  "network", "stockActivity",
  "stockMovement", "categoryDonut",
  "topSelling", "lowStockPanel", "warehouseCapacity",
  "recentActivity", "purchaseVsSales", "demandForecast",
];

function Dashboard({ go, toast }) {
  const DB = window.DB, fmt = window.fmt;
  const WIDGETS = React.useMemo(() => buildWidgets(DB, fmt, go), []);
  const ALL_IDS = Object.keys(WIDGETS);

  const [layout, setLayout] = dashUseState(() => {
    try {
      const saved = JSON.parse(localStorage.getItem("solastock_layout_v2"));
      if (Array.isArray(saved) && saved.length) return saved.filter(id => WIDGETS[id]);
    } catch (e) {}
    return DEFAULT_LAYOUT;
  });
  const [customizing, setCustomizing] = dashUseState(false);
  const [showGallery, setShowGallery] = dashUseState(false);
  const dragIdx = dashUseRef(null);
  const [overIdx, setOverIdx] = dashUseState(null);

  dashUseEffect(() => {
    localStorage.setItem("solastock_layout_v2", JSON.stringify(layout));
  }, [layout]);

  function onDragStart(e, i) {
    dragIdx.current = i;
    e.dataTransfer.effectAllowed = "move";
    try { e.dataTransfer.setData("text/plain", String(i)); } catch (err) {}
  }
  function onDragOver(e, i) {
    e.preventDefault();
    e.dataTransfer.dropEffect = "move";
    if (i !== overIdx) setOverIdx(i);
  }
  function onDrop(e, i) {
    e.preventDefault();
    const from = dragIdx.current;
    if (from == null || from === i) { setOverIdx(null); return; }
    setLayout(prev => {
      const next = [...prev];
      const [moved] = next.splice(from, 1);
      next.splice(i, 0, moved);
      return next;
    });
    dragIdx.current = null;
    setOverIdx(null);
  }
  function removeWidget(id) {
    setLayout(prev => prev.filter(x => x !== id));
  }
  function addWidget(id) {
    setLayout(prev => [...prev, id]);
    setShowGallery(false);
    toast("Widget added");
  }
  function saveLayout() {
    localStorage.setItem("solastock_layout_v2", JSON.stringify(layout));
    setCustomizing(false);
    toast("Layout saved");
  }
  function resetLayout() {
    setLayout(DEFAULT_LAYOUT);
    toast("Layout reset to default");
  }

  const available = ALL_IDS.filter(id => !layout.includes(id));

  return (
    <Page>
      <div className="page-head">
        <div>
          <h1 className="page-title">Good morning, Jordan 👋</h1>
          <p className="page-sub">Here's what's happening across your 5 warehouses today.</p>
        </div>
        <div className="head-actions">
          {!customizing ? (
            <>
              <button className="btn" onClick={() => go("reports")}><Icon name="download" size={17} />Export</button>
              <button className="btn" onClick={() => setCustomizing(true)}>
                <Icon name="sliders" size={17} />Customize Dashboard
              </button>
            </>
          ) : (
            <>
              <button className="btn" onClick={resetLayout}><Icon name="refresh" size={16} />Reset</button>
              <button className="btn" onClick={() => setShowGallery(true)}><Icon name="plus" size={17} />Add Widget</button>
              <button className="btn primary" onClick={saveLayout}><Icon name="save" size={17} />Save Layout</button>
            </>
          )}
        </div>
      </div>

      {customizing && (
        <div style={{ display: "flex", alignItems: "center", gap: 12, padding: "12px 16px", marginBottom: 18,
          background: "var(--amber-tint)", border: "1px solid var(--amber)", borderRadius: 14, color: "var(--amber-strong)", fontWeight: 700, fontSize: 13.5 }}>
          <Icon name="grid" size={18} />
          <span>Customize mode — drag cards by their handle to rearrange, remove with the × button, or add new widgets.</span>
        </div>
      )}

      <div className={"grid" + (customizing ? " customizing" : "")}>
        {layout.map((id, i) => {
          const w = WIDGETS[id];
          if (!w) return null;
          return (
            <div
              key={id}
              className={"widget " + w.span + (overIdx === i && customizing ? " drag-over" : "") + (dragIdx.current === i ? " dragging" : "")}
              draggable={customizing}
              onDragStart={(e) => onDragStart(e, i)}
              onDragOver={(e) => customizing && onDragOver(e, i)}
              onDrop={(e) => customizing && onDrop(e, i)}
              onDragEnd={() => { dragIdx.current = null; setOverIdx(null); }}
            >
              {customizing && (
                <>
                  <button className="widget-remove" onClick={() => removeWidget(id)} title="Remove"><Icon name="x" /></button>
                  <div className="drag-handle" title="Drag to move"><Icon name="grid" size={16} /></div>
                </>
              )}
              {w.render()}
            </div>
          );
        })}

        {customizing && (
          <button className="add-tile" onClick={() => setShowGallery(true)}>
            <Icon name="plus" />
            <span>Add Widget</span>
            <span style={{ fontSize: 12, color: "var(--text-3)", fontWeight: 600 }}>{available.length} available</span>
          </button>
        )}
      </div>

      {showGallery && (
        <WidgetGallery available={available} widgets={WIDGETS} onAdd={addWidget} onClose={() => setShowGallery(false)} />
      )}
    </Page>
  );
}

const WIDGET_DESC = {
  invValue: "Total stock value with trend", lowStock: "Items below reorder point",
  outStock: "Items at zero stock", salesToday: "Today's sales order count",
  pendingPO: "Open purchase orders", accuracy: "Cycle count accuracy",
  turnover: "Inventory turnover ratio", valueTrend: "12-month value chart",
  health: "Composite health gauge", aiIntelligence: "AI reorder suggestions",
  smartAlerts: "Priority alerts feed", stockMovement: "Units in vs out",
  categoryDonut: "Value by category", topSelling: "Best sellers list",
  lowStockPanel: "Low stock warnings", warehouseCapacity: "Capacity per site",
  recentActivity: "Live activity feed", purchaseVsSales: "Buy vs sell flow",
  demandForecast: "AI demand projection", fastMoving: "High velocity items",
  deadStock: "Slow / aging stock", warehousePulse: "Throughput per site",
  network: "Live distribution map", stockActivity: "Movement heatmap",
};

function WidgetGallery({ available, widgets, onAdd, onClose }) {
  return (
    <div style={{ position: "fixed", inset: 0, zIndex: 120, background: "rgba(12,14,20,0.5)",
      backdropFilter: "blur(6px)", display: "grid", placeItems: "center", padding: 24 }}
      onClick={onClose}>
      <div className="card" style={{ width: "min(720px, 100%)", maxHeight: "82vh", overflow: "hidden", display: "flex", flexDirection: "column", boxShadow: "var(--shadow-lg)" }}
        onClick={e => e.stopPropagation()}>
        <div className="card-head" style={{ paddingBottom: 14, borderBottom: "1px solid var(--line)" }}>
          <div><h3 style={{ fontSize: 17 }}>Add a Widget</h3><div className="sub">Drop new insights onto your dashboard</div></div>
          <div className="spacer" />
          <button className="icon-btn" onClick={onClose}><Icon name="x" /></button>
        </div>
        <div style={{ padding: 18, overflowY: "auto", display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(210px, 1fr))", gap: 12 }}>
          {available.length === 0 && (
            <div className="empty" style={{ gridColumn: "1 / -1", padding: 40 }}>
              <div className="ico"><Icon name="check" /></div>
              <h2>All widgets added</h2>
              <p>Every available widget is already on your dashboard. Remove one to free up space.</p>
            </div>
          )}
          {available.map(id => (
            <button key={id} onClick={() => onAdd(id)} style={{ textAlign: "left", padding: 14, borderRadius: 16,
              border: "1px solid var(--line)", background: "var(--surface-2)", display: "flex", flexDirection: "column", gap: 6, transition: "all 0.18s" }}
              onMouseEnter={e => { e.currentTarget.style.borderColor = "var(--amber)"; e.currentTarget.style.background = "var(--amber-tint)"; }}
              onMouseLeave={e => { e.currentTarget.style.borderColor = "var(--line)"; e.currentTarget.style.background = "var(--surface-2)"; }}>
              <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                <div className="metric-ico amber" style={{ width: 30, height: 30 }}><Icon name="box" size={15} /></div>
                <b style={{ fontSize: 13.5, fontWeight: 800 }}>{widgets[id].title}</b>
              </div>
              <span style={{ fontSize: 12.5, color: "var(--text-2)", fontWeight: 600 }}>{WIDGET_DESC[id] || ""}</span>
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}

window.Dashboard = Dashboard;
