import { defineStore } from 'pinia';
import pos from '@/services/pos';

let nextId = 1;

/**
 * Avisos: toasts (desaparecen solos) y avisos PERSISTENTES de impresión
 * (impresoras con error y envíos fallidos), que no se van hasta resolverlos.
 */
export const useNoticesStore = defineStore('notices', {
    state: () => ({
        toasts: [],
        printers: [],
        failedJobs: [],
    }),
    getters: {
        printersInError: (s) => s.printers.filter((p) => p.status === 'error'),
        activePrinters: (s) => s.printers,
    },
    actions: {
        toast(text, type = 'info', ms = 3000) {
            const id = nextId++;
            this.toasts.push({ id, text, type });
            setTimeout(() => this.dismiss(id), ms);
        },
        success(text) {
            this.toast(text, 'success');
        },
        error(text) {
            this.toast(text, 'error', 5000);
        },
        dismiss(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },
        async loadPrinting() {
            const [printers, failed] = await Promise.all([pos.printers(), pos.failedJobs()]);
            this.printers = printers;
            this.failedJobs = failed;
        },
        updatePrinter({ printerId, status, error }) {
            const printer = this.printers.find((p) => p.id === printerId);
            if (printer) Object.assign(printer, { status, error });
        },
        async retry(job) {
            await pos.retryJob(job.id);
            this.failedJobs = this.failedJobs.filter((j) => j.id !== job.id);
            this.toast(`Reintentando en ${job.printer?.name}…`);
        },
        async redirect(job, printerId) {
            const updated = await pos.redirectJob(job.id, printerId);
            this.failedJobs = this.failedJobs.filter((j) => j.id !== job.id);
            this.toast(`Enviado a ${updated.printer?.name}`);
        },
    },
});
