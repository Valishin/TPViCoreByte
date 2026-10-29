import { defineStore } from 'pinia';
import pos, { errorCode } from '@/services/pos';

/**
 * Autorización de encargado sin cerrar la sesión del camarero.
 *
 * withAuthorization(action, fn): llama a fn(null); si la API responde
 * "authorization_required", abre el modal "Autorizar" y repite fn(token)
 * con el token de un solo uso que devuelve el PIN del encargado.
 */
export const useAuthorizeStore = defineStore('authorize', {
    state: () => ({ request: null }), // { action, label, resolve, reject }
    actions: {
        ask(action, label) {
            return new Promise((resolve, reject) => {
                this.request = { action, label, resolve, reject };
            });
        },
        async grant(managerId, pin) {
            const token = await pos.authorize(managerId, pin, this.request.action);
            this.request.resolve(token);
            this.request = null;
        },
        cancel() {
            this.request?.reject(new Error('cancelled'));
            this.request = null;
        },
        async withAuthorization(action, fn, label = null) {
            try {
                return await fn(null);
            } catch (error) {
                if (errorCode(error) !== 'authorization_required') throw error;
                const token = await this.ask(action, label ?? error.response.data.message);
                return fn(token);
            }
        },
    },
});

export function isCancelled(error) {
    return error?.message === 'cancelled';
}
