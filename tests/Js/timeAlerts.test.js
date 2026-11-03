import { describe, expect, it } from 'vitest';
import { timeAlerts } from '@/utils/timeAlerts';

const NOW = new Date('2026-11-03T21:00:00Z').getTime();
const at = (minutes) => new Date(NOW + minutes * 60000).toISOString();
const settings = { enabled: true, takeaway_minutes: 10, table_minutes: 120 };

describe('Avisos de tiempo', () => {
    it('desactivados en /admin: ninguno', () => {
        expect(timeAlerts({ takeaways: [{ id: 1, type: 'domicilio', promised_at: at(-5) }], settings: { ...settings, enabled: false }, now: NOW })).toEqual([]);
    });

    it('llevar y domicilio: cerca de la hora o tarde (los tarde primero); con margen o ya salido, no', () => {
        const alerts = timeAlerts({
            takeaways: [
                { id: 1, type: 'domicilio', customer_name: 'Lucía', promised_at: at(8), delivery_status: 'en_cocina', status: 'abierto' },
                { id: 2, type: 'para_llevar', customer_name: 'Pep', promised_at: at(-3), delivery_status: 'listo', status: 'cerrado' },
                { id: 3, type: 'domicilio', customer_name: 'Ana', promised_at: at(40), delivery_status: 'en_cocina', status: 'abierto' },
                { id: 4, type: 'domicilio', customer_name: 'Joan', promised_at: at(-20), delivery_status: 'en_reparto', status: 'cerrado' },
                { id: 5, type: 'para_llevar', customer_name: 'Mar', promised_at: at(-1), delivery_status: 'pendiente', status: 'cancelado' },
            ],
            settings,
            now: NOW,
        });
        expect(alerts.map((a) => [a.level, a.text, a.path])).toEqual([
            ['late', 'Para llevar · Pep · tarde 3 min', '/pedidos/2'],
            ['soon', 'Domicilio · Lucía · quedan 8 min', '/pedidos/1'],
        ]);
    });

    it('mesas abiertas más del límite; las unidas, un solo aviso; 0 = sin aviso', () => {
        const tables = [
            { id: 1, name: '4', order: { id: 10, opened_at: at(-130) } },
            { id: 2, name: '5', order: { id: 11, opened_at: at(-30) } },
            { id: 3, name: '6', order: { id: 12, opened_at: at(-150) }, group: { label: 'Mesa 6 + 7', is_main: true } },
            { id: 4, name: '7', order: { id: 12, opened_at: at(-150) }, group: { label: 'Mesa 6 + 7', is_main: false } },
        ];
        expect(timeAlerts({ tables, settings, now: NOW }).map((a) => a.text)).toEqual(['Mesa 6 + 7 · 2 h 30 abierta', 'Mesa 4 · 2 h 10 abierta']);
        expect(timeAlerts({ tables, settings: { ...settings, table_minutes: 0 }, now: NOW })).toEqual([]);
    });
});
