import english from './locales/en.json' with { type: 'json' };
// Only the selected public UI catalog is embedded by the server, so switching
// languages does not download every other language or touch customer records.
export const supportedLocales = ['en','ar','fr','ru','ur','de','es','zh','pt','tr','it'];
export const normalizeLocale = (locale) => supportedLocales.includes(String(locale ?? '').split(/[-_]/)[0]) ? String(locale).split(/[-_]/)[0] : 'en';
const current = normalizeLocale(typeof window === 'undefined' ? 'en' : window.SOLASTOCK_LOCALE?.locale);
export const translatedCatalogs = typeof window !== 'undefined' && window.SOLASTOCK_TRANSLATIONS ? { [current]: window.SOLASTOCK_TRANSLATIONS } : {};
const bySource = Object.fromEntries(Object.entries(english).map(([key,value]) => [value, translatedCatalogs[current]?.[key] ?? value]));
export function sourceText(english, arabic, locale = current) {
 const language = normalizeLocale(locale);
 if (language === 'ar') return arabic ?? english;
 return language === current ? (bySource[english] ?? english) : english;
}
// Getter pairs defer lookup until rendering; shared components retain explicit
// Arabic choices while the other languages share the selected catalog.
export const localizedPair = (english,arabic) => ({get 0() { return sourceText(english,arabic,current === 'ar' ? 'en' : current); }, get 1() { return sourceText(english,arabic,current === 'en' ? 'ar' : current); }, map(fn) { return [this[0],this[1]].map(fn); }});
