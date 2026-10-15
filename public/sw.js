const STATIC = 'gakin-static-v2';
const SESSION = 'gakin-session-v2';
const OWNER_KEY = new URL('/__gakin_offline_owner', self.location.origin).href;
const TTL = 12 * 60 * 60 * 1000;
const BUILD = 'I6rEexQR8n1I02FowLglPT95CbuNMpLKpyGqbkD3ZCE1DI6NoD3+IRcYVwBjlhVFNn4hT/T/h5tCfv19LOl0gQm1S69oBuHnXETZWqNXfTCNY/9/AfKq7b30FC9Q74hO';
let owner = null;
let ownerReady = Promise.resolve();

async function clearPrivateCaches() {
    owner = null;
    const keys = await caches.keys();
    await Promise.all(keys.filter(key => key.startsWith('gakin-pages-') || key.startsWith('gakin-session-')).map(key => caches.delete(key)));
}

async function restoreOwner() {
    await ownerReady;
    const session = await caches.open(SESSION);
    const response = await session.match(OWNER_KEY);
    const saved = response ? await response.json() : null;
    if (!saved || saved.expiresAt <= Date.now()) {
        await clearPrivateCaches();
        return null;
    }
    owner = saved.owner;
    return owner;
}

async function selectOwner(value) {
    if (!/^[a-f0-9-]{36}$/.test(value || '')) return;
    owner = value;
    const keys = await caches.keys();
    await Promise.all(keys.filter(key => key.startsWith('gakin-pages-') && key !== 'gakin-pages-' + owner).map(key => caches.delete(key)));
    const session = await caches.open(SESSION);
    await session.put(OWNER_KEY, new Response(JSON.stringify({owner, expiresAt: Date.now() + TTL})));
}

async function cachePage(data) {
    const current = await restoreOwner();
    if (!current || data.owner !== current) return;
    const url = new URL(data.url);
    if (url.origin !== self.location.origin || !/^\/surveys\/[a-f0-9-]+$/.test(url.pathname)) return;
    const response = await fetch(url, {credentials: 'include'});
    if (!response.ok || response.redirected) return;
    const html = await response.clone().text();
    if (!html.includes('name="user-id" content="' + current + '"')) return;
    await ownerReady;
    if (owner !== current) return;
    const pages = await caches.open('gakin-pages-' + current);
    await pages.put(url, response);
    const assets = await caches.open(STATIC);
    for (const match of html.matchAll(/(?:src|href)="([^"]+)"/g)) {
        const asset = new URL(match[1], url);
        if (asset.origin === url.origin && asset.pathname.startsWith('/build/assets/')) {
            const file = await fetch(asset);
            if (file.ok) await assets.put(asset, file);
        }
    }
}

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(key => key.startsWith('gakin-static-') && key !== STATIC).map(key => caches.delete(key)));
    await self.clients.claim();
})()));
self.addEventListener('message', event => {
    const data = event.data || {};
    if (data.type === 'owner') {
        ownerReady = ownerReady.then(() => selectOwner(data.owner));
        event.waitUntil(ownerReady);
    }
    if (data.type === 'logout') {
        ownerReady = ownerReady.then(clearPrivateCaches);
        event.waitUntil(ownerReady);
    }
    if (data.type === 'cache-page') event.waitUntil(cachePage(data).catch(() => {}));
});
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith((async () => {
            const cache = await caches.open(STATIC);
            const saved = await cache.match(event.request);
            if (saved) return saved;
            const response = await fetch(event.request);
            if (response.ok) await cache.put(event.request, response.clone());
            return response;
        })());
    } else if (event.request.mode === 'navigate' && /^\/surveys\/[a-f0-9-]+$/.test(url.pathname)) {
        event.respondWith((async () => {
            try {
                const response = await fetch(event.request);
                if ([401, 403, 419].includes(response.status) || (response.redirected && new URL(response.url).pathname === '/login')) {
                    await ownerReady;
                    await clearPrivateCaches();
                }
                return response;
            } catch {
                const current = await restoreOwner();
                if (current) {
                    const cache = await caches.open('gakin-pages-' + current);
                    const saved = await cache.match(event.request);
                    if (saved) return saved;
                }
                return new Response('<h1>Anda sedang offline</h1><p>Buka survey saat online terlebih dahulu. Draft perangkat tetap tersimpan.</p>', {headers: {'Content-Type': 'text/html; charset=utf-8'}});
            }
        })());
    }
});
