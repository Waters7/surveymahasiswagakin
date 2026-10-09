import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const source = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');
const origin = 'https://gakin.test';
const owner = '11111111-1111-4111-8111-111111111111';
const url = origin + '/surveys/22222222-2222-4222-8222-222222222222';
const html = `<meta name="user-id" content="${owner}"><main>Survey tersimpan</main>`;
function storage() {
    const stores = new Map();
    const key = value => typeof value === 'string' ? value : value.url || value.href;
    return {
        keys: async () => [...stores.keys()],
        delete: async name => stores.delete(name),
        open: async name => {
            if (!stores.has(name)) stores.set(name, new Map());
            const store = stores.get(name);
            return {put: async (request, response) => store.set(key(request), response.clone()), match: async request => store.get(key(request))?.clone()};
        },
    };
}
function worker(caches, fetch = async () => {throw new Error('offline');}) {
    const handlers = {};
    vm.runInNewContext(source, {URL, Response, Date, caches, fetch, self: {location: {origin}, clients: {claim: async () => {}}, skipWaiting() {}, addEventListener: (name, handler) => handlers[name] = handler}});
    return {
        message: data => {let task; handlers.message({data, waitUntil: promise => task = promise}); return task;},
        navigate: () => {let task; handlers.fetch({request: {url, method: 'GET', mode: 'navigate'}, respondWith: promise => task = promise}); return task;},
    };
}
async function saved() {
    const caches = storage();
    const sw = worker(caches, async () => new Response(html));
    await sw.message({type: 'owner', owner});
    await sw.message({type: 'cache-page', owner, url});
    return caches;
}
test('survey remains available after service worker restarts offline', async () => {
    assert.match(await (await worker(await saved()).navigate()).text(), /Survey tersimpan/);
});
test('switching account removes the previous account pages', async () => {
    const caches = await saved();
    const sw = worker(caches);
    await sw.message({type: 'owner', owner: '33333333-3333-4333-8333-333333333333'});
    assert.doesNotMatch(await (await sw.navigate()).text(), /Survey tersimpan/);
    assert.equal((await caches.keys()).includes('gakin-pages-' + owner), false);
});
test('logout removes persisted offline access across worker restarts', async () => {
    const caches = await saved();
    await worker(caches).message({type: 'logout'});
    assert.doesNotMatch(await (await worker(caches).navigate()).text(), /Survey tersimpan/);
});
test('server access rejection revokes cached page access', async () => {
    const caches = await saved();
    assert.equal((await worker(caches, async () => new Response('Forbidden', {status: 403})).navigate()).status, 403);
    assert.doesNotMatch(await (await worker(caches).navigate()).text(), /Survey tersimpan/);
});
test('expired offline session cannot serve private pages', async () => {
    const caches = await saved();
    await (await caches.open('gakin-session-v2')).put(origin + '/__gakin_offline_owner', new Response(JSON.stringify({owner, expiresAt: 0})));
    assert.doesNotMatch(await (await worker(caches).navigate()).text(), /Survey tersimpan/);
});
