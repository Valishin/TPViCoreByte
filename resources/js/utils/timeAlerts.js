import { timeLeft } from '@/stores/takeaway';
import { duration, minutesSince } from '@/utils/format';

/** «8 min» o, desde una hora, «1 h 20» (como el resto de la TPV). */
const span = (minutes) => (minutes < 60 ? `${minutes} min` : duration(minutes));

/**
 * Avisos de tiempo (ver TimeAlerts.vue). Puro: recibe las mesas, los pedidos de
 * llevar / domicilio, los ajustes y la hora, y devuelve los avisos a mostrar
 * (los tarde primero, después los que menos margen tienen).
 *
 * @returns {Array<{key: string, level: 'soon'|'late', text: string, path: string, rank: number}>}
 */
export function timeAlerts({ tables = [], takeaways = [], settings = {}, now = Date.now() }) {
    if (!settings?.enabled) return [];
    const alerts = [];

    // Llevar y domicilio: cerca de la hora prometida o tarde.
    for (const o of takeaways) {
        // Solo lo que aún hay que preparar o entregar en el local (en reparto ya ha salido).
        // (Un para llevar ya cobrado sigue esperando a que lo recojan: también avisa.)
        if (o.status === 'cancelado' || ['en_reparto', 'entregado', 'no_entregado'].includes(o.delivery_status)) continue;
        const left = timeLeft(o, now);
        if (!left) continue;
        const minutes = Math.round((new Date(o.promised_at).getTime() - now) / 60000);
        if (minutes > (settings.takeaway_minutes ?? 10)) continue;
        const kind = o.type === 'domicilio' ? 'Domicilio' : 'Para llevar';
        const level = minutes < 0 ? 'late' : 'soon';
        alerts.push({
            key: `pedido:${o.id}:${level}`,
            level,
            text: `${kind} · ${o.customer_name || 'Sin nombre'} · ${level === 'late' ? `tarde ${span(-minutes)}` : minutes === 0 ? 'es ahora' : `quedan ${span(minutes)}`}`,
            path: `/pedidos/${o.id}`,
            rank: minutes,
        });
    }

    // Mesas abiertas demasiado tiempo (0 = sin aviso). Mesas unidas: un solo aviso, el de la principal.
    const limit = settings.table_minutes ?? 0;
    if (limit > 0) {
        for (const t of tables) {
            if (!t.order?.opened_at || (t.group && !t.group.is_main)) continue;
            const open = minutesSince(t.order.opened_at, now);
            if (open < limit) continue;
            alerts.push({
                key: `mesa:${t.order.id}:soon`,
                level: 'soon',
                text: `${t.group?.label ?? `Mesa ${t.name}`} · ${duration(open)} abierta`,
                path: `/mesas/pedido/${t.order.id}`,
                rank: limit - open,
            });
        }
    }

    return alerts.sort((a, b) => (a.level === b.level ? a.rank - b.rank : a.level === 'late' ? -1 : 1));
}
