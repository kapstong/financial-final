(function(){
    function formatCurrency(value) {
        return 'PHP ' + Math.round(Number(value || 0)).toLocaleString();
    }

    function isPrivacyRedacted(resp) {
        return !!(resp && resp.privacy_redacted);
    }

    function maskedValue() {
        return 'Masked';
    }

    function renderForecast(resp) {
        const container = document.getElementById('forecastDriversBody');
        if (!container) return;

        try {
            const history = resp.history || [];
            const forecast = resp.forecast || [];
            const model = resp.model || {};
            const method = resp.method || model.method || 'random_forest_regressor';
            const redacted = isPrivacyRedacted(resp);

            const cards = document.querySelectorAll('.forecast-card h3');
            if (cards && cards.length >= 3) {
                if (redacted) {
                    cards[2].textContent = maskedValue();
                } else {
                    const forecastTotal = forecast.reduce((sum, item) => sum + Number(item.value || 0), 0);
                    cards[2].textContent = forecast.length ? formatCurrency(forecastTotal) : 'Not available';
                }
            }

            const rows = [];
            rows.push(`<tr><td colspan="4"><strong>Model:</strong> ${method.replaceAll('_', ' ')}</td></tr>`);
            if (model.details && typeof model.details === 'object') {
                const details = [];
                if (model.details.training_rows !== undefined) details.push(`training rows: ${model.details.training_rows}`);
                if (model.details.lag_months !== undefined) details.push(`lag months: ${model.details.lag_months}`);
                if (model.details.mean_absolute_error !== undefined && model.details.mean_absolute_error !== null) {
                    details.push(`MAE: ${Number(model.details.mean_absolute_error).toFixed(2)}`);
                }
                if (details.length) rows.push(`<tr><td colspan="4"><strong>Training:</strong> ${details.join(', ')}</td></tr>`);
            }

            rows.push('<tr><th>Month</th><th>History</th><th>Forecast</th><th>Notes</th></tr>');
            const max = Math.max(history.length, forecast.length);
            for (let idx = 0; idx < max; idx++) {
                const historical = history[idx];
                const projected = forecast[idx];
                rows.push('<tr>' +
                    `<td>${(historical && historical.date) || (projected && projected.date) || ''}</td>` +
                    `<td>${historical ? (redacted ? maskedValue() : formatCurrency(historical.value)) : '-'}</td>` +
                    `<td>${projected ? (redacted ? maskedValue() : formatCurrency(projected.value)) : '-'}</td>` +
                    `<td>${projected ? 'Random Forest Regressor prediction' : 'Historical monthly outflow'}</td>` +
                    '</tr>');
            }

            container.innerHTML = rows.join('');
        } catch (error) {
            console.error('Error rendering forecast', error);
        }
    }

    async function fetchAndRenderForecast(apiPath) {
        const response = await fetch(apiPath);
        const data = await response.json();
        if (data.error) {
            throw new Error(data.details || data.error);
        }
        renderForecast(data);
        return data;
    }

    window.forecasting = { fetchAndRenderForecast, renderForecast, isPrivacyRedacted, maskedValue };
})();
