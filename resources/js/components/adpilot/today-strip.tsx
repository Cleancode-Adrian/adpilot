import { dateTime, money, num } from '@/lib/format';
import { Radio } from 'lucide-react';

interface Totals {
    cost: number;
    impressions: number;
    clicks: number;
    link_clicks?: number | null;
    landing_page_views?: number | null;
    conversions: number;
    cpa: number | null;
}
export interface Today extends Totals {
    meta: Totals;
    google: Totals;
    synced_at: string | null;
}

/**
 * Dzisiejsze dane (aktualizowane co godzinę). Dane z dnia bieżącego są wstępne:
 * Meta odświeża je co ~15–30 min, Google Ads z opóźnieniem do ~3 h, konwersje dopisują się później.
 */
export function TodayStrip({ today, currency, leads }: { today: Today; currency: string; leads: boolean }) {
    const meta = today.meta;
    const items: [string, string][] = [
        ['Wydatki', money(today.cost, currency, 2)],
        ['Wyświetlenia', num(today.impressions)],
        ['Kliknięcia', num(today.clicks)],
        ...(meta.impressions > 0 && meta.link_clicks != null
            ? ([
                  ['Meta: kliknięcia w link', num(meta.link_clicks)],
                  ['Meta: wejścia na stronę', num(meta.landing_page_views ?? 0)],
              ] as [string, string][])
            : []),
        [leads ? 'Leady' : 'Konwersje', num(today.conversions, 1)],
    ];

    return (
        <div className="bg-card flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border px-4 py-3">
            <div className="flex items-center gap-2">
                <Radio className="size-4 text-[#1baf7a]" />
                <div>
                    <div className="text-sm font-semibold">Dziś</div>
                    <div className="text-muted-foreground text-[11px]">
                        {today.synced_at ? `stan na ${dateTime(today.synced_at)}` : 'dane wstępne'}
                    </div>
                </div>
            </div>
            {items.map(([label, value]) => (
                <div key={label}>
                    <div className="text-muted-foreground text-xs">{label}</div>
                    <div className="font-semibold tabular-nums">{value}</div>
                </div>
            ))}
            <div className="text-muted-foreground ml-auto max-w-64 text-[11px] leading-snug">
                Aktualizacja co godzinę. Meta – opóźnienie ~15–30 min, Google Ads – do 3 h, konwersje dopisują się później.
            </div>
        </div>
    );
}
