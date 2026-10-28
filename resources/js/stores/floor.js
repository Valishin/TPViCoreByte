import { defineStore } from 'pinia';
import pos from '@/services/pos';

/** Plano: salas y mesas con el resumen de su pedido abierto. */
export const useFloorStore = defineStore('floor', {
    state: () => ({ rooms: [], roomId: null, loaded: false }),
    getters: {
        currentRoom: (s) => s.rooms.find((r) => r.id === s.roomId) ?? s.rooms[0],
        tables: (s) => s.rooms.flatMap((r) => r.tables.map((t) => ({ ...t, room: r.name }))),
        freeTables() {
            return this.tables.filter((t) => t.status === 'libre');
        },
    },
    actions: {
        async load() {
            this.rooms = await pos.rooms();
            if (!this.rooms.some((r) => r.id === this.roomId)) {
                this.roomId = this.rooms[0]?.id ?? null;
            }
            this.loaded = true;
        },
        /** Recarga con un pequeño debounce (llegan varios eventos seguidos). */
        refresh() {
            clearTimeout(this._timer);
            this._timer = setTimeout(() => this.load().catch(() => {}), 150);
        },
    },
});
