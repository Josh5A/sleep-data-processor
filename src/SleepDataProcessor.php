<?php

/**
 * Sleep Data Processor
 * 
 * This script reads sleep data from a CSV file, processes it,
 * and generates a tab-delimited file suitable for pasting into
 * a preformatted Google Spreadsheet template.
 * 
 * 
 * Usage: Copy the output into the following Google Sheet:
 * https://docs.google.com/spreadsheets/d/1065h1_dnySKa4V5WC4Bry-QYEWjQsVoalkyoNno8YnA/edit#gid=1627615060
 * (see 2023 tab)
 * 
 * PHP version 7.4+
 * 
 * @copyright 2024 Josh Alexander
 * @license   https://www.gnu.org/licenses/gpl-3.0.en.html GNU General Public License v3
 * @version   1.0.0
 * @link      https://github.com/Josh5A/sleep-data-processor
 */

/**
 * SleepDataProcessor class
 * 
 * Handles the processing of sleep data from CSV to a formatted chart output.
 * 
 * Time is handled as a linear count of wall-clock seconds, not as a timestamp:
 * every day is treated as exactly 24 hours long, which for a wall-clock chart
 * it is. A date/time string becomes daysFromCivil(Y, M, D) * 86400 + H * 3600 +
 * M * 60 + S, and all arithmetic happens on those integers. Hour index is
 * floor(t / 3600), which is the same thing as a YYYY-MM-DD-HH key spelled
 * differently.
 * 
 * That removes timezones, daylight saving, and DateTime from the maths
 * entirely. It matters for three reasons: the chart is a wall-clock grid, so
 * real elapsed time is the wrong axis; output would otherwise depend on the
 * machine's timezone; and a wall-clock time that never existed (there is one in
 * the sample data, 2026-03-09 02:51:57) is unambiguous here.
 */
class SleepDataProcessor
{
    /**
     * Seconds in one day, always, by definition here
     */
    private const SECONDS_PER_DAY = 86400;

    /**
     * Seconds in one hour
     */
    private const SECONDS_PER_HOUR = 3600;

    /**
     * Header name of the column holding Sleep Cycle's own elapsed duration
     */
    private const TIME_IN_BED_HEADER = 'Time in bed (seconds)';

    /**
     * Input file name
     * 
     * @var string
     */
    private $inputFile;

    /**
     * Output file name
     * 
     * @var string
     */
    private $outputFile;

    /**
     * CSV delimiter
     * 
     * @var string
     */
    private $csvDelimiter = ';';

    /**
     * Date format for parsing
     * 
     * Retained for API compatibility. The parser accepts 'Y-m-d H:i:s', which is
     * what Sleep Cycle exports; it is not a general format string.
     * 
     * @var string
     */
    private $dateFormat = 'Y-m-d H:i:s';

    /**
     * Parsed sessions, in file order, duplicates already dropped
     * 
     * Each entry: ['start' => int, 'end' => int, 'timeInBed' => float|null],
     * where start and end are wall-second counts.
     * 
     * @var array
     */
    private $sessions = [];

    /**
     * Array to store processed sleep hours, keyed by hour index
     * 
     * @var array
     */
    private $sleepHours = [];

    /**
     * Extra hours a row's sessions really lasted, keyed by row index
     * 
     * @var array
     */
    private $rowDrift = [];

    /**
     * Start of the chart range, in wall-seconds (an 18:00)
     * 
     * @var int
     */
    private $startDate;

    /**
     * End of the chart range, in wall-seconds (a 17:00)
     * 
     * @var int
     */
    private $endDate;

    /**
     * Constructor
     * 
     * @param string $inputFile     Input file name (optional)
     * @param string $outputFile    Output file name (optional)
     * @param string $csvDelimiter  CSV delimiter (optional)
     * @param string $dateFormat    Date format for parsing (optional)
     */
    public function __construct(
        ?string $inputFile = null,
        ?string $outputFile = null,
        ?string $csvDelimiter = null,
        ?string $dateFormat = null
    ) {
        if ($inputFile !== null) {
            $this->inputFile = $inputFile;
        }
        if ($outputFile !== null) {
            $this->outputFile = $outputFile;
        }
        if ($csvDelimiter !== null) {
            $this->csvDelimiter = $csvDelimiter;
        }
        if ($dateFormat !== null) {
            $this->dateFormat = $dateFormat;
        }
    }

    /**
     * Get the input file name
     * 
     * @return string
     */
    public function getInputFile(): string
    {
        return $this->inputFile;
    }

    /**
     * Set the input file name
     * 
     * @param string $inputFile
     * @return void
     */
    public function setInputFile(string $inputFile): void
    {
        $this->inputFile = $inputFile;
    }

    /**
     * Get the output file name
     * 
     * @return string
     */
    public function getOutputFile(): string
    {
        return $this->outputFile;
    }

    /**
     * Set the output file name
     * 
     * @param string $outputFile
     * @return void
     */
    public function setOutputFile(string $outputFile): void
    {
        $this->outputFile = $outputFile;
    }

    /**
     * Get the CSV delimiter
     * 
     * @return string
     */
    public function getCsvDelimiter(): string
    {
        return $this->csvDelimiter;
    }

    /**
     * Set the CSV delimiter
     * 
     * @param string $csvDelimiter
     * @return void
     */
    public function setCsvDelimiter(string $csvDelimiter): void
    {
        $this->csvDelimiter = $csvDelimiter;
    }

    /**
     * Get the date format
     * 
     * @return string
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    /**
     * Set the date format
     * 
     * @param string $dateFormat
     * @return void
     */
    public function setDateFormat(string $dateFormat): void
    {
        $this->dateFormat = $dateFormat;
    }

    /**
     * Main processing method
     * 
     * Orchestrates the entire data processing workflow.
     * 
     * @return void
     */
    public function process(): void
    {
        $this->readCsvData();
        $this->findDateRange();
        $this->processSleepData();
        $this->generateChart();
    }

    /**
     * Reads and parses the CSV into $this->sessions
     * 
     * The file is read once. Only Start and End are read positionally, as
     * before; every other column is ignored except 'Time in bed (seconds)',
     * which is looked up by header name because column order could vary between
     * Sleep Cycle versions.
     * 
     * Exact duplicate rows - the same Start *and* the same End string - are
     * dropped. Sleep Cycle exports occasionally contain a session twice; left
     * in, the duplicate would double every hour it touches once the hour map
     * accumulates. Raw strings are compared, deliberately without normalising
     * first: two rows are duplicates only if the export says so byte for byte.
     * 
     * @return void
     */
    private function readCsvData(): void
    {
        $file = file($this->inputFile);

        if ($file === false || count($file) === 0) {
            throw new RuntimeException('Could not read ' . $this->inputFile);
        }

        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', array_shift($file));
        $header = array_map('trim', explode($this->csvDelimiter, $headerLine));
        $timeInBedColumn = array_search(self::TIME_IN_BED_HEADER, $header, true);

        $this->sessions = [];
        $seen = [];

        foreach ($file as $line) {
            if (trim($line) === '') {
                continue;
            }

            $data = explode($this->csvDelimiter, $line);
            $key = ($data[0] ?? '') . "\x00" . ($data[1] ?? '');

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $start = $this->parseWallSeconds($data[0] ?? '');
            $end = $this->parseWallSeconds($data[1] ?? '');

            if ($start === null || $end === null) {
                continue;
            }

            $timeInBed = null;
            if ($timeInBedColumn !== false && isset($data[$timeInBedColumn]) && trim($data[$timeInBedColumn]) !== '') {
                $timeInBed = (float) trim($data[$timeInBedColumn]);
            }

            $this->sessions[] = [
                'start' => $start,
                'end' => $end,
                'timeInBed' => $timeInBed,
            ];
        }

        if (count($this->sessions) === 0) {
            throw new RuntimeException('No usable sleep sessions found in ' . $this->inputFile);
        }
    }

    /**
     * Converts a 'Y-m-d H:i:s' string to a linear wall-second count
     * 
     * @param string $value Date/time string from the CSV
     * 
     * @return int|null Wall-seconds, or null if the string is not a date/time
     */
    private function parseWallSeconds(string $value): ?int
    {
        $value = trim($value);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/', $value, $parts)) {
            return null;
        }

        $days = $this->daysFromCivil((int) $parts[1], (int) $parts[2], (int) $parts[3]);

        return $days * self::SECONDS_PER_DAY
            + (int) $parts[4] * self::SECONDS_PER_HOUR
            + (int) $parts[5] * 60
            + (int) $parts[6];
    }

    /**
     * Days since 1970-01-01 for a civil date (Howard Hinnant's algorithm)
     * 
     * @param int $y Year
     * @param int $m Month, 1-12
     * @param int $d Day of month
     * 
     * @return int
     */
    private function daysFromCivil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /**
     * Civil date for a day count since 1970-01-01 (the inverse of the above)
     * 
     * @param int $z Days since 1970-01-01
     * 
     * @return array [year, month, day]
     */
    private function civilFromDays(int $z): array
    {
        $z += 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp + ($mp < 10 ? 3 : -9);
        $y += $m <= 2 ? 1 : 0;

        return [$y, $m, $d];
    }

    /**
     * Day number containing a wall-second count
     * 
     * @param int $seconds Wall-seconds
     * 
     * @return int Days since 1970-01-01
     */
    private function dayOf(int $seconds): int
    {
        return (int) floor($seconds / self::SECONDS_PER_DAY);
    }

    /**
     * Hour of day, 0-23, for a wall-second count
     * 
     * @param int $seconds Wall-seconds
     * 
     * @return int
     */
    private function hourOfDay(int $seconds): int
    {
        return intdiv($seconds - $this->dayOf($seconds) * self::SECONDS_PER_DAY, self::SECONDS_PER_HOUR);
    }

    /**
     * Determines the start and end dates of the sleep data range
     * 
     * @return void
     */
    private function findDateRange(): void
    {
        $first = $this->sessions[0];
        $last = $this->sessions[count($this->sessions) - 1];

        $this->setStartDate($first['start']);
        $this->setEndDate($last['end']);
    }

    /**
     * Sets the start date based on the first data entry
     * 
     * The chart row runs 18:00 to 17:00, so the range starts at 18:00 on the
     * first session's date, backed up a day if that session started at or
     * before 17:00 - it belongs to the previous night's row.
     * 
     * @param int $firstStart First session's start, in wall-seconds
     * 
     * @return void
     */
    private function setStartDate(int $firstStart): void
    {
        $this->startDate = $this->dayOf($firstStart) * self::SECONDS_PER_DAY + 18 * self::SECONDS_PER_HOUR;

        if ($this->hourOfDay($firstStart) <= 17) {
            $this->startDate -= self::SECONDS_PER_DAY;
        }
    }

    /**
     * Sets the end date based on the last data entry
     * 
     * @param int $lastEnd Last session's end, in wall-seconds
     * 
     * @return void
     */
    private function setEndDate(int $lastEnd): void
    {
        $this->endDate = $this->dayOf($lastEnd) * self::SECONDS_PER_DAY + 17 * self::SECONDS_PER_HOUR;

        if ($this->hourOfDay($lastEnd) >= 18) {
            $this->endDate += self::SECONDS_PER_DAY;
        }
    }

    /**
     * Processes all sleep data entries
     * 
     * @return void
     */
    private function processSleepData(): void
    {
        foreach ($this->sessions as $session) {
            $this->processSession($session['start'], $session['end']);
            $this->recordDrift($session);
        }

        $this->clampSleepHours();
    }

    /**
     * Walks one session hour by hour, accumulating the fraction of each hour slept
     * 
     * @param int $startTime Start of sleep, in wall-seconds
     * @param int $endTime   End of sleep, in wall-seconds
     * 
     * @return void
     */
    private function processSession(int $startTime, int $endTime): void
    {
        // Round down to the start of the hour
        $currentHour = intdiv($startTime, self::SECONDS_PER_HOUR) * self::SECONDS_PER_HOUR;

        while ($currentHour < $endTime) {
            $hourEnd = $currentHour + self::SECONDS_PER_HOUR;

            $sleepStart = max($currentHour, $startTime);
            $sleepEnd = min($hourEnd, $endTime);

            $sleepMinutes = ($sleepEnd - $sleepStart) / 60;
            $sleepFraction = $sleepMinutes / 60;

            $hourIndex = intdiv($currentHour, self::SECONDS_PER_HOUR);
            $this->sleepHours[$hourIndex] = ($this->sleepHours[$hourIndex] ?? 0) + $sleepFraction;

            $currentHour = $hourEnd;
        }
    }

    /**
     * Records how much longer a session really lasted than its timestamps say
     * 
     * On a daylight saving night the Start and End strings are both true local
     * wall-clock times, but the real elapsed time between them is an hour more
     * or an hour less than subtracting one from the other suggests. Sleep
     * Cycle's own 'Time in bed (seconds)' carries the true duration, so the
     * difference between the two is the hour that daylight saving added or
     * removed. On files/sleepdata.csv this is non-zero for exactly 4 of 1002
     * sessions, always by exactly one hour, and always on a transition night.
     * 
     * The 24 cells stay as they are - the chart is a wall-clock grid and only
     * has 24 columns - so the drift goes into the row total instead. The row is
     * the one containing the session's *start*.
     * 
     * That attribution is wrong only for a session that both has drift and
     * straddles the 17:00/18:00 row boundary, which needs a 10+ hour sleep
     * running from afternoon into the next morning on one of two nights a year.
     * Fixing it properly would mean knowing when the transition occurred, i.e.
     * a timezone database - the exact dependency this project does without - so
     * it is disclosed on the page rather than coded around.
     * 
     * @param array $session Parsed session
     * 
     * @return void
     */
    private function recordDrift(array $session): void
    {
        if ($session['timeInBed'] === null) {
            return;
        }

        $drift = $session['timeInBed'] - ($session['end'] - $session['start']);

        if ($drift == 0) {
            return;
        }

        $row = intdiv($session['start'] - $this->startDate, self::SECONDS_PER_DAY);
        $this->rowDrift[$row] = ($this->rowDrift[$row] ?? 0) + $drift / self::SECONDS_PER_HOUR;
    }

    /**
     * Clamps any accumulated hour above 1.00 back down to 1.00
     * 
     * A single session can contribute at most 1.00 to any one clock hour, so a
     * sum above 1.00 means two sessions claim the same minutes. Exact duplicate
     * rows are already dropped in readCsvData(); this is a guard for genuinely
     * overlapping sessions, which no export seen so far contains. It must never
     * fire on clean data, so it warns when it does.
     * 
     * Values at or below 1.00 are left exactly as they are. No remainder is
     * carried into the following hour: those minutes are already accounted for
     * by whichever session actually covers them, so carrying would double-count.
     * 
     * @return void
     */
    private function clampSleepHours(): void
    {
        foreach ($this->sleepHours as $key => $amount) {
            if ($amount > 1.0) {
                trigger_error(
                    sprintf('Overlapping sessions: hour index %d accumulated %.4f, clamped to 1.00', $key, $amount),
                    E_USER_WARNING
                );
                $this->sleepHours[$key] = 1.0;
            }
        }
    }

    /**
     * Generates the final chart output
     * 
     * @return void
     */
    private function generateChart(): void
    {
        ob_start();

        $row = 0;
        for ($rowStart = $this->startDate; $rowStart < $this->endDate; $rowStart += self::SECONDS_PER_DAY) {
            $this->outputDateRange($rowStart);
            $this->outputSleepHours($rowStart, $row);
            echo PHP_EOL;
            $row++;
        }

        $chart = ob_get_clean();
        file_put_contents($this->outputFile, $chart);
    }

    /**
     * Outputs the date range for a row in the chart
     * 
     * @param int $rowStart Start of the row, in wall-seconds (an 18:00)
     * 
     * @return void
     */
    private function outputDateRange(int $rowStart): void
    {
        $today = $this->dayOf($rowStart);

        echo $this->formatDate($today) . ' → ' . $this->formatDate($today + 1) . "\t";
    }

    /**
     * Formats a day number as MM/DD/YY
     * 
     * @param int $day Days since 1970-01-01
     * 
     * @return string
     */
    private function formatDate(int $day): string
    {
        [$year, $month, $dayOfMonth] = $this->civilFromDays($day);

        return sprintf('%02d/%02d/%02d', $month, $dayOfMonth, $year % 100);
    }

    /**
     * Outputs the 24 hourly cells for a row, then the row total
     * 
     * The total is the sum of the cells plus any daylight saving drift for
     * sessions starting in this row, so on a transition night it reports real
     * sleep time even though the columns cannot.
     * 
     * Whether a total is printed at all is decided by the cell sum alone, never
     * by cell sum plus drift. A drift-only row is impossible - a session that
     * starts in a row always fills at least one cell - while a spring-forward
     * row carrying an hour of negative drift could in principle sum to zero,
     * and printing 24 filled cells followed by no total reads as a bug.
     * 
     * @param int $rowStart Start of the row, in wall-seconds (an 18:00)
     * @param int $row      Row index, from 0
     * 
     * @return void
     */
    private function outputSleepHours(int $rowStart, int $row): void
    {
        $cellHours = 0;
        $firstHour = intdiv($rowStart, self::SECONDS_PER_HOUR);

        for ($i = 1; $i <= 24; $i++) {
            $amount = $this->sleepHours[$firstHour + $i - 1] ?? 0;
            $cellHours += $amount;

            echo $amount ? number_format($amount, 2) : '';
            echo "\t";
        }

        if ($cellHours > 0) {
            $totalHours = max(0, $cellHours + ($this->rowDrift[$row] ?? 0));
            $totalMinutes = round($totalHours * 60);
            $hours = floor($totalMinutes / 60);
            $minutes = $totalMinutes % 60;
            echo sprintf("%02d:%02d", $hours, $minutes);
        }
    }
}
