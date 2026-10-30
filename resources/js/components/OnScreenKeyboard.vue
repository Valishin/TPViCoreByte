<template>
    <Teleport to="body">
        <div
            v-if="visible"
            ref="root"
            class="c-keyboard"
            :class="{ 'c-keyboard--numeric': keyboard.mode === 'numeric' }"
            role="group"
            aria-label="Teclado en pantalla"
            @pointerdown.prevent
            @mousedown.prevent
        >
            <!-- El campo que se edita, siempre visible encima del teclado -->
            <div class="c-keyboard__preview" aria-hidden="true">
                <span v-if="keyboard.label" class="c-keyboard__label">{{ keyboard.label }}</span>
                <span class="c-keyboard__value">{{ keyboard.value }}<span class="c-keyboard__caret"></span></span>
                <!-- Aviso del campo (NIF no válido, zona por CP…): se ve mientras se teclea -->
                <span v-if="keyboard.hint" :class="['c-keyboard__hint', { 'c-keyboard__hint--error': keyboard.hintError }]">{{ keyboard.hint }}</span>
            </div>

            <div v-if="keyboard.mode === 'numeric'" class="c-keyboard__grid">
                <button
                    v-for="k in numericKeys"
                    :key="k.id"
                    type="button"
                    tabindex="-1"
                    :class="keyClass(k)"
                    :aria-label="k.aria"
                    @click="press(k)"
                >{{ k.text }}</button>
            </div>

            <template v-else>
                <div v-for="(row, i) in textRows" :key="i" class="c-keyboard__row">
                    <button
                        v-for="k in row"
                        :key="k.id"
                        type="button"
                        tabindex="-1"
                        :class="keyClass(k)"
                        :style="k.grow ? { flexGrow: k.grow } : null"
                        :aria-label="k.aria"
                        :aria-pressed="k.pressed?.() ?? null"
                        @click="press(k)"
                    >{{ k.label ? k.label() : k.text }}</button>
                </div>
            </template>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    applyInputMode, backspace, closeKeyboard, done, hideKeyboard, insertText, isTextField, keyboard, keyboardEnabled,
    letterFor, openKeyboard, pressAccent, suppressSystemKeyboard, syncHint, toggleShift, typeLetter,
} from '@/composables/useOnScreenKeyboard';
import { useSessionStore } from '@/stores/session';

const session = useSessionStore();
const root = ref(null);
const visible = computed(() => keyboardEnabled.value && !!keyboard.target && !keyboard.hidden);

// --- Disposición de teclas --------------------------------------------------
const letter = (c) => ({ id: c, type: 'letter', base: c, label: () => letterFor(c) });
const char = (c, extra = {}) => ({ id: c, type: 'char', text: c, ...extra });
const BACK = { id: 'back', type: 'back', text: '⌫', aria: 'Borrar', grow: 1.5, mod: 'action' };
const DONE = { id: 'done', type: 'done', text: 'Hecho', grow: 1.5, mod: 'primary' };
const HIDE = { id: 'hide', type: 'hide', text: 'Ocultar ⌄', aria: 'Ocultar el teclado', grow: 1.5, mod: 'action' };

const textRows = [
    [...'1234567890'].map((c) => char(c)).concat(BACK),
    [...'qwertyuiop'].map(letter).concat({ id: '´', type: 'accent', text: '´', aria: 'Tilde', mod: 'accent', pressed: () => keyboard.accent === '´' }),
    [...'asdfghjklñ'].map(letter).concat(DONE),
    [{
        id: 'shift', type: 'shift', aria: 'Mayúsculas', grow: 1.5, mod: 'action',
        label: () => (keyboard.shift === 'lock' ? '⇪' : '⇧'), pressed: () => keyboard.shift !== 'off',
    }, ...[...'zxcvbnm'].map(letter), char(','), char('.'), char('-')],
    [
        { id: '¨', type: 'accent', text: '¨', aria: 'Diéresis', mod: 'accent', pressed: () => keyboard.accent === '¨' },
        char('@'), char('/'),
        { id: 'space', type: 'char', text: 'espacio', value: ' ', aria: 'Espacio', grow: 5, mod: 'space' },
        char('º'), char('ª'), char('#'),
        HIDE,
    ],
];

const numericKeys = [
    ...'123'.split('').map((c) => char(c)), BACK,
    ...'456'.split('').map((c) => char(c)), char('+'),
    ...'789'.split('').map((c) => char(c)), HIDE,
    char('-'), char('0'), char('.'), DONE,
];

function keyClass(k) {
    return [
        'c-keyboard__key',
        k.mod && `c-keyboard__key--${k.mod}`,
        k.pressed?.() && 'c-keyboard__key--on',
        k.type === 'shift' && keyboard.shift === 'lock' && 'c-keyboard__key--locked',
    ];
}

function press(k) {
    const el = keyboard.target;
    if (!el) return;
    if (document.activeElement !== el) el.focus({ preventScroll: true });
    switch (k.type) {
        case 'letter': typeLetter(k.base); break;
        case 'char': insertText(k.value ?? k.text); break;
        case 'accent': pressAccent(k.text); break;
        case 'shift': toggleShift(); break;
        case 'back': backspace(); break;
        case 'done': done(); break;
        case 'hide': hideKeyboard(); break;
    }
}

// --- Campo que se edita --------------------------------------------------------
function keyboardHeight() {
    return root.value?.offsetHeight ?? 0;
}

// El scroll no se toca al enfocar un campo: solo se añade espacio abajo para
// poder bajar a mano (y el teclado enseña arriba lo que se escribe).
// setTimeout y no requestAnimationFrame: rAF no corre si la ventana no está en primer plano.
function schedulePad() {
    nextTick(() => setTimeout(padScroller, 0));
}

/**
 * Con el teclado abierto, el contenedor que hace scroll recibe abajo un margen
 * igual a lo que el teclado le tapa: se puede bajar hasta el final y ver lo que
 * se escribe aunque el campo esté abajo del todo. Se quita al cerrar.
 */
let padded = null; // { el, prev }
function padScroller() {
    unpadScroller();
    const el = keyboard.target;
    if (!el || !visible.value) return;
    const scroller = scrollParent(el);
    if (!scroller) return; // la página entera: ya lleva padding-bottom en el body
    const covered = scroller.getBoundingClientRect().bottom - (window.innerHeight - keyboardHeight());
    if (covered <= 0) return; // hojas y modales: ya están por encima del teclado
    const base = parseFloat(getComputedStyle(scroller).paddingBottom) || 0;
    padded = { el: scroller, prev: scroller.style.paddingBottom };
    scroller.style.paddingBottom = `${base + covered + 16}px`;
}
function unpadScroller() {
    if (padded) padded.el.style.paddingBottom = padded.prev;
    padded = null;
}

function scrollParent(el) {
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
        const { overflowY } = getComputedStyle(p);
        if (overflowY === 'auto' || overflowY === 'scroll') return p;
    }
    return null;
}

// El aviso del campo cambia al teclear (lo pone Vue en data-osk-hint / aria-invalid).
let hintObserver = null;
watch(() => keyboard.target, (el) => {
    hintObserver?.disconnect();
    if (!el) return;
    hintObserver = new MutationObserver(() => syncHint(el));
    hintObserver.observe(el, { attributes: true, attributeFilter: ['data-osk-hint', 'aria-invalid'] });
    syncHint(el);
});

let resizeObserver = null;
watch(visible, async (open) => {
    const html = document.documentElement;
    resizeObserver?.disconnect();
    if (!open) {
        unpadScroller();
        html.style.setProperty('--keyboard-height', '0px');
        delete html.dataset.keyboard;
        return;
    }
    await nextTick();
    html.dataset.keyboard = 'open';
    const update = () => {
        html.style.setProperty('--keyboard-height', `${keyboardHeight()}px`);
        schedulePad();
    };
    resizeObserver = new ResizeObserver(update);
    resizeObserver.observe(root.value);
    update();
});

// --- Escucha global: cualquier campo de texto de la app -------------------------
function onFocusIn(e) {
    const el = e.target;
    if (!isTextField(el)) return;
    applyInputMode(el);
    if (keyboardEnabled.value) {
        openKeyboard(el);
        schedulePad();
    }
}

/** Tocar el campo con el teclado oculto lo vuelve a mostrar (el foco no cambia: no hay focusin). */
function onPointerDown(e) {
    if (keyboard.hidden && e.target === keyboard.target) {
        keyboard.hidden = false;
        schedulePad();
    }
}

function onFocusOut() {
    // Espera a saber a dónde va el foco: a otro campo → se queda; a otra cosa → se cierra.
    setTimeout(() => {
        if (keyboard.target && !isTextField(document.activeElement)) closeKeyboard();
    }, 0);
}

function onInput(e) {
    if (e.target === keyboard.target) keyboard.value = e.target.value; // también con el teclado físico
}

function applyAll(scope = document) {
    scope.querySelectorAll?.('input, textarea').forEach(applyInputMode);
}

const mutations = new MutationObserver((records) => {
    for (const r of records) {
        r.addedNodes.forEach((n) => {
            if (n.nodeType !== 1) return;
            applyInputMode(n);
            applyAll(n);
        });
    }
    if (keyboard.target && !keyboard.target.isConnected) closeKeyboard();
});

watch(suppressSystemKeyboard, () => applyAll());
watch(keyboardEnabled, (on) => {
    if (!on) closeKeyboard();
    else if (isTextField(document.activeElement)) openKeyboard(document.activeElement);
});
watch(
    () => session.settings?.show_onscreen_keyboard_pos,
    (value) => { keyboard.enabledSetting = value !== false; },
    { immediate: true },
);

onMounted(() => {
    document.addEventListener('focusin', onFocusIn);
    document.addEventListener('pointerdown', onPointerDown, true);
    document.addEventListener('focusout', onFocusOut);
    document.addEventListener('input', onInput, true);
    mutations.observe(document.body, { childList: true, subtree: true });
    applyAll();
});
onBeforeUnmount(() => {
    document.removeEventListener('focusin', onFocusIn);
    hintObserver?.disconnect();
    document.removeEventListener('pointerdown', onPointerDown, true);
    document.removeEventListener('focusout', onFocusOut);
    document.removeEventListener('input', onInput, true);
    mutations.disconnect();
    resizeObserver?.disconnect();
});
</script>
