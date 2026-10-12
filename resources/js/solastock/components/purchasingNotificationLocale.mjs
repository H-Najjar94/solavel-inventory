import { normalizeLocale, sourceText } from '../i18n/locales.js';

// Only the warehouse publishers' exact public templates are translated.
// Organization/customer names, document numbers and unknown message text stay intact.
export const warehouseNotificationTitles = [
 ['Purchase partially received', 'تم استلام جزء من الشراء'],
 ['Purchase fully received', 'تم استلام الشراء بالكامل'],
 ['Receiving request cancelled', 'تم إلغاء طلب الاستلام'],
 ['Purchase awaiting warehouse review', 'شراء بانتظار مراجعة المستودع'],
 ['Purchase ready for receiving', 'الشراء جاهز للاستلام'],
 ['Warehouse setup needed', 'يلزم إعداد المستودع'],
 ['Sale partially dispatched', 'تم إرسال جزء من المبيعات'],
 ['Sale fully dispatched', 'تم إرسال المبيعات بالكامل'],
 ['Fulfillment request cancelled', 'تم إلغاء طلب التجهيز'],
 ['Sale awaiting warehouse review', 'مبيعات بانتظار مراجعة المستودع'],
 ['Sale ready for dispatch', 'المبيعات جاهزة للإرسال'],
 ['Fulfillment finished', 'اكتمل التجهيز'],
];

export const warehouseNotificationBodies = [
 ['RR', 'Review received and remaining quantities in RR-:ref.', 'راجع الكميات المستلمة والمتبقية في طلب الاستلام RR-:ref.'],
 ['RR', 'All requested quantities in RR-:ref have been received.', 'تم استلام جميع الكميات المطلوبة في طلب الاستلام RR-:ref.'],
 ['RR', 'RR-:ref is cancelled. Previously recorded receipts are preserved.', 'تم إلغاء طلب RR-:ref مع حفظ مستندات الاستلام السابقة.'],
 ['RR', 'Open RR-:ref and confirm only goods physically received.', 'افتح طلب RR-:ref وأكد الكميات المستلمة فعلياً فقط.'],
 ['RR', 'RR-:ref is visible, but goods cannot be received until an authorized warehouse is configured.', 'طلب RR-:ref متاح، ولكن يجب إعداد مستودع مصرح به قبل استلام البضاعة.'],
 ['FR|CS', 'Review dispatched and remaining quantities in :request.', 'راجع الكميات المرسلة والمتبقية في طلب التجهيز :request.'],
 ['FR|CS', 'All requested quantities in :request have been dispatched.', 'تم إرسال جميع الكميات المطلوبة في طلب التجهيز :request.'],
 ['FR|CS', ':request is cancelled. Previously recorded shipments are preserved.', 'تم إلغاء طلب :request مع حفظ مستندات الإرسال السابقة.'],
 ['FR|CS', 'Open :request and confirm only goods physically dispatched.', 'افتح طلب :request وأكد الكميات المرسلة فعلياً فقط.'],
 ['FR|CS', ':request is visible, but goods cannot be dispatched until an authorized warehouse is configured.', 'طلب :request متاح، ولكن يجب إعداد مستودع مصرح به قبل إرسال البضاعة.'],
 ['FR', 'Review the dispatched quantities and undelivered quantities closed by credit in :request.', 'راجع الكميات المرسلة والكميات غير المرسلة المغلقة بإشعار دائن في طلب :request.'],
 ['CS', 'Review the dispatched quantities and undelivered quantities cancelled by refund in :request.', 'راجع الكميات المرسلة والكميات غير المرسلة المغلقة باسترداد نقدي في طلب :request.'],
];

const fill = (template, prefix, id) => template.replaceAll(':ref', id).replaceAll(':request', `${prefix}-${id}`);
export const warehouseNotificationDirection = locale => ['ar', 'ur'].includes(normalizeLocale(locale)) ? 'rtl' : 'ltr';

export function localizePurchasingNotification(item, selectedLocale) {
 const locale = normalizeLocale(selectedLocale);
 const structured = item.localized?.[locale] ?? item.translations?.[locale];
 const english = item.localized?.en ?? item.translations?.en;
 const originalTitle = english?.title ?? item.title;
 const pair = warehouseNotificationTitles.find(([en, ar]) => originalTitle === en || originalTitle === `${en} — ${ar}`);
 const title = typeof structured?.title === 'string' ? structured.title
  : pair ? sourceText(pair[0], pair[1], locale) : item.title;
 if (typeof structured?.body === 'string') return { title, body: structured.body };
 const original = english?.body ?? item.body;
 if (!pair || typeof original !== 'string') return {title, body: item.body};
 const lines = original.split('\n');
 // Current payload: original header + English. Legacy: header + English + Arabic.
 if (lines.length !== 2 && lines.length !== 3) return {title, body: item.body};
 const reference = lines[0].match(/ · (RR|FR|CS)-([1-9]\d*)$/);
 if (!reference) return {title, body: item.body};
 const [, prefix, id] = reference;
 for (const [kinds, en, ar] of warehouseNotificationBodies) {
  if (!kinds.split('|').includes(prefix) || lines[1] !== fill(en, prefix, id)) continue;
  if (lines.length === 3 && lines[2] !== fill(ar, prefix, id)) continue;
  return {title, body: lines[0] + '\n' + fill(sourceText(en, ar, locale), prefix, id)};
 }
 return {title, body: item.body};
}
