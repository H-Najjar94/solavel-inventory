// Copied from shared location-maps commit 4ab537e. Do not edit here.
import { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './location-map.css';
import { LatLng, addTiles, formatCoords, googleMapsUrl, isValidLatLng, observeResize, pinIcon } from './location-utils';

export interface MapPin extends LatLng { label?: string }

export interface LocationMapLabels {
  openInGoogleMaps: string; noLocation: string; setLocation: string; tilesUnavailable: string;
}

export interface LocationMapProps {
  pins: MapPin[];
  radius?: number;
  labels: LocationMapLabels;
  height?: number;
  emptyState?: { canEdit: boolean; setLocationHref?: string };
}

export default function LocationMap({ pins, radius, labels, height = 280, emptyState }: LocationMapProps) {
  const valid = pins.filter(isValidLatLng);
  const elRef = useRef<HTMLDivElement>(null);
  const [tilesFailed, setTilesFailed] = useState(false);
  const key = valid.map((p) => `${p.lat},${p.lng},${p.label ?? ''}`).join('|') + `#${radius ?? ''}`;

  useEffect(() => {
    const el = elRef.current;
    if (!el || valid.length === 0) return;
    const map = L.map(el, { center: [valid[0].lat, valid[0].lng], zoom: 15, scrollWheelZoom: false, attributionControl: false });
    addTiles(map, () => setTilesFailed(true), () => setTilesFailed(false));
    const group = L.featureGroup();
    valid.forEach((p) => {
      L.marker([p.lat, p.lng], { icon: pinIcon({ label: p.label }), keyboard: false, title: p.label }).addTo(group);
      if (radius && radius > 0) L.circle([p.lat, p.lng], { radius, className: 'slm-circle', interactive: false }).addTo(group);
    });
    group.addTo(map);
    if (valid.length === 1 && !radius) map.setView([valid[0].lat, valid[0].lng], 15);
    else map.fitBounds(group.getBounds(), { padding: [30, 30], maxZoom: 16 });
    const stop = observeResize(el, map);
    return () => { stop(); map.remove(); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  if (valid.length === 0) {
    return (
      <div className="slm-empty">
        <p>{labels.noLocation}</p>
        {emptyState?.canEdit && emptyState.setLocationHref && <a className="slm-btn" href={emptyState.setLocationHref}>{labels.setLocation}</a>}
      </div>
    );
  }

  return (
    <div className="slm-view">
      <div className="slm-mapbox" dir="ltr" style={{ height }}><div ref={elRef} className="slm-map" /></div>
      {tilesFailed && <p className="slm-warn slm-note">{labels.tilesUnavailable}</p>}
      <ul className="slm-pinlist">
        {valid.map((p, i) => (
          <li key={i} className="slm-pinrow">
            {p.label && <strong>{p.label}</strong>}
            <span className="slm-coords" dir="ltr">{formatCoords(p)}</span>
            <a className="slm-link" href={googleMapsUrl(p)} target="_blank" rel="noopener noreferrer"><bdi>{labels.openInGoogleMaps}</bdi></a>
          </li>
        ))}
      </ul>
    </div>
  );
}
