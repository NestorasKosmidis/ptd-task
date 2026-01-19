let map, poiLayer, routeLayer;
let lastCompute = null; // store last /routes/compute response
let pois = [];

function el(id) { return document.getElementById(id); }

function setStatus(msg, ok = true) {
  const s = el("status");
  s.textContent = msg;
  s.className = "hint " + (ok ? "ok" : "bad");
}

function apiHeaders() {
  return {
    "Content-Type": "application/json",
    "X-API-Key": el("apiKey").value.trim(),
  };
}

function apiBase() {
  return el("apiBase").value.trim().replace(/\/+$/, "");
}


function initMap() {
  map = L.map("map").setView([39.6243, 19.9217], 14);

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "&copy; OpenStreetMap contributors",
  }).addTo(map);

  poiLayer = L.layerGroup().addTo(map);
  routeLayer = L.layerGroup().addTo(map);
}

function populateSelects() {
  const from = el("fromPoi");
  const to = el("toPoi");
  from.innerHTML = "";
  to.innerHTML = "";

  for (const p of pois) {
    const opt1 = document.createElement("option");
    opt1.value = p.id;
    opt1.textContent = `${p.name} (${p.id})`;
    from.appendChild(opt1);

    const opt2 = document.createElement("option");
    opt2.value = p.id;
    opt2.textContent = `${p.name} (${p.id})`;
    to.appendChild(opt2);
  }

  if (pois.length >= 2) {
    from.value = pois[0].id;
    to.value = pois[1].id;
  }
}

function drawPois() {
  poiLayer.clearLayers();

  for (const p of pois) {
    const lat = p.location?.lat ?? p.lat;
    const lon = p.location?.lon ?? p.lon;

    if (typeof lat !== "number" || typeof lon !== "number") continue;

    const m = L.marker([lat, lon]).addTo(poiLayer);
    m.bindPopup(`<b>${p.name}</b><br/>${p.category ?? ""}<br/><small>${p.id}</small>`);
  }

  const bounds = L.latLngBounds([]);
  poiLayer.eachLayer((layer) => {
    if (layer.getLatLng) bounds.extend(layer.getLatLng());
  });

  if (bounds.isValid()) map.fitBounds(bounds.pad(0.2));

}

async function loadPois() {
  setStatus("Loading POIs...");
  lastCompute = null;
  el("btnPersist").disabled = true;
  routeLayer.clearLayers();

  const url = `${apiBase()}/pois`;
  const r = await fetch(url, { headers: apiHeaders() });

  if (!r.ok) {
    const t = await r.text();
    setStatus(`Failed to load POIs: ${r.status} ${t}`, false);
    return;
  }

  const data = await r.json();

  // Your API returns: {count, results: [...]}
  pois = data.results ?? data.items ?? [];

  populateSelects();
  drawPois();
  setStatus(`Loaded ${pois.length} POIs`);
}

async function computeRoute() {
  const fromId = el("fromPoi").value;
  const toId = el("toPoi").value;

  if (!fromId || !toId || fromId === toId) {
    setStatus("Select two different POIs", false);
    return;
  }

  setStatus("Computing route...");
  el("btnPersist").disabled = true;
  routeLayer.clearLayers();

  const body = {
    locations: [{ poiId: fromId }, { poiId: toId }],
    vehicle: "car",
    format: "geojson",
  };

  const url = `${apiBase()}/routes/compute`;
  const r = await fetch(url, {
    method: "POST",
    headers: apiHeaders(),
    body: JSON.stringify(body),
  });

  const text = await r.text();
  if (!r.ok) {
    setStatus(`Compute failed: ${r.status} ${text}`, false);
    return;
  }

  const data = JSON.parse(text);
  lastCompute = data;

  // Leaflet can render GeoJSON directly (expects [lon,lat] which we have)
  const gj = {
    type: "Feature",
    properties: {
      distanceMeters: data.distanceMeters,
      durationMillis: data.durationMillis,
    },
    geometry: data.geometry,
  };

  const layer = L.geoJSON(gj, { });
  layer.addTo(routeLayer);

  const bounds = layer.getBounds();
  if (bounds.isValid()) map.fitBounds(bounds.pad(0.2));

  setStatus(`Route OK: ${(data.distanceMeters/1000).toFixed(2)} km, ${(data.durationMillis/60000).toFixed(1)} min`);
  el("btnPersist").disabled = false;
}

async function persistRoute() {
  if (!lastCompute || !lastCompute.geometry) {
    setStatus("Compute a route first", false);
    return;
  }

  const fromId = el("fromPoi").value;
  const toId = el("toPoi").value;

  const fromPoi = pois.find(p => p.id === fromId);
  const toPoi = pois.find(p => p.id === toId);

  const body = {
    name: `UI route: ${fromPoi?.name ?? fromId} -> ${toPoi?.name ?? toId}`,
    public: false,
    vehicle: "car",
    ownerId: "P2018020",
    poiSequence: lastCompute.poiSequence ?? [
      { poiId: fromId, name: fromPoi?.name ?? null },
      { poiId: toId, name: toPoi?.name ?? null }
    ],
    geometry: lastCompute.geometry,
    encodedPolyline: null
  };

  setStatus("Persisting route...");
  const url = `${apiBase()}/routes`;
  const r = await fetch(url, {
    method: "POST",
    headers: apiHeaders(),
    body: JSON.stringify(body),
  });

  const text = await r.text();
  if (!r.ok) {
    setStatus(`Persist failed: ${r.status} ${text}`, false);
    return;
  }

  const data = JSON.parse(text);
  setStatus(`Persisted route: ${data.id}`);
}

document.addEventListener("DOMContentLoaded", () => {
  initMap();

  el("btnLoad").addEventListener("click", () => loadPois().catch(e => setStatus(String(e), false)));
  el("btnCompute").addEventListener("click", () => computeRoute().catch(e => setStatus(String(e), false)));
  el("btnPersist").addEventListener("click", () => persistRoute().catch(e => setStatus(String(e), false)));

  // auto-load for convenience
  loadPois().catch(e => setStatus(String(e), false));
});
