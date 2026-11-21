// Copied from shared location-maps (content sha256 524ace44e2bc; shared repo had no commit yet). Do not edit here.
// Shared helpers for LocationPicker / LocationMap. App-agnostic.
import L from 'leaflet';

export type LatLng = { lat: number; lng: number };

export const OSM_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
export const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors';
export const RADIUS_MIN = 10;
export const RADIUS_MAX = 10000;

/** Valid pair: finite numbers, lat -90..90, lng -180..180 (0 is valid). */
export function isValidLatLng<T extends { lat: unknown; lng: unknown }>(v: T | null | undefined): v is T & LatLng {
  if (!v) return false;
  const { lat, lng } = v;
  return typeof lat === 'number' && typeof lng === 'number' && Number.isFinite(lat) && Number.isFinite(lng)
    && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
}

export const round7 = (n: number) => Math.round(n * 1e7) / 1e7;
export const formatCoords = (p: LatLng) => `${p.lat.toFixed(6)}, ${p.lng.toFixed(6)}`;
export const googleMapsUrl = (p: LatLng) => `https://www.google.com/maps/search/?api=1&query=${p.lat},${p.lng}`;

const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));

/** Pin as a divIcon (avoids the bundler default-icon problem). 44x44 hit area, tip at bottom centre. */
export function pinIcon(opts: { label?: string; draggable?: boolean } = {}): L.DivIcon {
  const title = opts.label ? ` title="${esc(opts.label)}"` : '';
  return L.divIcon({
    className: 'slm-pin-wrap',
    html: `<span class="slm-pin${opts.draggable ? ' slm-pin--drag' : ''}"${title}><svg viewBox="0 0 24 32" width="28" height="36" aria-hidden="true"><path d="M12 0C5.4 0 0 5.4 0 12c0 9 12 20 12 20s12-11 12-20C24 5.4 18.6 0 12 0z" fill="currentColor"/><circle cx="12" cy="12" r="4.5" fill="#fff"/></svg></span>`,
    iconSize: [44, 44],
    iconAnchor: [22, 42],
  });
}

export function handleIcon(): L.DivIcon {
  return L.divIcon({
    className: 'slm-pin-wrap',
    html: '<span class="slm-handle"><span class="slm-handle-dot"></span></span>',
    iconSize: [44, 44],
    iconAnchor: [22, 22],
  });
}

/** Point `meters` east of a position (for the radius handle). */
export function eastOf(p: LatLng, meters: number): LatLng {
  const dLng = meters / (111320 * Math.max(0.01, Math.cos((p.lat * Math.PI) / 180)));
  return { lat: p.lat, lng: p.lng + dLng };
}

/** Create the tile layer and report when tiles fail (so callers can show a fallback note). */
export function addTiles(map: L.Map, onFail: () => void, onOk: () => void): L.TileLayer {
  const layer = L.tileLayer(OSM_TILES, { maxZoom: 19, attribution: OSM_ATTRIBUTION });
  let errors = 0;
  layer.on('tileerror', () => { if (++errors >= 3) onFail(); });
  layer.on('tileload', () => { errors = 0; onOk(); });
  layer.addTo(map);
  return layer;
}

/** Keep the map correct when its container is resized or revealed (modals, tabs). */
export function observeResize(el: HTMLElement, map: L.Map): () => void {
  if (typeof ResizeObserver === 'undefined') return () => {};
  const ro = new ResizeObserver(() => map.invalidateSize());
  ro.observe(el);
  return () => ro.disconnect();
}
