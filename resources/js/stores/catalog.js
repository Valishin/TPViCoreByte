import { defineStore } from 'pinia';
import pos from '@/services/pos';

const CACHE_KEY = 'tpv.catalog';

function readCache() {
    try {
        const cached = JSON.parse(localStorage.getItem(CACHE_KEY) || 'null');
        // Descartar cachés con otra forma (versiones antiguas).
        return Array.isArray(cached?.data?.categories) && Array.isArray(cached?.data?.products) ? cached : null;
    } catch {
        return null;
    }
}

/** Carta cacheada en el dispositivo; solo se recarga si cambia la versión. */
export const useCatalogStore = defineStore('catalog', {
    state: () => {
        const cached = readCache();
        return { version: cached?.version ?? null, data: cached?.data ?? null, loading: false };
    },
    getters: {
        categories: (s) => s.data?.categories ?? [],
        products: (s) => s.data?.products ?? [],
        productById: (s) => (id) => s.data?.products.find((p) => p.id === id),
        productsOf: (s) => (categoryId) => (s.data?.products ?? []).filter((p) => p.category_id === categoryId),
        groupById: (s) => (id) => s.data?.modifier_groups.find((g) => g.id === id),
        groupsOf() {
            return (product) => (product?.modifier_group_ids ?? []).map((id) => this.groupById(id)).filter(Boolean);
        },
        halfCandidates: (s) => (product) =>
            (s.data?.products ?? []).filter((p) => p.allows_half_and_half && p.available && p.id !== product.id),
    },
    actions: {
        async load() {
            this.loading = true;
            try {
                const fresh = await pos.catalog(this.data ? this.version : null);
                if (fresh) {
                    this.version = fresh.version;
                    this.data = fresh.data;
                    try {
                        localStorage.setItem(CACHE_KEY, JSON.stringify(fresh));
                    } catch {
                        // sin caché local: se pedirá de nuevo la próxima vez
                    }
                }
            } finally {
                this.loading = false;
            }
        },
        /** ¿Hace falta abrir el configurador para este producto? */
        needsConfig(product) {
            return (
                product.type !== 'simple' ||
                product.variants.length > 0 ||
                product.modifier_group_ids.length > 0 ||
                product.allows_half_and_half
            );
        },
    },
});
