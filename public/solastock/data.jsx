/* SolaStock — mock data layer (exposed on window) */
(function () {
  const CATEGORIES = [
    { name: "Electronics", color: "#3b7de0" },
    { name: "Apparel", color: "#e09921" },
    { name: "Home & Living", color: "#1f9d6b" },
    { name: "Accessories", color: "#7c6df0" },
    { name: "Sports", color: "#e05151" },
    { name: "Beauty", color: "#d65c9e" },
  ];

  const WAREHOUSES = [
    { id: "WH-01", name: "Central Fulfillment", city: "Austin, TX", cap: 86, value: 168400, items: 4820, low: 12, lat: 1 },
    { id: "WH-02", name: "West Coast Hub", city: "Oakland, CA", cap: 64, value: 112900, items: 3140, low: 9, lat: 2 },
    { id: "WH-03", name: "Midwest Depot", city: "Columbus, OH", cap: 92, value: 74200, items: 2510, low: 8, lat: 3 },
    { id: "WH-04", name: "Southeast Store", city: "Atlanta, GA", cap: 41, value: 48600, items: 1380, low: 5, lat: 4 },
    { id: "WH-05", name: "Northeast Micro", city: "Newark, NJ", cap: 78, value: 24820, items: 990, low: 3, lat: 5 },
  ];

  const SUPPLIERS = ["Nordic Components", "Vertex Trading Co.", "Lumen Supply", "Pacific Source", "Atlas Wholesale", "Brightline Goods", "Meridian Imports"];

  const ITEM_NAMES = [
    ["Aurora Wireless Headphones", "Electronics"],
    ["Titan USB-C Hub 8-in-1", "Electronics"],
    ["Nimbus Mechanical Keyboard", "Electronics"],
    ["Vega 4K Webcam Pro", "Electronics"],
    ["Pulse Bluetooth Speaker", "Electronics"],
    ["Orion Laptop Stand", "Accessories"],
    ["Meridian Leather Wallet", "Accessories"],
    ["Cascade Water Bottle 1L", "Home & Living"],
    ["Lumen Desk Lamp", "Home & Living"],
    ["Drift Ceramic Mug Set", "Home & Living"],
    ["Summit Running Shoes", "Sports"],
    ["Apex Yoga Mat Pro", "Sports"],
    ["Glide Resistance Bands", "Sports"],
    ["Halo Cotton Tee", "Apparel"],
    ["Frost Merino Hoodie", "Apparel"],
    ["Tundra Down Jacket", "Apparel"],
    ["Cove Canvas Tote", "Accessories"],
    ["Ember Scented Candle", "Home & Living"],
    ["Velvet Matte Lipstick", "Beauty"],
    ["Dew Hydrating Serum", "Beauty"],
    ["Bloom Facial Roller", "Beauty"],
    ["Nova Charging Pad", "Electronics"],
    ["Ridge Trail Backpack", "Sports"],
    ["Linen Throw Blanket", "Home & Living"],
    ["Slate Sunglasses", "Accessories"],
    ["Coral Swim Shorts", "Apparel"],
    ["Forge Cast Iron Pan", "Home & Living"],
    ["Echo Noise Earbuds", "Electronics"],
    ["Maple Cutting Board", "Home & Living"],
    ["Stride Compression Socks", "Sports"],
  ];

  function pad(n, len) { return String(n).padStart(len, "0"); }
  function rnd(a, b) { return a + Math.floor(Math.random() * (b - a + 1)); }
  function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

  // deterministic-ish seed for stable demo
  let seed = 42;
  function srand() { seed = (seed * 9301 + 49297) % 233280; return seed / 233280; }
  function srnd(a, b) { return a + Math.floor(srand() * (b - a + 1)); }
  function spick(arr) { return arr[Math.floor(srand() * arr.length)]; }

  const ITEMS = ITEM_NAMES.map((it, i) => {
    const cat = CATEGORIES.find(c => c.name === it[1]);
    const reorder = srnd(15, 60);
    const stock = srand() < 0.12 ? 0 : srand() < 0.22 ? srnd(2, reorder - 2) : srnd(reorder + 5, 480);
    const committed = Math.min(stock, srnd(0, Math.floor(stock * 0.4)));
    const cost = srnd(6, 180);
    const price = +(cost * (1.3 + srand() * 0.9)).toFixed(2);
    const wh = WAREHOUSES.slice(0, srnd(2, 4)).map(w => ({ id: w.id, name: w.name, qty: srnd(0, Math.floor(stock / 1.5)) }));
    let status = "ok";
    if (stock === 0) status = "out";
    else if (stock <= reorder) status = "low";
    return {
      id: "ITM-" + pad(1001 + i, 4),
      sku: it[1].slice(0, 3).toUpperCase() + "-" + pad(rnd(1000, 9999), 4) + "-" + String.fromCharCode(65 + (i % 26)),
      name: it[0],
      category: it[1],
      catColor: cat.color,
      stock, committed,
      available: stock - committed,
      reorder,
      cost,
      price,
      margin: +(((price - cost) / price) * 100).toFixed(1),
      supplier: spick(SUPPLIERS),
      warehouses: wh,
      status,
      velocity: srnd(0, 100),    // demand velocity score
      sold30: srnd(0, 420),
      health: srnd(35, 99),
    };
  });

  // make headline numbers match the brief
  const STATS = {
    inventoryValue: 428920,
    totalItems: 12840,
    lowStock: 37,
    outOfStock: 12,
    warehouses: 5,
    pendingPO: 18,
    salesToday: 64,
    accuracy: 97.4,
    turnover: 6.8,
    deadStock: 21,
    fastMoving: 142,
    healthScore: 88,
  };

  // chart series
  const months = ["Jul", "Aug", "Sep", "Oct", "Nov", "Dec", "Jan", "Feb", "Mar", "Apr", "May", "Jun"];
  const valueTrend = [312, 328, 305, 351, 372, 360, 388, 401, 419, 408, 422, 429].map((v, i) => ({ x: months[i], y: v * 1000 }));
  const movementSeries = months.map((m, i) => ({
    x: m,
    in: srnd(120, 340),
    out: srnd(110, 320),
  }));
  const purchaseVsSales = months.slice(6).map((m) => ({ x: m, purchase: srnd(80, 200), sales: srnd(90, 240) }));

  const categoryBreakdown = CATEGORIES.map(c => ({
    name: c.name, color: c.color, value: srnd(8, 32),
  }));
  // normalize to 100
  const ctot = categoryBreakdown.reduce((s, c) => s + c.value, 0);
  categoryBreakdown.forEach(c => c.pct = Math.round((c.value / ctot) * 100));

  const topSelling = [...ITEMS].sort((a, b) => b.sold30 - a.sold30).slice(0, 5);
  const lowStockItems = ITEMS.filter(i => i.status === "low" || i.status === "out").slice(0, 6);
  const deadStockItems = [...ITEMS].sort((a, b) => a.velocity - b.velocity).slice(0, 5);
  const fastMovingItems = [...ITEMS].sort((a, b) => b.velocity - a.velocity).slice(0, 5);

  const ACTIVITIES = [
    { type: "in", who: "PO-2041 received", what: "+340 units · Aurora Wireless Headphones", time: "12 min ago", wh: "WH-01" },
    { type: "out", who: "SO-8870 shipped", what: "−64 units · 8 line items", time: "38 min ago", wh: "WH-02" },
    { type: "adjust", who: "Cycle count", what: "−3 units · Nimbus Keyboard (damage)", time: "1 hr ago", wh: "WH-01" },
    { type: "transfer", who: "Transfer TR-310", what: "120 units · WH-03 → WH-04", time: "2 hr ago", wh: "WH-03" },
    { type: "in", who: "PO-2039 received", what: "+90 units · Forge Cast Iron Pan", time: "3 hr ago", wh: "WH-05" },
    { type: "out", who: "SO-8861 shipped", what: "−28 units · Summit Running Shoes", time: "5 hr ago", wh: "WH-01" },
  ];

  const PURCHASE_ORDERS = Array.from({ length: 9 }, (_, i) => {
    const statuses = ["Draft", "Sent", "Partial", "Received", "Sent", "Received"];
    const st = statuses[i % statuses.length];
    return {
      id: "PO-" + (2048 - i),
      supplier: spick(SUPPLIERS),
      date: ["Jun 09", "Jun 08", "Jun 06", "Jun 05", "Jun 03", "Jun 01", "May 29", "May 27", "May 24"][i],
      expected: ["Jun 14", "Jun 13", "Jun 11", "Jun 10", "Jun 08", "Jun 06", "Jun 03", "Jun 02", "May 30"][i],
      items: srnd(3, 24),
      total: srnd(2400, 38000),
      status: st,
    };
  });

  const SALES_ORDERS = Array.from({ length: 6 }, (_, i) => ({
    id: "SO-" + (8872 - i),
    customer: ["Bright Retail Co.", "Urban Outfit Ltd.", "Peak Sports", "Nest Living", "Glow Beauty Bar", "Tech Haven"][i],
    items: srnd(2, 12),
    total: srnd(180, 4200),
    status: ["Packed", "Shipped", "Processing", "Shipped", "Delivered", "Processing"][i],
  }));

  // AI suggestions
  const AI_SUGGESTIONS = [
    { lead: "Reorder Aurora Wireless Headphones", desc: "Stock will hit zero in ~6 days at current velocity. Suggested PO: 280 units from Nordic Components.", conf: 94 },
    { lead: "Reduce Tundra Down Jacket stock", desc: "Seasonal demand dropping 40%. Consider promotion to clear 120 aging units before Q3.", conf: 81 },
    { lead: "Rebalance Summit Running Shoes", desc: "WH-02 overstocked, WH-04 low. Transfer 60 units to avoid a stockout next week.", conf: 88 },
  ];

  const SMART_ALERTS = [
    { sev: "high", text: "12 SKUs out of stock across 3 warehouses", icon: "alert" },
    { sev: "med", text: "Reorder point breached on 37 items", icon: "trenddown" },
    { sev: "low", text: "WH-03 approaching 92% capacity", icon: "warehouse" },
  ];

  window.DB = {
    CATEGORIES, WAREHOUSES, SUPPLIERS, ITEMS, STATS,
    valueTrend, movementSeries, purchaseVsSales, categoryBreakdown,
    topSelling, lowStockItems, deadStockItems, fastMovingItems,
    ACTIVITIES, PURCHASE_ORDERS, SALES_ORDERS, AI_SUGGESTIONS, SMART_ALERTS,
    months,
  };

  // formatters
  window.fmt = {
    money: (n, dp = 0) => "$" + Number(n).toLocaleString("en-US", { minimumFractionDigits: dp, maximumFractionDigits: dp }),
    moneyK: (n) => n >= 1000 ? "$" + (n / 1000).toFixed(n >= 100000 ? 0 : 1) + "k" : "$" + n,
    num: (n) => Number(n).toLocaleString("en-US"),
  };
})();
