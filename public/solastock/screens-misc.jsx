/* SolaStock — remaining nav pages */

function SalesOrders({ go, toast }) {
  const DB = window.DB, fmt = window.fmt;
  function soBadge(s){ const m={Processing:["info"],Packed:["low"],Shipped:["info"],Delivered:["ok"]}; return <span className={"badge "+(m[s]?m[s][0]:"info")}><span className="led"/>{s}</span>; }
  return (
    <Page>
      <div className="page-head">
        <div><h1 className="page-title">Sales Orders</h1><p className="page-sub">{DB.STATS.salesToday} orders today · 42 shipped</p></div>
        <div className="head-actions">
          <button className="btn"><Icon name="download" size={17} />Export</button>
          <button className="btn primary" onClick={() => toast("New sales order")}><Icon name="plus" size={18} />New Order</button>
        </div>
      </div>
      <div className="grid" style={{ marginBottom: 18 }}>
        <div className="w-3"><MetricWidget icon="so" color="green" label="Orders Today" value={DB.STATS.salesToday} chip="+12%" chipDir="up" foot="vs yesterday" /></div>
        <div className="w-3"><MetricWidget icon="dollar" color="amber" label="Revenue Today" value="$18.4k" chip="+9%" chipDir="up" foot="64 orders" /></div>
        <div className="w-3"><MetricWidget icon="truck" color="blue" label="To Ship" value="22" foot="packing now" /></div>
        <div className="w-3"><MetricWidget icon="check" color="violet" label="Fulfillment Rate" value="98.1%" chip="+0.3%" chipDir="up" foot="on time" /></div>
      </div>
      <div className="card">
        <div className="tbl-wrap"><table className="tbl">
          <thead><tr><th>Order</th><th>Customer</th><th style={{textAlign:"right"}}>Items</th><th style={{textAlign:"right"}}>Total</th><th>Status</th><th></th></tr></thead>
          <tbody>{DB.SALES_ORDERS.map(o => (
            <tr key={o.id} onClick={() => toast("Open " + o.id)}>
              <td className="mono" style={{ fontWeight: 800, fontSize: 12.5 }}>{o.id}</td>
              <td><div className="tname"><div className="metric-ico violet" style={{ width: 32, height: 32 }}><Icon name="customers" size={15} /></div><b>{o.customer}</b></div></td>
              <td className="num" style={{ textAlign: "right" }}>{o.items}</td>
              <td className="num" style={{ textAlign: "right" }}>{fmt.money(o.total)}</td>
              <td>{soBadge(o.status)}</td>
              <td style={{ textAlign: "right" }}><Icon name="chevright" size={16} style={{ color: "var(--text-3)" }} /></td>
            </tr>
          ))}</tbody>
        </table></div>
      </div>
    </Page>
  );
}

function PartnerGrid({ title, sub, kind, toast }) {
  const DB = window.DB, fmt = window.fmt;
  const list = kind === "supplier"
    ? DB.SUPPLIERS.map((s, i) => ({ name: s, meta: ["Austin, TX","Oakland, CA","Portland, OR","Dallas, TX","Denver, CO","Seattle, WA","Chicago, IL"][i], stat1: ["48","32","21","64","19","27","15"][i] + " SKUs", stat2: fmt.money([84200,62100,41800,98400,28600,52300,19400][i]), lead: [3,5,4,2,6,4,7][i] + "d lead" }))
    : ["Bright Retail Co.","Urban Outfit Ltd.","Peak Sports","Nest Living","Glow Beauty Bar","Tech Haven","Coastal Goods"].map((s, i) => ({ name: s, meta: ["Wholesale","Retail","Retail","Wholesale","Boutique","Online","Retail"][i], stat1: [142,86,64,38,52,210,29][i] + " orders", stat2: fmt.money([184200,92100,71800,38400,52300,310200,24400][i]), lead: "since 202" + (i % 5) }));
  return (
    <Page>
      <div className="page-head">
        <div><h1 className="page-title">{title}</h1><p className="page-sub">{sub}</p></div>
        <div className="head-actions"><button className="btn"><Icon name="upload" size={17} />Import</button><button className="btn primary" onClick={() => toast("Add " + kind)}><Icon name="plus" size={18} />Add {kind === "supplier" ? "Supplier" : "Customer"}</button></div>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(280px, 1fr))", gap: 16 }}>
        {list.map((p, i) => (
          <div className="card card-pad" key={i} style={{ cursor: "pointer" }} onClick={() => toast(p.name)}>
            <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 16 }}>
              <div className={"metric-ico " + (kind === "supplier" ? "blue" : "violet")} style={{ width: 46, height: 46, borderRadius: 14, fontSize: 16, fontWeight: 800 }}>{p.name.split(" ").slice(0,2).map(w=>w[0]).join("")}</div>
              <div style={{ flex: 1, minWidth: 0 }}><b style={{ fontSize: 14.5, fontWeight: 800, display: "block" }}>{p.name}</b><span style={{ fontSize: 12.5, color: "var(--text-2)", fontWeight: 600 }}>{p.meta} · {p.lead}</span></div>
            </div>
            <div style={{ display: "flex", justifyContent: "space-between", paddingTop: 14, borderTop: "1px solid var(--line-soft)" }}>
              <div><div style={{ fontSize: 11, color: "var(--text-3)", fontWeight: 700 }}>{kind === "supplier" ? "CATALOG" : "ORDERS"}</div><div style={{ fontWeight: 800 }}>{p.stat1}</div></div>
              <div><div style={{ fontSize: 11, color: "var(--text-3)", fontWeight: 700 }}>{kind === "supplier" ? "PURCHASED" : "LIFETIME"}</div><div style={{ fontWeight: 800 }}>{p.stat2}</div></div>
            </div>
          </div>
        ))}
      </div>
    </Page>
  );
}

function TransfersPage({ toast }) {
  const DB = window.DB, fmt = window.fmt;
  const rows = [
    ["TR-310","Summit Running Shoes","WH-03","WH-04",120,"In transit","low"],
    ["TR-309","Aurora Wireless Headphones","WH-01","WH-02",80,"Completed","ok"],
    ["TR-308","Forge Cast Iron Pan","WH-05","WH-01",45,"Completed","ok"],
    ["TR-307","Frost Merino Hoodie","WH-02","WH-04",60,"Draft","info"],
    ["TR-306","Echo Noise Earbuds","WH-01","WH-03",200,"In transit","low"],
  ];
  return (
    <Page>
      <div className="page-head">
        <div><h1 className="page-title">Transfers</h1><p className="page-sub">Move stock between your 5 warehouses</p></div>
        <div className="head-actions"><button className="btn primary" onClick={() => toast("New transfer")}><Icon name="plus" size={18} />New Transfer</button></div>
      </div>
      <div className="card"><div className="tbl-wrap"><table className="tbl">
        <thead><tr><th>Transfer</th><th>Item</th><th>From</th><th></th><th>To</th><th style={{textAlign:"right"}}>Qty</th><th>Status</th></tr></thead>
        <tbody>{rows.map((r,i)=>(
          <tr key={i} onClick={() => toast("Open " + r[0])}>
            <td className="mono" style={{ fontWeight: 800, fontSize: 12.5 }}>{r[0]}</td>
            <td><b>{r[1]}</b></td>
            <td className="mono" style={{ fontSize: 12.5, color: "var(--text-2)" }}>{r[2]}</td>
            <td style={{ color: "var(--amber)" }}><Icon name="movement" size={16} /></td>
            <td className="mono" style={{ fontSize: 12.5, color: "var(--text-2)" }}>{r[3]}</td>
            <td className="num" style={{ textAlign: "right" }}>{r[4]}</td>
            <td><span className={"badge " + r[6]}><span className="led" />{r[5]}</span></td>
          </tr>
        ))}</tbody>
      </table></div></div>
    </Page>
  );
}

function AdjustmentsPage({ toast }) {
  const rows = [
    ["ADJ-119","Nimbus Mechanical Keyboard","WH-01","−3","Damage","K. Reyes","Jun 09"],
    ["ADJ-118","Velvet Matte Lipstick","WH-02","−8","Expiry","A. Cho","Jun 08"],
    ["ADJ-117","Cascade Water Bottle 1L","WH-01","+12","Recount","K. Reyes","Jun 07"],
    ["ADJ-116","Tundra Down Jacket","WH-04","−5","Theft","M. Ito","Jun 05"],
    ["ADJ-115","Drift Ceramic Mug Set","WH-03","−2","Damage","A. Cho","Jun 04"],
  ];
  return (
    <Page>
      <div className="page-head">
        <div><h1 className="page-title">Adjustments</h1><p className="page-sub">Stock corrections, write-offs & cycle counts</p></div>
        <div className="head-actions"><button className="btn"><Icon name="scan" size={17} />Cycle Count</button><button className="btn primary" onClick={() => toast("New adjustment")}><Icon name="plus" size={18} />New Adjustment</button></div>
      </div>
      <div className="card"><div className="tbl-wrap"><table className="tbl">
        <thead><tr><th>Ref</th><th>Item</th><th>Warehouse</th><th style={{textAlign:"right"}}>Change</th><th>Reason</th><th>User</th><th style={{textAlign:"right"}}>Date</th></tr></thead>
        <tbody>{rows.map((r,i)=>(
          <tr key={i} onClick={() => toast("Open " + r[0])}>
            <td className="mono" style={{ fontWeight: 800, fontSize: 12.5 }}>{r[0]}</td>
            <td><b>{r[1]}</b></td>
            <td className="mono" style={{ fontSize: 12.5, color: "var(--text-2)" }}>{r[2]}</td>
            <td className="num" style={{ textAlign: "right", color: r[3][0] === "+" ? "var(--green)" : "var(--red)" }}>{r[3]}</td>
            <td><span className="chip">{r[4]}</span></td>
            <td style={{ color: "var(--text-2)" }}>{r[5]}</td>
            <td style={{ textAlign: "right", color: "var(--text-3)", fontSize: 12.5 }}>{r[6]}</td>
          </tr>
        ))}</tbody>
      </table></div></div>
    </Page>
  );
}

function ReportsPage({ toast }) {
  const reports = [
    ["Inventory Valuation", "Total stock value by category & warehouse", "reports", "amber"],
    ["Stock Movement Summary", "In/out flow across all locations", "movement", "green"],
    ["Reorder Report", "Items below reorder point", "trenddown", "red"],
    ["ABC Analysis", "Classify SKUs by value contribution", "layers", "blue"],
    ["Dead Stock Report", "Slow-moving & aging inventory", "clock", "violet"],
    ["Supplier Performance", "Lead times & fill rates", "suppliers", "blue"],
    ["Sales by Product", "Top performers & revenue", "trendup", "green"],
    ["Shrinkage & Adjustments", "Loss analysis over time", "adjustments", "amber"],
  ];
  return (
    <Page>
      <div className="page-head">
        <div><h1 className="page-title">Reports</h1><p className="page-sub">Generate & schedule inventory reports</p></div>
        <div className="head-actions"><button className="btn primary" onClick={() => toast("Custom report builder")}><Icon name="plus" size={18} />Custom Report</button></div>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(280px, 1fr))", gap: 16 }}>
        {reports.map((r, i) => (
          <div className="card card-pad" key={i} style={{ cursor: "pointer" }} onClick={() => toast("Generating: " + r[0])}>
            <div className={"metric-ico " + r[3]} style={{ width: 46, height: 46, borderRadius: 14, marginBottom: 14 }}><Icon name={r[2]} /></div>
            <b style={{ fontSize: 15, fontWeight: 800, display: "block", marginBottom: 5 }}>{r[0]}</b>
            <span style={{ fontSize: 13, color: "var(--text-2)", fontWeight: 600, lineHeight: 1.5 }}>{r[1]}</span>
            <div style={{ display: "flex", alignItems: "center", gap: 6, marginTop: 14, color: "var(--amber-strong)", fontWeight: 700, fontSize: 13 }}>
              Run report <Icon name="chevright" size={15} />
            </div>
          </div>
        ))}
      </div>
    </Page>
  );
}

function SettingsPage({ theme, setTheme, toast }) {
  const sections = [
    ["Organization", "Business profile, currency, timezone", "settings"],
    ["Warehouses", "Manage locations & zones", "warehouse"],
    ["Users & Roles", "Team access and permissions", "customers"],
    ["Reorder Automation", "AI auto-reorder rules & thresholds", "sparkles"],
    ["Integrations", "Shopify, accounting, shipping carriers", "transfers"],
    ["Notifications", "Alerts, digests & webhooks", "bell"],
  ];
  return (
    <Page>
      <div className="page-head"><div><h1 className="page-title">Settings</h1><p className="page-sub">Configure SolaStock for your business</p></div></div>
      <div className="detail-grid" style={{ gridTemplateColumns: "1fr 320px" }}>
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(280px, 1fr))", gap: 16, alignContent: "start" }}>
          {sections.map((s, i) => (
            <div className="card card-pad" key={i} style={{ cursor: "pointer" }} onClick={() => toast(s[0])}>
              <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
                <div className="metric-ico amber" style={{ width: 42, height: 42, borderRadius: 13 }}><Icon name={s[2]} /></div>
                <div style={{ flex: 1 }}><b style={{ fontSize: 14.5, fontWeight: 800, display: "block" }}>{s[0]}</b><span style={{ fontSize: 12.5, color: "var(--text-2)", fontWeight: 600 }}>{s[1]}</span></div>
                <Icon name="chevright" size={16} style={{ color: "var(--text-3)" }} />
              </div>
            </div>
          ))}
        </div>
        <div className="card">
          <CardHead title="Appearance" sub="Theme preference" />
          <div className="card-body" style={{ display: "flex", flexDirection: "column", gap: 10 }}>
            {[["light","Light","sun"],["dark","Dark","moon"]].map(t => (
              <button key={t[0]} className="select" style={{ height: 52, justifyContent: "flex-start", borderColor: theme === t[0] ? "var(--amber)" : "var(--line)", background: theme === t[0] ? "var(--amber-tint)" : "var(--surface)" }} onClick={() => setTheme(t[0])}>
                <Icon name={t[2]} size={18} /><span style={{ fontSize: 14 }}>{t[1]} mode</span>
                {theme === t[0] && <Icon name="check" size={16} style={{ marginLeft: "auto", color: "var(--amber-strong)" }} />}
              </button>
            ))}
          </div>
        </div>
      </div>
    </Page>
  );
}

Object.assign(window, { SalesOrders, PartnerGrid, TransfersPage, AdjustmentsPage, ReportsPage, SettingsPage });
