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

export const dictionaries = {
  en: Object.assign({}, core.en, catalog.en, catalog.detailEn, catalog.detailExtrasEn, stock.en, stock.warehouseEn, stock.warehouseDetailEn, operations.en, purchasing.en, sales.en, traceability.en, insights.en, admin.en, adjustment.en),
  ar: Object.assign({}, core.ar, catalog.ar, catalog.detailAr, catalog.detailExtrasAr, stock.ar, stock.warehouseAr, stock.warehouseDetailAr, operations.ar, purchasing.ar, sales.ar, traceability.ar, insights.ar, admin.ar, adjustment.ar),
};

// English source phrases used by page islands. Keeping this separate from
// keyed labels lets legacy JSX pages participate in localization immediately.
export const phrases = {
  Dashboard: 'لوحة التحكم', Overview: 'نظرة عامة', Catalog: 'دليل المنتجات', Stock: 'المخزون',
  Operations: 'العمليات', Purchasing: 'المشتريات', 'Sales / Fulfillment': 'المبيعات / التنفيذ',
  Traceability: 'التتبع', Insights: 'التقارير والتحليلات', Admin: 'الإدارة',
  Items: 'الأصناف', Item: 'الصنف', Warehouses: 'المستودعات', Warehouse: 'المستودع',
  Customers: 'العملاء', Customer: 'العميل', Suppliers: 'الموردون', Supplier: 'المورد',
  'Current Stock': 'المخزون الحالي', 'Stock Ledger': 'سجل المخزون', 'Opening Stock': 'الرصيد الافتتاحي',
  Adjustments: 'التسويات', Adjustment: 'تسوية', Transfers: 'التحويلات', Transfer: 'تحويل',
  'Stock Counts': 'جرد المخزون', 'Stock Count': 'جرد المخزون', Scanner: 'الماسح',
  'Purchase Orders': 'أوامر الشراء', 'Purchase Order': 'أمر شراء', 'Goods Receipts': 'إيصالات الاستلام',
  'Goods Receipt': 'إيصال استلام', 'Sales Orders': 'أوامر البيع', 'Sales Order': 'أمر بيع',
  Picking: 'التجهيز', Packing: 'التعبئة', Shipments: 'الشحنات', Shipment: 'شحنة',
  'Sales Returns': 'مرتجعات المبيعات', 'Sales Return': 'مرتجع مبيعات', 'Lots / Batches': 'التشغيلات / الدفعات',
  'Serial Numbers': 'الأرقام التسلسلية', Recalls: 'الاستدعاءات', Recall: 'استدعاء', Reports: 'التقارير',
  Settings: 'الإعدادات', 'SolaBooks': 'SolaBooks', 'No data available': 'لا توجد بيانات',
  'No results found': 'لا توجد نتائج', 'No items yet': 'لا توجد أصناف بعد', 'No documents yet': 'لا توجد مستندات بعد',
  'Add item': 'إضافة صنف', 'New item': 'صنف جديد', 'Edit item': 'تعديل الصنف', 'Item details': 'تفاصيل الصنف',
  Save: 'حفظ', 'Save changes': 'حفظ التغييرات', Cancel: 'إلغاء', Create: 'إنشاء', Update: 'تحديث',
  Delete: 'حذف', Edit: 'تعديل', Add: 'إضافة', Remove: 'إزالة', Search: 'بحث', Clear: 'مسح',
  Filter: 'تصفية', Filters: 'الفلاتر', Actions: 'الإجراءات', Details: 'التفاصيل', Submit: 'إرسال',
  Select: 'اختيار', 'Select item': 'اختر الصنف', Required: 'مطلوب', Optional: 'اختياري',
  Status: 'الحالة', Type: 'النوع', Date: 'التاريخ', From: 'من', To: 'إلى', Notes: 'ملاحظات',
  Reference: 'المرجع', Description: 'الوصف', Category: 'التصنيف', Barcode: 'الباركود', Quantity: 'الكمية',
  'Unit cost': 'تكلفة الوحدة', 'Total cost': 'التكلفة الإجمالية', Balance: 'الرصيد', Available: 'متاح',
  Reserved: 'محجوز', 'On hand': 'المتاح فعلياً', Pending: 'معلّق', Draft: 'مسودة', Posted: 'مرحّل',
  Approved: 'معتمد', Completed: 'مكتمل', Received: 'مستلم', Ordered: 'مطلوب', Picked: 'تم التجهيز',
  Packed: 'تم التعبئة', Shipped: 'تم الشحن', Returned: 'مرتجع', Increase: 'زيادة', Decrease: 'نقصان',
  'Try again': 'حاول مرة أخرى', Loading: 'جارٍ التحميل', 'Loading…': 'جارٍ التحميل…',
  'No organization': 'لا توجد مؤسسة', 'No access': 'لا يوجد وصول', 'Finish setup to use SolaStock': 'أكمل الإعداد لاستخدام SolaStock',
  No: 'لا', yes: 'نعم', Data: 'بيانات', Sample: 'تجريبي', Primary: 'رئيسي', Documents: 'المستندات',
  Document: 'مستند', Performance: 'الأداء', Code: 'الرمز', Email: 'البريد الإلكتروني', Phone: 'الهاتف',
  Address: 'العنوان', 'Tax number': 'الرقم الضريبي', Currency: 'العملة', 'Payment terms': 'شروط الدفع',
  Reason: 'السبب', Reversal: 'العكس', Value: 'القيمة', Total: 'الإجمالي', Warehouse: 'المستودع',
  Source: 'المصدر', Direction: 'الاتجاه', Qty: 'الكمية', Time: 'الوقت', Actor: 'المنفذ', Action: 'الإجراء',
  Entity: 'الكيان', Before: 'قبل', After: 'بعد', Lines: 'السطور', Line: 'سطر', Number: 'الرقم',
  'Order date': 'تاريخ الطلب', Expected: 'المتوقع', Backorder: 'طلب متأخر', Carrier: 'شركة الشحن',
  Scope: 'النطاق', Affected: 'المتأثر', 'On hand': 'المتاح فعلياً', Shipped: 'تم الشحن',
  'No actions recorded.': 'لا توجد إجراءات مسجلة.', 'No audit events': 'لا توجد أحداث تدقيق',
  'No warehouse image': 'لا توجد صورة للمستودع', 'read-only': 'للقراءة فقط', 'No lines yet.': 'لا توجد سطور بعد.',
  'Add barcode': 'إضافة باركود', Lookup: 'بحث', Images: 'الصور', Specifications: 'المواصفات',
  Tracking: 'التتبع', Costing: 'التكلفة', Brand: 'العلامة التجارية', Variants: 'الأنواع', Labels: 'الملصقات',
  Audit: 'التدقيق', Created: 'تاريخ الإنشاء', Updated: 'آخر تحديث', 'View valuation': 'عرض التقييم',
  'Recent movements': 'الحركات الأخيرة', 'Stock by warehouse': 'المخزون حسب المستودع',
  'Low stock': 'مخزون منخفض', 'Needs review': 'يحتاج مراجعة', Reconciled: 'تمت المطابقة',
  'Stock in': 'إدخال مخزون', 'Stock out': 'إخراج مخزون', 'From warehouse': 'من المستودع', 'To warehouse': 'إلى المستودع',
  'Create pick list': 'إنشاء قائمة تجهيز', 'Create shipment': 'إنشاء شحنة', 'Receive transfer': 'استلام التحويل',
  'Ship to in-transit': 'شحن إلى قيد النقل', 'Export CSV': 'تصدير CSV', 'View lots': 'عرض الدفعات',
  'All statuses': 'كل الحالات', 'No available serials.': 'لا توجد أرقام تسلسلية متاحة.',
  'No lifecycle events yet.': 'لا توجد أحداث لدورة الحياة بعد.', 'No audit events recorded.': 'لا توجد أحداث تدقيق مسجلة.',
  New: 'جديد', Purchase: 'شراء', Orders: 'أوامر', Goods: 'بضائع', Receipts: 'إيصالات',
  Sales: 'مبيعات', Returns: 'مرتجعات', Current: 'الحالي', Ledger: 'السجل', Opening: 'افتتاحي',
  Stocktake: 'جرد', Cycle: 'دوري', Full: 'كامل', Once: 'مرة واحدة', Weekly: 'أسبوعي', Monthly: 'شهري', Quarterly: 'ربع سنوي',
  Increase: 'زيادة', Decrease: 'نقصان', Posting: 'الترحيل', posts: 'يرحّل', posted: 'مرحّل',
  move: 'ينقل', moves: 'ينقل', receive: 'استلام', receives: 'يستلم', Create: 'إنشاء',
  'No tax': 'بدون ضريبة', 'Not classified': 'غير مصنف', 'Each serial': 'كل رقم تسلسلي',
  'No results': 'لا توجد نتائج', 'No records': 'لا توجد سجلات', 'No activity': 'لا يوجد نشاط',
};

export function getLocale() {
  return window.SOLASTOCK_LOCALE?.locale === 'ar' ? 'ar' : 'en';
}

export function t(key, fallback, params = {}) {
  const locale = getLocale();
  const value = dictionaries[locale][key] ?? fallback ?? dictionaries.en[key] ?? key;
  return String(value).replace(/:([A-Za-z0-9_]+)/g, (_, name) => params[name] ?? `:${name}`);
}
