// Recognize only the existing purchasing publisher format; names and references
// remain untouched. Unknown notification text is never split by script detection.
const titles = new Map([
 ['Purchase partially received — تم استلام جزء من الشراء', ['Purchase partially received', 'تم استلام جزء من الشراء']],
 ['Purchase fully received — تم استلام الشراء بالكامل', ['Purchase fully received', 'تم استلام الشراء بالكامل']],
 ['Receiving request cancelled — تم إلغاء طلب الاستلام', ['Receiving request cancelled', 'تم إلغاء طلب الاستلام']],
 ['Purchase awaiting warehouse review — شراء بانتظار مراجعة المستودع', ['Purchase awaiting warehouse review', 'شراء بانتظار مراجعة المستودع']],
 ['Purchase ready for receiving — الشراء جاهز للاستلام', ['Purchase ready for receiving', 'الشراء جاهز للاستلام']],
 ['Warehouse setup needed — يلزم إعداد المستودع', ['Warehouse setup needed', 'يلزم إعداد المستودع']],
]);
const bodies = [
 id => [`Review received and remaining quantities in RR-${id}.`, `راجع الكميات المستلمة والمتبقية في طلب الاستلام RR-${id}.`],
 id => [`All requested quantities in RR-${id} have been received.`, `تم استلام جميع الكميات المطلوبة في طلب الاستلام RR-${id}.`],
 id => [`RR-${id} is cancelled. Previously recorded receipts are preserved.`, `تم إلغاء طلب RR-${id} مع حفظ مستندات الاستلام السابقة.`],
 id => [`Open RR-${id} and confirm only goods physically received.`, `افتح طلب RR-${id} وأكد الكميات المستلمة فعلياً فقط.`],
 id => [`RR-${id} is visible, but goods cannot be received until an authorized warehouse is configured.`, `طلب RR-${id} متاح، ولكن يجب إعداد مستودع مصرح به قبل استلام البضاعة.`],
];
export function localizePurchasingNotification(item, locale) {
 const lang = locale === 'ar' ? 'ar' : 'en';
 const structured = item.localized?.[lang] ?? item.translations?.[lang];
 const title = typeof structured?.title === 'string' ? structured.title : (titles.get(item.title)?.[lang === 'ar' ? 1 : 0] ?? item.title);
 if (typeof structured?.body === 'string') return {title, body: structured.body};
 const original = item.body;
 if (typeof original !== 'string') return {title, body: original};
 const lines = original.split('\n');
 if (lines.length !== 3) return {title, body: original};
 const id = lines[0].match(/ · RR-([1-9]\d*)$/)?.[1];
 if (!id) return {title, body: original};
 for (const pair of bodies.map(make => make(id))) {
  if (lines[1] === pair[0] && lines[2] === pair[1]) return {title, body: lines[0] + '\n' + pair[lang === 'ar' ? 1 : 0]};
 }
 return {title, body: original};
}
