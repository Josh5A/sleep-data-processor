/**
 * Sleep Data Processor
 *
 * Turns a Sleep Cycle CSV export into the tab-delimited text that the Google
 * Sheets chart template expects. Text in, text out: no DOM, no file reading, no
 * network. The page handles all of that; this file is a transcription of
 * src/SleepDataProcessor.php and is verified against it by byte diff.
 *
 * Time is a linear count of wall-clock seconds, never a Date: a timestamp is
 * daysFromCivil(Y, M, D) * 86400 + H * 3600 + M * 60 + S, and every day is
 * exactly 24 hours long, which for a wall-clock chart it is. That keeps output
 * identical whatever timezone the visitor's browser is in, and makes a
 * wall-clock time that never existed (2026-03-09 02:51:57, in the author's own
 * data) unambiguous.
 *
 * @license GNU General Public License v3
 * @link    https://github.com/Josh5A/sleep-data-processor
 */

const SECONDS_PER_DAY = 86400;
const SECONDS_PER_HOUR = 3600;
const TIME_IN_BED_HEADER = 'Time in bed (seconds)';
const DELIMITERS = [';', ',', '\t'];
const NOT_SLEEP_CYCLE = "This file couldn't be processed. It doesn't look like a Sleep Cycle export.";

/**
 * Processes a Sleep Cycle CSV export into chart text.
 *
 * @param {string} csvText Contents of the CSV file
 * @returns {string} Tab-delimited chart text, one row per night, trailing newline included
 * @throws {Error} If the text is not a readable Sleep Cycle export
 */
export function processSleepData(csvText) {
    const sessions = readCsvData(csvText);
    const startDate = findStartDate(sessions[0].start);
    const endDate = findEndDate(sessions[sessions.length - 1].end);
    const { sleepHours, rowDrift } = accumulate(sessions, startDate);

    return generateChart(sleepHours, rowDrift, startDate, endDate);
}

/**
 * Reads and parses the CSV into a list of sessions, in file order.
 *
 * Only Start and End are read positionally, as the PHP does; every other column
 * is ignored except 'Time in bed (seconds)', which is looked up by header name
 * because column order could vary between Sleep Cycle versions.
 *
 * Exact duplicate rows - the same Start *and* the same End string - are dropped.
 * Sleep Cycle exports occasionally contain a session twice, and left in, the
 * duplicate would double every hour it touches once the hour map accumulates.
 * Raw strings are compared, deliberately without normalising first.
 *
 * @param {string} csvText Contents of the CSV file
 * @returns {Array<{start: number, end: number, timeInBed: number|null}>}
 * @throws {Error} If there is no recognisable header, or no session parses
 */
function readCsvData(csvText) {
    if (typeof csvText !== 'string') {
        throw new Error(NOT_SLEEP_CYCLE);
    }

    // A BOM would otherwise make the first header name '\uFEFFStart'
    const lines = csvText.replace(/^\uFEFF/, '').split('\n').map(stripCarriageReturn);
    const headerLine = lines.shift();

    if (headerLine === undefined) {
        throw new Error(NOT_SLEEP_CYCLE);
    }

    const delimiter = sniffDelimiter(headerLine);
    const header = headerLine.split(delimiter).map((name) => name.trim());

    if (!header.includes('Start') || !header.includes('End')) {
        throw new Error(NOT_SLEEP_CYCLE);
    }

    const timeInBedColumn = header.indexOf(TIME_IN_BED_HEADER);

    const sessions = [];
    const seen = new Set();
    let firstDataRow = true;

    for (const line of lines) {
        if (line.trim() === '') {
            continue;
        }

        const data = line.split(delimiter);
        const key = `${data[0] ?? ''}\u0000${data[1] ?? ''}`;

        if (seen.has(key)) {
            continue;
        }
        seen.add(key);

        const start = parseWallSeconds(data[0] ?? '');
        const end = parseWallSeconds(data[1] ?? '');

        if (start === null || end === null) {
            // If the very first data row will not parse, this is not an export
            // we understand, and guessing at what was meant helps nobody.
            if (firstDataRow) {
                throw new Error(NOT_SLEEP_CYCLE);
            }
            firstDataRow = false;
            continue;
        }
        firstDataRow = false;

        let timeInBed = null;
        if (timeInBedColumn !== -1 && data[timeInBedColumn] !== undefined && data[timeInBedColumn].trim() !== '') {
            const parsed = Number(data[timeInBedColumn].trim());
            timeInBed = Number.isFinite(parsed) ? parsed : null;
        }

        sessions.push({ start, end, timeInBed });
    }

    if (sessions.length === 0) {
        throw new Error(NOT_SLEEP_CYCLE);
    }

    return sessions;
}

/**
 * Removes a trailing carriage return, so CRLF files behave like LF ones.
 *
 * @param {string} line
 * @returns {string}
 */
function stripCarriageReturn(line) {
    return line.endsWith('\r') ? line.slice(0, -1) : line;
}

/**
 * Picks the delimiter from the header line: whichever of ; , or tab appears most.
 *
 * Sleep Cycle's export format varies by locale, so the semicolon the PHP
 * hardcodes cannot be assumed for a file someone else exported.
 *
 * @param {string} headerLine
 * @returns {string}
 */
function sniffDelimiter(headerLine) {
    let best = DELIMITERS[0];
    let bestCount = 0;

    for (const candidate of DELIMITERS) {
        const count = headerLine.split(candidate).length - 1;
        if (count > bestCount) {
            best = candidate;
            bestCount = count;
        }
    }

    return best;
}

/**
 * Converts a 'YYYY-MM-DD HH:MM:SS' string to a linear wall-second count.
 *
 * @param {string} value Date/time string from the CSV
 * @returns {number|null} Wall-seconds, or null if the string is not a date/time
 */
function parseWallSeconds(value) {
    const parts = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/.exec(value.trim());

    if (parts === null) {
        return null;
    }

    const days = daysFromCivil(Number(parts[1]), Number(parts[2]), Number(parts[3]));

    return days * SECONDS_PER_DAY
        + Number(parts[4]) * SECONDS_PER_HOUR
        + Number(parts[5]) * 60
        + Number(parts[6]);
}

/**
 * Days since 1970-01-01 for a civil date (Howard Hinnant's algorithm).
 *
 * @param {number} y Year
 * @param {number} m Month, 1-12
 * @param {number} d Day of month
 * @returns {number}
 */
function daysFromCivil(y, m, d) {
    y -= m <= 2 ? 1 : 0;
    const era = Math.floor((y >= 0 ? y : y - 399) / 400);
    const yoe = y - era * 400;
    const doy = Math.floor((153 * (m + (m > 2 ? -3 : 9)) + 2) / 5) + d - 1;
    const doe = yoe * 365 + Math.floor(yoe / 4) - Math.floor(yoe / 100) + doy;

    return era * 146097 + doe - 719468;
}

/**
 * Civil date for a day count since 1970-01-01 (the inverse of the above).
 *
 * @param {number} z Days since 1970-01-01
 * @returns {[number, number, number]} [year, month, day]
 */
function civilFromDays(z) {
    z += 719468;
    const era = Math.floor((z >= 0 ? z : z - 146096) / 146097);
    const doe = z - era * 146097;
    const yoe = Math.floor((doe - Math.floor(doe / 1460) + Math.floor(doe / 36524) - Math.floor(doe / 146096)) / 365);
    let y = yoe + era * 400;
    const doy = doe - (365 * yoe + Math.floor(yoe / 4) - Math.floor(yoe / 100));
    const mp = Math.floor((5 * doy + 2) / 153);
    const d = doy - Math.floor((153 * mp + 2) / 5) + 1;
    const m = mp + (mp < 10 ? 3 : -9);
    y += m <= 2 ? 1 : 0;

    return [y, m, d];
}

/**
 * Day number containing a wall-second count.
 *
 * @param {number} seconds
 * @returns {number} Days since 1970-01-01
 */
function dayOf(seconds) {
    return Math.floor(seconds / SECONDS_PER_DAY);
}

/**
 * Hour of day, 0-23, for a wall-second count.
 *
 * @param {number} seconds
 * @returns {number}
 */
function hourOfDay(seconds) {
    return Math.floor((seconds - dayOf(seconds) * SECONDS_PER_DAY) / SECONDS_PER_HOUR);
}

/**
 * Start of the chart range: 18:00 on the first session's date, backed up a day
 * if that session started at or before 17:00, since it belongs to the previous
 * night's row.
 *
 * @param {number} firstStart First session's start, in wall-seconds
 * @returns {number} Wall-seconds
 */
function findStartDate(firstStart) {
    const startDate = dayOf(firstStart) * SECONDS_PER_DAY + 18 * SECONDS_PER_HOUR;

    return hourOfDay(firstStart) <= 17 ? startDate - SECONDS_PER_DAY : startDate;
}

/**
 * End of the chart range: 17:00 on the last session's end date, pushed forward a
 * day if that session ended at or after 18:00.
 *
 * @param {number} lastEnd Last session's end, in wall-seconds
 * @returns {number} Wall-seconds
 */
function findEndDate(lastEnd) {
    const endDate = dayOf(lastEnd) * SECONDS_PER_DAY + 17 * SECONDS_PER_HOUR;

    return hourOfDay(lastEnd) >= 18 ? endDate + SECONDS_PER_DAY : endDate;
}

/**
 * Walks every session hour by hour, building the hour map and the row drift.
 *
 * @param {Array<{start: number, end: number, timeInBed: number|null}>} sessions
 * @param {number} startDate Start of the chart range, in wall-seconds
 * @returns {{sleepHours: Map<number, number>, rowDrift: Map<number, number>}}
 */
function accumulate(sessions, startDate) {
    const sleepHours = new Map();
    const rowDrift = new Map();

    for (const session of sessions) {
        processSession(sleepHours, session.start, session.end);
        recordDrift(rowDrift, session, startDate);
    }

    clampSleepHours(sleepHours);

    return { sleepHours, rowDrift };
}

/**
 * Walks one session hour by hour, accumulating the fraction of each hour slept.
 *
 * @param {Map<number, number>} sleepHours Hour map, keyed by hour index
 * @param {number} startTime Start of sleep, in wall-seconds
 * @param {number} endTime End of sleep, in wall-seconds
 * @returns {void}
 */
function processSession(sleepHours, startTime, endTime) {
    // Round down to the start of the hour
    let currentHour = Math.floor(startTime / SECONDS_PER_HOUR) * SECONDS_PER_HOUR;

    while (currentHour < endTime) {
        const hourEnd = currentHour + SECONDS_PER_HOUR;

        const sleepStart = Math.max(currentHour, startTime);
        const sleepEnd = Math.min(hourEnd, endTime);

        // Divided in two steps, as the PHP does: (s / 60) / 60 and s / 3600 are
        // not always the same double.
        const sleepMinutes = (sleepEnd - sleepStart) / 60;
        const sleepFraction = sleepMinutes / 60;

        const hourIndex = Math.floor(currentHour / SECONDS_PER_HOUR);
        sleepHours.set(hourIndex, (sleepHours.get(hourIndex) ?? 0) + sleepFraction);

        currentHour = hourEnd;
    }
}

/**
 * Records how much longer a session really lasted than its timestamps say.
 *
 * On a daylight saving night the Start and End strings are both true local
 * wall-clock times, but the real elapsed time between them is an hour more or an
 * hour less than subtracting one from the other suggests. Sleep Cycle's own
 * 'Time in bed (seconds)' carries the true duration, so the difference between
 * the two is the hour that daylight saving added or removed.
 *
 * The 24 cells stay as they are - the chart is a wall-clock grid and only has 24
 * columns - so the drift goes into the row total instead, on the row containing
 * the session's start.
 *
 * @param {Map<number, number>} rowDrift Drift map, keyed by row index
 * @param {{start: number, end: number, timeInBed: number|null}} session
 * @param {number} startDate Start of the chart range, in wall-seconds
 * @returns {void}
 */
function recordDrift(rowDrift, session, startDate) {
    if (session.timeInBed === null) {
        return;
    }

    const drift = session.timeInBed - (session.end - session.start);

    if (drift === 0) {
        return;
    }

    const row = Math.floor((session.start - startDate) / SECONDS_PER_DAY);
    rowDrift.set(row, (rowDrift.get(row) ?? 0) + drift / SECONDS_PER_HOUR);
}

/**
 * Clamps any accumulated hour above 1.00 back down to 1.00.
 *
 * A single session can contribute at most 1.00 to any one clock hour, so a sum
 * above 1.00 means two sessions claim the same minutes. Exact duplicate rows are
 * already dropped; this is a guard for genuinely overlapping sessions, which no
 * export seen so far contains.
 *
 * Values at or below 1.00 are left exactly as they are. No remainder is carried
 * into the following hour: those minutes are already accounted for by whichever
 * session actually covers them, so carrying would double-count.
 *
 * @param {Map<number, number>} sleepHours
 * @returns {void}
 */
function clampSleepHours(sleepHours) {
    for (const [key, amount] of sleepHours) {
        if (amount > 1.0) {
            sleepHours.set(key, 1.0);
        }
    }
}

/**
 * Builds the chart text, one row per night, 18:00 to 17:00.
 *
 * @param {Map<number, number>} sleepHours
 * @param {Map<number, number>} rowDrift
 * @param {number} startDate Wall-seconds
 * @param {number} endDate Wall-seconds
 * @returns {string}
 */
function generateChart(sleepHours, rowDrift, startDate, endDate) {
    let chart = '';
    let row = 0;

    for (let rowStart = startDate; rowStart < endDate; rowStart += SECONDS_PER_DAY) {
        chart += outputDateRange(rowStart);
        chart += outputSleepHours(sleepHours, rowDrift, rowStart, row);
        chart += '\n';
        row++;
    }

    return chart;
}

/**
 * The date range label for a row: 'MM/DD/YY -> MM/DD/YY' with a literal U+2192,
 * followed by one tab.
 *
 * @param {number} rowStart Start of the row, in wall-seconds (an 18:00)
 * @returns {string}
 */
function outputDateRange(rowStart) {
    const today = dayOf(rowStart);

    return `${formatDate(today)} → ${formatDate(today + 1)}\t`;
}

/**
 * Formats a day number as MM/DD/YY, zero-padded.
 *
 * @param {number} day Days since 1970-01-01
 * @returns {string}
 */
function formatDate(day) {
    const [year, month, dayOfMonth] = civilFromDays(day);

    return `${pad(month)}/${pad(dayOfMonth)}/${pad(year % 100)}`;
}

/**
 * The 24 hourly cells for a row, each followed by a tab, then the row total.
 *
 * The total is the sum of the cells plus any daylight saving drift for sessions
 * starting in this row, so on a transition night it reports real sleep time even
 * though the columns cannot.
 *
 * Whether a total is printed at all is decided by the cell sum alone, never by
 * cell sum plus drift. A drift-only row is impossible - a session that starts in
 * a row always fills at least one cell - while a spring-forward row carrying an
 * hour of negative drift could in principle sum to zero, and printing 24 filled
 * cells followed by no total reads as a bug.
 *
 * @param {Map<number, number>} sleepHours
 * @param {Map<number, number>} rowDrift
 * @param {number} rowStart Start of the row, in wall-seconds (an 18:00)
 * @param {number} row Row index, from 0
 * @returns {string}
 */
function outputSleepHours(sleepHours, rowDrift, rowStart, row) {
    let output = '';
    let cellHours = 0;
    const firstHour = Math.floor(rowStart / SECONDS_PER_HOUR);

    for (let i = 1; i <= 24; i++) {
        const amount = sleepHours.get(firstHour + i - 1) ?? 0;
        cellHours += amount;

        // Tests the raw value, not the rounded string: a five-second sleep hour
        // prints 0.00, while a truly untouched hour prints nothing.
        output += amount ? formatTwo(amount) : '';
        output += '\t';
    }

    if (cellHours > 0) {
        const totalHours = Math.max(0, cellHours + (rowDrift.get(row) ?? 0));
        const totalMinutes = Math.round(totalHours * 60);
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        output += `${pad(hours)}:${pad(minutes)}`;
    }

    return output;
}

/**
 * Formats a number to two decimal places the way PHP's number_format() does.
 *
 * Three things have to line up for the output to match byte for byte, and cell
 * values here are seconds / 60 / 60, so exact .xx5 ties turn up in real data -
 * a tie needs the seconds to be an odd multiple of 18.
 *
 * 1. Rounding is half away from zero, so 0.105 must give 0.11.
 * 2. PHP decides that on the double's *shortest decimal representation*, not on
 *    its true binary value. The double nearest 0.105 is really
 *    0.10499999999999999611, and PHP still rounds it up. JS toString() produces
 *    that same shortest representation, so reading the digits off it and
 *    rounding there agrees with PHP on every value in the author's data.
 * 3. toFixed() would get both wrong: (0.105).toFixed(2) is '0.10', because it
 *    rounds the binary value, which is fractionally below the tie.
 *
 * @param {number} value
 * @returns {string}
 */
function formatTwo(value) {
    const sign = value < 0 ? '-' : '';
    const magnitude = Math.abs(value);

    // Exponent form only shows up far below a hundredth, where the answer is 0.00
    const text = magnitude >= 1e-6 ? magnitude.toString() : '0';
    const [whole, fraction = ''] = text.split('.');
    const digits = fraction.padEnd(3, '0');

    let cents = Number(whole) * 100 + Number(digits.slice(0, 2));

    if (digits.charCodeAt(2) >= 53) {
        cents++;
    }

    return `${sign}${Math.floor(cents / 100)}.${pad(cents % 100)}`;
}

/**
 * Zero-pads a number to two digits.
 *
 * @param {number} value
 * @returns {string}
 */
function pad(value) {
    return String(value).padStart(2, '0');
}
