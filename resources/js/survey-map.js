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
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
    });
    tiles.on('tileerror', () => { error.hidden = false; });
    tiles.addTo(map);
    L.control.scale({imperial: false}).addTo(map);
    const points = [];
    const focusMarkers = new Map();
    const visitsBySurveyor = new Map();
    const routes = L.layerGroup();
    let locatedTargets = 0;

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
        const actual = coordinates(target.actual);
        if (actual) {
            const visits = visitsBySurveyor.get(target.surveyor) || [];
            visits.push({point: actual, startedAt: target.started_at || ''});
            visitsBySurveyor.set(target.surveyor, visits);
        }
    });
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
            map.setView(marker.getLatLng(), 16);
            marker.openPopup();
            element.focus({preventScroll: true});
        });
    }
    fitPoints();
    document.getElementById('map-summary').textContent = locatedTargets
        ? `${locatedTargets} dari ${targets.length} target memiliki koordinat. ${targets.length - locatedTargets} belum memiliki titik lokasi.`
        : targets.length ? `Belum ada koordinat untuk ${targets.length} target. Cari alamat melalui daftar di bawah; titik akan tampil setelah GPS diisi.` : 'Belum ada penugasan untuk ditampilkan.';
    new ResizeObserver(() => map.invalidateSize()).observe(element);
}
