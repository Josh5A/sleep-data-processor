# Sleep Data Processor
Script for processing Sleep Cycle data exports

## Background

I wrote this script to make it easy for me to turn my [Sleep Cycle](https://www.sleepcycle.com/) app data into visual sleep charts showing sleep and wake times and hours slept. 

These charts are used to diagnose and manage sleep and circadian rhythm disorders.

### Example sleep chart made with this script:
![image](https://github.com/user-attachments/assets/aa7babfd-58d1-4561-b6e0-5a39fdc7139e)

## Usage

### Setup

This script is designed to work with this [Google Sheets template](https://docs.google.com/spreadsheets/d/1bae1Rd7Ow1-quu7ddtPsrhsauH6KFFqn4suwRdhfGoM/edit?usp=sharing).

1. Open the Google Sheets template link

2. Choose "File" > "Make a Copy"


### Process sleep log

1. Export your Sleep Cycle data — Instructions are [here](https://support.sleepcycle.com/hc/en-us/articles/12221835792796-I-d-like-to-export-my-data-from-Sleep-Cycle)

2. Place the exported data file in the files directory (or edit the file path in `bin/process-chart.php` to point to your file location).

3. Run `bin/process-chart.php`

```bash
php -f bin/process-chart.php
```

4. Open the `files/sleep_chart_output.tdv` file in a text editor.

5. Copy the contents of the file

6. Go to the Google Sheet, select cell A2, and paste. You should see a chart appear.

### Updating your sleep log

1. Move or rename the old `files/sleepdata.csv` file.

2. Follow the same steps above to update your chart. Sleep Cycle always exports your entire sleep log history, so you can just paste over the previous data from the top.




