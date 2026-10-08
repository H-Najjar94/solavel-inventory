#!/usr/bin/env python3
"""Disposable-only launcher/SSO/first-transaction production smoke."""
import json, os, time, urllib.request

WD = os.environ.get("WEBDRIVER_URL", "http://127.0.0.1:4444")
ROOT = os.environ.get("SOLAVEL_ROOT", "https://solavel.com")
EMAIL = os.environ["SOLASTOCK_E2E_EMAIL"]
PASSWORD = os.environ["SOLASTOCK_E2E_PASSWORD"]
LABEL = os.environ.get("SOLASTOCK_E2E_LABEL", "activation")
READ_ONLY = os.environ.get("SOLASTOCK_E2E_READ_ONLY", "0") == "1"

def call(method, path, payload=None):
    req = urllib.request.Request(WD + path, data=None if payload is None else json.dumps(payload).encode(), method=method,
        headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=90) as res:
        raw = res.read().decode()
        return json.loads(raw) if raw else None

class Browser:
    def __init__(self):
        result = call("POST", "/session", {"capabilities":{"alwaysMatch":{"browserName":"firefox","acceptInsecureCerts":True,
            "moz:firefoxOptions":{"args":["-headless"]}}}})
        self.sid = result["value"]["sessionId"]
        self.base = f"/session/{self.sid}"
        self.post("/timeouts", {"implicit":3000,"pageLoad":90000,"script":90000})
    def post(self, path, body=None): return call("POST", self.base + path, body or {})
    def get(self, path): return call("GET", self.base + path)
    def close(self):
        try: call("DELETE", self.base)
        except Exception: pass
    def url(self, value): self.post("/url", {"url":value})
    def find(self, css): return self.post("/element", {"using":"css selector","value":css})["value"]["element-6066-11e4-a52e-4f735466cecf"]
    def type(self, css, value): self.post(f"/element/{self.find(css)}/value", {"text":value})
    def click(self, css): self.post(f"/element/{self.find(css)}/click")
    def js(self, source, *args): return self.post("/execute/sync", {"script":source,"args":list(args)})["value"]
    def async_js(self, source, *args): return self.post("/execute/async", {"script":source,"args":list(args)})["value"]
    def wait(self, predicate, seconds=60):
        end=time.time()+seconds
        while time.time()<end:
            try:
                if predicate(): return
            except Exception: pass
            time.sleep(.5)
        raise RuntimeError("browser wait timed out")

API = r"""
const done=arguments[arguments.length-1], [path,method,body]=arguments;
(async()=>{const token=document.querySelector('meta[name="csrf-token"]')?.content||'';
const r=await fetch('/inventory/api/v1'+path,{method:method||'GET',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token,'X-Requested-With':'XMLHttpRequest'},body:body==null?undefined:JSON.stringify(body)});
const text=await r.text();let data=null;try{data=JSON.parse(text)}catch(e){}done({ok:r.ok,status:r.status,data,error:r.ok?null:text.slice(0,300)});})().catch(e=>done({ok:false,status:0,error:e.message}));
"""

def required(label, result):
    if not result.get("ok"):
        raise RuntimeError(f"{label} failed: HTTP {result.get('status')} {result.get('error')}")
    return (result.get("data") or {}).get("data")

def main():
    b=Browser(); report={"label":LABEL,"checks":{},"issues":[]}
    try:
        report["phase"]="login_page"
        b.url(ROOT+"/login?product=inventory")
        b.wait(lambda: b.find("#email"))
        b.type("#email",EMAIL); b.type("#password",PASSWORD); b.click("button[type=submit]")
        report["phase"]="login_submit"
        b.wait(lambda: "/login" not in b.get("/url")["value"])
        report["phase"]="sso"
        b.url(ROOT+"/sso/inventory?to=%2Finventory%2Fdashboard")
        b.wait(lambda: "/inventory" in b.get("/url")["value"] and b.js("return document.readyState")=="complete")
        time.sleep(2)
        report["checks"]["sso"]={"ok":True,"path":urllib.parse.urlparse(b.get("/url")["value"]).path}
        report["phase"]="tenant_status"
        status=required("tenant status",b.async_js(API,"/tenant/status","GET",None))
        meta=required("meta",b.async_js(API,"/meta","GET",None))
        report["checks"]["tenant"]={"state":status.get("state"),"client_id":meta.get("tenant",{}).get("client_id"),"organization_id":meta.get("organization",{}).get("id")}
        failed_resources=b.js("return performance.getEntriesByType('resource').filter(x=>x.responseStatus && x.responseStatus>=400).map(x=>({url:new URL(x.name).pathname,status:x.responseStatus})).slice(0,20)")
        report["checks"]["assets"]={"failed_resources":failed_resources}
        if failed_resources: raise RuntimeError("browser loaded one or more failed assets")
        if READ_ONLY:
            report["checks"]["read_only"]={"dashboard":True,"meta_api":True,"tenant_status_api":True}
            print(json.dumps(report,sort_keys=True)); return
        stamp=str(int(time.time()))
        report["phase"]="transaction"
        wh=required("warehouse",b.async_js(API,"/warehouses","POST",{"code":f"E2E-{stamp}","name":f"Disposable E2E Warehouse {stamp}","type":"warehouse","is_active":True}))
        item=required("item",b.async_js(API,"/items","POST",{"sku":f"E2E-{stamp}","name":f"Disposable E2E Item {stamp}","item_type":"inventory","costing_method":"fifo","purchase_price":"7.00","sales_price":"10.00","is_active":True,"reorder_point":"2"}))
        opening=required("opening stock",b.async_js(API,"/opening-stock","POST",{"warehouse_id":wh["id"],"notes":"Disposable activation acceptance","lines":[{"item_id":item["id"],"quantity":"3","unit_cost":"7.00"}]}))
        required("post opening stock",b.async_js(API,f"/opening-stock/{opening['id']}/post","POST",{}))
        movements=required("movements",b.async_js(API,f"/items/{item['id']}/movements?per_page=20","GET",None))
        valuation=required("valuation",b.async_js(API,f"/items/{item['id']}/valuation","GET",None))
        movement_rows=movements if isinstance(movements,list) else movements.get("data",[])
        report["checks"]["first_transaction"]={"warehouse":bool(wh.get("id")),"item":bool(item.get("id")),"opening_posted":True,"ledger_entries":len(movement_rows),"valuation_available":valuation is not None}
    except Exception as exc:
        try:
            report["debug"]={"path":urllib.parse.urlparse(b.get("/url")["value"]).path,"body":b.js("return document.body.innerText.slice(0,500)")}
        except Exception: pass
        report["issues"].append(str(exc))
    finally: b.close()
    print(json.dumps(report,sort_keys=True))
    raise SystemExit(1 if report["issues"] else 0)

if __name__=="__main__":
    import urllib.parse
    main()
