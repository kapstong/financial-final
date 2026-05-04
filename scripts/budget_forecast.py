#!/usr/bin/env python3
import csv
import json
import os
import sys
from datetime import datetime

try:
    import numpy as np
    from sklearn.ensemble import RandomForestRegressor
except Exception:
    np = None
    RandomForestRegressor = None


def parse_month(date_value):
    return datetime.strptime(date_value[:10], "%Y-%m-%d")


def add_months(date_value, months):
    month_index = date_value.month - 1 + months
    year = date_value.year + month_index // 12
    month = month_index % 12 + 1
    return datetime(year, month, 1)


def simple_forecast(dates, values, periods, details):
    last_date = parse_month(dates[-1]) if dates else datetime.today().replace(day=1)
    vals = [float(v) for v in values]
    forecast = []

    if len(vals) < 2:
        last_value = vals[-1] if vals else 0.0
        for idx in range(1, periods + 1):
            target_date = add_months(last_date, idx)
            forecast.append({"date": target_date.strftime("%Y-%m-01"), "value": round(last_value, 2)})
        return {"method": "naive", "forecast": forecast, "details": details}

    growth_rates = []
    for idx in range(1, len(vals)):
        previous = vals[idx - 1]
        growth_rates.append(0.0 if previous == 0 else (vals[idx] - previous) / previous)

    average_growth = sum(growth_rates) / len(growth_rates) if growth_rates else 0.0
    value = vals[-1]
    for idx in range(1, periods + 1):
        target_date = add_months(last_date, idx)
        value = value * (1 + average_growth)
        forecast.append({"date": target_date.strftime("%Y-%m-01"), "value": round(float(max(value, 0)), 2)})

    return {
        "method": "avg_growth_fallback",
        "forecast": forecast,
        "details": f"{details}; average monthly growth rate: {average_growth:.4f}",
    }


def build_lag_features(values, dates, lag_count=6):
    rows = []
    targets = []
    for idx in range(lag_count, len(values)):
        date_value = parse_month(dates[idx])
        lag_values = values[idx - lag_count:idx]
        rows.append(lag_values + [date_value.month, date_value.year, idx])
        targets.append(values[idx])
    return rows, targets


def random_forest_forecast(dates, values, periods):
    lag_count = min(6, max(2, len(values) // 2))
    x_train, y_train = build_lag_features(values, dates, lag_count)
    if not x_train:
        return simple_forecast(dates, values, periods, "Insufficient history for RandomForestRegressor")

    model = RandomForestRegressor(
        n_estimators=300,
        random_state=42,
        min_samples_leaf=1,
        max_features=1.0,
    )
    model.fit(np.array(x_train, dtype=float), np.array(y_train, dtype=float))

    rolling_values = [float(v) for v in values]
    last_date = parse_month(dates[-1])
    forecast = []

    for step in range(1, periods + 1):
        target_date = add_months(last_date, step)
        feature_row = rolling_values[-lag_count:] + [target_date.month, target_date.year, len(rolling_values)]
        prediction = float(model.predict(np.array([feature_row], dtype=float))[0])
        prediction = max(prediction, 0.0)
        forecast.append({"date": target_date.strftime("%Y-%m-01"), "value": round(prediction, 2)})
        rolling_values.append(prediction)

    fitted = model.predict(np.array(x_train, dtype=float))
    mae = float(np.mean(np.abs(np.array(y_train, dtype=float) - fitted))) if len(y_train) else None
    return {
        "method": "random_forest_regressor",
        "forecast": forecast,
        "details": {
            "estimator": "sklearn.ensemble.RandomForestRegressor",
            "n_estimators": 300,
            "lag_months": lag_count,
            "training_rows": len(x_train),
            "mean_absolute_error": mae,
        },
    }


def main():
    if len(sys.argv) < 3:
        print(json.dumps({"error": "Usage: budget_forecast.py <input_csv> <predict_months>"}))
        sys.exit(1)

    input_csv = sys.argv[1]
    periods = int(sys.argv[2]) if sys.argv[2].isdigit() else 12

    if not os.path.isfile(input_csv):
        print(json.dumps({"error": "Input file not found"}))
        sys.exit(1)

    dates = []
    values = []
    with open(input_csv, newline="", encoding="utf-8-sig") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            if not row.get("date"):
                continue
            dates.append(row["date"])
            values.append(float(row.get("amount") or row.get("value") or 0))

    if not dates:
        print(json.dumps({"error": "No usable historical rows found"}))
        sys.exit(1)

    paired = sorted(zip(dates, values), key=lambda item: item[0])
    dates = [item[0] for item in paired]
    values = [item[1] for item in paired]

    if RandomForestRegressor is None or np is None:
        result = simple_forecast(dates, values, periods, "scikit-learn is unavailable")
    else:
        result = random_forest_forecast(dates, values, periods)

    result["history"] = [{"date": date, "value": float(value)} for date, value in zip(dates, values)]
    print(json.dumps(result))


if __name__ == "__main__":
    main()
