import { computed, reactive } from 'vue';
import { layout } from '@/composables/useLayout';

/**
 * Teclado en pantalla de la caja (docs/diseno-tactil.md).
 *
 * - En caja ningún campo de texto abre el teclado del sistema: se les pone
 *   inputmode="none" (el teclado físico sigue funcionando).
 * - Si el ajuste "Mostrar teclado en pantalla en caja" está activo, al enfocar
 *   un campo de texto aparece el teclado propio (c-keyboard).
 * - En móvil no se toca nada: se usa el teclado del teléfono.
 *
 * Cualquier <input> de texto o <textarea> lo tiene sin hacer nada. Para pedir
 * el modo numérico: v-keyboard="'numeric'" o <TextField keyboard="numeric">,
 * o inputmode="numeric|tel|decimal" / type="tel|number". data-keyboard="off"
 * lo desactiva en un campo concreto.
 */
const TEXT_TYPES = ['', 'text', 'search', 'email', 'url', 'tel', 'number', 'password'];

export const keyboard = reactive({
    enabledSetting: true, // ajuste del local (session.settings.show_onscreen_keyboard_pos)
    target: null,         // campo que se está editando
    mode: 'text',         // 'text' | 'numeric'
    label: '',
    value: '',
    shift: 'off',         // 'off' | 'once' | 'lock'
    accent: null,         // tecla muerta pendiente: '´' | '¨'
    dirty: false,         // se ha escrito con el teclado en pantalla desde que se enfocó
    hidden: false,        // "Ocultar": el campo sigue activo (teclado físico); vuelve al tocarlo
});

/** En caja nunca se abre el teclado del sistema. */
export const suppressSystemKeyboard = computed(() => layout.value === 'pos');
export const keyboardEnabled = computed(() => layout.value === 'pos' && keyboard.enabledSetting);

export function isTextField(el) {
    if (!el || el.disabled || el.readOnly || el.dataset?.keyboard === 'off') return false;
    if (el.tagName === 'TEXTAREA') return true;
    return el.tagName === 'INPUT' && TEXT_TYPES.includes((el.getAttribute('type') || '').toLowerCase());
}

export function modeOf(el) {
    if (el.dataset.keyboard === 'numeric' || el.dataset.keyboard === 'text') return el.dataset.keyboard;
    const im = el.dataset.oskInputmode ?? el.getAttribute('inputmode') ?? '';
    const type = (el.getAttribute('type') || '').toLowerCase();
    return ['numeric', 'tel', 'decimal'].includes(im) || ['tel', 'number'].includes(type) ? 'numeric' : 'text';
}

function labelOf(el) {
    const own = el.closest('label')?.querySelector('.c-field__label')?.textContent
        ?? el.labels?.[0]?.textContent
        ?? el.getAttribute('aria-label')
        ?? '';
    return own.trim() || el.getAttribute('placeholder') || '';
}

/** inputmode="none" en caja (guardando el original) o el original fuera de ella. */
export function applyInputMode(el) {
    if (!isTextField(el)) return;
    if (suppressSystemKeyboard.value) {
        if (el.dataset.oskInputmode === undefined) el.dataset.oskInputmode = el.getAttribute('inputmode') ?? '';
        el.setAttribute('inputmode', 'none');
    } else if (el.dataset.oskInputmode !== undefined) {
        el.dataset.oskInputmode ? el.setAttribute('inputmode', el.dataset.oskInputmode) : el.removeAttribute('inputmode');
        delete el.dataset.oskInputmode;
    }
}

function wantsCapital(el) {
    if (keyboard.mode !== 'text' || keyboard.shift === 'lock') return false;
    const type = (el.getAttribute('type') || '').toLowerCase();
    if (['email', 'url', 'password'].includes(type) || el.getAttribute('autocapitalize') === 'off') return false;
    const before = el.value.slice(0, el.selectionStart ?? el.value.length);
    return before.trim() === '' || /[.!?]\s+$/.test(before);
}

/**
 * El navegador no lanza "change" al soltar un campo cuyo valor se ha cambiado
 * por código: se lanza aquí para que se guarde igual que con el teclado físico.
 */
function commit(el) {
    if (el && keyboard.dirty) el.dispatchEvent(new Event('change', { bubbles: true }));
    keyboard.dirty = false;
}

export function openKeyboard(el) {
    if (keyboard.target && keyboard.target !== el) commit(keyboard.target);
    keyboard.target = el;
    keyboard.hidden = false;
    keyboard.mode = modeOf(el);
    keyboard.label = labelOf(el);
    keyboard.value = el.value;
    keyboard.accent = null;
    keyboard.shift = wantsCapital(el) ? 'once' : 'off';
}

export function closeKeyboard() {
    commit(keyboard.target);
    keyboard.target = null;
    keyboard.accent = null;
    keyboard.hidden = false;
}

/** Oculta el teclado sin soltar el campo: se puede seguir con el físico. Al tocar el campo, vuelve. */
export function hideKeyboard() {
    keyboard.hidden = true;
    keyboard.accent = null;
}

/** Notifica el cambio como si se hubiera tecleado (v-model escucha "input"). */
function emitInput(el) {
    el.dispatchEvent(new Event('input', { bubbles: true }));
    keyboard.value = el.value;
    keyboard.dirty = true;
}

function selection(el) {
    // email/number no tienen selección: se escribe al final.
    const start = el.selectionStart;
    return start === null || start === undefined
        ? { start: el.value.length, end: el.value.length, ranged: false }
        : { start, end: el.selectionEnd, ranged: true };
}

export function insertText(text) {
    const el = keyboard.target;
    if (!el) return;
    const { start, end, ranged } = selection(el);
    const max = el.maxLength > 0 ? el.maxLength : Infinity;
    if (el.value.length - (end - start) + text.length > max) return;

    if (ranged) el.setRangeText(text, start, end, 'end');
    else el.value += text;
    emitInput(el);
    if (keyboard.shift === 'once') keyboard.shift = 'off';
    if (wantsCapital(el)) keyboard.shift = 'once';
}

export function backspace() {
    const el = keyboard.target;
    if (!el) return;
    keyboard.accent = null;
    const { start, end, ranged } = selection(el);
    if (!ranged) {
        el.value = [...el.value].slice(0, -1).join('');
    } else if (start !== end) {
        el.setRangeText('', start, end, 'end');
    } else if (start > 0) {
        // Un carácter completo aunque sea de 2 unidades UTF-16.
        const prev = [...el.value.slice(0, start)].pop() ?? '';
        el.setRangeText('', start - prev.length, start, 'end');
    } else {
        return;
    }
    emitInput(el);
    if (keyboard.shift !== 'lock') keyboard.shift = wantsCapital(el) ? 'once' : 'off';
}

const ACCENTS = {
    '´': { a: 'á', e: 'é', i: 'í', o: 'ó', u: 'ú' },
    '¨': { u: 'ü', i: 'ï', e: 'ë', a: 'ä', o: 'ö' },
};

/** Letra con mayúsculas y la tilde pendiente aplicadas (lo que se ve en la tecla). */
export function letterFor(base) {
    const accented = keyboard.accent ? ACCENTS[keyboard.accent][base] ?? base : base;
    return keyboard.shift === 'off' ? accented : accented.toLocaleUpperCase('es');
}

export function typeLetter(base) {
    const pending = keyboard.accent;
    const accented = pending ? ACCENTS[pending][base] : null;
    const upper = keyboard.shift !== 'off';
    keyboard.accent = null;
    // Tilde + algo que no la lleva: se escriben las dos cosas.
    const text = (pending && !accented ? pending : '') + (accented ?? base);
    insertText(upper ? text.toLocaleUpperCase('es') : text);
}

export function pressAccent(mark) {
    // Doble pulsación: escribe el signo tal cual.
    if (keyboard.accent === mark) {
        keyboard.accent = null;
        insertText(mark);
        return;
    }
    keyboard.accent = mark;
}

export function toggleShift() {
    keyboard.shift = { off: 'once', once: 'lock', lock: 'off' }[keyboard.shift];
}

/** "Hecho": confirma (change), suelta el campo y cierra. */
export function done() {
    const el = keyboard.target;
    keyboard.dirty = true; // "Hecho" siempre confirma
    closeKeyboard();
    el?.blur();
}
