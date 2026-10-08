import React, { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useToast } from '../stores/toast.jsx';
import { useI18n } from '../i18n/context.jsx';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
const copy = {
 en: { title: 'Catalog synchronization', back: 'SolaCount connection', description: 'Items and reference data sync without changing quantities or local prices.', empty: 'Catalog is up to date', emptyHint: 'New and changed catalog records appear here after saving.', type: 'Type', record: 'Record', status: 'Status', reason: 'Action needed', retry: 'Retry synchronization', busy: 'Queuing…', queued: 'Synchronization queued', failed: 'Could not load synchronization status', unit: 'Unit', category: 'Category', item: 'Item', pending: 'Pending', retrying: 'Retrying', unknown_outcome: 'Checking delivery', delivering: 'Delivering', delivered: 'Synchronized', intervention_required: 'Needs attention', catalog_dependency_pending: 'Waiting for the related unit or category.', catalog_source_actor_required: 'An authorized catalog editor must retry this record.', catalog_source_changed: 'The source changed. Retry to review its current version.', catalog_field_conflict: 'The physical unit or tracking setup differs. Review the mapping before retrying.', catalog_shared_reference_change: 'A shared Finance reference differs. Review its mapping.', catalog_identity_conflict: 'Review the existing mapping; no duplicate was created.', catalog_source_not_authorized: 'The source editor no longer has permission. An authorized editor can retry.', fallback: 'Delivery is pending. Retry when the connection is available.' },
 ar: { title: 'مزامنة الأصناف', back: 'ربط SolaCount', description: 'تتم مزامنة الأصناف والبيانات المرجعية دون تغيير الكميات أو الأسعار المحلية.', empty: 'لا توجد سجلات للمراجعة', emptyHint: 'تظهر الأصناف والبيانات المرجعية الجديدة أو المعدلة هنا بعد حفظها.', type: 'النوع', record: 'السجل', status: 'الحالة', reason: 'الإجراء المطلوب', retry: 'إعادة المزامنة', busy: 'جارٍ الإرسال…', queued: 'تمت جدولة المزامنة', failed: 'تعذر تحميل حالة المزامنة', unit: 'وحدة', category: 'تصنيف', item: 'صنف', pending: 'قيد الانتظار', retrying: 'إعادة المحاولة', unknown_outcome: 'جارٍ التحقق من التسليم', delivering: 'جارٍ التسليم', delivered: 'تمت المزامنة', intervention_required: 'تحتاج إلى مراجعة', catalog_dependency_pending: 'بانتظار مزامنة الوحدة أو التصنيف المرتبط.', catalog_source_actor_required: 'يلزم أن يعيد محرر مخول مزامنة هذا السجل.', catalog_source_changed: 'تغير السجل المصدر. أعد المحاولة لمراجعة النسخة الحالية.', catalog_field_conflict: 'تختلف الوحدة أو إعدادات التتبع. راجع الربط قبل إعادة المحاولة.', catalog_shared_reference_change: 'تختلف البيانات المرجعية المشتركة في المالية. راجع الربط.', catalog_identity_conflict: 'راجع الربط الحالي؛ لم يتم إنشاء سجل مكرر.', catalog_source_not_authorized: 'لم يعد محرر السجل مخولاً. يمكن لمحرر مخول إعادة المحاولة.', fallback: 'التسليم معلق. أعد المحاولة عندما يكون الاتصال متاحاً.' },
};
export default function CatalogSyncPage() {
 const { locale } = useI18n(); const t = copy[locale === 'ar' ? 'ar' : 'en'];
 const toast = useToast(); const qc = useQueryClient(); const [busy, setBusy] = useState(null);
 const query = useApiQuery(['catalog-sync'], api.catalogSync, { fallback: null });
 const rows = query.data?.rows ?? [];
 async function retry(row) {
  if (busy) return; setBusy(row.source_uuid);
  try { await api.retryCatalogSync(row.source_uuid); toast.push(t.queued, 'success'); await qc.invalidateQueries({ queryKey: ['catalog-sync'] }); }
  catch (error) { toast.failure(error, t[error?.message] || t.fallback); }
  finally { setBusy(null); }
 }
 return <section className="page">
  <Breadcrumbs items={[{ label: t.back, to: '/integrations/solacount' }, { label: t.title }]} />
  <header className="page-head"><h1>{t.title}</h1></header><p>{t.description}</p>
  <div className="panel">
   {query.isError ? <EmptyState title={t.failed} hint={t.fallback} /> : rows.length === 0 ? <EmptyState title={t.empty} hint={t.emptyHint} /> : <div style={{ overflowX: 'auto' }}><table className="data-table">
    <thead><tr><th>{t.type}</th><th>{t.record}</th><th>{t.status}</th><th>{t.reason}</th><th /></tr></thead>
    <tbody>{rows.map(row => <tr key={row.source_uuid}><td>{t[row.entity_type]}</td><td>{row.name || `#${row.source_id}`}</td><td><span className={`badge ${row.state === 'delivered' ? 'badge--live' : 'badge--warn'}`}>{t[row.state] || t.pending}</span></td><td>{row.last_error ? (t[row.last_error] || t.fallback) : '—'}</td><td>{!['delivered', 'delivering'].includes(row.state) && <button className="btn btn--sm" disabled={busy !== null} onClick={() => retry(row)}>{busy === row.source_uuid ? t.busy : t.retry}</button>}</td></tr>)}</tbody>
   </table></div>}
  </div>
 </section>;
}
