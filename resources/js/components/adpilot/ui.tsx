import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type Severity } from '@/types/adpilot';
import { usePage } from '@inertiajs/react';
import { AlertOctagon, AlertTriangle, ArrowDownRight, ArrowUpRight, CheckCircle2, Info, Lightbulb, Minus, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

/** Komunikaty flash z backendu (sukces / błąd). */
export function Flash() {
    const { flash } = usePage<SharedData>().props;
    const [visible, setVisible] = useState({ success: flash?.success, error: flash?.error });
    useEffect(() => setVisible({ success: flash?.success, error: flash?.error }), [flash?.success, flash?.error]);

    if (!visible.success && !visible.error) return null;
    return (
        <div className="space-y-2 px-4 pt-4">
            {visible.success && (
                <div className="flex items-start gap-2 rounded-lg border border-green-600/30 bg-green-50 px-4 py-3 text-sm text-green-900 dark:bg-green-950/40 dark:text-green-100">
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                    <span className="flex-1">{visible.success}</span>
                    <button onClick={() => setVisible((v) => ({ ...v, success: null }))} className="opacity-60 hover:opacity-100" aria-label="Zamknij">
                        ×
                    </button>
                </div>
            )}
            {visible.error && (
                <div className="flex items-start gap-2 rounded-lg border border-red-600/30 bg-red-50 px-4 py-3 text-sm text-red-900 dark:bg-red-950/40 dark:text-red-100">
                    <XCircle className="mt-0.5 size-4 shrink-0" />
                    <span className="flex-1">{visible.error}</span>
                    <button onClick={() => setVisible((v) => ({ ...v, error: null }))} className="opacity-60 hover:opacity-100" aria-label="Zamknij">
                        ×
                    </button>
                </div>
            )}
        </div>
    );
}

/** Status: zawsze ikona + etykieta (nigdy sam kolor). Kolory statusu zarezerwowane – nie dla serii. */
const SEVERITY: Record<Severity, { label: string; icon: typeof Info; cls: string }> = {
    critical: { label: 'Krytyczne', icon: AlertOctagon, cls: 'text-[#b42f2f] bg-[#d03b3b]/10 border-[#d03b3b]/40 dark:text-[#f08080]' },
    high: { label: 'Wysokie', icon: AlertTriangle, cls: 'text-[#a4471f] bg-[#ec835a]/12 border-[#ec835a]/50 dark:text-[#ec835a]' },
    medium: { label: 'Średnie', icon: Info, cls: 'text-[#7a5600] bg-[#fab219]/15 border-[#fab219]/50 dark:text-[#fab219]' },
    low: { label: 'Niskie', icon: Minus, cls: 'text-muted-foreground bg-muted border-border' },
};

export function SeverityBadge({ severity, kind }: { severity: Severity; kind?: 'problem' | 'opportunity' }) {
    if (kind === 'opportunity') {
        return (
            <span className="inline-flex items-center gap-1 rounded-full border border-[#0ca30c]/40 bg-[#0ca30c]/10 px-2 py-0.5 text-xs font-medium text-[#0a7d0a] dark:text-[#4ccc4c]">
                <Lightbulb className="size-3" /> Szansa
            </span>
        );
    }
    const s = SEVERITY[severity];
    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium', s.cls)}>
            <s.icon className="size-3" /> {s.label}
        </span>
    );
}

/** Kafelek KPI z porównaniem do poprzedniego okresu. */
export function KpiTile({
    label,
    value,
    delta,
    inverse = false,
    hint,
}: {
    label: string;
    value: string;
    delta?: number | null;
    inverse?: boolean;
    hint?: string;
}) {
    const good = delta === null || delta === undefined ? null : inverse ? delta < 0 : delta > 0;
    const Icon = !delta ? Minus : delta > 0 ? ArrowUpRight : ArrowDownRight;
    return (
        <div className="bg-card rounded-xl border p-4" title={hint}>
            <div className="text-muted-foreground text-sm">{label}</div>
            <div className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{value}</div>
            <div className="mt-1 flex items-center gap-1 text-xs">
                {delta === null || delta === undefined ? (
                    <span className="text-muted-foreground">brak porównania</span>
                ) : (
                    <>
                        <span
                            className={cn(
                                'inline-flex items-center gap-0.5 font-medium tabular-nums',
                                good ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400',
                            )}
                        >
                            <Icon className="size-3.5" />
                            {delta > 0 ? '+' : ''}
                            {delta.toLocaleString('pl-PL')}%
                        </span>
                        <span className="text-muted-foreground">vs poprzedni okres</span>
                    </>
                )}
            </div>
        </div>
    );
}

export function Section({ title, description, actions, children, className }: { title: string; description?: string; actions?: React.ReactNode; children: React.ReactNode; className?: string }) {
    return (
        <section className={cn('bg-card rounded-xl border', className)}>
            <div className="flex flex-wrap items-start justify-between gap-2 border-b px-4 py-3">
                <div>
                    <h2 className="font-semibold">{title}</h2>
                    {description && <p className="text-muted-foreground text-sm">{description}</p>}
                </div>
                {actions}
            </div>
            <div className="p-4">{children}</div>
        </section>
    );
}

export function NativeSelect(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select
            {...props}
            className={cn(
                'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none',
                props.className,
            )}
        />
    );
}

export function EmptyState({ title, children }: { title: string; children?: React.ReactNode }) {
    return (
        <div className="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm">
            <div className="text-foreground mb-1 font-medium">{title}</div>
            {children}
        </div>
    );
}

export function PlatformDot({ platform }: { platform: string }) {
    const color = platform === 'google_ads' ? 'var(--series-google)' : platform === 'meta_ads' ? 'var(--series-meta)' : 'var(--muted-foreground)';
    return <span className="inline-block size-2.5 shrink-0 rounded-[3px]" style={{ background: color }} aria-hidden />;
}
