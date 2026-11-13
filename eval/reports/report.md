# Volunteer rotas: evaluation

Simulated churches, a quarter (13 Sundays) each. Load spread is the standard deviation of how much of their offered time each volunteer is asked for.

## Tuning churches: 15 churches, 1,100 volunteers, 4,576 places

| Method | Places filled | Filled after declines | Two roles at one service | Times over a person's monthly limit | Volunteers never asked | Back-to-back Sundays | Households split | Load spread |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Team spreadsheets (each leader rotates their own list) | 92.6% | 89.1% | 374 | 802 | 3.6% | 24.0% | 20.4% | 0.556 |
| One coordinator, first person who can | 88.7% | 86.2% | 0 | 0 | 27.7% | 34.3% | 5.5% | 0.374 |
| This plugin | 90.5% | 88.2% | 0 | 0 | 0.7% | 10.8% | 1.5% | 0.197 |

## Held-out churches: 40 churches, 3,030 volunteers, 12,896 places

| Method | Places filled | Filled after declines | Two roles at one service | Times over a person's monthly limit | Volunteers never asked | Back-to-back Sundays | Households split | Load spread |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Team spreadsheets (each leader rotates their own list) | 93.2% | 90.0% | 1,141 | 2,337 | 3.1% | 25.3% | 24.1% | 0.567 |
| One coordinator, first person who can | 88.5% | 86.1% | 0 | 0 | 26.2% | 34.1% | 5.6% | 0.360 |
| This plugin | 90.9% | 88.5% | 0 | 0 | 0.1% | 11.1% | 1.1% | 0.190 |

## Very small and very large churches (held back): 10 churches, 1,021 volunteers, 4,160 places

| Method | Places filled | Filled after declines | Two roles at one service | Times over a person's monthly limit | Volunteers never asked | Back-to-back Sundays | Households split | Load spread |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Team spreadsheets (each leader rotates their own list) | 96.3% | 93.9% | 362 | 747 | 2.2% | 23.1% | 24.6% | 0.617 |
| One coordinator, first person who can | 91.1% | 89.5% | 0 | 0 | 30.0% | 30.2% | 17.9% | 0.284 |
| This plugin | 91.9% | 90.1% | 0 | 0 | 0.0% | 10.9% | 0.0% | 0.142 |

## Gate (regression limits, not targets)

- PASS heldout_double_booked_max: 0 (limit 0)
- PASS heldout_filled_vs_first_min: 0.024 (limit 0)
- PASS heldout_unused_max: 0.001 (limit 0.05)
- PASS heldout_consecutive_vs_first_max: -0.2297 (limit -0.1)
- PASS different_filled_vs_first_min: 0.0081 (limit -0.01)
- PASS different_double_booked_max: 0 (limit 0)
