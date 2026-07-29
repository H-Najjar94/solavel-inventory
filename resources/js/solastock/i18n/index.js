import * as core from './core.js';
import * as catalog from './catalog.js';
import * as stock from './stock.js';
import * as operations from './operations.js';
import * as purchasing from './purchasing.js';
import * as sales from './sales.js';
import * as traceability from './traceability.js';
import * as insights from './insights.js';
import * as admin from './admin.js';
import * as adjustment from './adjustments.js';
import * as ledger from './ledger.js';
import * as onboarding from './onboarding.js';
import { warehouseDetailEn, warehouseDetailAr } from './warehouseDetail.js';
import * as receiving from './receiving.js';
import * as transfers from './transfers.js';
import * as reports from './reports.js';
import * as sharedDocuments from './sharedDocuments.js';
import * as returns from './returns.js';
import * as errors from './errors.js';
import * as dashboard from './dashboard.js';
import * as counts from './counts.js';
import * as traceabilityPages from './traceabilityPages.js';
import * as openingStock from './openingStock.js';
import * as partners from './partners.js';
import * as settingsPages from './settingsPages.js';
import * as itemDetailFull from './itemDetailFull.js';
import * as salesOrders from './salesOrders.js';
import * as recallsScanner from './recallsScanner.js';
import * as fulfillmentPages from './fulfillmentPages.js';

export const dictionaries = {
  en: Object.assign({}, core.en, catalog.en, catalog.detailEn, catalog.detailExtrasEn, itemDetailFull.en, stock.en, stock.warehouseEn, stock.warehouseDetailEn, warehouseDetailEn, operations.en, purchasing.en, receiving.en, transfers.en, sales.en, salesOrders.en, fulfillmentPages.en, returns.en, recallsScanner.en, traceability.en, traceabilityPages.traceabilityPagesEn, insights.en, admin.en, adjustment.en, ledger.en, onboarding.en, reports.en, sharedDocuments.en, errors.en, dashboard.en, counts.en, openingStock.en, partners.en, settingsPages.en),
  ar: Object.assign({}, core.ar, catalog.ar, catalog.detailAr, catalog.detailExtrasAr, itemDetailFull.ar, stock.ar, stock.warehouseAr, stock.warehouseDetailAr, warehouseDetailAr, operations.ar, purchasing.ar, receiving.ar, transfers.ar, sales.ar, salesOrders.ar, fulfillmentPages.ar, returns.ar, recallsScanner.ar, traceability.ar, traceabilityPages.traceabilityPagesAr, insights.ar, admin.ar, adjustment.ar, ledger.ar, onboarding.ar, reports.ar, sharedDocuments.ar, errors.ar, dashboard.ar, counts.ar, openingStock.ar, partners.ar, settingsPages.ar),
};

export function getLocale() {
  return window.SOLASTOCK_LOCALE?.locale === 'ar' ? 'ar' : 'en';
}

export function t(key, fallback, params = {}) {
  const locale = getLocale();
  const value = dictionaries[locale][key] ?? fallback ?? dictionaries.en[key] ?? key;
  return String(value).replace(/:([A-Za-z0-9_]+)/g, (_, name) => params[name] ?? `:${name}`);
}
