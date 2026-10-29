/**
 * Kiosko (Chromium en el mini PC abre http://localhost/?kiosk=1): el cursor del
 * ratón se oculta tras 3 s quieto y vuelve al moverlo. En Wayland (cage) no hay
 * un "unclutter" fiable, así que lo hace la propia página. Con el táctil,
 * wlroots ya lo oculta solo. Se recuerda en el dispositivo.
 */
const KEY = 'tpv.kiosk';
const IDLE_MS = 3000;

function isKiosk() {
    const param = new URLSearchParams(window.location.search).get('kiosk');
    try {
        if (param !== null) localStorage.setItem(KEY, param === '0' ? '0' : '1');
        return localStorage.getItem(KEY) === '1';
    } catch {
        return param === '1';
    }
}

export function startKioskCursor() {
    if (!isKiosk()) return;
    const html = document.documentElement;
    html.dataset.kiosk = '1';
    let timer = null;
    const show = () => {
        delete html.dataset.cursor;
        clearTimeout(timer);
        timer = setTimeout(() => (html.dataset.cursor = 'hidden'), IDLE_MS);
    };
    window.addEventListener('pointermove', show, { passive: true });
    window.addEventListener('pointerdown', show, { passive: true });
    show();
}
