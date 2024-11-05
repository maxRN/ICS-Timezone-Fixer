<?php
// Define constants
define('MAX_FILE_SIZE', 819200); // 800 kB
define('MISSING_TIMEZONES_FILE', __DIR__ . '/missing_timezones');

// Main execution
try {
    $icsUrl = getIcsUrl();
    validateUrl($icsUrl);
    validateFileContent($icsUrl);
    $icsContent = fetchIcsContent($icsUrl, MAX_FILE_SIZE);
    $missingTimezones = readMissingTimezones(MISSING_TIMEZONES_FILE);
    $modifiedIcsContent = insertMissingTimezones($icsContent, $missingTimezones);
    outputIcsContent($modifiedIcsContent);
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

// Function to get the ICS URL from the query parameter
function getIcsUrl() {
    if (!isset($_GET['ics_url']) || empty($_GET['ics_url'])) {
        throw new Exception('No ICS URL provided.');
    }
    return $_GET['ics_url'];
}

// Function to validate the provided URL and enforce HTTPS
function validateUrl($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception('Invalid URL.');
    }

    // Enforce HTTPS
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (strtolower($scheme) !== 'https') {
        throw new Exception('Only HTTPS URLs are allowed.');
    }
}

// Function to validate the file content by downloading a small portion
function validateFileContent($url) {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new Exception('Failed to initialize cURL for partial content download.');
    }

    $partialContent = '';
    $maxBytes = 1024; // Read first 1 KB

    $writeFunction = function($ch, $data) use (&$partialContent, $maxBytes) {
        $length = strlen($data);
        $partialContent .= $data;
        if (strlen($partialContent) >= $maxBytes) {
            return -1; // Stop reading
        }
        return $length;
    };

    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeFunction);
    curl_setopt($ch, CURLOPT_RANGE, '0-' . ($maxBytes - 1));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    // Execute cURL request
    $result = curl_exec($ch);

    if ($result === false && curl_errno($ch) !== CURLE_WRITE_ERROR) {
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        throw new Exception("Failed to read file content. HTTP Code: $httpCode. cURL error: $error");
    }

    curl_close($ch);

    // Check if the content contains 'BEGIN:VCALENDAR'
    if (strpos($partialContent, 'BEGIN:VCALENDAR') === false) {
        throw new Exception('The file does not appear to be a valid ICS file (BEGIN:VCALENDAR not found).');
    }
}

// Function to fetch the ICS content with a size limit
function fetchIcsContent($url, $maxFileSize) {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new Exception('Failed to initialize cURL.');
    }

    $icsContent = '';
    $totalDownloaded = 0;

    // Define the write function callback
    $writeFunction = function($ch, $data) use (&$icsContent, &$totalDownloaded, $maxFileSize) {
        $length = strlen($data);
        $newTotal = $totalDownloaded + $length;

        if ($newTotal > $maxFileSize) {
            return -1; // Stop reading if limit is exceeded
        } else {
            $icsContent .= $data;
            $totalDownloaded = $newTotal;
            return $length;
        }
    };

    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeFunction);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 seconds to connect
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);        // 30 seconds max execution time

    // Execute cURL request
    $result = curl_exec($ch);

    if ($result === false) {
        if (curl_errno($ch) == CURLE_WRITE_ERROR && $totalDownloaded > $maxFileSize) {
            curl_close($ch);
            throw new Exception('The ICS file exceeds the maximum allowed size of 800 kB.');
        } else {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('Unable to fetch the ICS file. cURL error: ' . $error);
        }
    }

    curl_close($ch);

    return $icsContent;
}

// Function to read the missing timezones from the side file
function readMissingTimezones($filename) {
    if (!file_exists($filename)) {
        throw new Exception('Missing timezones file not found.');
    }

    $content = file_get_contents($filename);
    if ($content === false) {
        throw new Exception('Unable to read the missing timezones file.');
    }

    return $content;
}

// Function to insert missing timezones into the ICS content
function insertMissingTimezones($icsContent, $missingTimezones) {
    $pos = strpos($icsContent, 'BEGIN:VEVENT');
    if ($pos === false) {
        throw new Exception('Invalid ICS file format.');
    }

    $modifiedIcsContent = substr($icsContent, 0, $pos) . $missingTimezones . "\n" . substr($icsContent, $pos);

    return $modifiedIcsContent;
}

// Function to output the modified ICS content with appropriate headers
function outputIcsContent($modifiedIcsContent) {
    // Now that everything is validated and modified, set the content type headers
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="modified_calendar.ics"');

    echo $modifiedIcsContent;
}
?>