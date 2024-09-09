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
 */
class SleepDataProcessor
{
    /*
    private const INPUT_FILE = 'sleepdata.csv';
    public const OUTPUT_FILE = 'sleep_chart_output2.tdv';
    private const CSV_DELIMITER = ';';
    private const DATE_FORMAT = 'Y-m-d H:i:s';
    */

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
     * @var string
     */
    private $dateFormat = 'Y-m-d H:i:s';

    /**
     * Array to store processed sleep hours
     * 
     * @var array
     */
    private $sleepHours = [];

    /**
     * Start date of the sleep data range
     * 
     * @var DateTime
     */
    private $startDate;

    /**
     * End date of the sleep data range
     * 
     * @var DateTime
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
        string $inputFile = null,
        string $outputFile = null,
        string $csvDelimiter = null,
        string $dateFormat = null
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
     * Reads CSV data from the input file
     * 
     * @return array Array of CSV lines, with header removed
     */
    private function readCsvData(): array
    {
        $file = file($this->inputFile);
        array_shift($file); // Remove header
        return $file;
    }

    /**
     * Determines the start and end dates of the sleep data range
     * 
     * @return void
     */
    private function findDateRange(): void
    {
        $file = $this->readCsvData();
        $firstLine = explode($this->csvDelimiter, $file[0]);
        $lastLine = explode($this->csvDelimiter, $file[array_key_last($file)]);

        $this->setStartDate($firstLine[0]);
        $this->setEndDate($lastLine[1]);
    }

    /**
     * Sets the start date based on the first data entry
     * 
     * @param string $firstDateTime First date/time from CSV
     * 
     * @return void
     */
    private function setStartDate(string $firstDateTime): void
    {
        $firstDateTime = DateTime::createFromFormat($this->dateFormat, $firstDateTime);
        $this->startDate = DateTime::createFromFormat('m/d/y H:i', $firstDateTime->format('m/d/y') . ' 18:00');

        if ($firstDateTime->format('H') <= 17) {
            $this->startDate->modify('-1 day');
        }
    }

    /**
     * Sets the end date based on the last data entry
     * 
     * @param string $lastDateTime Last date/time from CSV
     * 
     * @return void
     */
    private function setEndDate(string $lastDateTime): void
    {
        $lastDateTime = DateTime::createFromFormat($this->dateFormat, $lastDateTime);
        $this->endDate = DateTime::createFromFormat('m/d/y H:i', $lastDateTime->format('m/d/y') . ' 17:00');

        if ($lastDateTime->format('H') >= 18) {
            $this->endDate->modify('+1 day');
        }
    }

    /**
     * Processes all sleep data entries
     * 
     * @return void
     */
    private function processSleepData(): void
    {
        foreach ($this->readCsvData() as $line) {
            $data = explode($this->csvDelimiter, $line);
            $startTime = DateTime::createFromFormat($this->dateFormat, $data[0]);
            $endTime = DateTime::createFromFormat($this->dateFormat, $data[1]);
            
            $interval = date_diff($startTime, $endTime);
            
            // if interval is less than an hour AND the days are the same, then record the percentage of the minutes
            if ($interval->format('%H') == 0 && $startTime->format('d') == $endTime->format('d')) {
                $this->processSingleHourSleep($startTime, $interval);

            // otherwise, go hour by hour
            } else {
                $this->processMultiHourSleep($startTime, $endTime);
            }
        }
    }

    /**
     * Processes a sleep entry that occurs within a single hour
     * 
     * @param DateTime     $startTime Start time of sleep
     * @param DateInterval $interval  Duration of sleep
     * 
     * @return void
     */
    private function processSingleHourSleep(DateTime $startTime, DateInterval $interval): void
    {
        $chartHour = $startTime->format('m/d/y H') . ':00';
        $chartStamp = strtotime($chartHour);
        $minutes = $interval->format('%I');
        $percentage = round($minutes / 60, 2);
        $this->sleepHours[$chartStamp] = $percentage;
    }

    /**
     * Processes a sleep entry that spans multiple hours
     * 
     * @param DateTime $startTime Start time of sleep
     * @param DateTime $endTime   End time of sleep
     * 
     * @return void
     */
    private function processMultiHourSleep(DateTime $startTime, DateTime $endTime): void
    {
        $currentHour = clone $startTime;
        $currentHour->setTime($currentHour->format('H'), 0); // Round down to the start of the hour

        while ($currentHour < $endTime) {
            $hourEnd = clone $currentHour;
            $hourEnd->modify('+1 hour');

            $sleepStart = max($currentHour, $startTime);
            $sleepEnd = min($hourEnd, $endTime);

            $sleepMinutes = ($sleepEnd->getTimestamp() - $sleepStart->getTimestamp()) / 60;
            $sleepFraction = $sleepMinutes / 60;

            $this->sleepHours[$currentHour->getTimestamp()] = $sleepFraction;

            $currentHour = $hourEnd;
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
        $currentHour = clone $this->startDate;

        while ($currentHour < $this->endDate) {
            $this->outputDateRange($currentHour);
            $this->outputSleepHours($currentHour);
            echo PHP_EOL;
        }

        $chart = ob_get_clean();
        file_put_contents($this->outputFile, $chart);
    }

    /**
     * Outputs the date range for a row in the chart
     * 
     * @param DateTime $currentHour Current hour being processed
     * 
     * @return void
     */
    private function outputDateRange(DateTime $currentHour): void
    {
        $tomorrow = (clone $currentHour)->modify('+1 day -1 hour');
        echo $currentHour->format('m/d/y') . ' → ' . $tomorrow->format('m/d/y') . "\t";
    }

    /**
     * Outputs sleep hours for a row in the chart
     * 
     * @param DateTime $currentHour Current hour being processed
     * 
     * @return void
     */

    private function outputSleepHours(DateTime &$currentHour): void
    {
        $totalHours = 0;
        for ($i = 1; $i <= 24; $i++) {
            $timestamp = $currentHour->getTimestamp();
            $amount = $this->sleepHours[$timestamp] ?? 0;
            $totalHours += $amount;
            
            echo $amount ? number_format($amount, 2) : '';
            echo "\t";
            
            $currentHour->modify('+1 hour');
        }

        if ($totalHours > 0) {
            $totalMinutes = round($totalHours * 60);
            $hours = floor($totalMinutes / 60);
            $minutes = $totalMinutes % 60;
            echo sprintf("%02d:%02d", $hours, $minutes);
        }
    }
}


