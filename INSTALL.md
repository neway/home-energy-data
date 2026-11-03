# Installing

Tested on Ubuntu 22.04 with PHP 8.4 and MySQL 8. Run everything as a user that can read the config file.

## 1. Packages

```
sudo apt install php-cli php-mysql php-curl php-mbstring mysql-server poppler-utils
```

- PHP 8.0 or later, with `pdo_mysql`, `curl` and `mbstring`.
- MySQL 8 or MariaDB 10.2 or later (the battery report uses window functions).
- `poppler-utils` gives `pdftotext`, for reading EDF bill PDFs (the bill import is in progress; see README.md's TODO).

## 2. Folders

Keep the code and your own data side by side, in two repos:

```
home_energy_project/
  home_energy_toolset/   this repo: code only, public
  home_energy_private/   your data: bills (edf_bills/), notes. Keep this repo private
```

```
mkdir home_energy_project && cd home_energy_project
git clone <toolset repo URL> home_energy_toolset
mkdir -p home_energy_private/edf_bills && chmod 700 home_energy_private
```

Never put bills in the toolset: they carry your name, address, account and meter numbers, and half-hourly usage
that shows when the house is empty.

## 3. Database

```
sudo mysql
CREATE DATABASE solar CHARACTER SET utf8mb4;
CREATE USER 'solar_user'@'localhost' IDENTIFIED BY '<a long random password>';
GRANT ALL ON solar.* TO 'solar_user'@'localhost';
```

The importer creates its tables on first run.

## 4. Config

```
cd home_energy_toolset
cp givenergy.ini.sample ../home_energy_private/givenergy.ini
chmod 600 ../home_energy_private/givenergy.ini
```

Fill in the database login, your GivEnergy API key (GivEnergy portal, Account Settings, API tokens) and inverter
serial, and, if you're with Octopus, your Octopus API key and meter numbers. Never commit it: it holds your keys and passwords (the private repo's `.gitignore` excludes `*.ini`).

## 5. First import

GivEnergy keeps your full history, so load it from the day the system was commissioned (this takes a while: one API
call per day, with `request_delay_ms` between calls):

```
php givenergy_import.php --config=../home_energy_private/givenergy.ini --from=<commissioning date> --to=<yesterday>
```

Check it worked:

```
mysql solar -e "SELECT MIN(sample_date), MAX(sample_date), COUNT(*) FROM givenergy_datapoints"
```

## 6. Every night

`--auto-catchup` imports every day since the last one imported. Then run the report:

```
php givenergy_import.php --config=../home_energy_private/givenergy.ini --auto-catchup
php battery_report.php --config=../home_energy_private/givenergy.ini           # text
php battery_report.php --config=../home_energy_private/givenergy.ini --html    # HTML, e.g. to email
```

A crontab line, at 04:00 so yesterday is complete (adjust the paths):

```
0 4 * * * cd /path/to/home_energy_project/home_energy_toolset && php givenergy_import.php --config=../home_energy_private/givenergy.ini --auto-catchup >> /var/log/givenergy_import.log 2>&1
```

## 7. EDF bills (optional)

Download each bill PDF from EDF's MyAccount into `../home_energy_private/edf_bills/`, then:

```
php edf_import.php --config=../home_energy_private/givenergy.ini
```

It loads every PDF there into the `edf_*` tables and is safe to re-run. A bill whose figures don't add up (lines to
subtotals, half-hours to the billed kWh, the balance carried from the last bill) isn't loaded: the output says what
didn't match, and the script exits 1.

## 8. Shelly devices and weather (optional)

Add your Shelly Cloud server and key (Shelly app: User settings, Authorization cloud key) to the ini:

```
shelly_server = "https://shelly-XX-eu.shelly.cloud"
shelly_auth_key = "..."
```

Then backfill once (slow: about one request a second), poll every 2 minutes (only the devices in
`SHELLY_POLL_DEVICES`), and load the weather:

```
php shelly_import.php --config=../home_energy_private/givenergy.ini --auto-catchup --from=2025-01-01
*/2 * * * * php /path/to/home_energy_toolset/shelly_import.php --config=/path/to/home_energy_private/givenergy.ini --poll >/dev/null
php weather_import.php --config=../home_energy_private/givenergy.ini --from=2025-01-01
```

Run `shelly_import.php --auto-catchup` and `weather_import.php` nightly with the other imports. Set the device roles
(`SHELLY_*`, `OFFICE_*`) and `WEATHER_*` in `../home_energy_private/config.php` (see `config.sample.php`).

## 9. Your system's numbers

The battery report's tariff, install date, costs and rated capacity are constants in
`../home_energy_private/config.php`. Copy `config.sample.php` there and set them to your own: the report won't run
without it. README.md explains each section and how to check the numbers.
