<?php

require_once(__DIR__.'/../src/SleepDataProcessor.php');

// Set file paths (optional argv overrides, defaults unchanged)
$inputFile = $argv[1] ?? __DIR__.'/../files/sleepdata.csv';
$outputFile = $argv[2] ?? __DIR__.'/../files/sleep_chart_output.tdv';

// Run the processor
$processor = new SleepDataProcessor($inputFile, $outputFile);
$processor->process();

echo "Processing complete. Output saved to " . realpath($outputFile) . PHP_EOL;

/**
 * Now you can copy and paste the contents of your output file into the Google Sheets template
 * 
 * Make a copy from https://docs.google.com/spreadsheets/d/1bae1Rd7Ow1-quu7ddtPsrhsauH6KFFqn4suwRdhfGoM/edit?usp=sharing
 */