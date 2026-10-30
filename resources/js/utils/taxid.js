/**
 * Validación en vivo del NIF/NIE/CIF (la misma que App\Support\TaxId; el
 * servidor vuelve a validar). NIF-IVA de la UE: solo prefijo y longitud aquí.
 */
const LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';
const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];

export const normalizeTaxId = (v) => String(v ?? '').toUpperCase().replace(/[\s.\-/]+/g, '');

export function detectTaxId(value) {
    const v = normalizeTaxId(value);
    if (/^\d{8}[A-Z]$/.test(v)) return 'nif';
    if (/^[XYZKLM]\d{7}[A-Z]$/.test(v)) return 'nie';
    if (/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/.test(v)) return 'cif';
    if (/^[A-Z]{2}[A-Z0-9+*]{2,13}$/.test(v) && EU.includes(v.slice(0, 2))) return 'vat_eu';
    return null;
}

function validCif(v) {
    const [, type, digits, control] = v.match(/^([ABCDEFGHJNPQRSUVW])(\d{7})([0-9A-J])$/) ?? [];
    if (!type) return false;
    let sum = 0;
    for (let i = 0; i < 7; i++) {
        const n = Number(digits[i]);
        sum += i % 2 === 0 ? String(n * 2).split('').reduce((a, d) => a + Number(d), 0) : n;
    }
    const check = (10 - (sum % 10)) % 10;
    const letter = 'JABCDEFGHI'[check];
    if ('PQRSNW'.includes(type)) return control === letter;
    if ('ABEH'.includes(type)) return control === String(check);
    return control === String(check) || control === letter;
}

/** Mensaje de error, o null si vale (o si aún está vacío). */
export function taxIdError(value, type = null) {
    const v = normalizeTaxId(value);
    if (!v) return null;
    const t = type || detectTaxId(v);
    switch (t) {
        case 'nif':
            return /^\d{8}[A-Z]$/.test(v) && LETTERS[Number(v.slice(0, 8)) % 23] === v[8] ? null : 'El NIF no es válido: revisa las cifras y la letra.';
        case 'nie': {
            const ok = /^[XYZKLM]\d{7}[A-Z]$/.test(v) && LETTERS[Number(({ X: '0', Y: '1', Z: '2' }[v[0]] ?? '') + v.slice(1, 8)) % 23] === v[8];
            return ok ? null : 'El NIE no es válido: revisa las cifras y la letra.';
        }
        case 'cif':
            return validCif(v) ? null : 'El CIF no es válido: revisa el dígito o la letra de control.';
        case 'vat_eu':
            if (v.startsWith('ES')) return taxIdError(v.slice(2)) ? 'El NIF-IVA español no es válido.' : null;
            return v.length >= 6 ? null : 'El NIF-IVA no tiene el formato del país.';
        case 'otro':
            return v.length >= 4 && v.length <= 20 ? null : 'El documento debe tener entre 4 y 20 caracteres.';
        default:
            return 'No es un NIF, NIE, CIF ni NIF-IVA de la UE válido.';
    }
}

export const taxIdLabels = { nif: 'NIF', nie: 'NIE', cif: 'CIF', vat_eu: 'NIF-IVA UE', otro: 'Otro (pasaporte…)' };
