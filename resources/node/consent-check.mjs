// Sprawdzenie zgód w prawdziwej przeglądarce: otwiera stronę z czystym profilem, NIE klika nic w banerze
// i zapisuje, jakie zapytania do narzędzi śledzących wyszły oraz jakie ciasteczka powstały przed jakąkolwiek zgodą.
// Użycie: node resources/node/consent-check.mjs <adres> <ścieżka do Chrome/Chromium>
// Wynik: jeden wiersz JSON na standardowym wyjściu.
import puppeteer from 'puppeteer-core';

const [url, executablePath] = process.argv.slice(2);
const WAIT_MS = 7000; // czas na załadowanie banera i ewentualnych tagów

const out = { ok: false, url, final_url: null, requests: [], cookies: [], banner: null, error: null };
let browser;
try {
    browser = await puppeteer.launch({
        executablePath,
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--lang=pl-PL', '--window-size=1366,900'],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1366, height: 900 });
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'pl-PL,pl;q=0.9,en;q=0.8' });
    const seen = new Set();
    page.on('request', (req) => {
        const u = req.url();
        if (u.startsWith('data:') || seen.size > 600) return;
        seen.add(u.slice(0, 600));
    });
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    // Wtyczki wydajności (WP Rocket, LiteSpeed…) uruchamiają skrypty dopiero po pierwszym ruchu odwiedzającego –
    // ruszamy myszką i przewijamy (to nie jest zgoda), żeby zobaczyć to, co zobaczy zwykły użytkownik.
    await new Promise((r) => setTimeout(r, 2500));
    await page.mouse.move(300, 300);
    await page.mouse.move(420, 380);
    await page.evaluate(() => window.scrollBy(0, 400));
    await new Promise((r) => setTimeout(r, WAIT_MS));
    out.final_url = page.url();
    out.requests = [...seen];
    const client = await page.createCDPSession();
    const { cookies } = await client.send('Network.getAllCookies');
    out.cookies = cookies.map((c) => ({ name: c.name, domain: c.domain }));
    // widoczny baner: element z „cookie / zgod / consent” w treści lub klasie, przypięty do ekranu
    out.banner = await page.evaluate(() => {
        const visible = (el) => {
            const r = el.getBoundingClientRect();
            const s = getComputedStyle(el);
            return r.width > 150 && r.height > 30 && s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) > 0.1;
        };
        for (const el of document.querySelectorAll('div, section, aside, dialog, form')) {
            const s = getComputedStyle(el);
            if (!['fixed', 'sticky'].includes(s.position) || !visible(el)) continue;
            const text = (el.innerText || '').slice(0, 1500);
            if (!/cookie|ciasteczk|zgod|consent|prywatno/i.test(text)) continue;
            const buttons = [...el.querySelectorAll('button, a, [role="button"], input[type="button"], input[type="submit"]')]
                .filter(visible)
                .map((b) => (b.innerText || b.value || '').trim().replace(/\s+/g, ' ').slice(0, 40))
                .filter(Boolean);
            return { text: text.replace(/\s+/g, ' ').slice(0, 300), buttons: buttons.slice(0, 12) };
        }
        return null;
    });
    out.ok = true;
} catch (e) {
    out.error = String(e?.message ?? e).slice(0, 300);
} finally {
    await browser?.close().catch(() => {});
}
process.stdout.write(JSON.stringify(out));
