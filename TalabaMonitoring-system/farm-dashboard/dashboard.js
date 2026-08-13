/**
 * Map Picker
 */
let farmMap    = null;
let farmMarker = null;

// Tight bounding box — Cavite province only
const CAVITE_BOUNDS = [
    [14.08, 120.76],   // SW corner (Tagaytay south / Gen. Trias west)
    [14.52, 121.05]    // NE corner (Bacoor north / Carmona east)
];

document.getElementById('staticBackdrop').addEventListener('shown.bs.modal', function () {
    if (farmMap) {
        farmMap.invalidateSize();
        return;
    }

    setTimeout(function () {

        farmMap = L.map('farm-map-picker', {
            center: [14.28, 120.87],
            zoom: 11,
            minZoom: 10,
            maxZoom: 18,
            maxBounds: CAVITE_BOUNDS,
            maxBoundsViscosity: 1.0
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 18
        }).addTo(farmMap);

        farmMap.on('click', async function (e) {
            const { lat, lng } = e.latlng;

            // 🔥 CALL WEATHER ALERT
            checkWeatherAlerts(lat, lng);

            if (farmMarker) farmMap.removeLayer(farmMarker);
            farmMarker = L.marker([lat, lng]).addTo(farmMap);

            document.getElementById('lat-display').value = lat.toFixed(6);
            document.getElementById('lng-display').value = lng.toFixed(6);
            document.getElementById('lat-hidden').value  = lat.toFixed(6);
            document.getElementById('lng-hidden').value  = lng.toFixed(6);

            document.getElementById('map-address-display').innerHTML =
                '<span class="text-muted">Fetching address…</span>';

            try {
                const r = await fetch(
                    `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`
                );
                const d = await r.json();
                const addr = d.display_name || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                document.getElementById('map-address-display').textContent = addr;
                document.getElementById('location-hidden').value = addr;
            } catch {
                const fallback = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                document.getElementById('map-address-display').textContent = fallback;
                document.getElementById('location-hidden').value = fallback;
            }
        });

    }, 300);
});

// ============================================================
//  HARVEST HISTORY CHART
//  Reads HARVEST_DATA (injected by PHP) and renders a line/bar
//  chart showing harvested quantity over time, one dataset per
//  farm.  Supports Monthly / Quarterly / Yearly grouping and
//  Line / Bar chart-type toggling.
// ============================================================

/** Colour palette for up to 10 farms */
const FARM_COLORS = [
    '#0d6efd', '#198754', '#dc3545', '#fd7e14',
    '#6f42c1', '#0dcaf0', '#ffc107', '#20c997',
    '#d63384', '#6c757d'
];

/** Build the period key used for grouping  (e.g. "2024-03", "2024-Q2", "2024") */
function periodKey(dateStr, groupBy) {
    const d = new Date(dateStr + 'T00:00:00');
    const y = d.getFullYear();
    const m = d.getMonth(); // 0-based
    if (groupBy === 'year')    return `${y}`;
    if (groupBy === 'quarter') return `${y}-Q${Math.floor(m / 3) + 1}`;
    return `${y}-${String(m + 1).padStart(2, '0')}`;        // month (default)
}

/** Human-readable label for a period key */
function periodLabel(key, groupBy) {
    if (groupBy === 'year')    return key;
    if (groupBy === 'quarter') return key.replace('-', ' ');  // "2024 Q2"
    // month: "2024-03" → "Mar 2024"
    const [y, m] = key.split('-');
    const d = new Date(parseInt(y), parseInt(m) - 1, 1);
    return d.toLocaleDateString('en-PH', { month: 'short', year: 'numeric' });
}

let performanceChart = null;   // keep reference so we can update it

function buildChartData(groupBy) {
    const raw = (typeof HARVEST_DATA !== 'undefined') ? HARVEST_DATA : [];

    if (raw.length === 0) return null;

    // 1. Collect all unique sorted period keys
    const allPeriods = [...new Set(raw.map(r => periodKey(r.harvestDate, groupBy)))]
        .sort();

    // 2. Group by farm
    const farmMap = {};   // { farm_id: { name, totals: { period: qty } } }
    raw.forEach(r => {
        const key = r.farm_id;
        if (!farmMap[key]) farmMap[key] = { name: r.farm_name, totals: {} };
        const p = periodKey(r.harvestDate, groupBy);
        farmMap[key].totals[p] = (farmMap[key].totals[p] || 0) + r.quantity;
    });

    // 3. Build datasets (one per farm, sorted by farm_id for consistent colours)
    const farms = Object.entries(farmMap).sort((a, b) => a[0] - b[0]);
    const datasets = farms.map(([farmId, info], idx) => {
        const color = FARM_COLORS[idx % FARM_COLORS.length];
        const data  = allPeriods.map(p => info.totals[p] ?? 0);

        return {
            label:                info.name,
            data,
            borderColor:          color,
            backgroundColor:      color + '22',   // ~13 % opacity fill
            pointBackgroundColor: color,
            pointRadius:          5,
            pointHoverRadius:     7,
            borderWidth:          2.5,
            tension:              0.35,
            fill:                 true,
        };
    });

    return {
        labels:   allPeriods.map(p => periodLabel(p, groupBy)),
        datasets,
    };
}

function initPerformanceChart() {
    const ctx      = document.getElementById('performanceChart');
    const emptyMsg = document.getElementById('harvestChartEmpty');
    if (!ctx) return;

    const raw = (typeof HARVEST_DATA !== 'undefined') ? HARVEST_DATA : [];

    // No data yet → hide canvas, show empty state
    if (raw.length === 0) {
        ctx.closest('.chart')?.classList.add('d-none');
        emptyMsg?.classList.remove('d-none');
        return;
    }

    let groupBy   = 'month';   // current state
    let chartType = 'line';

    const chartData = buildChartData(groupBy);

    performanceChart = new Chart(ctx, {
        type: chartType,
        data: chartData,
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            interaction: {
                mode:      'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display:  true,
                    position: 'top',
                    labels:   { usePointStyle: true, padding: 16, font: { size: 12 } }
                },
                tooltip: {
                    callbacks: {
                        label: ctx =>
                            ` ${ctx.dataset.label}: ${ctx.parsed.y.toLocaleString('en-PH')} kg`
                    }
                },
                title: {
                    display: false,
                }
            },
            scales: {
                x: {
                    grid:  { display: false },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text:    'Quantity (kg)',
                        font:    { size: 12 },
                        color:   '#6c757d',
                    },
                    ticks: {
                        font: { size: 11 },
                        callback: v => v.toLocaleString('en-PH')
                    }
                }
            }
        }
    });

    // ── Group-by buttons ──────────────────────────────────────────
    document.getElementById('chartGroupBy')?.addEventListener('click', e => {
        const btn = e.target.closest('[data-group]');
        if (!btn || !performanceChart) return;

        groupBy = btn.dataset.group;
        document.querySelectorAll('#chartGroupBy [data-group]').forEach(b =>
            b.classList.toggle('active', b === btn));

        const newData = buildChartData(groupBy);
        performanceChart.data.labels   = newData.labels;
        performanceChart.data.datasets = newData.datasets;
        performanceChart.update('active');
    });

    // ── Chart-type buttons ────────────────────────────────────────
    document.getElementById('chartType')?.addEventListener('click', e => {
        const btn = e.target.closest('[data-type]');
        if (!btn || !performanceChart) return;

        chartType = btn.dataset.type;
        document.querySelectorAll('#chartType [data-type]').forEach(b =>
            b.classList.toggle('active', b === btn));

        performanceChart.config.type = chartType;
        // Bar charts look better without fill
        performanceChart.data.datasets.forEach(ds => {
            ds.fill = (chartType === 'line');
        });
        performanceChart.update('active');
    });
}


// ============================================================
//  HARVEST DATE ALGORITHM
//  Based on peer-reviewed aquaculture research:
//
//  | Breeding Method          | Min Months | Max Months | Notes                                     |
//  |--------------------------|-----------|-----------|-------------------------------------------|
//  | Triploids                |     8     |    18     | Fastest: sterile, all energy → growth      |
//  | Tetraploids (4n)         |    10     |    20     | Faster than diploid, slightly slower than tri |
//  | Crossbreeding            |    12     |    20     | Hybrid vigor; grow-out 12–24 mo           |
//  | Line / Selective         |    14     |    24     | Standard diploid selective breeding        |
//  | Family                   |    14     |    24     | Family-based selection, similar to line    |
//  | Fertilization (Hatchery) |    12     |    24     | Hatchery diploid; depends on env           |
//  | Stake Method             |     8     |    12     | Traditional intertidal; slower             |
//  | Temperature Manipulation |    10     |    18     | Controlled environment accelerates growth  |
//  | Environmental Cues       |    12     |    20     | Induced spawning; moderate acceleration    |
//  | Cultch / Substrate       |    14     |    24     | Spat-on-shell; bottom/off-bottom culture   |
//  | Seed Collection (Wild)   |    18     |    36     | Wild spat; slowest, most variable          |
//
//  Sources:
//   • UF/IFAS FA208 – Triploid oyster production & performance (Guo et al.)
//   • IFAS Panhandle Outdoors – Diploid vs Triploid harvest windows
//   • Chefs Resources – Oyster farming methods (Rack & Bag, Beach, Longline)
//   • NOAA Fisheries – Pacific oyster aquaculture grow-out windows
//   • Huitres Boyard – French oyster cycle (spat → market 18–36 mo)
//   • Malpequebay Oyster Farms – Nursery (6–12 mo) + Grow-out (12–24 mo)
// ============================================================

const HARVEST_WINDOWS = {
//     // Polyploidy-based methods
//     "Triploids":               { minMonths: 8,  maxMonths: 18, label: "Triploid",          color: "#198754" }, // green — fastest
//     "Tetraploids (4n)":        { minMonths: 10, maxMonths: 20, label: "Tetraploid (4n)",    color: "#0d6efd" }, // blue
//     // Genetic selection
//     "line":                    { minMonths: 14, maxMonths: 24, label: "Line Breeding",       color: "#6f42c1" }, // purple
//     "family":                  { minMonths: 14, maxMonths: 24, label: "Family Breeding",     color: "#6f42c1" }, // purple
//     "cross":                   { minMonths: 12, maxMonths: 20, label: "Crossbreeding",       color: "#0dcaf0" }, // cyan
//     // Hatchery / spawning induction
//     "Fertilization":           { minMonths: 12, maxMonths: 24, label: "Hatchery Fertilization", color: "#ffc107" }, // yellow
//     "Temperature Manipulation":{ minMonths: 10, maxMonths: 18, label: "Temp. Manipulation",  color: "#fd7e14" }, // orange
//     "Environmental Cues":      { minMonths: 12, maxMonths: 20, label: "Environmental Cues",  color: "#20c997" }, // teal
//     // Grow-out / culture methods
//     "Stake Method":            { minMonths: 8, maxMonths: 12, label: "Stake Method",        color: "#795548" }, // brown
//     "Cultch / Substrate":      { minMonths: 14, maxMonths: 24, label: "Cultch / Substrate",  color: "#607d8b" }, // blue-grey
//     "Seed Collection":         { minMonths: 18, maxMonths: 36, label: "Wild Seed Collection", color: "#dc3545" }, // red — slowest
// };
// Polyploidy-based methods
    "Triploids":               { minMonths: 6,  maxMonths: 9,  label: "Triploid",          color: "#198754" }, // green — fastest
    "Tetraploids (4n)":        { minMonths: 7,  maxMonths: 10, label: "Tetraploid (4n)",    color: "#0d6efd" }, // blue

    // Genetic selection
    "line":                    { minMonths: 8,  maxMonths: 12, label: "Line Breeding",       color: "#6f42c1" }, // purple
    "family":                  { minMonths: 9,  maxMonths: 13, label: "Family Breeding",     color: "#6f42c1" }, // purple
    "cross":                   { minMonths: 7,  maxMonths: 11, label: "Crossbreeding",       color: "#0dcaf0" }, // cyan

    // Hatchery / spawning induction
    "Fertilization":           { minMonths: 8,  maxMonths: 12, label: "Hatchery Fertilization", color: "#ffc107" }, // yellow
    "Temperature Manipulation":{ minMonths: 7,  maxMonths: 11, label: "Temp. Manipulation",  color: "#fd7e14" }, // orange
    "Environmental Cues":      { minMonths: 9,  maxMonths: 14, label: "Environmental Cues",  color: "#20c997" }, // teal

    // Grow-out / culture methods
    "Stake Method":            { minMonths: 8,  maxMonths: 12, label: "Stake Method",        color: "#795548" }, // brown
    "Cultch / Substrate":      { minMonths: 8,  maxMonths: 13, label: "Cultch / Substrate",  color: "#607d8b" }, // blue-grey
    "Seed Collection":         { minMonths: 10, maxMonths: 16, label: "Wild Seed Collection", color: "#dc3545" }, // red — slowest
};


/**
 * Returns { earliest: Date, latest: Date, windowLabel: string, color: string }
 * 
 * given a breed method key and a seeding date string (YYYY-MM-DD).
 */
function computeHarvestDates(breedMethod, seedingDateStr) {
    const window = HARVEST_WINDOWS[breedMethod];
    if (!window || !seedingDateStr) return null;

    const seeding = new Date(seedingDateStr + 'T00:00:00');
    if (isNaN(seeding)) return null;

    const earliest = new Date(seeding);
    earliest.setMonth(earliest.getMonth() + window.minMonths);

    const latest = new Date(seeding);
    latest.setMonth(latest.getMonth() + window.maxMonths);

    return {
        earliest,
        latest,
        minMonths:   window.minMonths,
        maxMonths:   window.maxMonths,
        windowLabel: window.label,
        color:       window.color
    };
}

/**
 * Formats a Date to "Jan 15, 2026"
 */
function fmtDate(d) {
    return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

/**
 * Injects harvest date badge into each farm card
 * 
 * and returns an array of harvest events for the calendar + reminders.
 */
function buildHarvestEvents() {
    const events = [];

    document.querySelectorAll('.farm-card-item').forEach(card => {
        const farmId     = card.dataset.farmId || '?';
        const breedMethod = card.dataset.breedMethod || '';
        const seedingDate = card.dataset.seedingDate || '';
        const farmName    = card.querySelector('.fw-bold.fs-5')?.textContent?.trim() || `Farm ${farmId}`;

        // Skip cards with no relevant data
        if (!breedMethod || !seedingDate) return;

        const result = computeHarvestDates(breedMethod, seedingDate);
        if (!result) return;

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        // Status flags
        const isReadyNow    = today >= result.earliest && today <= result.latest;
        const isOverdue     = today > result.latest;
        const daysToEarliest = Math.ceil((result.earliest - today) / 86400000);

        // ---- Inject harvest badge into card footer ----
        let footer = card.querySelector('.card-footer');
        if (footer) {
            // Remove old badge if re-running
            footer.querySelector('.harvest-badge')?.remove();

            const badge = document.createElement('div');
            badge.className = 'harvest-badge mt-2 pt-2 border-top w-100';
            badge.style.cssText = 'font-size:.78rem;';

            let statusHtml = '';
            if (isOverdue) {
                statusHtml = `<span class="badge bg-danger ms-1">Overdue</span>`;
            } else if (isReadyNow) {
                statusHtml = `<span class="badge bg-success ms-1 harvest-pulse">✅ Ready to Harvest!</span>`;
            } else if (daysToEarliest <= 30) {
                statusHtml = `<span class="badge bg-warning text-dark ms-1">~${daysToEarliest}d away</span>`;
            }

            badge.innerHTML = `
                <div class="d-flex flex-wrap align-items-center gap-1">
                    <span style="color:${result.color};">●</span>
                    <span class="text-muted">Breeding:</span>
                    <strong style="color:${result.color};">${result.windowLabel}</strong>
                    ${statusHtml}
                </div>
                <div class="text-muted mt-1">
                    🌱 Seeded: <strong>${fmtDate(new Date(seedingDate + 'T00:00:00'))}</strong>
                </div>
                <div class="mt-1">
                    🪣 Harvest Window:
                    <span class="fw-semibold" style="color:${result.color};">
                        ${fmtDate(result.earliest)} — ${fmtDate(result.latest)}
                    </span>
                </div>`;
            footer.appendChild(badge);
        }

        // ---- Collect event for calendar + reminders ----
        events.push({
            farmId,
            farmName,
            breedMethod,
            windowLabel:  result.windowLabel,
            color:        result.color,
            earliest:     result.earliest,
            latest:       result.latest,
            seedingDate:  new Date(seedingDate + 'T00:00:00'),
            isReadyNow,
            isOverdue,
            daysToEarliest,
        });
    });

    return events;
}


/**
 * Calendar
 * 
 * Extended to highlight expected harvest dates.
 */
function initCalendar(harvestEvents = []) {

    const monthYear    = document.getElementById("monthYear");
    const calendarDays = document.getElementById("calendarDays");
    const prevBtn      = document.getElementById("prevMonth");
    const nextBtn      = document.getElementById("nextMonth");

    if (!monthYear || !calendarDays) return;

    let currentDate = new Date();

    /**
     * Build a lookup: "YYYY-M-D" → array of harvest events covering that day
     * (a day is "covered" if it falls between earliest and latest, inclusive)
     */
    function buildDayMap(year, month) {
        const map = {};
        harvestEvents.forEach(ev => {
            // Iterate through each day of the month and check coverage
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            for (let d = 1; d <= daysInMonth; d++) {
                const dt = new Date(year, month, d);
                if (dt >= ev.earliest && dt <= ev.latest) {
                    const key = `${year}-${month}-${d}`;
                    if (!map[key]) map[key] = [];
                    map[key].push(ev);
                }
            }
        });
        return map;
    }

    function renderCalendar(date) {

        const year  = date.getFullYear();
        const month = date.getMonth();

        const firstDay   = new Date(year, month, 1).getDay();
        const totalDays  = new Date(year, month + 1, 0).getDate();
        const monthName  = date.toLocaleString("default", { month: "long" });

        monthYear.textContent = `${monthName} ${year}`;
        calendarDays.innerHTML = "";

        const dayMap = buildDayMap(year, month);
        const today  = new Date();

        const prevMonthLastDay = new Date(year, month, 0).getDate();

        // Previous month ghost days
        for (let i = firstDay - 1; i >= 0; i--) {
            const btn = document.createElement("button");
            btn.classList.add("cal-btn", "btn", "btn-sm");
            btn.disabled = true;
            btn.textContent = prevMonthLastDay - i;
            calendarDays.appendChild(btn);
        }

        // Current month days
        for (let day = 1; day <= totalDays; day++) {
            const btn = document.createElement("button");
            btn.classList.add("cal-btn", "btn", "btn-sm");
            btn.textContent = day;

            const isToday = (
                day   === today.getDate()  &&
                month === today.getMonth() &&
                year  === today.getFullYear()
            );
            if (isToday) btn.classList.add("btn-primary");

            // Harvest window highlighting
            const key    = `${year}-${month}-${day}`;
            const events = dayMap[key];
            if (events && events.length > 0) {
                // Use the color of the first (or only) event
                const ev = events[0];

                if (!isToday) {
                    btn.style.background = hexToRgba(ev.color, 0.18);
                    btn.style.color      = ev.color;
                    btn.style.fontWeight = '700';
                    btn.style.border     = `1.5px solid ${hexToRgba(ev.color, 0.5)}`;
                    btn.style.borderRadius = '50%';
                }

                // Tooltip showing farm name(s)
                const names = [...new Set(events.map(e => e.farmName))].join(', ');
                btn.title = `🪣 Harvest window: ${names}`;

                // Small dot indicator below date
                const dot = document.createElement('span');
                dot.style.cssText = `
                    display:block;
                    width:5px; height:5px;
                    border-radius:50%;
                    background:${ev.color};
                    margin: 1px auto 0;
                `;
                btn.appendChild(dot);
            }

            calendarDays.appendChild(btn);
        }

        // Fill remaining cells
        const cellsSoFar = firstDay + totalDays;
        const totalCells = 7 * 6;
        for (let i = 1; i <= totalCells - cellsSoFar; i++) {
            const btn = document.createElement("button");
            btn.classList.add("cal-btn", "btn", "btn-sm");
            btn.disabled = true;
            btn.textContent = i;
            calendarDays.appendChild(btn);
        }
    }

    prevBtn?.addEventListener("click", () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar(currentDate);
    });
    nextBtn?.addEventListener("click", () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar(currentDate);
    });

    renderCalendar(currentDate);
}

/** Converts #rrggbb to rgba(r,g,b,a) */
function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1,3),16);
    const g = parseInt(hex.slice(3,5),16);
    const b = parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${alpha})`;
}


/**
 * Reminders Panel
 * 
 * Populates the #remindersList with harvest countdown reminders.
 */
function buildReminders(harvestEvents) {
    const list = document.getElementById('remindersList');
    if (!list) return;

    // Clear existing dynamic reminders (keep any static PHP-rendered ones if any)
    list.querySelectorAll('.harvest-reminder').forEach(el => el.remove());

    if (harvestEvents.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'list-group-item text-muted text-center py-3 harvest-reminder';
        empty.innerHTML = `<small>No farm data available yet. Add a farm to see harvest reminders.</small>`;
        list.appendChild(empty);
        return;
    }

    // Sort: overdue first, then soonest earliest date
    const sorted = [...harvestEvents].sort((a, b) => {
        if (a.isOverdue !== b.isOverdue) return a.isOverdue ? -1 : 1;
        return a.earliest - b.earliest;
    });

    const today = new Date();
    today.setHours(0,0,0,0);

    sorted.forEach(ev => {
        const li = document.createElement('li');
        li.className = 'list-group-item harvest-reminder d-flex align-items-start gap-2 py-2 px-3';

        // Color dot
        let iconHtml = `<span style="min-width:10px;width:10px;height:10px;border-radius:50%;background:${ev.color};display:inline-block;margin-top:4px;flex-shrink:0;"></span>`;

        // Status label
        let statusBadge = '';
        let urgencyClass = '';
        if (ev.isOverdue) {
            statusBadge  = `<span class="badge bg-danger">Overdue</span>`;
            urgencyClass = 'border-start border-danger border-3';
        } else if (ev.isReadyNow) {
            statusBadge  = `<span class="badge bg-success harvest-pulse">✅ Ready!</span>`;
            urgencyClass = 'border-start border-success border-3';
        } else if (ev.daysToEarliest <= 30) {
            statusBadge  = `<span class="badge bg-warning text-dark">Soon</span>`;
            urgencyClass = 'border-start border-warning border-3';
        } else {
            statusBadge  = `<span class="badge bg-secondary">Upcoming</span>`;
        }

        li.className += ` ${urgencyClass}`;

        // Days until / since label
        let timeLabel = '';
        if (ev.isOverdue) {
            const daysPast = Math.ceil((today - ev.latest) / 86400000);
            timeLabel = `<small class="text-danger">⚠️ Past harvest window by ${daysPast} day(s)</small>`;
        } else if (ev.isReadyNow) {
            const daysLeft = Math.ceil((ev.latest - today) / 86400000);
            timeLabel = `<small class="text-success">Window closes in ${daysLeft} day(s) (${fmtDate(ev.latest)})</small>`;
        } else {
            timeLabel = `<small class="text-muted">Earliest: ${fmtDate(ev.earliest)} &nbsp;|&nbsp; ${ev.daysToEarliest} day(s) away</small>`;
        }

        li.innerHTML = `
            ${iconHtml}
            <div class="flex-grow-1 overflow-hidden">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                    <strong class="text-truncate" style="max-width:160px;" title="${ev.farmName}">${ev.farmName}</strong>
                    ${statusBadge}
                </div>
                <div class="text-muted" style="font-size:.75rem;">${ev.windowLabel}</div>
                <div>${timeLabel}</div>
                <div style="font-size:.72rem;color:#6c757d;">
                    Window: ${fmtDate(ev.earliest)} — ${fmtDate(ev.latest)}
                </div>
            </div>`;

        list.appendChild(li);
    });
}


/**
 * Open-Meteo Weather & Marine Data
 */
async function fetchFarmWeather() {

    const defaultLat = 14.28;
    const defaultLng = 120.87;

    try {
        const forecastRes = await fetch(
            `https://api.open-meteo.com/v1/forecast?latitude=${defaultLat}&longitude=${defaultLng}&current=wind_speed_10m&wind_speed_unit=kmh`
        );
        const forecastData = await forecastRes.json();
        const windSpeed = forecastData.current?.wind_speed_10m ?? '—';

        const marineRes = await fetch(
            `https://marine-api.open-meteo.com/v1/marine?latitude=${defaultLat}&longitude=${defaultLng}&current=sea_surface_temperature,wave_height`
        );
        const marineData = await marineRes.json();
        const waterTemp  = marineData.current?.sea_surface_temperature ?? null;
        const waveHeight = marineData.current?.wave_height ?? null;

        const tideType = waveHeight !== null
            ? (waveHeight > 0.5 ? '🌊 High Tide' : '🏝️ Low Tide')
            : '—';

        const windEl  = document.getElementById('weather-wind-speed');
        const tideEl  = document.getElementById('weather-tide-type');
        const tempEl  = document.getElementById('weather-water-temp');

        if (windEl)  windEl.textContent  = windSpeed !== '—' ? `${windSpeed} km/h` : '—';
        if (tideEl)  tideEl.textContent  = tideType;
        if (tempEl)  tempEl.textContent  = waterTemp !== null ? `${waterTemp} °C` : '—';

    } catch (err) {
        console.error('Weather Update card fetch failed:', err);
    }

    const farmCards = document.querySelectorAll('.farm-card-item[data-lat][data-lng]');

    for (const card of farmCards) {
        const lat = parseFloat(card.dataset.lat);
        const lng = parseFloat(card.dataset.lng);

        if (isNaN(lat) || isNaN(lng)) continue;

        const tideSpan = card.querySelector('.farm-tide-type');
        const tempSpan = card.querySelector('.farm-water-temp');

        if (tideSpan) tideSpan.textContent = '⏳';
        if (tempSpan) tempSpan.textContent = '⏳';

        try {
            const marineRes = await fetch(
                `https://marine-api.open-meteo.com/v1/marine?latitude=${lat}&longitude=${lng}&current=sea_surface_temperature,wave_height`
            );
            const marineData = await marineRes.json();
            const waterTemp  = marineData.current?.sea_surface_temperature ?? null;
            const waveHeight = marineData.current?.wave_height ?? null;

            const tideType = waveHeight !== null
                ? (waveHeight > 0.5 ? '🌊 High Tide' : '🏝️ Low Tide')
                : '—';

            if (tideSpan) tideSpan.textContent = tideType;
            if (tempSpan) tempSpan.textContent = waterTemp !== null ? `${waterTemp} °C` : '—';

        } catch (err) {
            console.error(`Marine fetch failed for farm at ${lat},${lng}:`, err);
            if (tideSpan) tideSpan.textContent = 'N/A';
            if (tempSpan) tempSpan.textContent = 'N/A';
        }
    }
}

// ==========================================
// WEATHER ALERT FUNCTION (NO API KEY)
// ==========================================
async function checkWeatherAlerts(lat, lng) {
    try {
        const res = await fetch(
            `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lng}&current_weather=true&hourly=precipitation,weathercode,windspeed_10m,temperature_2m`
        );

        const data = await res.json();

        const current = data.current_weather;
        const hourly = data.hourly;

        const weatherCode = current.weathercode;
        const windSpeed = current.windspeed;
        const temp = current.temperature;

        let alertMessage = "";
        let alertType = "";

        // 🌪️ Storm / Typhoon
        if (windSpeed > 60 || [95, 96, 99].includes(weatherCode)) {
            alertType = "danger";
            alertMessage = "⚠️ Storm Alert: Possible typhoon conditions.";
        }

        // 🌧️ Heavy Rain
        else if (hourly.precipitation.some(p => p > 20)) {
            alertType = "warning";
            alertMessage = "🌧️ Heavy Rain: Flood risk detected.";
        }

        // 🌵 Drought
        else {
            const avgRain = hourly.precipitation.reduce((a, b) => a + b, 0) / hourly.precipitation.length;

            if (avgRain < 0.5 && temp > 32) {
                alertType = "warning";
                alertMessage = "🌵 Drought Alert: Low rainfall + high temp.";
            }
        }

        // ✅ SEND TO YOUR MODAL
        if (alertMessage !== "") {
            addNotification(alertMessage, alertType);
        }

    } catch (err) {
        console.error("Weather error:", err);
    }
}

/**
 * weather for notification modal
 */
function addNotification(message, type) {
    const list = document.getElementById("notification-list");
    if (!list) return;

    let bgClass = "list-group-item-light";
    if (type === "danger")  bgClass = "list-group-item-danger";
    if (type === "warning") bgClass = "list-group-item-warning";

    const timeNow = new Date().toLocaleTimeString();
    const item = document.createElement("div");  // use div, not <a>, to match PHP style
    // ADD "weather-alert-item" class so we can count them separately
    item.className = `p-3 border-bottom weather-alert-item ${bgClass}`;
    item.innerHTML = `
        <div class="d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
                <h6 class="mb-1">${message}</h6>
                <small class="text-muted">${timeNow}</small>
            </div>
            <span class="badge bg-warning text-dark">Weather</span>
        </div>`;
    list.appendChild(item);
}

// Event listener for notification modal
const notifModal = document.getElementById('notificationModal');
if (notifModal) {
    notifModal.addEventListener('shown.bs.modal', async function () {
        const list = document.getElementById("notification-list");
        if (!list) return;

        // Count items already rendered by PHP (DB notifications)
        const existingCount = list.querySelectorAll('.p-3.border-bottom').length;

        // Add loading indicator
        const loadingItem = document.createElement("div");
        loadingItem.className = "list-group-item list-group-item-action";
        loadingItem.id = "weather-loading-indicator";
        loadingItem.innerHTML = `<strong>Checking weather alerts...</strong><div class="small text-muted">Just a moment</div>`;
        list.appendChild(loadingItem);

        // Remove empty-state message if we're about to add weather alerts
        const emptyState = list.querySelector('.text-center.text-muted');

        // Load alerts for each farm
        const weatherAlertsBefore = list.querySelectorAll('.weather-alert-item').length;

        const promises = (typeof FARMS_DATA !== 'undefined' ? FARMS_DATA : []).map(farm => {
            if (farm.latitude && farm.longitude) {
                return checkWeatherAlerts(parseFloat(farm.latitude), parseFloat(farm.longitude));
            }
        }).filter(p => p);

        await Promise.all(promises);

        // Remove loading item
        const loader = document.getElementById('weather-loading-indicator');
        if (loader) loader.remove();

        const weatherAlertsAfter = list.querySelectorAll('.weather-alert-item').length;
        const newAlerts = weatherAlertsAfter - weatherAlertsBefore;

        // Update bell badge count
        const badge = document.getElementById('notif-bell-badge');
        if (badge && newAlerts > 0) {
            const current = parseInt(badge.textContent.trim()) || 0;
            badge.textContent = current + newAlerts;
            badge.style.display = 'inline-flex';
        }

        // If nothing at all (no DB notifs, no weather alerts), show "all clear"
        if (list.querySelectorAll('.p-3.border-bottom, .weather-alert-item').length === 0) {
            if (emptyState) emptyState.style.display = '';
        } else {
            if (emptyState) emptyState.style.display = 'none';
        }
    });
}

/**
 * Initialize Everything
 */
document.addEventListener("DOMContentLoaded", function () {
    initPerformanceChart();

    // 1. Compute harvest dates and inject badges on farm cards
    const harvestEvents = buildHarvestEvents();

    // 2. Initialize calendar with harvest highlighting
    initCalendar(harvestEvents);

    // 3. Populate reminders panel
    buildReminders(harvestEvents);

    // 4. Fetch live weather/marine data
    fetchFarmWeather();
});