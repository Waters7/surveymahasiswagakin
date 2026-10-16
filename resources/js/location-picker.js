import L from 'leaflet';

const DEFAULT_CENTER = {latitude: 1.4748, longitude: 124.8421};

const round = value => Math.round(value * 1e7) / 1e7;

function valid(point) {
    const latitude = Number(point?.latitude);
    const longitude = Number(point?.longitude);
    return point?.latitude != null && point?.longitude != null && Number.isFinite(latitude) && Number.isFinite(longitude) && Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180 ? {latitude, longitude} : null;
}

/**
 * Titik awal peta: lokasi yang sudah diisi, alamat terdaftar, lalu perkiraan dari alamat.
 */
export function startingPoint(location, registered) {
    return valid(location) || valid(registered) || valid({latitude: registered?.approx_latitude, longitude: registered?.approx_longitude});
}

/**
 * Peta pemilihan titik kunjungan. Ketuk peta atau geser penanda; onPick menerima koordinat terpilih.
 */
export function createLocationPicker(element, start, onPick, onError) {
    const center = start || DEFAULT_CENTER;
    const map = L.map(element).setView([center.latitude, center.longitude], start ? 17 : 12);
    // OSM memblokir tile tanpa Referer, sedangkan halaman memakai Referrer-Policy same-origin: kirim origin saja.
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        referrerPolicy: 'strict-origin',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
    });
    tiles.once('tileerror', () => onError());
    tiles.addTo(map);
    const icon = L.divIcon({className: 'location-pin', html: '<span></span>', iconSize: [30, 30], iconAnchor: [15, 30]});
    const marker = L.marker([center.latitude, center.longitude], {icon, draggable: true, title: 'Titik kunjungan'});
    const place = latlng => {
        marker.setLatLng(latlng);
        if (!map.hasLayer(marker)) marker.addTo(map);
        onPick({latitude: round(latlng.lat), longitude: round(latlng.lng)});
    };
    if (start) place(L.latLng(start.latitude, start.longitude));
    // Peta dibuat saat panelnya baru dibuka; ukur ulang setiap kali wadahnya berubah agar ubin memenuhi area.
    new ResizeObserver(() => map.invalidateSize()).observe(element);
    map.on('click', event => place(event.latlng));
    marker.on('dragend', () => place(marker.getLatLng()));

    return {refresh: () => map.invalidateSize()};
}
