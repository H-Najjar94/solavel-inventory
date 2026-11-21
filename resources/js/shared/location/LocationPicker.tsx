// Copied from shared location-maps commit 9be03ad. Do not edit here.
// Shared SolaCount location picker (source: solavel location-maps shared/). Copy into each app; no app imports.
import { useCallback, useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './location-map.css';
import {
  LatLng, RADIUS_MAX, RADIUS_MIN, addTiles, eastOf, formatCoords, handleIcon, isValidLatLng, observeResize, pinIcon, round7,
} from './location-utils';

export type { LatLng } from './location-utils';

export interface GeoResult {
  label: string; name: string; area: string; city: string; country: string; country_code: string; lat: number; lng: number;
}

export interface LocationPickerLabels {
  searchPlaceholder: string; searchButton: string; searching: string; noResults: string; searchUnavailable: string;
  selectedLocation: string; noLocationSelected: string; useAddress: string; clearLocation: string;
  radiusLabel: string; radiusUnit: string; mapHint: string; tilesUnavailable: string; resultsLabel: string;
}

export interface LocationPickerProps {
  value: LatLng | null;
  onChange: (v: LatLng | null) => void;
  radius?: number;
  onRadiusChange?: (m: number) => void;
  /** App endpoint, e.g. /geo/search. Called with q, lang, countries, lat, lng, viewbox. */
  searchUrl: string;
  countries?: string[];
  lang: 'ar' | 'en';
  defaultCenter: LatLng;
  labels: LocationPickerLabels;
  /** Explicit action only: the host decides whether to copy the result into its address field. */
  onUseAddress?: (r: GeoResult) => void;
  disabled?: boolean;
  height?: number;
}

type Status = 'idle' | 'loading' | 'empty' | 'unavailable' | 'done';

export default function LocationPicker(props: LocationPickerProps) {
  const { value, radius, searchUrl, countries, lang, defaultCenter, labels, disabled, height = 320 } = props;
  const radiusMode = typeof props.onRadiusChange === 'function' && typeof radius === 'number';

  const elRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<L.Map | null>(null);
  const markerRef = useRef<L.Marker | null>(null);
  const circleRef = useRef<L.Circle | null>(null);
  const handleRef = useRef<L.Marker | null>(null);
  const draggingHandle = useRef(false);
  const propsRef = useRef(props);
  propsRef.current = props;

  const [q, setQ] = useState('');
  const [status, setStatus] = useState<Status>('idle');
  const [results, setResults] = useState<GeoResult[]>([]);
  const [picked, setPicked] = useState<GeoResult | null>(null);
  const [tilesFailed, setTilesFailed] = useState(false);
  const abortRef = useRef<AbortController | null>(null);

  const setPin = useCallback((p: LatLng) => {
    const pt = { lat: round7(p.lat), lng: round7(p.lng) };
    if (isValidLatLng(pt)) propsRef.current.onChange(pt);
  }, []);

  // Create the map once.
  useEffect(() => {
    const el = elRef.current;
    if (!el) return;
    const start = isValidLatLng(value) ? value : defaultCenter;
    const map = L.map(el, { center: [start.lat, start.lng], zoom: isValidLatLng(value) ? 15 : 11, worldCopyJump: true, attributionControl: false });
    mapRef.current = map;
    addTiles(map, () => setTilesFailed(true), () => setTilesFailed(false));
    map.on('click', (e: L.LeafletMouseEvent) => {
      if (propsRef.current.disabled) return;
      setPicked(null);
      setPin({ lat: e.latlng.lat, lng: wrapLng(e.latlng.lng) });
    });
    const stop = observeResize(el, map);
    return () => { stop(); abortRef.current?.abort(); map.remove(); mapRef.current = null; markerRef.current = circleRef.current = handleRef.current = null; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Sync marker / circle / handle with props.
  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;
    const valid = isValidLatLng(value);
    if (!valid) {
      markerRef.current?.remove(); markerRef.current = null;
      circleRef.current?.remove(); circleRef.current = null;
      handleRef.current?.remove(); handleRef.current = null;
      return;
    }
    const ll = L.latLng(value!.lat, value!.lng);
    if (!markerRef.current) {
      const m = L.marker(ll, { icon: pinIcon({ draggable: true }), draggable: !disabled, keyboard: false }).addTo(map);
      m.on('dragend', () => { const p = m.getLatLng(); setPicked(null); setPin({ lat: p.lat, lng: wrapLng(p.lng) }); });
      markerRef.current = m;
      if (radiusMode) map.setView(ll, Math.max(map.getZoom(), 15)); else if (!map.getBounds().contains(ll)) map.setView(ll, Math.max(map.getZoom(), 15));
    } else {
      markerRef.current.setLatLng(ll);
      if (!map.getBounds().contains(ll)) map.panTo(ll);
    }
    if (disabled) markerRef.current.dragging?.disable(); else markerRef.current.dragging?.enable();

    if (radiusMode) {
      const r = Math.min(RADIUS_MAX, Math.max(RADIUS_MIN, radius as number));
      if (!circleRef.current) circleRef.current = L.circle(ll, { radius: r, className: 'slm-circle', interactive: false }).addTo(map);
      else { circleRef.current.setLatLng(ll); circleRef.current.setRadius(r); }
      const hp = eastOf(value!, r);
      if (!handleRef.current) {
        const h = L.marker([hp.lat, hp.lng], { icon: handleIcon(), draggable: !disabled, keyboard: false }).addTo(map);
        h.on('dragstart', () => { draggingHandle.current = true; });
        h.on('drag', () => {
          const c = markerRef.current?.getLatLng(); if (!c) return;
          const d = Math.round(map.distance(c, h.getLatLng()));
          const next = Math.min(RADIUS_MAX, Math.max(RADIUS_MIN, d));
          circleRef.current?.setRadius(next);
          propsRef.current.onRadiusChange?.(next);
        });
        h.on('dragend', () => { draggingHandle.current = false; const c = markerRef.current?.getLatLng(); if (c) { const p = eastOf({ lat: c.lat, lng: c.lng }, circleRef.current?.getRadius() ?? r); h.setLatLng([p.lat, p.lng]); } });
        handleRef.current = h;
      } else if (!draggingHandle.current) {
        handleRef.current.setLatLng([hp.lat, hp.lng]);
      }
      if (disabled) handleRef.current.dragging?.disable(); else handleRef.current.dragging?.enable();
    } else {
      circleRef.current?.remove(); circleRef.current = null;
      handleRef.current?.remove(); handleRef.current = null;
    }
  }, [value?.lat, value?.lng, radius, radiusMode, disabled, setPin]); // eslint-disable-line react-hooks/exhaustive-deps

  async function runSearch() {
    const term = q.trim();
    const map = mapRef.current;
    if (!term || disabled) return;
    abortRef.current?.abort();
    const ac = new AbortController();
    abortRef.current = ac;
    setStatus('loading'); setResults([]); setPicked(null);
    const params = new URLSearchParams({ q: term, lang });
    if (countries?.length) params.set('countries', countries.join(','));
    if (map) {
      const c = map.getCenter(); const b = map.getBounds();
      params.set('lat', String(round7(c.lat))); params.set('lng', String(round7(c.lng)));
      params.set('viewbox', [b.getWest(), b.getNorth(), b.getEast(), b.getSouth()].map(round7).join(','));
    }
    try {
      const res = await fetch(searchUrl + (searchUrl.includes('?') ? '&' : '?') + params.toString(), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: ac.signal,
      });
      if (!res.ok) throw new Error(String(res.status));
      const data = await res.json();
      const list: GeoResult[] = Array.isArray(data?.results) ? data.results.filter(isValidLatLng) : [];
      if (data?.unavailable) setStatus('unavailable');
      else if (!list.length) setStatus('empty');
      else setStatus('done');
      setResults(list);
    } catch (err) {
      if ((err as Error).name === 'AbortError') return;
      setStatus('unavailable');
    }
  }

  function choose(r: GeoResult) {
    setPicked(r);
    mapRef.current?.flyTo([r.lat, r.lng], 16, { duration: 0.6 });
    setPin({ lat: r.lat, lng: r.lng });
  }

  function clear() { setPicked(null); props.onChange(null); }

  const sub = (r: GeoResult) => [r.area, r.city, r.country].filter(Boolean).join(', ');
  const validValue = isValidLatLng(value);

  return (
    <div className="slm-picker">
      <div className="slm-search" role="search">
        <input type="search" className="slm-input" value={q} onChange={(e) => setQ(e.target.value)} placeholder={labels.searchPlaceholder}
          aria-label={labels.searchPlaceholder} disabled={disabled}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); runSearch(); } }} />
        <button type="button" onClick={() => runSearch()} className="slm-btn slm-btn--primary" disabled={disabled || status === 'loading' || !q.trim()}>
          {status === 'loading' ? labels.searching : labels.searchButton}
        </button>
      </div>

      <div className="slm-status" role="status" aria-live="polite">
        {status === 'loading' && <span>{labels.searching}</span>}
        {status === 'empty' && <span>{labels.noResults}</span>}
        {status === 'unavailable' && <span className="slm-warn">{labels.searchUnavailable}</span>}
      </div>

      {status === 'done' && results.length > 0 && (
        <ul className="slm-results" aria-label={labels.resultsLabel}>
          {results.map((r, i) => (
            <li key={`${r.lat},${r.lng},${i}`}>
              <button type="button" className="slm-result" onClick={() => choose(r)} disabled={disabled}>
                <strong className="slm-result-name">{r.name || r.label}</strong>
                {sub(r) && <span className="slm-muted">{sub(r)}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}

      <div className="slm-mapbox" dir="ltr" style={{ height }}>
        <div ref={elRef} className="slm-map" />
      </div>
      {tilesFailed && <p className="slm-warn slm-note">{labels.tilesUnavailable}</p>}
      {!disabled && <p className="slm-muted slm-note">{labels.mapHint}</p>}

      <div className="slm-selected">
        <span className="slm-selected-label">{labels.selectedLocation}</span>
        <span className="slm-coords" dir="ltr">{validValue ? formatCoords(value!) : labels.noLocationSelected}</span>
        <span className="slm-actions">
          {picked && props.onUseAddress && !disabled && (
            <button type="button" className="slm-btn" onClick={() => props.onUseAddress!(picked)}>{labels.useAddress}</button>
          )}
          {validValue && !disabled && <button type="button" className="slm-btn slm-btn--ghost" onClick={clear}>{labels.clearLocation}</button>}
        </span>
      </div>

      {radiusMode && validValue && (
        <label className="slm-radius">
          <span>{labels.radiusLabel}</span>
          <input type="number" className="slm-input slm-input--num" min={RADIUS_MIN} max={RADIUS_MAX} step={10} value={radius}
            disabled={disabled} dir="ltr"
            onChange={(e) => { const n = Number(e.target.value); if (Number.isFinite(n)) props.onRadiusChange!(Math.min(RADIUS_MAX, Math.max(RADIUS_MIN, Math.round(n)))); }} />
          <span className="slm-muted">{labels.radiusUnit}</span>
        </label>
      )}
    </div>
  );
}

function wrapLng(lng: number): number { return ((((lng + 180) % 360) + 360) % 360) - 180; }
