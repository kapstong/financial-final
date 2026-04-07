(function(){
    // Shared forecasting logic (TF.js client-side)
    function buildNaiveForecast(dates, values, periods, details) {
        const lastDate = new Date(dates[dates.length - 1]);
        const out = [];
        let ld = new Date(lastDate.getFullYear(), lastDate.getMonth(), 1);
        const lastVal = values[values.length - 1] || 0;
        for (let i = 1; i <= periods; i++) {
            const m = new Date(ld.getFullYear(), ld.getMonth() + i, 1);
            out.push({ date: `${m.getFullYear()}-${String(m.getMonth() + 1).padStart(2,'0')}-01`, value: lastVal });
        }
        return { method: 'naive', forecast: out, details };
    }

    function loadTfJs() {
        if (window.tf) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.8.0/dist/tf.min.js';
            s.onload = () => resolve();
            s.onerror = () => reject(new Error('Failed to load TF.js'));
            document.head.appendChild(s);
        });
    }

    async function tfForecast(history, periods = 12) {
        if (!history || history.length === 0) {
            return { method: 'naive', forecast: [], details: 'No history provided' };
        }

        const sanitizedHistory = history.filter(h => {
            const value = Number(h && h.value);
            return h && h.date && Number.isFinite(value);
        });
        const dates = sanitizedHistory.map(h => h.date);
        const values = sanitizedHistory.map(h => Number(h.value));

        if (values.length === 0) {
            return { method: 'naive', forecast: [], details: 'No valid history provided' };
        }

        const windowSize = 6;
        if (values.length <= windowSize) {
            return buildNaiveForecast(dates, values, periods, 'Insufficient history, repeating last value');
        }

        try {
            await loadTfJs();

            const xs = [];
            const ys = [];
            for (let i = 0; i + windowSize < values.length; i++) {
                xs.push(values.slice(i, i + windowSize));
                ys.push(values[i + windowSize]);
            }

            if (xs.length === 0 || ys.length === 0) {
                return buildNaiveForecast(dates, values, periods, 'Unable to build TF training windows, repeating last value');
            }

            const mean = values.reduce((s, v) => s + v, 0) / values.length;
            const variance = values.reduce((s, v) => s + Math.pow(v - mean, 2), 0) / values.length;
            const std = Math.sqrt(variance) || 1;

            const normXs = xs.map(r => r.map(v => (v - mean) / std));
            const normYs = ys.map(v => (v - mean) / std);

            const tfXs = tf.tensor2d(normXs, [normXs.length, windowSize]);
            const tfYs = tf.tensor2d(normYs, [normYs.length, 1]);

            const model = tf.sequential();
            model.add(tf.layers.dense({ units: 64, activation: 'relu', inputShape: [windowSize] }));
            model.add(tf.layers.dense({ units: 32, activation: 'relu' }));
            model.add(tf.layers.dense({ units: 1 }));
            model.compile({ optimizer: 'adam', loss: 'meanAbsoluteError' });

            const historyFit = await model.fit(tfXs, tfYs, { epochs: 80, batchSize: 8, verbose: 0 });

            let lastWindow = values.slice(-windowSize).map(v => (v - mean) / std);
            const forecast = [];
            let lastDate = new Date(dates[dates.length - 1]);
            lastDate = new Date(lastDate.getFullYear(), lastDate.getMonth(), 1);

            for (let p = 1; p <= periods; p++) {
                const tensor = tf.tensor2d([lastWindow], [1, windowSize]);
                const predNorm = (await model.predict(tensor).data())[0];
                const pred = predNorm * std + mean;
                const m = new Date(lastDate.getFullYear(), lastDate.getMonth() + p, 1);
                forecast.push({ date: `${m.getFullYear()}-${String(m.getMonth() + 1).padStart(2,'0')}-01`, value: pred });
                lastWindow = lastWindow.slice(1).concat([(pred - mean) / std]);
            }

            const trainingLoss = historyFit.history && historyFit.history.loss ? historyFit.history.loss[historyFit.history.loss.length - 1] : null;

            return {
                method: 'tfjs_dense',
                forecast,
                details: { mean, std },
                training: { loss: trainingLoss, epochs: historyFit.epoch ? historyFit.epoch.length : historyFit.history.loss.length }
            };
        } catch (err) {
            console.warn('TF forecast fallback to naive model', err);
            return buildNaiveForecast(dates, values, periods, 'TF model unavailable, repeating last value');
        }
    }

    async function fetchAndRenderForecast(apiPath, predictMonths = 12) {
        try {
            const resp = await fetch(apiPath);
            const data = await resp.json();
            if (data.error) {
                console.error('Forecast error:', data.error);
                if (typeof showAlert === 'function') showAlert('Forecast error: ' + (data.error || 'unknown'), 'warning');
                return;
            }
            const history = data.history || [];
            const tfres = await tfForecast(history, predictMonths);
            const combined = { method: tfres.method, history: history, forecast: tfres.forecast, details: tfres.details, training: tfres.training };
            renderForecast(combined);
        } catch (err) {
            console.error('Failed to fetch forecast', err);
            if (typeof showAlert === 'function') showAlert('Failed to fetch forecast: ' + (err.message || err), 'danger');
        }
    }

    function renderForecast(resp) {
        const container = document.getElementById('forecastDriversBody');
        if (!container) return;

        try {
            const history = resp.history || [];
            const forecast = resp.forecast || [];
            const details = resp.details || {};

            const totalProjected = forecast.reduce((s, x) => s + (x.value || 0), 0) + (history.length ? history[history.length-1].value : 0);
            const variance = (history.reduce((s, x) => s + (x.value || 0), 0) - totalProjected) * -1;

            const cards = document.querySelectorAll('.forecast-card h3');
            if (cards && cards.length >= 3) {
                cards[0].textContent = 'PHP ' + Math.round(totalProjected).toLocaleString();
                cards[1].textContent = 'PHP ' + Math.round(variance).toLocaleString();
                cards[2].textContent = (forecast.length ? 'PHP ' + Math.round(forecast.reduce((s,x)=>s+(x.value||0),0)).toLocaleString() : 'Not available');
            }

            let html = '';
            if (resp.method) html += `<tr><td colspan="4"><strong>Model:</strong> ${resp.method}</td></tr>`;
            if (resp.training) html += `<tr><td colspan="4"><strong>Training:</strong> loss=${resp.training.loss?.toFixed(4) || 'n/a'}, epochs=${resp.training.epochs || 'n/a'}</td></tr>`;
            if (details && typeof details === 'object') html += `<tr><td colspan="4"><strong>Details:</strong> ${JSON.stringify(details)}</td></tr>`;

            html += '<tr><th>Month</th><th>History</th><th>Forecast</th><th>Notes</th></tr>';
            const max = Math.max((resp.history || []).length, (resp.forecast || []).length);
            for (let i = 0; i < max; i++) {
                const h = (resp.history || [])[i];
                const f = (resp.forecast || [])[i];
                html += '<tr>' +
                    `<td>${(h && h.date) || (f && f.date) || ''}</td>` +
                    `<td>${h ? 'PHP ' + Number(h.value).toLocaleString() : '-'}</td>` +
                    `<td>${f ? 'PHP ' + Number(f.value).toLocaleString() : '-'}</td>` +
                    `<td>${(i===0 ? 'Computed using historical monthly totals; model: ' + (resp.method || '') : '')}</td>` +
                    '</tr>';
            }

            container.innerHTML = html;
        } catch (e) {
            console.error('Error rendering forecast', e);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('refreshForecastBtn');
        if (btn) {
            btn.addEventListener('click', function(){
                // api path uses parent folder to reach /api
                const apiPath = '../api/budgets.php?action=forecast&months=48';
                fetchAndRenderForecast(apiPath, 12);
            });
        }
    });

    // Expose for manual use if needed
    window.forecasting = { tfForecast, fetchAndRenderForecast, renderForecast };
})();
