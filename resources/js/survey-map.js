import L from 'leaflet';

function coordinates(location) {
    if (location?.latitude == null || location?.longitude == null) return null;
    const latitude = Number(location.latitude);
    const longitude = Number(location.longitude);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) return null;
    return [latitude, longitude];
}

export function initMap() {
    const element = document.getElementById('survey-map');
    if (!element) return;
    const targets = JSON.parse(document.getElementById('map-data').textContent);
    const map = L.map(element, {scrollWheelZoom: false}).setView([1.3, 124.85], 8);
    const error = document.getElementById('map-error');
    // OSM memblokir tile tanpa Referer, sedangkan halaman memakai Referrer-Policy same-origin: kirim origin saja.
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        referrerPolicy: 'strict-origin',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
    });
    tiles.on('tileerror', () => { error.hidden = false; });
    tiles.addTo(map);
    L.control.scale({imperial: false}).addTo(map);
    const points = [];
    const focusMarkers = new Map();
    const visitsBySurveyor = new Map();
    const routes = L.layerGroup();
    const approximateGroups = new Map();
    const approximateMarkers = new Set();
    let locatedTargets = 0;
    let approximateTargets = 0;

    targets.forEach((target, index) => {
        let found = false;
        for (const [type, color] of [['registered', '#6694bc'], ['actual', '#15856a']]) {
            const point = coordinates(target[type]);
            if (!point) continue;
            found = true;
            points.push(point);
            const popup = document.createElement('div');
            const title = document.createElement('strong');
            title.textContent = target.name;
            popup.append(title);
            for (const text of [target.program, target.surveyor, target.status.replaceAll('_', ' '), type === 'actual' ? 'GPS kunjungan' : 'Alamat terdaftar']) {
                const paragraph = document.createElement('p');
                paragraph.textContent = text;
                popup.append(paragraph);
            }
            const detail = document.createElement('a');
            detail.href = target.url;
            detail.textContent = 'Lihat hasil / tugas';
            popup.append(detail);
            const marker = L.circleMarker(point, {radius: type === 'actual' ? 9 : 6, color, fillColor: color, fillOpacity: .85}).addTo(map).bindPopup(popup);
            focusMarkers.set(String(index), marker);
        }
        if (found) locatedTargets++;
        const estimate = found ? null : coordinates(target.approximate);
        if (estimate) {
            const key = estimate.join(',');
            const group = approximateGroups.get(key) || {point: estimate, precision: target.approximate.precision, label: target.approximate.label, items: []};
            group.items.push({target, index});
            approximateGroups.set(key, group);
            approximateTargets++;
        }
        const actual = coordinates(target.actual);
        if (actual) {
            const visits = visitsBySurveyor.get(target.surveyor) || [];
            visits.push({point: actual, startedAt: target.started_at || ''});
            visitsBySurveyor.set(target.surveyor, visits);
        }
    });
    for (const group of approximateGroups.values()) {
        points.push(group.point);
        const popup = document.createElement('div');
        const title = document.createElement('strong');
        title.textContent = `${group.items.length} target · perkiraan tingkat ${group.precision}`;
        const place = document.createElement('p');
        place.textContent = group.label || '';
        const note = document.createElement('p');
        note.textContent = 'Titik dari nama wilayah pada alamat, bukan lokasi rumah. Konfirmasi dengan mahasiswa.';
        const list = document.createElement('ul');
        list.className = 'map-approx-list';
        for (const {target} of group.items.slice(0, 15)) {
            const item = document.createElement('li');
            const link = document.createElement('a');
            link.href = target.url;
            link.textContent = target.name;
            item.append(link, ` · ${target.surveyor}`);
            list.append(item);
        }
        popup.append(title, place, note, list);
        if (group.items.length > 15) {
            const more = document.createElement('p');
            more.textContent = `dan ${group.items.length - 15} target lainnya pada daftar di bawah.`;
            popup.append(more);
        }
        const size = Math.min(44, 24 + group.items.length * 2);
        const icon = L.divIcon({className: 'map-approx-marker', html: `<span>${group.items.length}</span>`, iconSize: [size, size]});
        const marker = L.marker(group.point, {icon, title: `${group.items.length} target, perkiraan tingkat ${group.precision}`}).addTo(map).bindPopup(popup);
        approximateMarkers.add(marker);
        for (const {index} of group.items) focusMarkers.set(String(index), marker);
    }
    for (const visits of visitsBySurveyor.values()) {
        if (visits.length < 2) continue;
        visits.sort((first, second) => first.startedAt.localeCompare(second.startedAt));
        L.polyline(visits.map(visit => visit.point), {color: '#73a99b', dashArray: '5 8', weight: 2}).addTo(routes);
    }
    const fitPoints = () => {
        map.invalidateSize();
        if (points.length) map.fitBounds(points, {padding: [35, 35], maxZoom: 14});
        else map.setView([1.3, 124.85], 8);
    };
    document.getElementById('map-fit').addEventListener('click', fitPoints);
    document.getElementById('map-retry').addEventListener('click', () => {
        error.hidden = true;
        tiles.redraw();
    });
    document.getElementById('show-routes').addEventListener('change', event => {
        if (event.target.checked) routes.addTo(map);
        else routes.remove();
    });
    for (const button of document.querySelectorAll('[data-map-focus]')) {
        const marker = focusMarkers.get(button.dataset.mapFocus);
        if (!marker) continue;
        button.hidden = false;
        button.addEventListener('click', () => {
            element.scrollIntoView({behavior: 'smooth', block: 'center'});
            map.setView(marker.getLatLng(), approximateMarkers.has(marker) ? 12 : 16);
            marker.openPopup();
            element.focus({preventScroll: true});
        });
    }
    fitPoints();
    const withoutPoint = targets.length - locatedTargets - approximateTargets;
    document.getElementById('map-summary').textContent = !targets.length
        ? 'Belum ada penugasan untuk ditampilkan.'
        : locatedTargets || approximateTargets
            ? `${locatedTargets} dari ${targets.length} target memiliki koordinat${approximateTargets ? `, ${approximateTargets} memakai titik perkiraan dari alamat` : ''}. ${withoutPoint} belum memiliki titik lokasi.`
            : `Belum ada koordinat untuk ${targets.length} target. Cari alamat melalui daftar di bawah; titik akan tampil setelah GPS diisi.`;
    new ResizeObserver(() => map.invalidateSize()).observe(element);
}
