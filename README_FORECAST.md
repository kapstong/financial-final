Forecasting integration

Files added:
- `scripts/budget_forecast.py`: CLI script to run forecasting with `sklearn.ensemble.RandomForestRegressor`. Accepts `<input_csv> <predict_months>` and outputs JSON.
- `requirements.txt`: Python dependencies (`pandas`, `numpy`, `statsmodels`).

Setup
1. Install Python (3.8+ recommended). Either create a virtualenv at `.venv` in project root or set environment variable `PYTHON_EXECUTABLE` to the python executable path used by the webserver.

Example (Windows PowerShell):

```powershell
python -m venv .venv
.\.venv\Scripts\pip install -r requirements.txt
$env:PYTHON_EXECUTABLE = (Resolve-Path .\.venv\Scripts\python.exe).Path
```

Usage
- The dashboard Forecast tab calls `api/budgets.php?action=forecast&months=48&forecast_date=<YYYY-MM-DD>`.
- `forecast_date` controls the prediction horizon. For example, tomorrow predicts through the month containing tomorrow; a later selected date expands the Random Forest forecast window up to the API limit.
- The API aggregates historical monthly expense data, runs the Python Random Forest Regressor script, and returns `method`, `history`, `forecast`, `model`, and `drivers`.

If Python or dependencies are unavailable, the script falls back to a simple average-growth forecast and still returns a readable JSON result. Install `scikit-learn` from `requirements.txt` to use the Random Forest model.
