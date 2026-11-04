import { defineStore } from 'pinia';
import pos from '@/services/pos';
import { layout } from '@/composables/useLayout';

/**
 * Camarero actual y bloqueo. Al bloquear se cierra la sesión en el servidor,
 * pero se recuerda dónde estaba y quién: si vuelve a entrar el mismo, sigue
 * donde lo dejó; si entra otro, empieza en el plano.
 */
export const useSessionStore = defineStore('session', {
    state: () => ({
        user: null,
        settings: null,
        checked: false,
        lockedBy: null, // { userId, route }
        loginCount: 0, // entradas (PIN o sesión recuperada al abrir el programa)
    }),
    getters: {
        isLoggedIn: (s) => !!s.user,
        isCourier: (s) => s.user?.role === 'repartidor',
        hasType: (s) => (type) => (s.settings?.enabled_order_types ?? []).includes(type),
        /** Sala (mesa o barra) y/o pedidos para llevar / a domicilio activos en el local. */
        hasFloor() {
            return this.hasType('mesa') || this.hasType('barra');
        },
        hasTakeaway() {
            return this.hasType('para_llevar') || this.hasType('domicilio');
        },
        /** Pantalla de inicio: repartidor → sus repartos; sin sala → Pedidos. */
        home() {
            if (this.isCourier) return '/repartos';
            return this.hasFloor || !this.hasTakeaway ? '/mesas' : '/pedidos';
        },
        lockMinutes: (s) =>
            layout.value === 'pos' ? s.settings?.auto_lock_minutes_pos ?? 0 : s.settings?.auto_lock_minutes_mobile ?? 0,
    },
    actions: {
        async check() {
            try {
                const me = await pos.me();
                this.user = me.user;
                this.settings = me.settings;
                this.loginCount++;   // al abrir el programa con la sesión ya iniciada, también
            } catch {
                this.user = null;
            } finally {
                this.checked = true;
            }
        },
        async login(userId, pin) {
            const me = await pos.login(userId, pin);
            this.user = me.user;
            this.settings = me.settings;
            const resume = this.lockedBy?.userId === me.user.id ? this.lockedBy.route : null;
            const sameUser = this.lockedBy ? this.lockedBy.userId === me.user.id : true;
            this.lockedBy = null;
            this.loginCount++;   // CashPrompt: al entrar, si la caja está cerrada, ofrece abrirla
            return { resume, sameUser };
        },
        async lock(currentRoute) {
            if (!this.user) return;
            this.lockedBy = { userId: this.user.id, route: currentRoute };
            this.user = null;
            await pos.logout().catch(() => {});
        },
    },
});
