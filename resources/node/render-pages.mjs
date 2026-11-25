// Wyrenderowany kod podstron (po uruchomieniu skryptów) – audyt czyta wtedy treść tak, jak widzi ją przeglądarka
// i wtyczki SEO: nagłówki, obrazy i formularze dokładane skryptem, obrazy ładowane „leniwie”.
// Użycie: node resources/node/render-pages.mjs <ścieżka do Chrome> <adres> [<adres> …]
// Wynik: JSON { "<adres>": "<html>" | null } na standardowym wyjściu.
import puppeteer from 'puppeteer-core';

const [executablePath, ...urls] = process.argv.slice(2);
const out = {};
let browser;
try {
    browser = await puppeteer.launch({
        executablePath,
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--lang=pl-PL', '--window-size=1366,900'],
    });
    for (const url of urls) {
        const page = await browser.newPage();
        try {
            await page.setViewport({ width: 1366, height: 900 });
            await page.setExtraHTTPHeaders({ 'Accept-Language': 'pl-PL,pl;q=0.9,en;q=0.8' });
            await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 25000 });
            await new Promise((r) => setTimeout(r, 1500));
            // ruch myszą i przewinięcie do końca – wtyczki wydajności uruchamiają skrypty, obrazy „leniwe” się ładują
            await page.mouse.move(300, 300);
            await page.evaluate(async () => {
                for (let y = 0; y < document.body.scrollHeight && y < 30000; y += 700) {
                    window.scrollTo(0, y);
                    await new Promise((r) => setTimeout(r, 120));
                }
                window.scrollTo(0, 0);
            });
            await new Promise((r) => setTimeout(r, 1200));
            const html = await page.evaluate(() => '<!doctype html>\n' + document.documentElement.outerHTML);
            out[url] = html.length > 4_000_000 ? null : html;
        } catch {
            out[url] = null;
        } finally {
            await page.close().catch(() => {});
        }
    }
} catch {
    // brak przeglądarki – audyt użyje surowego kodu
} finally {
    await browser?.close().catch(() => {});
}
process.stdout.write(JSON.stringify(out));
