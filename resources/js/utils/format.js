/**
 * Formato de importes: el servidor manda céntimos (enteros). Solo aritmética
 * entera: 1250 → "12,50 €".
 */
export function money(cents) {
    if (cents === null || cents === undefined) return '';
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(Math.trunc(cents));
    const units = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const decimals = String(abs % 100).padStart(2, '0');
    return `${sign}${units},${decimals} €`;
}

/** "+1,00 €" para incrementos de precio (vacío si es 0). */
export function delta(cents) {
    if (!cents) return '';
    return (cents > 0 ? '+' : '') + money(cents);
}

/** 1 → "1.er", 2 → "2.º", 3 → "3.er" … */
export function ordinal(n) {
    return [1, 3].includes(n % 10) && n % 100 !== 11 && n % 100 !== 13 ? `${n}.er` : `${n}.º`;
}

export function time(iso) {
    return iso ? new Date(iso).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) : '';
}

/** Minutos desde una fecha ISO hasta "now" (ms). */
export function minutesSince(iso, now = Date.now()) {
    return iso ? Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000)) : 0;
}

/** 75 → "1 h 15", 12 → "12 min" */
export function duration(minutes) {
    if (minutes < 60) return `${minutes} min`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m ? `${h} h ${String(m).padStart(2, '0')}` : `${h} h`;
}

/**
 * Identificador aleatorio (clave de idempotencia). crypto.randomUUID solo
 * existe en HTTPS/localhost; getRandomValues funciona también por http en la LAN.
 */
export function uid() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

/** Estado del envío de una factura por email. */
export function emailStatus(email) {
    if (!email) return '';
    if (email.status === 'enviado') return email.sent_at ? `enviada a las ${time(email.sent_at)}` : 'enviada';
    if (email.status === 'error') return `error, se reintentará${email.error ? ` (${email.error})` : ''}`;
    return 'en cola';
}
