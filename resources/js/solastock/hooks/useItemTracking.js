import { useApiQuery } from './useApiQuery.js';
import { api } from '../services/api.js';

/**
 * Loads the item list once and returns a lookup for an item's tracking flags so
 * document line editors can show lot/serial/expiry capture only where required.
 */
export function useItemTracking() {
    const { data } = useApiQuery(['items-picker'], () => api.items({ per_page: 200, is_active: true }), { fallback: [] });
    const items = Array.isArray(data) ? data : (data?.data ?? []);
    const byId = (id) => items.find((it) => it.id === id) ?? {};
    return {
        items,
        trackingOf: byId,
        tracksLot: (id) => ['lot', 'lot_serial'].includes(byId(id).tracking_type),
        tracksSerial: (id) => ['serial', 'lot_serial'].includes(byId(id).tracking_type),
        tracksExpiry: (id) => !!byId(id).tracks_expiry,
    };
}
