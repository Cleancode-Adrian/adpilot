import { Button } from '@/components/ui/button';
import { Check, Copy, ExternalLink } from 'lucide-react';
import { useState } from 'react';

/** Link do skopiowania (np. formularz zgłoszeń) + podgląd w nowej karcie. */
export function CopyLink({ url, label = 'Kopiuj link' }: { url: string; label?: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <div className="flex flex-wrap items-center gap-1">
            <Button
                variant="outline"
                size="sm"
                className="h-7"
                onClick={async () => {
                    await navigator.clipboard.writeText(url);
                    setCopied(true);
                    setTimeout(() => setCopied(false), 2000);
                }}
            >
                {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />} {copied ? 'Skopiowano' : label}
            </Button>
            <Button variant="ghost" size="sm" className="h-7" asChild title="Podgląd">
                <a href={url} target="_blank" rel="noreferrer noopener">
                    <ExternalLink className="size-3.5" />
                </a>
            </Button>
        </div>
    );
}
