/* SolaStock — dashboard widget definitions */
const { useState: dUseState } = React;

function MetricWidget({ icon, color, label, value, chip, chipDir, spark, sparkColor, foot }) {
  return (
    <div className="card" style={{ height: "100%" }}>
      <div className="metric">
        <div className="metric-top">
          <div className={"metric-ico " + color}><Icon name={icon} /></div>
          <div className="metric-label">{label}</div>
          {chip && <div style={{ marginLeft: "auto" }}>
            <span className={"chip " + (chipDir || "")}>
              {chipDir === "up" && <Icon name="arrowup" />}
              {chipDir === "down" && <Icon name="arrowdown" />}
              {chip}
            </span>
          </div>}
        </div>
        <div className="metric-val">{value}</div>
        <div className="metric-foot">
          {spark && <Sparkline values={spark} color={sparkColor || "var(--amber)"} width={110} height={34} />}
          {foot && <span className="muted">{foot}</span>}
        </div>
      </div>
    </div>
  );
}

function ListRow({ item, rightLabel, rightSub, onClick }) {
  return (
    <div className="row-item" onClick={onClick} style={{ cursor: onClick ? "pointer" : "default" }}>
      <div className="thumb"><Placeholder label="IMG" /></div>
      <div className="row-meta">
        <b>{item.name}</b>
        <span className="mono">{item.sku}</span>
      </div>
      <div className="row-val">
        {rightLabel}
        {rightSub && <div style={{ fontSize: 11.5, color: "var(--text-3)", fontWeight: 600 }}>{rightSub}</div>}
      </div>
    </div>
  );
}

function buildWidgets(DB, fmt, go) {
  const dimSpark = [3,5,4,6,5,7,6,8];
  return {
    invValue: { title: "Total Inventory Value", span: "w-3", render: () => (
      <MetricWidget icon="dollar" color="amber" label="Inventory Value" value={fmt.money(DB.STATS.inventoryValue)}
        chip="+4.2%" chipDir="up" foot="vs last month" spark={[388,401,419,408,422,420,425,429]} />
    )},
    lowStock: { title: "Low Stock Items", span: "w-3", render: () => (
      <MetricWidget icon="trenddown" color="amber" label="Low Stock Items" value={DB.STATS.lowStock}
        chip="6 urgent" chipDir="down" foot="below reorder point" spark={[22,26,24,30,28,33,35,37]} sparkColor="var(--amber)" />
    )},
    outStock: { title: "Out of Stock Items", span: "w-3", render: () => (
      <MetricWidget icon="ban" color="red" label="Out of Stock" value={DB.STATS.outOfStock}
        chip="+3" chipDir="down" foot="needs restock" spark={[5,7,6,9,8,10,11,12]} sparkColor="var(--red)" />
    )},
    salesToday: { title: "Today's Sales Orders", span: "w-3", render: () => (
      <MetricWidget icon="so" color="green" label="Sales Orders Today" value={DB.STATS.salesToday}
        chip="+12%" chipDir="up" foot="42 shipped · 22 packing" spark={[40,48,44,52,58,55,60,64]} sparkColor="var(--green)" />
    )},
    pendingPO: { title: "Pending Purchase Orders", span: "w-3", render: () => (
      <MetricWidget icon="po" color="blue" label="Pending POs" value={DB.STATS.pendingPO}
        chip="3 arriving" chipDir="" foot="$84.2k in transit" spark={[12,14,13,16,15,17,18,18]} sparkColor="var(--blue)" />
    )},
    accuracy: { title: "Stock Accuracy", span: "w-3", render: () => (
      <MetricWidget icon="target" color="green" label="Stock Accuracy" value={DB.STATS.accuracy + "%"}
        chip="+0.6%" chipDir="up" foot="last cycle count" spark={[95.2,96,95.8,96.5,97,96.9,97.2,97.4]} sparkColor="var(--green)" />
    )},
    turnover: { title: "Inventory Turnover", span: "w-3", render: () => (
      <MetricWidget icon="refresh" color="violet" label="Inventory Turnover" value={DB.STATS.turnover + "×"}
        chip="+0.4" chipDir="up" foot="annualized rate" spark={[5.9,6.1,6,6.3,6.5,6.4,6.6,6.8]} sparkColor="var(--violet)" />
    )},

    valueTrend: { title: "Inventory Value Trend", span: "w-8", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Inventory Value Trend" sub="Total stock value · last 12 months">
          <span className="chip up"><Icon name="trendup" />+37% YoY</span>
        </CardHead>
        <div className="card-body">
          <AreaChart data={DB.valueTrend} color="var(--amber)" height={210} fmtY={(v) => fmt.moneyK(v)} />
        </div>
      </div>
    )},

    health: { title: "Stock Health Score", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Stock Health Score" sub="Composite index" />
        <div className="card-body" style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 14 }}>
          <Gauge value={DB.STATS.healthScore} label={DB.STATS.healthScore} sub="of 100" size={150} />
          <div style={{ display: "flex", gap: 18, fontSize: 12.5, fontWeight: 700, color: "var(--text-2)" }}>
            <span><span style={{ color: "var(--green)" }}>●</span> Availability 94%</span>
            <span><span style={{ color: "var(--amber)" }}>●</span> Freshness 81%</span>
          </div>
        </div>
      </div>
    )},

    aiIntelligence: { title: "AI Reorder Intelligence", span: "w-8", render: () => (
      <div className="ai-card" style={{ height: "100%" }}>
        <div className="ai-grain" />
        <div className="ai-head">
          <div className="ai-spark"><Icon name="sparkles" /></div>
          <div>
            <h3>Reorder Intelligence</h3>
            <div className="sub">SolaStock AI · updated 4 min ago</div>
          </div>
          <span className="ai-pill">3 suggestions</span>
        </div>
        <div className="ai-body">
          {DB.AI_SUGGESTIONS.map((s, i) => (
            <div className="ai-sugg" key={i}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div className="lead">{s.lead}</div>
                <div className="desc">{s.desc}</div>
                <div className="ai-conf">
                  <div className="track"><div className="fill" style={{ width: s.conf + "%" }} /></div>
                  <span className="pct">{s.conf}% confidence</span>
                </div>
              </div>
              <div className="ai-act">
                <button className="ai-btn go">Apply</button>
                <button className="ai-btn skip">Skip</button>
              </div>
            </div>
          ))}
        </div>
      </div>
    )},

    smartAlerts: { title: "Smart Alerts", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Smart Alerts" sub="Needs your attention">
          <span className="chip amber">{DB.SMART_ALERTS.length}</span>
        </CardHead>
        <div className="card-body" style={{ display: "flex", flexDirection: "column", gap: 10 }}>
          {DB.SMART_ALERTS.map((a, i) => {
            const tone = a.sev === "high" ? "red" : a.sev === "med" ? "amber" : "blue";
            return (
              <div key={i} style={{ display: "flex", gap: 12, alignItems: "center", padding: "11px 12px",
                background: "var(--surface-2)", borderRadius: 14, border: "1px solid var(--line-soft)" }}>
                <div className={"metric-ico " + tone} style={{ width: 34, height: 34 }}><Icon name={a.icon} size={17} /></div>
                <div style={{ fontSize: 13, fontWeight: 700, flex: 1 }}>{a.text}</div>
                <Icon name="chevright" size={16} style={{ color: "var(--text-3)" }} />
              </div>
            );
          })}
        </div>
      </div>
    )},

    stockMovement: { title: "Stock Movement", span: "w-6", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Stock Movement" sub="Units in vs out · 12 months">
          <div style={{ display: "flex", gap: 14, fontSize: 12, fontWeight: 700 }}>
            <span><span style={{ color: "var(--green)" }}>●</span> In</span>
            <span><span style={{ color: "var(--amber)" }}>●</span> Out</span>
          </div>
        </CardHead>
        <div className="card-body">
          <BarChart data={DB.movementSeries} keys={["in", "out"]} colors={["var(--green)", "var(--amber)"]} height={200} />
        </div>
      </div>
    )},

    categoryDonut: { title: "Category Breakdown", span: "w-6", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Category Breakdown" sub="Inventory value by category" />
        <div className="card-body" style={{ display: "flex", gap: 24, alignItems: "center", flexWrap: "wrap" }}>
          <DonutChart data={DB.categoryBreakdown} size={168} />
          <div className="donut-legend" style={{ flex: 1, minWidth: 160 }}>
            {DB.categoryBreakdown.map((c, i) => (
              <div className="lg" key={i}>
                <span className="sw" style={{ background: c.color }} />
                <span className="nm">{c.name}</span>
                <span className="vl">{c.pct}%</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    )},

    topSelling: { title: "Top Selling Items", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Top Selling Items" sub="Last 30 days">
          <Icon name="flame" size={17} style={{ color: "var(--amber)" }} />
        </CardHead>
        <div className="card-body" style={{ paddingTop: 4 }}>
          {DB.topSelling.map((it, i) => (
            <ListRow key={it.id} item={it} rightLabel={it.sold30 + " sold"} rightSub={fmt.money(it.price)} onClick={() => go("item", it.id)} />
          ))}
        </div>
      </div>
    )},

    lowStockPanel: { title: "Low Stock Warnings", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Low Stock Warnings" sub="Below reorder point">
          <button className="btn sm ghost" style={{ color: "var(--amber-strong)" }} onClick={() => go("items")}>View all</button>
        </CardHead>
        <div className="card-body" style={{ paddingTop: 4 }}>
          {DB.lowStockItems.map((it) => (
            <div className="row-item" key={it.id} onClick={() => go("item", it.id)} style={{ cursor: "pointer" }}>
              <div className="thumb"><Placeholder label="IMG" /></div>
              <div className="row-meta">
                <b>{it.name}</b>
                <span>{it.stock} left · reorder at {it.reorder}</span>
              </div>
              <StatusBadge status={it.status} />
            </div>
          ))}
        </div>
      </div>
    )},

    warehouseCapacity: { title: "Warehouse Capacity", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Warehouse Capacity" sub="Utilization" />
        <div className="card-body" style={{ display: "flex", flexDirection: "column", gap: 14, paddingTop: 8 }}>
          {DB.WAREHOUSES.map(w => {
            const tone = w.cap > 85 ? "var(--red)" : w.cap > 70 ? "var(--amber)" : "var(--green)";
            return (
              <div key={w.id}>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 6, fontSize: 13 }}>
                  <b style={{ fontWeight: 700 }}>{w.name}</b>
                  <span className="mono" style={{ fontWeight: 800, color: tone }}>{w.cap}%</span>
                </div>
                <div className="bar-track"><div className="bar-fill" style={{ width: w.cap + "%", background: tone }} /></div>
              </div>
            );
          })}
        </div>
      </div>
    )},

    recentActivity: { title: "Recent Stock Activity", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Recent Stock Activity" sub="Live feed">
          <span className="chip"><span style={{ width: 7, height: 7, borderRadius: 99, background: "var(--green)", display: "inline-block" }} /> Live</span>
        </CardHead>
        <div className="card-body">
          <div className="timeline">
            {DB.ACTIVITIES.slice(0, 5).map((a, i) => {
              const m = { in: ["green", "arrowdown"], out: ["amber", "arrowup"], adjust: ["violet", "adjustments"], transfer: ["blue", "transfers"] }[a.type];
              return (
                <div className="tl-item" key={i}>
                  <div className="tl-rail">
                    <div className={"tl-dot metric-ico " + m[0]}><Icon name={m[1]} /></div>
                    <div className="tl-line" />
                  </div>
                  <div className="tl-body">
                    <div className="t">{a.who}</div>
                    <div className="d">{a.what}</div>
                    <div className="time">{a.time} · {a.wh}</div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>
    )},

    purchaseVsSales: { title: "Purchase vs Sales Flow", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Purchase vs Sales" sub="Order flow · 6 months">
          <div style={{ display: "flex", gap: 12, fontSize: 12, fontWeight: 700 }}>
            <span><span style={{ color: "var(--blue)" }}>●</span> Buy</span>
            <span><span style={{ color: "var(--amber)" }}>●</span> Sell</span>
          </div>
        </CardHead>
        <div className="card-body">
          <FlowChart data={DB.purchaseVsSales} height={160} />
          <div style={{ display: "flex", justifyContent: "space-around", marginTop: 8 }}>
            <div style={{ textAlign: "center" }}>
              <div style={{ fontSize: 20, fontWeight: 800 }}>$214k</div>
              <div style={{ fontSize: 11.5, color: "var(--text-2)", fontWeight: 700 }}>Purchased</div>
            </div>
            <div style={{ textAlign: "center" }}>
              <div style={{ fontSize: 20, fontWeight: 800 }}>$298k</div>
              <div style={{ fontSize: 11.5, color: "var(--text-2)", fontWeight: 700 }}>Sold</div>
            </div>
          </div>
        </div>
      </div>
    )},

    demandForecast: { title: "Demand Forecast", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Demand Forecast" sub="Next 30 days · AI projected">
          <span className="chip amber"><Icon name="sparkles" size={13} />AI</span>
        </CardHead>
        <div className="card-body">
          <AreaChart data={DB.months.slice(6).map((m, i) => ({ x: m, y: [180,210,240,260,290,330][i] }))} color="var(--violet)" height={150} fmtY={v => v + " units"} />
          <div style={{ fontSize: 12.5, color: "var(--text-2)", fontWeight: 600, marginTop: 6 }}>
            Projected demand up <b style={{ color: "var(--violet)" }}>+22%</b> driven by Electronics & Sports.
          </div>
        </div>
      </div>
    )},

    fastMoving: { title: "Fast Moving Items", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Fast Moving Items" sub="High velocity">
          <Icon name="zap" size={17} style={{ color: "var(--amber)" }} />
        </CardHead>
        <div className="card-body" style={{ paddingTop: 4 }}>
          {DB.fastMovingItems.map(it => (
            <ListRow key={it.id} item={it} rightLabel={<span style={{ color: "var(--green)" }}>{it.velocity}%</span>} rightSub="velocity" onClick={() => go("item", it.id)} />
          ))}
        </div>
      </div>
    )},

    deadStock: { title: "Slow / Dead Stock", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Slow / Dead Stock" sub="Low velocity · aging">
          <span className="chip down">{DB.STATS.deadStock} items</span>
        </CardHead>
        <div className="card-body" style={{ paddingTop: 4 }}>
          {DB.deadStockItems.map(it => (
            <ListRow key={it.id} item={it} rightLabel={<span style={{ color: "var(--red)" }}>{it.velocity}%</span>} rightSub="velocity" onClick={() => go("item", it.id)} />
          ))}
        </div>
      </div>
    )},

    warehousePulse: { title: "Warehouse Pulse", span: "w-4", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Warehouse Pulse" sub="Throughput today">
          <Icon name="pulse" size={17} style={{ color: "var(--green)" }} />
        </CardHead>
        <div className="card-body" style={{ display: "flex", flexDirection: "column", gap: 12 }}>
          {DB.WAREHOUSES.slice(0, 4).map((w, i) => (
            <div key={w.id} style={{ display: "flex", alignItems: "center", gap: 12 }}>
              <span className="mono" style={{ fontSize: 11, fontWeight: 800, color: "var(--text-3)", width: 42 }}>{w.id}</span>
              <Sparkline values={[3,5,4,7,6,9,7,10,8,11].map(v => v + i)} color={i % 2 ? "var(--green)" : "var(--amber)"} width={120} height={28} fill={false} />
              <span style={{ marginLeft: "auto", fontSize: 13, fontWeight: 800 }}>{[420,318,256,142][i]}</span>
            </div>
          ))}
        </div>
      </div>
    )},

    network: { title: "Distribution Network", span: "w-7", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Distribution Network" sub="5 sites · 318 transfers in flight">
          <span className="chip"><span style={{ width: 7, height: 7, borderRadius: 99, background: "var(--green)", display: "inline-block" }} /> Live</span>
        </CardHead>
        <div className="card-body"><NetworkMap warehouses={DB.WAREHOUSES} active={DB.WAREHOUSES[0].id} setActive={() => {}} /></div>
      </div>
    )},

    stockActivity: { title: "Stock Activity", span: "w-5", render: () => (
      <div className="card" style={{ height: "100%" }}>
        <CardHead title="Stock Activity" sub="Daily movement intensity · 18 weeks" />
        <div className="card-body" style={{ display: "flex", flexDirection: "column", gap: 16 }}>
          <CalendarHeatmap weeks={18} />
          <div style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 11.5, color: "var(--text-2)", fontWeight: 700, marginTop: "auto" }}>
            <span>Less</span>
            {["var(--surface-3)","color-mix(in srgb, var(--amber) 25%, var(--surface-3))","color-mix(in srgb, var(--amber) 50%, var(--surface-3))","color-mix(in srgb, var(--amber) 75%, var(--surface-3))","var(--amber)"].map((c,i)=>(
              <span key={i} style={{ width: 13, height: 13, borderRadius: 3.5, background: c }} />
            ))}
            <span>More</span>
            <span style={{ marginLeft: "auto" }}>Peak: <b style={{ color: "var(--text)" }}>Mondays</b></span>
          </div>
        </div>
      </div>
    )},
  };
}

window.buildWidgets = buildWidgets;
window.MetricWidget = MetricWidget;
