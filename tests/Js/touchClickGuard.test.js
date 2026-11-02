import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AFTER_SCROLL_MS, startTouchClickGuard } from '@/composables/useTouchClickGuard';

let stop;
let button;
let clicks;
let time;

function pointer(type, x, y, pointerType = 'touch') {
    const e = new Event(type, { bubbles: true, cancelable: true });
    Object.assign(e, { pointerId: 1, pointerType, clientX: x, clientY: y });
    button.dispatchEvent(e);
}
const click = () => button.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 1 }));
const tap = (moveTo = 0, pointerType = 'touch') => {
    pointer('pointerdown', 100, 100, pointerType);
    pointer('pointermove', 100, 100 + moveTo, pointerType);
    pointer('pointerup', 100, 100 + moveTo, pointerType);
    click();
};

beforeEach(() => {
    time = 1000;
    vi.spyOn(performance, 'now').mockImplementation(() => time);
    document.body.innerHTML = '<div id="lista" style="overflow:auto"><button>Pizza</button></div>';
    button = document.querySelector('button');
    clicks = 0;
    button.addEventListener('click', () => clicks++);
    stop = startTouchClickGuard();
});
afterEach(() => {
    stop();
    vi.restoreAllMocks();
});

describe('Scroll con el dedo sin pulsar botones', () => {
    it('un toque normal (o un temblor de pocos píxeles) pulsa', () => {
        tap(0);
        tap(6);
        expect(clicks).toBe(2);
    });

    it('si el dedo se ha arrastrado, no pulsa', () => {
        tap(40);
        expect(clicks).toBe(0);
    });

    it('si la lista ha hecho scroll con el dedo puesto, no pulsa', () => {
        pointer('pointerdown', 100, 100);
        pointer('pointermove', 100, 104);
        document.getElementById('lista').dispatchEvent(new Event('scroll'));
        pointer('pointerup', 100, 104);
        click();
        expect(clicks).toBe(0);
    });

    it('el scroll que hace la propia pantalla no se come el siguiente toque', () => {
        document.getElementById('lista').dispatchEvent(new Event('scroll'));
        time += 20;
        tap(0);
        expect(clicks).toBe(1);
    });

    it('el toque que frena una lista que aún se desliza no pulsa; el siguiente sí', () => {
        pointer('pointerdown', 100, 300);
        pointer('pointermove', 100, 200);
        pointer('pointercancel', 100, 200); // el navegador se queda el gesto para el scroll
        document.getElementById('lista').dispatchEvent(new Event('scroll'));
        time += 50;
        document.getElementById('lista').dispatchEvent(new Event('scroll')); // sigue deslizándose
        time += 50;
        tap(0);
        expect(clicks).toBe(0);
        time += AFTER_SCROLL_MS + 50;
        tap(0);
        expect(clicks).toBe(1);
    });

    it('el ratón y el teclado no se tocan', () => {
        tap(40, 'mouse');
        button.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 0 }));
        expect(clicks).toBe(2);
    });
});
