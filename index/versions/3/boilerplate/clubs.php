<?php

namespace Api;

require_once __DIR__ . "/clubs.php";
require_once __DIR__ . "/database.php";

use Core\ApiException;

class Clubs {
    // configuration files
    public static $files = [
        "/cfg/_cfg.php",
        "/cfg/_tables.php",
        "/cfg/race_enums.php",
        "/cfg/session_enums.php",
        "/cfg/_uc.php",
        "/cfg/_globals.php",
    ];

    // clubs must not end with slash
    public static function list() {
        $result = [];

        foreach (\Manifest::$available_clubs as $clubname) {
            if ($info = static::info($clubname)) {
                $result[] = $info;
            }
        }

        return $result;
    }

    public static function exists($clubname) {
        return in_array($clubname, \Manifest::$available_clubs);
    }

    public static function info($clubname) {
        if ($clubname === null) {
            return null;
        }

        // do not use include_once to prevent future errors
        // do not use require* to prevent raising error
        if (!@include \Manifest::$path_to_clubs . $clubname . "/cfg/_cfg.php") {
            return null;
        }

        return [
            // clubname is required by the middleware
            "clubname" => $clubname,
            "is_release" => $g_is_release ?? false,
            "fullname" => $g_fullname ?? "",
            "shortcut" => $g_shortcut ?? "",
            "baseadr" => $g_baseadr ?? "",
            "mainwww" => $g_mainwww ?? "",
            "emailadr" => $g_emailadr ?? "",
        ];
    }

    public static function import($clubname) {
        if (!is_dir(\Manifest::$path_to_clubs . $clubname)) {
            throw new ApiException("Club not found. Attempting to join a non-existent club?", 404);
        }

        // do not use include_once to prevent future errors
        // do not use require* to prevent raising error
        foreach (static::$files as $file) {
            $path = \Manifest::$path_to_clubs . $clubname . $file;
            if (!is_file($path)) {
                throw new ApiException("Cannot load configuration file", 500, "Configuration file '" . $file . " does not exist.");
            }

            if (!@(include $path)) {
                throw new ApiException("Cannot load configuration file", 500, "Configuration file '" . $file . "' cannot be imported.");
            }
        }

        // do not worry, this is not as scary as it seems
        foreach (Feed::$feed as $cls) {
            foreach (array_keys(get_class_vars($cls)) as $key) {
                try {
                    // first try constant, then variable
                    $cls::$$key = defined($key) ? constant($key) : $$key;
                } catch (\ErrorException $e) {
                    throw new ApiException("Cannot load configuration variable", 500, "Variable '" . $key . "' does not exist.");
                }
            }
        }

        // set current club
        Session::$clubname = $clubname;
    }
}
