/* SolaStock — shared shell components */
const { useState, useEffect, useRef } = React;

const NAV = [
  { section: "Overview" },
  { id: "dashboard", label: "Dashboard", icon: "dashboard" },
  { id: "items", label: "Items", icon: "items" },
  { id: "warehouses", label: "Warehouses", icon: "warehouse" },
  { id: "movements", label: "Stock Movements", icon: "movement" },
  { section: "Operations" },
  { id: "purchase", label: "Purchase Orders", icon: "po", badge: 18 },
  { id: "sales", label: "Sales Orders", icon: "so", badge: 64 },
  { id: "suppliers", label: "Suppliers", icon: "suppliers" },
  { id: "customers", label: "Customers", icon: "customers" },
  { section: "Logistics" },
  { id: "transfers", label: "Transfers", icon: "transfers" },
  { id: "adjustments", label: "Adjustments", icon: "adjustments" },
  { section: "Insights" },
  { id: "reports", label: "Reports", icon: "reports" },
  { id: "settings", label: "Settings", icon: "settings" },
];

function Sidebar({ route, go, collapsed, setCollapsed, mobileOpen, setMobileOpen }) {
  return (
    <aside className={"sidebar" + (collapsed ? " collapsed" : "") + (mobileOpen ? " mobile-open" : "")}>
      <div className="side-brand">
        <div className="side-logo">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2.1" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z" />
            <path d="m3 7.5 9 4.5 9-4.5M12 12v9" />
          </svg>
        </div>
        <div className="side-brand-text">
          <b>SolaStock</b>
          <span>Inventory Command</span>
        </div>
      </div>

      <nav className="nav">
        {NAV.map((n, i) => n.section ? (
          <div className="side-section" key={"s" + i}>{n.section}</div>
        ) : (
          <button key={n.id} className={"nav-item" + (route === n.id ? " active" : "")}
            onClick={() => { go(n.id); setMobileOpen(false); }} title={n.label}>
            <Icon name={n.icon} />
            <span>{n.label}</span>
            {n.badge != null && <span className="nav-badge">{n.badge}</span>}
          </button>
        ))}
      </nav>

      <div className="side-foot">
        <button className="nav-item" onClick={() => setCollapsed(c => !c)} style={{ marginBottom: 8 }}>
          <Icon name={collapsed ? "chevright" : "chevleft"} />
          <span>Collapse</span>
        </button>
        <div className="side-user">
          <div className="av">JM</div>
          <div className="side-user-meta">
            <b>Jordan Mei</b>
            <span>Ops Manager</span>
          </div>
        </div>
      </div>
    </aside>
  );
}

function DateFilter() {
  const [open, setOpen] = useState(false);
  const ranges = ["Today", "Last 7 days", "Last 30 days", "This quarter", "Year to date"];
  const [sel, setSel] = useState("Last 30 days");
  const ref = useRef();
  useEffect(() => {
    const h = e => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener("mousedown", h);
    return () => document.removeEventListener("mousedown", h);
  }, []);
  return (
    <div style={{ position: "relative" }} ref={ref}>
      <button className="btn sm" onClick={() => setOpen(o => !o)} style={{ height: 42, borderRadius: 13 }}>
        <Icon name="calendar" size={17} /><span>{sel}</span><Icon name="chevdown" size={15} />
      </button>
      {open && (
        <div style={{ position: "absolute", top: 48, right: 0, zIndex: 50, background: "var(--surface)",
          border: "1px solid var(--line)", borderRadius: 14, boxShadow: "var(--shadow-lg)", padding: 6, minWidth: 180 }}>
          {ranges.map(r => (
            <button key={r} className="nav-item" style={{ color: sel === r ? "var(--amber-strong)" : "var(--text)", padding: "9px 12px" }}
              onClick={() => { setSel(r); setOpen(false); }}>
              <span style={{ fontWeight: 700 }}>{r}</span>
              {sel === r && <Icon name="check" size={16} style={{ marginLeft: "auto", color: "var(--amber-strong)" }} />}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function TopBar({ theme, setTheme, onMenu, title }) {
  return (
    <header className="topbar">
      <button className="icon-btn mobile-only" onClick={onMenu}><Icon name="menu" /></button>
      <div className="searchbar">
        <Icon name="search" />
        <input placeholder="Search items, SKU, suppliers…" />
        <kbd>⌘K</kbd>
      </div>
      <div style={{ flex: 1 }} />
      <DateFilter />
      <button className="btn primary" style={{ height: 42 }}>
        <Icon name="plus" size={18} /><span>New</span>
      </button>
      <button className="icon-btn" title="Notifications"><Icon name="bell" /><span className="dot" /></button>
      <button className="icon-btn" title="Toggle theme" onClick={() => setTheme(theme === "dark" ? "light" : "dark")}>
        <Icon name={theme === "dark" ? "sun" : "moon"} />
      </button>
    </header>
  );
}

function FabDock({ onAction }) {
  const [open, setOpen] = useState(false);
  const actions = [
    { id: "item", label: "New Item", icon: "package" },
    { id: "po", label: "Purchase Order", icon: "po" },
    { id: "transfer", label: "Stock Transfer", icon: "transfers" },
    { id: "scan", label: "Scan Barcode", icon: "scan" },
  ];
  return (
    <>
      <div className={"dock" + (open ? " open" : "")}>
        {actions.map(a => (
          <div className="dock-item" key={a.id}>
            <span className="lbl">{a.label}</span>
            <button className="db" onClick={() => { setOpen(false); onAction && onAction(a.label); }}><Icon name={a.icon} /></button>
          </div>
        ))}
      </div>
      <button className={"fab" + (open ? " open" : "")} onClick={() => setOpen(o => !o)} title="Quick actions">
        <Icon name="plus" />
      </button>
    </>
  );
}

function Toast({ msg }) {
  return (
    <div className={"toast" + (msg ? " show" : "")}>
      <Icon name="check" /><span>{msg}</span>
    </div>
  );
}

// generic card head
function CardHead({ title, sub, children }) {
  return (
    <div className="card-head">
      <div>
        <h3>{title}</h3>
        {sub && <div className="sub">{sub}</div>}
      </div>
      <div className="spacer" />
      {children}
    </div>
  );
}

function StatusBadge({ status }) {
  const map = { ok: ["ok", "In stock"], low: ["low", "Low stock"], out: ["out", "Out of stock"] };
  const [cls, label] = map[status] || ["ok", status];
  return <span className={"badge " + cls}><span className="led" />{label}</span>;
}

function Placeholder({ label, style }) {
  return <div className="ph" style={style}><span>{label}</span></div>;
}

// page wrapper with entrance
function Page({ children }) {
  return <div className="fade-up">{children}</div>;
}

Object.assign(window, { Sidebar, TopBar, FabDock, Toast, CardHead, StatusBadge, Placeholder, Page, NAV });
