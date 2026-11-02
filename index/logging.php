<?php

use Pecee\SimpleRouter\SimpleRouter as Router;
use Core\Logging;

if (!isset(Secrets::$logging_password_hash)) {
    http_response_code(500);
    die("Define logging password in secrets first.");
}

if (!isset($_SERVER["PHP_AUTH_USER"])) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="Logging"');

    die("This site is protected");
}

if (!password_verify($_SERVER["PHP_AUTH_USER"] . ":" . $_SERVER["PHP_AUTH_PW"], \Secrets::$logging_password_hash)) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="Logging"');
    die("Invalid credentials");
};

// log entries contain attacker-controlled data (request paths, messages),
// so everything printed below must be escaped; CSP is a backstop
function escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");

echo "<!DOCTYPE><html><head><link rel='stylesheet' href='" . \Manifest::$assets_url . "/exceptions.css' /></head><body>";

Router::get("/", function () {
    echo "<h1>Log viewer</h1>";
    echo "<p>Show logs for date: <code>" . escape($_SERVER["REQUEST_URI"] . "/" . Logging::$file_format) . "</code></p>";
    echo "<p>Show logs for <a href='" . escape(date(Logging::$file_format)) . "'>today</a></p>";
});

Router::get("/{date}", function ($date) {
    $parsed_date = preg_match("/^\d{4}-\d{2}-\d{2}$/", $date) ? date_create_from_format(Logging::$file_format, $date) : false;

    if ($parsed_date === false) {
        http_response_code(400);
        return "Invalid date, expected <code>" . escape(Logging::$file_format) . "</code>";
    }

    $filepath = Manifest::$logging_directory . "/" . date_format($parsed_date, Logging::$year_format . "/" . Logging::$month_format . "/" . Logging::$file_format) . ".log";

    try {
        $parsed_log = Logging::parse($filepath);
    } catch (\Throwable $e) {
        return "Could not open log for <code>" . escape($date) . "</code>";
    }

    echo "<p>Showing results for <code>" . escape($filepath) . "</code></p>";

    foreach ($parsed_log as ["clubname" => $path, "data" => $data, "ip" => $ip, "level" => $level, "timestamp" => $timestamp]) {
        $parsed_data = json_decode($data, true);

        if (!$parsed_data) {
            echo "<details><summary>" . escape($level) . " - " . escape($timestamp) . "<span>" . escape($path) . "</span></summary><pre>";
            echo escape($data);
        } else {
            $marker = $parsed_data["marker"] ?? "";
            $message = $parsed_data["message"] ?? "";
            $log_message = $parsed_data["log_message"] ?? "";
            $status_code = $parsed_data["code"] ?? "";
            $line = $parsed_data["line"] ?? null;
            $file = $parsed_data["file"] ?? null;
            $trace = $parsed_data["trace"] ?? [];

            echo "<details><summary>" . escape($level) . " - " . escape($timestamp) . ": <em>" . escape($message) . "</em> - <span>" . escape($path) . "</span></summary>";
            echo "<table style='margin: 1em;'><tbody>";
            echo "<tr><td>User Message</td><td><em>" . escape($message) . "</em></td></tr>";
            echo "<tr><td>Log Message</td><td><b>" . escape($log_message) . "</b></td></tr>";
            echo "<tr><td>Level</td><td>" . escape($level) . "</td></tr>";
            echo "<tr><td>Timestamp</td><td>" . escape($timestamp) . "</td></tr>";
            echo "<tr><td>Status code</td><td>" . escape($status_code) . "</td></tr>";
            echo "<tr><td>IP</td><td>" . escape($ip) . "</td></tr>";
            echo "<tr><td>Requested path</td><td><code>" . escape($path) . "</code></td></tr>";
            echo "<tr><td>Marker</td><td><code>" . escape($marker) . "</code></td></tr>";
            echo "<tr><td>Line</td><td><code>" . escape($line) . "</code></td></tr>";
            echo "<tr><td>File</td><td><code>" . escape($file) . "</code></td></tr>";
            echo "</tbody></table>";

            if (count($trace) == 0) {
                echo "<em>No stacktrace recorded</em>";
            } else {
                echo "<pre>";
                foreach ($trace as $stack) {
                    $stack_file = $stack["file"] ?? $file;
                    $stack_line = $stack["line"] ?? $line;

                    echo "<p>" . escape(dirname($stack_file)) . "/<b>" . escape(basename($stack_file)) . "</b>:" . escape($stack_line) . "</p>";
                    if (isset($stack["class"]) && isset($stack["type"])) {
                        echo "<b>" . escape($stack["class"] . $stack["type"]) . "</b>";
                    }

                    echo "<em>" . escape($stack["function"] ?? "") . "</em>(";
                    echo escape(json_encode($stack["args"] ?? [], JSON_PRETTY_PRINT));
                    echo ")\n";
                }
                echo "</pre>";
            }
        }

        echo "</pre></details>";
    }
});
