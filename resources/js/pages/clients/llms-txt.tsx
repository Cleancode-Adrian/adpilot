import { EmptyState, Section, SeverityBadge } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import ClientLayout from '@/layouts/client-layout';
import { dateTime } from '@/lib/format';
import { type ClientProps, type Severity } from '@/types/adpilot';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Check, Copy, Download, Play, RotateCcw } from 'lucide-react';
import { useState } from 'react';

interface AiIssue {
    code: string;
    severity: Severity;
    title: string;
    description: string;
    action: string;
}

export default function LlmsTxt({
    client,
    text,
    audit,
}: {
    client: ClientProps;
    text: string | null;
    audit: { id: number; finished_at: string | null; pages: number; has_llms_txt: boolean | null; issues: AiIssue[] } | null;
}) {
    const [value, setValue] = useState(text ?? '');
    const [copied, setCopied] = useState(false);
    const base = `/clients/${client.id}`;
    const domain = client.website_url.replace(/\/$/, '');

    const copy = async () => {
        await navigator.clipboard.writeText(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };
    const download = () => {
        const url = URL.createObjectURL(new Blob([value], { type: 'text/plain;charset=utf-8' }));
        const a = Object.assign(document.createElement('a'), { href: url, download: 'llms.txt' });
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <ClientLayout client={client} title="Generator llms.txt">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <Link href={`${base}/site`} className="text-muted-foreground inline-flex items-center gap-1 text-sm hover:underline">
                            <ArrowLeft className="size-3.5" /> Strona i audyt
                        </Link>
                        <h2 className="text-lg font-semibold">Generator llms.txt</h2>
                        <p className="text-muted-foreground max-w-2xl text-sm">
                            llms.txt to krótki spis najważniejszych podstron w Markdown dla asystentów AI (ChatGPT, Claude, Perplexity). Szkic poniżej
                            powstał z podstron znalezionych w ostatnim audycie – przejrzyj opisy, usuń zbędne linki i dopisz to, co ważne.
                        </p>
                    </div>
                </div>

                {!audit || text === null ? (
                    <EmptyState title="Najpierw uruchom audyt strony">
                        <p className="mb-2">Generator korzysta z listy podstron, tytułów i opisów zebranych w audycie.</p>
                        <Button size="sm" onClick={() => router.post(`${base}/site/audit`, {}, { onSuccess: () => router.visit(`${base}/site`) })}>
                            <Play className="size-4" /> Uruchom audyt
                        </Button>
                    </EmptyState>
                ) : (
                    <>
                        {audit.issues.length > 0 && (
                            <Section title="Widoczność w AI – wynik ostatniego audytu">
                                <ul className="space-y-2 text-sm">
                                    {audit.issues.map((i) => (
                                        <li key={i.code}>
                                            <div className="flex items-center gap-2 font-medium">
                                                <SeverityBadge severity={i.severity} /> {i.title}
                                            </div>
                                            <p className="text-muted-foreground mt-0.5">{i.description}</p>
                                            <p className="mt-0.5">
                                                <strong>Jak naprawić:</strong> {i.action}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}
                        {audit.has_llms_txt && (
                            <p className="rounded-lg border px-3 py-2 text-sm">
                                Strona ma już plik{' '}
                                <a href={`${domain}/llms.txt`} target="_blank" rel="noreferrer noopener" className="font-mono underline">
                                    /llms.txt
                                </a>
                                . Szkic poniżej możesz porównać z obecnym plikiem albo nim go zastąpić.
                            </p>
                        )}

                        <Section
                            title="Szkic pliku"
                            description={`Z audytu z ${dateTime(audit.finished_at)} · ${audit.pages} sprawdzonych podstron`}
                            actions={
                                <div className="flex flex-wrap gap-2">
                                    {value !== text && (
                                        <Button variant="ghost" size="sm" onClick={() => setValue(text)}>
                                            <RotateCcw className="size-4" /> Przywróć szkic
                                        </Button>
                                    )}
                                    <Button variant="outline" size="sm" onClick={copy}>
                                        {copied ? <Check className="size-4" /> : <Copy className="size-4" />} {copied ? 'Skopiowano' : 'Kopiuj'}
                                    </Button>
                                    <Button size="sm" onClick={download}>
                                        <Download className="size-4" /> Pobierz llms.txt
                                    </Button>
                                </div>
                            }
                        >
                            <textarea
                                value={value}
                                onChange={(e) => setValue(e.target.value)}
                                rows={Math.min(40, Math.max(14, value.split('\n').length + 2))}
                                spellCheck={false}
                                className="border-input bg-background w-full rounded-md border px-3 py-2 font-mono text-xs leading-relaxed"
                            />
                        </Section>

                        <Section title="Jak wgrać plik">
                            <ol className="list-decimal space-y-1 pl-5 text-sm">
                                <li>
                                    Pobierz plik i wgraj go do głównego katalogu strony (tam, gdzie <span className="font-mono">robots.txt</span>) –
                                    ma być dostępny pod adresem <span className="font-mono">{domain}/llms.txt</span>.
                                </li>
                                <li>
                                    WordPress: wgraj przez FTP / menedżer plików hostingu albo włącz generator llms.txt we wtyczce SEO (Yoast SEO,
                                    Rank Math) – wtedy wtyczka aktualizuje plik sama.
                                </li>
                                <li>Plik musi być zwykłym tekstem (nie stroną HTML). Po wgraniu uruchom ponownie audyt – sprawdzi format i linki.</li>
                            </ol>
                        </Section>
                    </>
                )}
            </div>
        </ClientLayout>
    );
}
