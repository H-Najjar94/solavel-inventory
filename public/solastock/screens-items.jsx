/* SolaStock — Items List + Item Details */
const { useState: iUseState, useMemo: iUseMemo } = React;

function FilterSelect({ label, icon, options, value, onChange }) {
  const [open, setOpen] = iUseState(false);
  const ref = React.useRef();
  React.useEffect(() => {
    const h = e => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener("mousedown", h);
    return () => document.removeEventListener("mousedown", h);
  }, []);
  const active = value && value !== "All";
  return (
    <div style={{ position: "relative" }} ref={ref}>
      <button className={"select" + (active ? " on" : "")} onClick={() => setOpen(o => !o)}>
        {icon && <Icon name={icon} />}
        <span>{active ? value : label}</span>
        <Icon name="chevdown" size={14} />
      </button>
      {open && (
        <div style={{ position: "absolute", top: 46, left: 0, zIndex: 50, background: "var(--surface)",
          border: "1px solid var(--line)", borderRadius: 13, boxShadow: "var(--shadow-lg)", padding: 6, minWidth: 180, maxHeight: 280, overflowY: "auto" }}>
          {["All", ...options].map(o => (
            <button key={o} className="nav-item" style={{ color: value === o ? "var(--amber-strong)" : "var(--text)", padding: "8px 11px" }}
              onClick={() => { onChange(o); setOpen(false); }}>
              <span style={{ fontWeight: 700, fontSize: 13.5 }}>{o}</span>
              {value === o && <Icon name="check" size={15} style={{ marginLeft: "auto", color: "var(--amber-strong)" }} />}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function ItemsList({ go, toast }) {
  const DB = window.DB, fmt = window.fmt;
  const [q, setQ] = iUseState("");
  const [cat, setCat] = iUseState("All");
  const [wh, setWh] = iUseState("All");
  const [status, setStatus] = iUseState("All");
  const [supplier, setSupplier] = iUseState("All");
  const [sel, setSel] = iUseState(new Set());
  const [view, setView] = iUseState("table");

  const filtered = iUseMemo(() => DB.ITEMS.filter(it => {
    if (q && !(it.name.toLowerCase().includes(q.toLowerCase()) || it.sku.toLowerCase().includes(q.toLowerCase()))) return false;
    if (cat !== "All" && it.category !== cat) return false;
    if (status !== "All" && it.status !== ({ "In stock": "ok", "Low stock": "low", "Out of stock": "out" }[status])) return false;
    if (supplier !== "All" && it.supplier !== supplier) return false;
    if (wh !== "All" && !it.warehouses.some(w => w.name === wh)) return false;
    return true;
  }), [q, cat, wh, status, supplier]);

  const allSel = filtered.length > 0 && filtered.every(it => sel.has(it.id));
  function toggle(id) { setSel(p => { const n = new Set(p); n.has(id) ? n.delete(id) : n.add(id); return n; }); }
  function toggleAll() { setSel(allSel ? new Set() : new Set(filtered.map(i => i.id))); }

  return (
    <Page>
      <div className="page-head">
        <div>
          <h1 className="page-title">Items</h1>
          <p className="page-sub">{fmt.num(DB.STATS.totalItems)} SKUs · {DB.STATS.lowStock} low · {DB.STATS.outOfStock} out of stock</p>
        </div>
        <div className="head-actions">
          <button className="btn"><Icon name="upload" size={17} />Import</button>
          <button className="btn"><Icon name="download" size={17} />Export</button>
          <button className="btn primary" onClick={() => toast("New item form")}><Icon name="plus" size={18} />New Item</button>
        </div>
      </div>

      <div className="filters">
        <div className="searchbar" style={{ maxWidth: 320, height: 40 }}>
          <Icon name="barcode" />
          <input placeholder="Search name or scan SKU…" value={q} onChange={e => setQ(e.target.value)} />
        </div>
        <FilterSelect label="Category" icon="layers" options={DB.CATEGORIES.map(c => c.name)} value={cat} onChange={setCat} />
        <FilterSelect label="Warehouse" icon="warehouse" options={DB.WAREHOUSES.map(w => w.name)} value={wh} onChange={setWh} />
        <FilterSelect label="Stock Status" icon="filter" options={["In stock", "Low stock", "Out of stock"]} value={status} onChange={setStatus} />
        <FilterSelect label="Supplier" icon="suppliers" options={DB.SUPPLIERS} value={supplier} onChange={setSupplier} />
        <div style={{ flex: 1 }} />
        <div className="seg">
          <button className={view === "table" ? "on" : ""} onClick={() => setView("table")}>Table</button>
          <button className={view === "cards" ? "on" : ""} onClick={() => setView("cards")}>Cards</button>
        </div>
      </div>

      {sel.size > 0 && (
        <div className="bulkbar">
          <b>{sel.size} selected</b>
          <button className="bb-btn"><Icon name="edit" />Edit</button>
          <button className="bb-btn"><Icon name="transfers" />Transfer</button>
          <button className="bb-btn"><Icon name="po" />Reorder</button>
          <button className="bb-btn"><Icon name="download" />Export</button>
          <div style={{ flex: 1 }} />
          <button className="bb-btn" onClick={() => setSel(new Set())}><Icon name="x" />Clear</button>
        </div>
      )}

      {view === "table" ? (
        <div className="card">
          <div className="tbl-wrap">
            <table className="tbl">
              <thead>
                <tr>
                  <th style={{ width: 36 }}><div className={"check" + (allSel ? " on" : "")} onClick={toggleAll}><Icon name="check" /></div></th>
                  <th>Item / SKU</th>
                  <th>Category</th>
                  <th style={{ textAlign: "right" }}>Stock</th>
                  <th style={{ textAlign: "right" }}>Committed</th>
                  <th style={{ textAlign: "right" }}>Available</th>
                  <th>Warehouse</th>
                  <th style={{ textAlign: "right" }}>Reorder</th>
                  <th>Status</th>
                  <th style={{ textAlign: "right" }}>Value</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map(it => (
                  <tr key={it.id} className={sel.has(it.id) ? "sel" : ""} onClick={() => go("item", it.id)}>
                    <td onClick={e => { e.stopPropagation(); toggle(it.id); }}>
                      <div className={"check" + (sel.has(it.id) ? " on" : "")}><Icon name="check" /></div>
                    </td>
                    <td>
                      <div className="tname">
                        <div className="thumb" style={{ width: 36, height: 36 }}><Placeholder label="IMG" /></div>
                        <div><b>{it.name}</b><div className="sku">{it.sku}</div></div>
                      </div>
                    </td>
                    <td><span className="chip" style={{ color: it.catColor, background: "transparent", borderColor: "var(--line)" }}>
                      <span style={{ width: 7, height: 7, borderRadius: 99, background: it.catColor }} />{it.category}</span></td>
                    <td className="num" style={{ textAlign: "right" }}>{it.stock}</td>
                    <td className="num" style={{ textAlign: "right", color: "var(--text-2)" }}>{it.committed}</td>
                    <td className="num" style={{ textAlign: "right" }}>{it.available}</td>
                    <td><span style={{ fontSize: 12.5, color: "var(--text-2)", fontWeight: 600 }}>{it.warehouses.length} sites</span></td>
                    <td className="num" style={{ textAlign: "right", color: "var(--text-3)" }}>{it.reorder}</td>
                    <td><StatusBadge status={it.status} /></td>
                    <td className="num" style={{ textAlign: "right" }}>{fmt.money(it.stock * it.cost)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {filtered.length === 0 && (
            <div className="empty"><div className="ico"><Icon name="search" /></div><h2>No items match</h2><p>Try adjusting your filters or search term.</p></div>
          )}
        </div>
      ) : (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(260px, 1fr))", gap: 16 }}>
          {filtered.map(it => (
            <div className="card card-pad" key={it.id} onClick={() => go("item", it.id)} style={{ cursor: "pointer" }}>
              <div style={{ display: "flex", gap: 12, alignItems: "flex-start" }}>
                <div className="thumb lg"><Placeholder label="PRODUCT" /></div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <b style={{ fontSize: 14.5, fontWeight: 800, display: "block" }}>{it.name}</b>
                  <span className="mono" style={{ fontSize: 11, color: "var(--text-3)" }}>{it.sku}</span>
                </div>
                <StatusBadge status={it.status} />
              </div>
              <div style={{ display: "flex", justifyContent: "space-between", marginTop: 16, paddingTop: 14, borderTop: "1px solid var(--line-soft)" }}>
                <div><div style={{ fontSize: 11.5, color: "var(--text-3)", fontWeight: 700 }}>STOCK</div><div style={{ fontSize: 18, fontWeight: 800 }}>{it.stock}</div></div>
                <div><div style={{ fontSize: 11.5, color: "var(--text-3)", fontWeight: 700 }}>AVAILABLE</div><div style={{ fontSize: 18, fontWeight: 800 }}>{it.available}</div></div>
                <div><div style={{ fontSize: 11.5, color: "var(--text-3)", fontWeight: 700 }}>VALUE</div><div style={{ fontSize: 18, fontWeight: 800 }}>{fmt.moneyK(it.stock * it.cost)}</div></div>
              </div>
            </div>
          ))}
        </div>
      )}
    </Page>
  );
}

function ItemDetail({ go, itemId, toast }) {
  const DB = window.DB, fmt = window.fmt;
  const item = DB.ITEMS.find(i => i.id === itemId) || DB.ITEMS[0];
  const [tab, setTab] = iUseState("movement");
  const profit = (item.price - item.cost);
  const tabs = [
    { id: "movement", label: "Movement History" },
    { id: "purchase", label: "Purchase History" },
    { id: "sales", label: "Sales History" },
    { id: "reorder", label: "Reorder Rules" },
  ];

  return (
    <Page>
      <div className="page-head">
        <div>
          <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 6, fontSize: 13, fontWeight: 700, color: "var(--text-2)" }}>
            <button className="btn ghost sm" style={{ padding: 0, height: "auto" }} onClick={() => go("items")}>Items</button>
            <Icon name="chevright" size={14} style={{ color: "var(--text-3)" }} />
            <span className="mono" style={{ fontSize: 12, color: "var(--text-3)" }}>{item.sku}</span>
          </div>
          <h1 className="page-title">{item.name}</h1>
        </div>
        <div className="head-actions">
          <button className="btn"><Icon name="edit" size={17} />Edit</button>
          <button className="btn"><Icon name="transfers" size={17} />Transfer</button>
          <button className="btn primary" onClick={() => toast("Reorder PO drafted")}><Icon name="po" size={17} />Reorder</button>
        </div>
      </div>

      <div className="detail-grid">
        {/* Left column */}
        <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
          <div className="card card-pad">
            <div className="thumb" style={{ width: "100%", height: 240, borderRadius: 18, marginBottom: 14 }}><Placeholder label="PRODUCT SHOT" /></div>
            <div style={{ display: "flex", gap: 8 }}>
              {[1,2,3].map(i => <div key={i} className="thumb" style={{ flex: 1, height: 56 }}><Placeholder label="" /></div>)}
            </div>
          </div>
          <div className="card">
            <CardHead title="Overview" />
            <div className="card-body" style={{ paddingTop: 4 }}>
              <div className="kv"><span className="k">SKU</span><span className="v mono" style={{ fontSize: 12.5 }}>{item.sku}</span></div>
              <div className="kv"><span className="k">Category</span><span className="v">{item.category}</span></div>
              <div className="kv"><span className="k">Status</span><StatusBadge status={item.status} /></div>
              <div className="kv"><span className="k">Total stock</span><span className="v">{item.stock}</span></div>
              <div className="kv"><span className="k">Committed</span><span className="v">{item.committed}</span></div>
              <div className="kv"><span className="k">Available</span><span className="v" style={{ color: "var(--green)" }}>{item.available}</span></div>
              <div className="kv"><span className="k">Reorder point</span><span className="v">{item.reorder}</span></div>
            </div>
          </div>
          <div className="card">
            <CardHead title="Related Suppliers" />
            <div className="card-body" style={{ paddingTop: 6 }}>
              {[item.supplier, DB.SUPPLIERS[(DB.SUPPLIERS.indexOf(item.supplier)+2)%DB.SUPPLIERS.length]].map((s, i) => (
                <div className="row-item" key={s}>
                  <div className="metric-ico blue" style={{ width: 36, height: 36 }}><Icon name="suppliers" size={17} /></div>
                  <div className="row-meta"><b>{s}</b><span>{i === 0 ? "Primary · " : "Backup · "}lead time {3 + i * 4}d</span></div>
                  <span className="chip">{fmt.money(item.cost)}</span>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Right column */}
        <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
          {/* margin summary */}
          <div className="grid" style={{ gap: 16 }}>
            <div className="w-3"><MetricWidget icon="dollar" color="green" label="Selling Price" value={fmt.money(item.price, 2)} foot="per unit" /></div>
            <div className="w-3"><MetricWidget icon="po" color="blue" label="Unit Cost" value={fmt.money(item.cost, 2)} foot="avg landed" /></div>
            <div className="w-3"><MetricWidget icon="trendup" color="amber" label="Margin" value={item.margin + "%"} chip={fmt.money(profit,2)} chipDir="up" foot="per unit profit" /></div>
            <div className="w-3"><MetricWidget icon="package" color="violet" label="Stock Value" value={fmt.moneyK(item.stock * item.cost)} foot={item.stock + " units"} /></div>
          </div>

          {/* stock by warehouse */}
          <div className="card">
            <CardHead title="Stock by Warehouse" sub={item.warehouses.length + " locations"} />
            <div className="card-body" style={{ display: "flex", flexDirection: "column", gap: 14, paddingTop: 8 }}>
              {item.warehouses.map(w => {
                const max = Math.max(...item.warehouses.map(x => x.qty), 1);
                return (
                  <div key={w.id}>
                    <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 6, fontSize: 13 }}>
                      <b style={{ fontWeight: 700 }}>{w.name} <span className="mono" style={{ color: "var(--text-3)", fontSize: 11 }}>{w.id}</span></b>
                      <span style={{ fontWeight: 800 }}>{w.qty} units</span>
                    </div>
                    <div className="bar-track"><div className="bar-fill" style={{ width: (w.qty / max * 100) + "%", background: "var(--amber)" }} /></div>
                  </div>
                );
              })}
            </div>
          </div>

          {/* tabs */}
          <div className="card">
            <div style={{ padding: "4px 20px 0" }}>
              <div className="tabs">
                {tabs.map(t => <button key={t.id} className={tab === t.id ? "on" : ""} onClick={() => setTab(t.id)}>{t.label}</button>)}
              </div>
            </div>
            <div className="card-body">
              {tab === "movement" && (
                <div className="timeline">
                  {[
                    ["in", "Received from PO-2041", "+340 units", "Jun 09 · WH-01"],
                    ["out", "Shipped SO-8870", "−64 units", "Jun 09 · WH-02"],
                    ["adjust", "Cycle count adjustment", "−3 units", "Jun 07 · WH-01"],
                    ["transfer", "Transferred to WH-04", "120 units", "Jun 05 · WH-03"],
                    ["in", "Received from PO-2033", "+200 units", "Jun 01 · WH-01"],
                  ].map((a, i) => {
                    const m = { in: ["green", "arrowdown"], out: ["amber", "arrowup"], adjust: ["violet", "adjustments"], transfer: ["blue", "transfers"] }[a[0]];
                    return (
                      <div className="tl-item" key={i}>
                        <div className="tl-rail"><div className={"tl-dot metric-ico " + m[0]}><Icon name={m[1]} /></div><div className="tl-line" /></div>
                        <div className="tl-body"><div className="t">{a[1]}</div><div className="d">{a[2]}</div><div className="time">{a[3]}</div></div>
                      </div>
                    );
                  })}
                </div>
              )}
              {tab === "purchase" && (
                <table className="tbl"><thead><tr><th>PO</th><th>Supplier</th><th>Date</th><th style={{textAlign:"right"}}>Qty</th><th style={{textAlign:"right"}}>Cost</th></tr></thead>
                <tbody>{[["PO-2041","Nordic Components","Jun 09",340],["PO-2033","Nordic Components","Jun 01",200],["PO-2018","Vertex Trading Co.","May 18",150]].map((r,i)=>(
                  <tr key={i}><td className="mono" style={{fontSize:12,fontWeight:700}}>{r[0]}</td><td>{r[1]}</td><td>{r[2]}</td><td className="num" style={{textAlign:"right"}}>{r[3]}</td><td className="num" style={{textAlign:"right"}}>{fmt.money(r[3]*item.cost)}</td></tr>
                ))}</tbody></table>
              )}
              {tab === "sales" && (
                <table className="tbl"><thead><tr><th>SO</th><th>Customer</th><th>Date</th><th style={{textAlign:"right"}}>Qty</th><th style={{textAlign:"right"}}>Revenue</th></tr></thead>
                <tbody>{[["SO-8870","Bright Retail Co.","Jun 09",64],["SO-8861","Peak Sports","Jun 08",28],["SO-8840","Tech Haven","Jun 05",45]].map((r,i)=>(
                  <tr key={i}><td className="mono" style={{fontSize:12,fontWeight:700}}>{r[0]}</td><td>{r[1]}</td><td>{r[2]}</td><td className="num" style={{textAlign:"right"}}>{r[3]}</td><td className="num" style={{textAlign:"right"}}>{fmt.money(r[3]*item.price)}</td></tr>
                ))}</tbody></table>
              )}
              {tab === "reorder" && (
                <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
                  <div style={{ display: "flex", gap: 12, padding: 16, borderRadius: 14, background: "var(--amber-tint)", border: "1px solid var(--amber)" }}>
                    <div className="ai-spark" style={{ width: 34, height: 34 }}><Icon name="sparkles" size={16} /></div>
                    <div><div style={{ fontWeight: 800, fontSize: 13.5, color: "var(--amber-strong)" }}>Auto-reorder enabled</div>
                      <div style={{ fontSize: 12.5, color: "var(--text-2)", fontWeight: 600, marginTop: 2 }}>AI drafts a PO when available stock falls below the reorder point.</div></div>
                  </div>
                  <div className="kv"><span className="k">Reorder point</span><span className="v">{item.reorder} units</span></div>
                  <div className="kv"><span className="k">Reorder quantity</span><span className="v">{item.reorder * 4} units</span></div>
                  <div className="kv"><span className="k">Preferred supplier</span><span className="v">{item.supplier}</span></div>
                  <div className="kv"><span className="k">Lead time</span><span className="v">5 days</span></div>
                  <div className="kv"><span className="k">Safety stock</span><span className="v">{Math.round(item.reorder * 0.5)} units</span></div>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </Page>
  );
}

window.ItemsList = ItemsList;
window.ItemDetail = ItemDetail;
