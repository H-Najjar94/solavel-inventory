/* SolaStock — Purchase Orders + Stock Movement */
const { useState: oUseState } = React;

function POStatusBadge({ status }) {
  const map = {
    Draft: ["info", "Draft"], Sent: ["low", "Sent"], Partial: ["low", "Partial"],
    Received: ["ok", "Received"], Cancelled: ["out", "Cancelled"],
  };
  const [cls, label] = map[status] || ["info", status];
  return <span className={"badge " + cls}><span className="led" />{label}</span>;
}

function PurchaseOrders({ go, toast }) {
  const DB = window.DB, fmt = window.fmt;
  const [filter, setFilter] = oUseState("All");
  const filters = ["All", "Draft", "Sent", "Partial", "Received"];
  const rows = DB.PURCHASE_ORDERS.filter(p => filter === "All" || p.status === filter);
  const pending = DB.PURCHASE_ORDERS.filter(p => p.status !== "Received").length;
  const inTransit = DB.PURCHASE_ORDERS.filter(p => p.status === "Sent" || p.status === "Partial").reduce((s, p) => s + p.total, 0);

  return (
    <Page>
      <div className="page-head">
        <div>
          <h1 className="page-title">Purchase Orders</h1>
          <p className="page-sub">{pending} open · {fmt.money(inTransit)} in transit</p>
        </div>
        <div className="head-actions">
          <button className="btn"><Icon name="download" size={17} />Export</button>
          <button className="btn primary" onClick={() => toast("New PO drafted")}><Icon name="plus" size={18} />New Purchase Order</button>
        </div>
      </div>

      <div className="grid" style={{ marginBottom: 4 }}>
        <div className="w-3"><MetricWidget icon="po" color="blue" label="Open POs" value={DB.STATS.pendingPO} foot="awaiting receipt" /></div>
        <div className="w-3"><MetricWidget icon="truck" color="amber" label="In Transit Value" value={fmt.moneyK(inTransit)} foot="6 shipments" /></div>
        <div className="w-3"><MetricWidget icon="check" color="green" label="Received (30d)" value="42" chip="+8%" chipDir="up" foot="on-time 94%" /></div>
        <div className="w-3"><MetricWidget icon="clock" color="violet" label="Avg Lead Time" value="5.2d" chip="−0.4d" chipDir="up" foot="faster than target" /></div>
      </div>

      <div className="filters" style={{ marginTop: 18 }}>
        <div className="seg">
          {filters.map(f => <button key={f} className={filter === f ? "on" : ""} onClick={() => setFilter(f)}>{f}</button>)}
        </div>
        <div style={{ flex: 1 }} />
        <div className="searchbar" style={{ maxWidth: 260, height: 40 }}><Icon name="search" /><input placeholder="Search PO or supplier…" /></div>
      </div>

      <div className="card">
        <div className="tbl-wrap">
          <table className="tbl">
            <thead><tr>
              <th>PO Number</th><th>Supplier</th><th>Order Date</th><th>Expected</th>
              <th style={{ textAlign: "right" }}>Items</th><th style={{ textAlign: "right" }}>Total</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
              {rows.map(p => (
                <tr key={p.id} onClick={() => toast("Open " + p.id)}>
                  <td className="mono" style={{ fontWeight: 800, fontSize: 12.5 }}>{p.id}</td>
                  <td><div className="tname"><div className="metric-ico blue" style={{ width: 32, height: 32 }}><Icon name="suppliers" size={15} /></div><b>{p.supplier}</b></div></td>
                  <td style={{ color: "var(--text-2)" }}>{p.date}</td>
                  <td style={{ color: "var(--text-2)" }}>{p.expected}</td>
                  <td className="num" style={{ textAlign: "right" }}>{p.items}</td>
                  <td className="num" style={{ textAlign: "right" }}>{fmt.money(p.total)}</td>
                  <td><POStatusBadge status={p.status} /></td>
                  <td style={{ textAlign: "right" }}><Icon name="chevright" size={16} style={{ color: "var(--text-3)" }} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </Page>
  );
}

function StockMovement({ go, toast }) {
  const DB = window.DB, fmt = window.fmt;
  const [range, setRange] = oUseState("12M");

  return (
    <Page>
      <div className="page-head">
        <div>
          <h1 className="page-title">Stock Movements</h1>
          <p className="page-sub">All inbound, outbound, transfers & adjustments</p>
        </div>
        <div className="head-actions">
          <div className="seg">
            {["7D", "30D", "12M"].map(r => <button key={r} className={range === r ? "on" : ""} onClick={() => setRange(r)}>{r}</button>)}
          </div>
          <button className="btn"><Icon name="download" size={17} />Export</button>
        </div>
      </div>

      <div className="grid">
        <div className="w-3"><MetricWidget icon="arrowdown" color="green" label="Units In" value="2,940" chip="+12%" chipDir="up" foot="this period" spark={[120,180,160,220,200,260,240,290]} sparkColor="var(--green)" /></div>
        <div className="w-3"><MetricWidget icon="arrowup" color="amber" label="Units Out" value="2,612" chip="+8%" chipDir="up" foot="this period" spark={[110,150,140,190,180,220,210,250]} /></div>
        <div className="w-3"><MetricWidget icon="transfers" color="blue" label="Transfers" value="318" foot="between sites" spark={[20,30,25,40,35,45,40,50]} sparkColor="var(--blue)" /></div>
        <div className="w-3"><MetricWidget icon="adjustments" color="violet" label="Adjustments" value="−47" chip="2.1%" chipDir="down" foot="shrinkage" spark={[8,6,9,5,7,4,6,5]} sparkColor="var(--violet)" /></div>

        <div className="w-8">
          <div className="card" style={{ height: "100%" }}>
            <CardHead title="Movement Volume" sub="Units in vs out over time">
              <div style={{ display: "flex", gap: 14, fontSize: 12, fontWeight: 700 }}>
                <span><span style={{ color: "var(--green)" }}>●</span> In</span>
                <span><span style={{ color: "var(--amber)" }}>●</span> Out</span>
              </div>
            </CardHead>
            <div className="card-body"><BarChart data={DB.movementSeries} keys={["in", "out"]} colors={["var(--green)", "var(--amber)"]} height={250} /></div>
          </div>
        </div>

        <div className="w-4">
          <div className="card" style={{ height: "100%" }}>
            <CardHead title="By Type" sub="Distribution" />
            <div className="card-body" style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 16 }}>
              <DonutChart size={150} data={[
                { name: "Inbound", color: "var(--green)", pct: 42 },
                { name: "Outbound", color: "var(--amber)", pct: 38 },
                { name: "Transfer", color: "var(--blue)", pct: 14 },
                { name: "Adjust", color: "var(--violet)", pct: 6 },
              ]} />
              <div className="donut-legend" style={{ width: "100%" }}>
                {[["Inbound","var(--green)","42%"],["Outbound","var(--amber)","38%"],["Transfer","var(--blue)","14%"],["Adjust","var(--violet)","6%"]].map(l=>(
                  <div className="lg" key={l[0]}><span className="sw" style={{background:l[1]}} /><span className="nm">{l[0]}</span><span className="vl">{l[2]}</span></div>
                ))}
              </div>
            </div>
          </div>
        </div>

        <div className="w-12">
          <div className="card">
            <CardHead title="Movement Log" sub="Recent transactions">
              <button className="btn sm ghost" style={{ color: "var(--amber-strong)" }}>View all</button>
            </CardHead>
            <div className="tbl-wrap">
              <table className="tbl">
                <thead><tr><th>Type</th><th>Reference</th><th>Item</th><th style={{textAlign:"right"}}>Qty</th><th>Warehouse</th><th>User</th><th style={{textAlign:"right"}}>Time</th></tr></thead>
                <tbody>
                  {[
                    ["in","PO-2041","Aurora Wireless Headphones","+340","WH-01","System","12 min ago"],
                    ["out","SO-8870","Multiple (8 items)","−64","WH-02","K. Reyes","38 min ago"],
                    ["adjust","ADJ-119","Nimbus Mechanical Keyboard","−3","WH-01","K. Reyes","1 hr ago"],
                    ["transfer","TR-310","Summit Running Shoes","120","WH-03→04","A. Cho","2 hr ago"],
                    ["in","PO-2039","Forge Cast Iron Pan","+90","WH-05","System","3 hr ago"],
                    ["out","SO-8861","Summit Running Shoes","−28","WH-01","K. Reyes","5 hr ago"],
                  ].map((r, i) => {
                    const m = { in: ["green", "arrowdown", "In"], out: ["amber", "arrowup", "Out"], adjust: ["violet", "adjustments", "Adjust"], transfer: ["blue", "transfers", "Transfer"] }[r[0]];
                    return (
                      <tr key={i}>
                        <td><span className={"badge"} style={{ background: "var(--surface-2)", color: `var(--${m[0] === "amber" ? "amber-strong" : m[0]})` }}>
                          <span className="metric-ico" style={{ width: 22, height: 22, background: "transparent" }}><Icon name={m[1]} size={13} /></span>{m[2]}</span></td>
                        <td className="mono" style={{ fontWeight: 700, fontSize: 12 }}>{r[1]}</td>
                        <td>{r[2]}</td>
                        <td className="num" style={{ textAlign: "right", color: r[3][0] === "+" ? "var(--green)" : r[3][0] === "−" ? "var(--red)" : "inherit" }}>{r[3]}</td>
                        <td className="mono" style={{ fontSize: 12, color: "var(--text-2)" }}>{r[4]}</td>
                        <td style={{ color: "var(--text-2)" }}>{r[5]}</td>
                        <td style={{ textAlign: "right", color: "var(--text-3)", fontSize: 12.5 }}>{r[6]}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </Page>
  );
}

window.PurchaseOrders = PurchaseOrders;
window.StockMovement = StockMovement;
