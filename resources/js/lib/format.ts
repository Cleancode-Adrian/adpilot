export const money = (v: number | null | undefined, currency = 'PLN', digits = 0) =>
    v === null || v === undefined
        ? '–'
        : new Intl.NumberFormat('pl-PL', { style: 'currency', currency, maximumFractionDigits: digits, minimumFractionDigits: digits }).format(v);

export const num = (v: number | null | undefined, digits = 0) =>
    v === null || v === undefined ? '–' : new Intl.NumberFormat('pl-PL', { maximumFractionDigits: digits, minimumFractionDigits: digits }).format(v);

export const pct = (v: number | null | undefined, digits = 0) => (v === null || v === undefined ? '–' : `${num(v, digits)}%`);

/** ROAS jako procent (4.5 → 450%) */
export const roas = (v: number | null | undefined) => (v === null || v === undefined ? '–' : `${num(v * 100, 0)}%`);

export const date = (v: string | null | undefined) => (v ? new Date(v).toLocaleDateString('pl-PL') : '–');

export const dateTime = (v: string | null | undefined) =>
    v ? new Date(v).toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' }) : '–';

export const platformName: Record<string, string> = {
    google_ads: 'Google Ads',
    meta_ads: 'Meta Ads',
    tracking: 'Pomiar',
    analytics: 'GA4',
    account: 'Konto',
};

/** Kolory serii – zwalidowana paleta kategoryczna (sloty 1–3), osobno dla trybu ciemnego. */
export const seriesColor = {
    google_ads: 'var(--series-google)',
    meta_ads: 'var(--series-meta)',
    revenue: 'var(--series-revenue)',
};
