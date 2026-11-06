<?php

namespace Api;

require_once __DIR__ . "/database.php";

use Core\ApiException;

class Utils {
    public static function getCurrentDate() {
        $date = explode(".", date("d.m.Y"));

        if (sizeof($date) !== 3) {
            return 0;
        }

        return mktime(0, 0, 0, $date[1], $date[0], $date[2]);
    }

    public static function dateToISO($date) {
        return @date("Y-m-d", $date);
    }

    public static function Date2String($date, $long = false) {
        return $date == 0 ? "" : @date($long ? "d.m.Y" : "j.n.Y", $date);
    }

    public static function getTimeToRace($race_date) {
        $diff = (int)($race_date - static::getCurrentDate());

        if ($diff > 0) {
            $diff = static::secondsToDays($diff);
        } else if ($diff < 0) {
            $diff = -1;
        }

        return $diff;
    }

    public static function getTimeToReg($entry_date) {
        // 90000 = 25 * 60 * 60 - posun terminu prihlasek o 2 hodiny navic, kvuli time() ktery vraci UTC je tam +25 hodin
        $entry_date += 90000;

        // puvodni pred pridanim 2 hodin navic
        //	$diff = (int)(($t_p) - GetCurrentDate());

        $diff = (int)(($entry_date) - time());
        if ($diff > 0) {
            $diff = static::secondsToDays($diff);
        } else if ($diff < 0) {
            $diff = -1;
        }

        return $diff;
    }

    public static function secondsToDays($seconds) {
        // 86400 = 24 * 60 * 60
        return floor($seconds / 86400);
    }

    public static function formatTimestamps(int ...$stamps) {
        // Examples:
        // formatTimestamps(1724596850, 1724683300) === "25.8.2024 - 26.8.2024"
        // formatTimestamps(1724596850, 0) === "25.8.2024 - ?"
        return join(" - ", array_map(function ($stamp) {
            return $stamp === 0 ? "?" : date("j.n.Y", $stamp);
        }, $stamps));
    }
}

class SessionUtils {
    public static function require_entry_is_not_locked($user_id) {
        if (Database::fetch_assoc("SELECT `entry_locked` FROM `" . Tables::$TBL_USER . "` WHERE `id` = ?", $user_id)["entry_locked"]) {
            throw new ApiException("Entry was locked for this user.", 403);
            return;
        }
    }

    public static function require_managing_this_user($user_id) {
        // raises error if current user is is not managing user

        if (Session::$user_id == $user_id) { // use == operator to accept str == int
            // the chief has obviously access to itself
            return true;
        }

        if (!Session::$policy_mng_small) {
            throw new ApiException("You must be at least a small manager.", 403);
            return;
        }

        if (Database::fetch_assoc("SELECT 1 FROM `" . Tables::$TBL_USER . "` WHERE `id` = ? AND `chief_id` = ? LIMIT 1", $user_id, Session::$user_id) === null) {
            throw new ApiException("The user you are trying to sign in has to be associated with you.", 403);
            return;
        }
    }

    public static function is_managing_this_user($user_id) {
        try {
            self::require_managing_this_user($user_id);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}


class RaceUtils {
    public static function require_race_is_not_cancelled($race_id) {
        if (Database::fetch_assoc("SELECT `cancelled` FROM `" . Tables::$TBL_RACE . "` WHERE `id` = ?", $race_id)["cancelled"] == 1) {
            throw new ApiException("This race was cancelled.", 404);
            return;
        }
    }

    public static function get_race_info($race_id) {
        $result = Database::fetch_assoc("SELECT * FROM `" . Tables::$TBL_RACE . "` WHERE `id` = ?", $race_id);

        if ($result === null) {
            throw new ApiException("The race you are looking for does not exists.", 404);
            return;
        }

        $dates = [Utils::dateToISO($result["datum"])]; // always provide date
        if ($result["vicedenni"]) $dates[] = Utils::dateToISO($result["datum2"]); // add second date if exists

        $entries = [Utils::dateToISO($result["prihlasky1"])]; // always provide entry
        if ($result["prihlasky2"] != 0 && $result["prihlasky"] > 1) $entries[] = Utils::dateToISO($result["prihlasky2"]);
        if ($result["prihlasky3"] != 0 && $result["prihlasky"] > 2) $entries[] = Utils::dateToISO($result["prihlasky3"]);
        if ($result["prihlasky4"] != 0 && $result["prihlasky"] > 3) $entries[] = Utils::dateToISO($result["prihlasky4"]);
        if ($result["prihlasky5"] != 0 && $result["prihlasky"] > 4) $entries[] = Utils::dateToISO($result["prihlasky5"]);

        $zebricek = [];
        for ($i = 0; $i < Enums::$g_zebricek_cnt; $i++) {
            if (Enums::$g_zebricek[$i]["id"] & $result["zebricek"]) {
                $zebricek[] = Enums::$g_zebricek[$i]["nm"];
            }
        }

        $sport = null;
        for ($i = 0; $i < Enums::$g_racetype_cnt; $i++) {
            if (Enums::$g_racetype[$i]["enum"] == $result["typ0"]) {
                $sport = Enums::$g_racetype[$i]["nm"];
                break;
            }
        }

        $link = $result["odkaz"];
        // https://stackoverflow.com/a/14701491/14900791
        if ($link && parse_url(ltrim($link, "/"), PHP_URL_SCHEME) === null) {
            // guess protocol
            $link = "http://" . $link;
        }

        $leader = null;
        if (Config::$g_enable_race_boss && $result["vedouci"] != 0) {
            $leader = Database::fetch_assoc("SELECT `id` AS `user_id`, `jmeno` AS `name`, `prijmeni` AS `surname` FROM `" . Tables::$TBL_USER . "` WHERE `id` = ?", $result["vedouci"]);
        }

        return [
            "race_id" => $result["id"],

            "dates" => $dates,
            "entries" => $entries,
            "name" => $result["nazev"],

            // not sure cancelled is string, use weak comparision
            "cancelled" => $result["cancelled"] == 1,
            "club" => $result["oddil"],
            "link" => $link,
            "place" => $result["misto"],
            "type" => Enums::$g_racetype0[$result["typ0"]],
            "sport" => $sport,
            "rankings" => $zebricek,
            "rank21" => $result["ranking"],
            "note" => $result["poznamka"],

            // 0 = No; 1 = Yes; 2 = Auto Yes; 3 = Shared;
            "transport" => Config::$g_enable_race_transport ? $result["transport"] : 0,

            // 0 = No; 1 = Yes; 2 = Auto Yes; 3 = Shared;
            "accommodation" => Config::$g_enable_race_accommodation ? $result["ubytovani"] : 0,

            // explode returns [""] on empty list
            "categories" => $result["kategorie"] == "" ? [] : explode(";", $result["kategorie"]),

            // race leader (vedouci), null when not set
            "leader" => $leader,
        ];
    }

    public static function get_time_to_registration($race_id) {
        // returns a registration state

        // For reg/unreg
        // 1 .. 5 - active term
        // 0 - any active term / cannot process	

        $zaznam = Database::fetch_assoc("SELECT `datum`, `prihlasky`, `prihlasky1`, `prihlasky2`, `prihlasky3`, `prihlasky4`, `prihlasky5` FROM `" . Tables::$TBL_RACE . "` WHERE `id` = ?", $race_id);

        if (Utils::getTimeToRace($zaznam["datum"]) <= 0) return 0;
        if ($zaznam["prihlasky"] == 0) return 1;
        if (Utils::getTimeToReg($zaznam["prihlasky1"]) != -1) return 1;
        if ($zaznam["prihlasky"] > 1 && Utils::getTimeToReg($zaznam["prihlasky2"]) != -1) return 2;
        if ($zaznam["prihlasky"] > 2 && Utils::getTimeToReg($zaznam["prihlasky3"]) != -1) return 3;
        if ($zaznam["prihlasky"] > 3 && Utils::getTimeToReg($zaznam["prihlasky4"]) != -1) return 4;
        if ($zaznam["prihlasky"] > 4 && Utils::getTimeToReg($zaznam["prihlasky5"]) != -1) return 5;

        return 0;
    }
}
