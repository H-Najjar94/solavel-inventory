import React from 'react';
import LocationPicker from '../../shared/location/LocationPicker.tsx';
import LocationMap from '../../shared/location/LocationMap.tsx';
import { getLocale, t } from '../i18n/index.js';

export const GEO_SEARCH_URL = `${window.SOLASTOCK_BASE_PATH ?? '/inventory'}/api/v1/geo/search`;
const DEFAULT_CENTER = { lat: 31.9539, lng: 35.9106 }; // Amman
const key = (k) => t(`locationMaps.${k}`);

const PICKER_KEYS = ['searchPlaceholder', 'searchButton', 'searching', 'noResults', 'searchUnavailable', 'selectedLocation', 'noLocationSelected',
    'useAddress', 'clearLocation', 'radiusLabel', 'radiusUnit', 'mapHint', 'tilesUnavailable', 'resultsLabel'];
const MAP_KEYS = ['openInGoogleMaps', 'noLocation', 'setLocation', 'tilesUnavailable'];
const pick = (keys) => Object.fromEntries(keys.map((k) => [k, key(k)]));

/** value: {lat,lng} | null. Search is submit-only; "Use this address" copies only on explicit click. */
export function LocationField({ value, onChange, onUseAddress, disabled }) {
    return (
        <LocationPicker value={value} onChange={onChange} onUseAddress={onUseAddress} disabled={disabled}
            searchUrl={GEO_SEARCH_URL} countries={['jo', 'ae']} lang={getLocale() === 'ar' ? 'ar' : 'en'}
            defaultCenter={DEFAULT_CENTER} labels={pick(PICKER_KEYS)} />
    );
}

export function LocationView({ pins, canEdit, setLocationHref, height }) {
    return <LocationMap pins={pins} height={height} labels={pick(MAP_KEYS)} emptyState={{ canEdit: !!canEdit, setLocationHref }} />;
}

/** Supplier map section. Linked suppliers: read-only SolaCount pins + edit link; standalone: picker over `value`/`onChange` ({lat,lng,address?}|null). */
export function SupplierLocationSection({ locations, value, onChange, onUseAddress, disabled }) {
    if (locations?.linked) {
        const pins = (locations.locations ?? []).map((l) => ({ lat: l.lat, lng: l.lng, label: l.label || l.address || undefined }));
        return (
            <div>
                <p className="field-hint">{t('locationMaps.linkedHint')}</p>
                {pins.length === 0 && <p className="field-hint">{t('locationMaps.linkedNoPins')}</p>}
                {pins.length > 0 && <LocationView pins={pins} canEdit={false} />}
                {locations.edit_url && <p><a className="btn btn--sm" href={locations.edit_url} target="_blank" rel="noopener noreferrer">{t('locationMaps.editInSolaCount')}</a></p>}
            </div>
        );
    }
    const pin = value && value.lat != null && value.lng != null ? { lat: Number(value.lat), lng: Number(value.lng) } : null;
    return <LocationField value={pin} disabled={disabled} onUseAddress={onUseAddress}
        onChange={(p) => onChange(p ? { ...(value ?? {}), lat: p.lat, lng: p.lng } : null)} />;
}
