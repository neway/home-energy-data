# home-energy-data

When the firm Giv Energy announced it was going into administration - I decided it was time to figure out how to keep a backed up copy of all the data that was stored within it's cloud infrastructure.  I wanted to not only keep hold of my data beyond the life of any associated company - but wanted flexibility to search and analyse my data in a way that is only possible with self hosting.

More docs here: https://www.newaytech.co.uk/home-energy-data-self-hosted

Imports GivEnergy inverter data (5-minute datapoints, daily totals, energy flows) and Octopus meter readings into
MySQL (`givenergy_import.php`), and reports on the battery's health and value (`battery_report.php`).
`READ.me` has sample SQL. INSTALL.md sets it up.

## Not yet done

1. **Past tariffs.** Payback prices every past year at today's tariff; price each period at its own rates.
2. **Axle grid events, automatically.** Payouts are entered by hand (below). Axle's householder endpoint (the one
   Home Assistant uses) gives only the next or current event: `GET https://api.axle.energy/vpp/home-assistant/event`
   with `Authorization: Bearer <token>` (Axle account, Account Settings, Home Assistant, Generate Token), returning
   `start_time`, `end_time`, `import_export`, `updated_at`, `opted_out`. No history or earnings, so: poll it every
   10-30 minutes and keep each event. The partner API at docs.axle.energy is for manufacturers, not householders.

## EDF bills

```
php edf_import.php --config=/path/to/givenergy.ini
```

Reads each PDF in `../home_energy_private/edf_bills` with `pdftotext -raw` (poppler-utils; `-layout` breaks the
half-hourly pages) into:

| Table | One row per |
|---|---|
| `edf_bills` | bill: date, balance before and after, payments, credits total |
| `edf_charges` | fuel and tariff period: readings, m3 and calorific value (gas), kWh (off-peak and peak on Go Electric), rates, standing charge, VAT, total. `supply` is import, export or gas |
| `edf_credits` | credit line (loyalty, smart charging, Sunday Saver...), before VAT |
| `edf_halfhours` | import half-hour from the bill's daily pages: rate, kWh, cost. `start_utc` counts on from local midnight, so clock-change days have 46 or 50 |

A bill is loaded only if it adds up (`EdfBill::problems`): each line to its subtotal and total, VAT, standing charge
days, readings to kWh, gas m3 to kWh by the calorific value, the half-hours to the kWh billed at each rate and to
each day's total, and the balance carried from the last bill. Re-running replaces each bill's rows.

What the bills add: the car. If the charger is outside GivEnergy's grid sensor, the meter's import (Octopus
half-hours, then the EDF bills' half-hours once they start) minus GivEnergy's is the car's charging. On days the car
doesn't charge the two should agree to about 0.01 kWh. To check it against the charger: myenergi support can export
a zappi's hourly data, where `avgBoostLoad1Power` is grid to car and `avgDivertedLoad1Power` solar to car (W averaged
over each hour, so W/1000 = kWh). EDF's Go Electric also bills the car's smart-charging slots at the cheap rate in the
daytime, so the half-hours, not a fixed off-peak window, say what was cheap.

## Axle grid events

Payouts are kept by hand in `../home_energy_private/axle_events.php`, copied from the Axle app's "Past" list:

```php
<?php
return [
    // [date, start, end (local time), £ paid], newest first
    ['2026-10-27', '19:00', '20:00', 5.29],
];
```

The report shows each event against GivEnergy's counters at its start and end (exported, imported, battery out,
battery %), and the 12 months' payouts in the conclusion.

## Shelly devices

```
php shelly_import.php --config=/path/to/givenergy.ini --poll                            # every 2 minutes
php shelly_import.php --config=/path/to/givenergy.ini --auto-catchup [--from=2024-06-01] # nightly; --from backfills
```

Reads every device on a Shelly Cloud account (`shelly_server` and `shelly_auth_key` in the ini: Shelly app, User
settings, Authorization cloud key). The cloud allows about one request a second, so a backfill takes a while (one
request per device per day).

| Table | What |
|---|---|
| `shelly_devices` | every device and channel: name, model, kind (power, sensor, other) |
| `shelly_device_names` | each name a device has had, from when it was first seen with it |
| `shelly_hourly` | the cloud's hourly history: Wh (and voltage) for power meters; min and max temperature and humidity for sensors |
| `shelly_minutes` | Wh per minute for Gen2+ power meters, from polling: each status gives the last three complete minutes (`aenergy.by_minute`, mWh, newest first, before `minute_ts`; see the Gen2 API's Switch docs), so polls 2 minutes apart overlap and miss nothing |
| `shelly_sensor_readings` | each sensor reading (H&T every ~10 minutes, BLU every 1-2), at the device's own time |

Everything is keyed on the device's hardware id and channel, never its name, as a plug can move to another job.
Gen1 power meters (e.g. 1PM, Dimmer 2) report minutes in an older format, so they have hourly history only. A BLU
sensor's cloud history can stop while it still reports live, so the report fills its missing hours from the polled
readings. Which device does what (outside sensor, garage, office heat pump, the heaters it replaced) is set by id
in `../home_energy_private/config.php`.

## Weather

```
php weather_import.php --config=/path/to/givenergy.ini [--from=2023-01-01]
```

Daily mean outside temperature from the Open-Meteo archive (free, no key) for `WEATHER_LATITUDE` and
`WEATHER_LONGITUDE` (a town is close enough), into `weather_daily`, for degree days: each day's mean below 15.5C,
added up, so heating in different winters compares fairly. Open-Meteo's free API is for non-commercial use (600
calls a minute, 10,000 a day; this makes one a night) and its data is CC BY 4.0: the report credits it.

## Battery report

```
php battery_report.php --config=/path/to/givenergy.ini [--html]
```

Reads `givenergy_datapoints` and `givenergy_daily_summary` up to yesterday (or the last imported day, if earlier),
so every day counted is complete. Takes about 20 seconds. Prints text tables, or with `--html` a page whose
`<title>` is the one-line summary and whose capacity and export cells are coloured red/amber/green (thresholds
`RAG_*` and `EXPORT_*`). Rows with dates run newest first. The tariff, install date, costs, the extra-capacity prices and the "when to add
storage" rule are constants in `../home_energy_private/config.php` (from `config.sample.php`): change them there.

Run it each night after the imports (INSTALL.md has a crontab line); the `--html` output makes a good email, with
its `<title>` as the subject.

Sections, in order:

| Section | Window | Source |
|---|---|---|
| Conclusion: house electricity net (import, solar export, battery export), standing charge, car, gas, battery ceiling | 12 months | samples; standing charges and gas rate from the latest EDF bill; car and gas from meter and bills |
| Car charging: meter import - GivEnergy import, by month | 12 months | Octopus then EDF half-hours, inverter counters |
| Axle grid events: each payout with what was exported, imported and out of the battery, and the battery's % | every event in `axle_events.php` | inverter counters at each event's start and end |
| Where the electricity goes: each Shelly-metered device's kWh (30 days, 12 months), share at peak, cost, and always-on draw, against GivEnergy's home use | 30 days, 12 months | `shelly_hourly`, inverter counters, samples |
| Garage humidity: inside and outside temperature, RH and absolute humidity, the gap, hours over 70% RH, the dehumidifier's kWh, cost and running hours, hours when outside was drier, how leaky | last 7 days, then each month | garage sensors and dehumidifier meter; `HOME_CHANGES` marked |
| Office heating: each winter (Dec-Feb) on the old heaters against the heat pump, per degree day, with the saving in that winter's weather; then the heat pump month by month with office and outside temperature | every complete winter; 12 months | `shelly_hourly`, `weather_daily` |
| EDF bills against GivEnergy: each bill line (units, standing charge, VAT, total), with GivEnergy's import or export for the same days | every bill | `edf_charges`, inverter counters |
| Demonstrated capacity | each of the last 7 days, 30 days, 12 months | samples (slices, below) |
| Full 100% to 0% discharges | 12 months | discharge counter |
| Month by month: solar, home use, import, **peak import**, export, **export from battery**, battery in / from grid / out, balance gap, capacity | each month of the 12 | inverter counters; peak import and battery export from samples |
| Export against generation | 12 months | counters, split by samples |
| What the system saves, with the kWh behind each cost | 12 months only (seasons swing too much for less) | samples, checked against counters |
| Payback: saved so far, still to pay, years to go | since install | samples |
| Can a 6 kW inverter cover the house | 12 months | samples |
| Extra capacity | 12 months | samples |
| When to add storage | 12 months | the above |

### Demonstrated capacity (`src/BatteryCapacity.php`)

How much energy the battery actually delivers, not what the SOC % claims.

- **Slices.** For each pair of consecutive samples on the same day, at most 15 minutes apart, where the battery is
  discharging (over 50 W at both), not charging (charge counter unchanged): take the SOC drop and the increase in
  the inverter's discharge counter (`battery_discharge_energy_today_kwh`). Group by the 10% band the step started
  in (100% counts in 90-100) and divide: kWh per 1% for that band. Capacity = sum of the ten bands x 10.
  A band with under 5% of SOC drop in the window is unknown. For a single day, unknown bands are borrowed from the 30
  days (marked *: the 0-9% band nearly always, as the reserve is 4%); for a month, the capacity is left blank (sunny
  months rarely run the battery low). Single days are noisy (the discharge counter moves in 0.1 kWh steps): look for
  the trend, not one band.
  The per-band figures show *where* capacity is lost: a healthy pack is flat at rated / 100 = 0.136 kWh per 1%.
  Green is 85%+ of that, amber 60-85%, red below 60%.
- **Full cycles.** Any day the battery went from 99% or more to 2% or less with no charging in between, the
  discharge counter from the first sample at the top (once charging stopped) to the last at the bottom.
  This is the direct measurement; the slices should agree with it.

Caveats: the 90-100% band reads high in sunny months, because the BMS holds 100% while small loads are drawn and
solar tops it back up. The 0-9% band has few samples (the reserve is usually 4%) so it is noisy over short windows.

### Checking the numbers

- **Balance gap** (month by month): solar + import + battery out should equal home use + export + battery in, give
  or take conversion losses. It runs at -1 to -4 kWh a month, under 0.5%.
- **Samples against counters** (under the savings): the 5-minute samples add up to 97-102% of the inverter's own
  import, export, home use and solar counters. The gaps are missing samples (skipped when more than 15 minutes apart).
- **Workings**: the savings table shows the off-peak, peak and export kWh behind each cost, so any line can be
  redone by hand at the tariff.

### What the system saves (`src/BatteryEconomics.php`)

Every step between `NORMAL` samples is priced peak or off-peak by the hour it ends in:

| Scenario | How it's priced |
|---|---|
| Actual | real grid import and export from `grid_power_w` |
| No battery | same load and solar: load minus solar imported, surplus exported |
| Grid only | the whole load imported, nothing exported |

Battery saves = no battery - actual. System saves = grid only - actual. "Peak import" is the leakage: real imports
in peak hours, when the battery had run out (or hit its power limit).

**Export from battery**: in each step, export up to the solar surplus at that moment (solar - load) counts as solar;
the rest came out of the battery. Not from `givenergy_energy_flows`: the API's flow data has impossible spikes
(e.g. 15.8 kWh battery to grid in half an hour). A battery charged mostly from the grid exports "grey" energy.

**EDF and grey export**: EDF's SEG terms pay only for export from the solar installation; if a meter reading looks
like it includes battery (or other) energy, EDF decide what share to pay. Not seen enforced, but battery export
isn't something to plan on, so nothing in the extra-capacity or decision sections counts export income. The yearly
export is measured against the MCS estimate of generation (`MCS_ESTIMATE_KWH`, which EDF can see): green
below 90%, amber 90-100%, red over. The month table shows each month's export against that month's actual solar.

**Payback** adds up the same savings from `INSTALL_DATE`, then divides what is still to pay by the last 12 months'
saving. It prices *every* year at today's tariff, which misprices years on a different tariff (e.g. a different off-peak
window makes the battery look useless then). If past rates and off-peak hours are ever recorded, price each period
at its own tariff.

**6 kW inverter**: time and energy where the house drew more than `EXTRA_INVERTER_KW`, which a battery on that
inverter couldn't supply.

### Extra capacity (`BatteryEconomics::extraCapacity`)

Replays the last 12 months with an extra battery (in `EXTRA_CHUNK_KWH` chunks, on its own AC-coupled inverter) alongside the
existing system, using the *actual* grid flows, so the existing battery's real behaviour and fade are built in:

- off-peak: fills to full at the off-peak rate (losses included)
- peak: stores solar that was actually exported (giving up the export rate), and covers actual peak imports up to
  the inverter's power
- each kWh moved saves the peak rate less the off-peak cost of putting it in

It never exports. Costs are installed (supply + `EXTRA_INSTALL_GBP`). "Total usable" is the last 30 days'
demonstrated capacity plus the extra, to show where own use runs out of peak import to move.

### When to add storage (`decisionSection`)

Own use only. Top the usable total up to `DECISION_TOTAL_KWH` (in whole chunks) when either:

- **A.** that top-up pays back from own use within `DECISION_PAYBACK_YEARS` (e.g. the extra battery's warranty), at the installed cost.
  As the existing battery fades, the top-up grows and so does the peak import it can move.
- **B.** peak import over the last 12 months passes `DECISION_PEAK_IMPORT_KWH`: the existing
  battery has faded, or daytime load (e.g. a heat pump) has grown, so there's more to shift. 12 months, not 30 days,
  because winter alone can push a 30-day figure past it.
