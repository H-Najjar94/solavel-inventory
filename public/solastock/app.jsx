/* SolaStock — app shell: routing, theme, mount */
const { useState: aUseState, useEffect: aUseEffect } = React;

function App() {
  // theme: match system default, persisted
  const [theme, setThemeState] = aUseState(() => {
    const saved = localStorage.getItem("solastock_theme");
    if (saved) return saved;
    return window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
  });
  function setTheme(t) { setThemeState(t); localStorage.setItem("solastock_theme", t); }
  aUseEffect(() => { document.documentElement.setAttribute("data-theme", theme); }, [theme]);

  // routing
  const [route, setRoute] = aUseState("dashboard");
  const [itemId, setItemId] = aUseState(null);
  const contentRef = React.useRef();
  function go(r, id) {
    if (r === "item") setItemId(id);
    setRoute(r);
    if (contentRef.current) contentRef.current.scrollTop = 0;
  }

  const [collapsed, setCollapsed] = aUseState(false);
  const [mobileOpen, setMobileOpen] = aUseState(false);

  // toast
  const [toastMsg, setToastMsg] = aUseState("");
  const toastTimer = React.useRef();
  function toast(msg) {
    setToastMsg(msg);
    clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToastMsg(""), 2400);
  }

  let screen;
  switch (route) {
    case "dashboard": screen = <Dashboard go={go} toast={toast} />; break;
    case "items": screen = <ItemsList go={go} toast={toast} />; break;
    case "item": screen = <ItemDetail go={go} itemId={itemId} toast={toast} />; break;
    case "warehouses": screen = <WarehouseOverview go={go} toast={toast} />; break;
    case "movements": screen = <StockMovement go={go} toast={toast} />; break;
    case "purchase": screen = <PurchaseOrders go={go} toast={toast} />; break;
    case "sales": screen = <SalesOrders go={go} toast={toast} />; break;
    case "suppliers": screen = <PartnerGrid title="Suppliers" sub="7 active vendors · $387k purchased YTD" kind="supplier" toast={toast} />; break;
    case "customers": screen = <PartnerGrid title="Customers" sub="Wholesale & retail accounts" kind="customer" toast={toast} />; break;
    case "transfers": screen = <TransfersPage toast={toast} />; break;
    case "adjustments": screen = <AdjustmentsPage toast={toast} />; break;
    case "reports": screen = <ReportsPage toast={toast} />; break;
    case "settings": screen = <SettingsPage theme={theme} setTheme={setTheme} toast={toast} />; break;
    default: screen = <Dashboard go={go} toast={toast} />;
  }

  return (
    <div className="app">
      {mobileOpen && <div style={{ position: "fixed", inset: 0, zIndex: 99, background: "rgba(0,0,0,0.4)" }} onClick={() => setMobileOpen(false)} />}
      <Sidebar route={route === "item" ? "items" : route} go={go} collapsed={collapsed} setCollapsed={setCollapsed} mobileOpen={mobileOpen} setMobileOpen={setMobileOpen} />
      <div className="main">
        <TopBar theme={theme} setTheme={setTheme} onMenu={() => setMobileOpen(true)} />
        <div className="content" ref={contentRef} key={route === "item" ? "item-" + itemId : route}>
          {screen}
        </div>
      </div>
      <FabDock onAction={(a) => toast(a)} />
      <Toast msg={toastMsg} />
    </div>
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(<App />);
