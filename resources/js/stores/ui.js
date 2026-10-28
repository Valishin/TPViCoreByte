import { defineStore } from 'pinia';
import { layout } from '@/composables/useLayout';

const THEME_KEY = 'tpv.theme';

function stored() {
    try {
        return localStorage.getItem(THEME_KEY);
    } catch {
        return null;
    }
}

/** Tema (oscuro por defecto en caja, claro en móvil) recordado por dispositivo. */
export const useUiStore = defineStore('ui', {
    state: () => ({
        theme: stored() ?? (layout.value === 'pos' ? 'dark' : 'light'),
    }),
    getters: {
        layout: () => layout.value,
        isPos: () => layout.value === 'pos',
    },
    actions: {
        applyTheme() {
            document.documentElement.dataset.theme = this.theme;
        },
        toggleTheme() {
            this.theme = this.theme === 'dark' ? 'light' : 'dark';
            try {
                localStorage.setItem(THEME_KEY, this.theme);
            } catch {
                // modo privado: no se recuerda, pero funciona
            }
            this.applyTheme();
        },
    },
});
