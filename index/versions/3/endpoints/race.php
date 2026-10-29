<?php

namespace Api;

use Pecee\SimpleRouter\SimpleRouter as Router;

require_once __DIR__ . "/../boilerplate/middlewares.php";
require_once __DIR__ . "/../boilerplate/session.php";
require_once __DIR__ . "/../boilerplate/utils.php";
require_once __DIR__ . "/../boilerplate/database.php";
require_once __DIR__ . "/../boilerplate/endpoint.php";
require_once __DIR__ . "/../boilerplate/notify.php";
require_once __DIR__ . "/../boilerplate/config.php";
require_once __DIR__ . "/../boilerplate/input.php";
require_once __DIR__ . "/../boilerplate/modify_log.php";

use Core\ApiException;

class Race implements Endpoint {
    public static function init(): void {
        Router::get("/races", [static::class, "races"]);

        Router::partialGroup("/race", function () {
            $race_id = ["race_id" => "[0-9]+"];
            $user_id = ["user_id" => "[0-9]+"];

            Router::form("/", [static::class, "warn_race_required"]);

            Router::get("/{race_id}/redirect", [static::class, "redirect"])->where($race_id);

            Router::group(["middleware" => AuthRequired::class], function () use ($race_id, $user_id) {
                Router::get("/{race_id}", [static::class, "detail"])->where($race_id);
                Router::get("/{race_id}/relations", [static::class, "relations"])->where($race_id);
                Router::post("/{race_id}/signin/{user_id}", [static::class, "signin"])->where($race_id + $user_id);
                Router::post("/{race_id}/signout/{user_id}", [static::class, "signout"])->where($race_id + $user_id);
                Router::post("/{race_id}/notify", [static::class, "notify"])->where($race_id);
                Router::get("/{race_id}/payments", [static::class, "payments"])->where($race_id);
            });
        });
    }

    public static function warn_race_required() {
        throw new ApiException("race_id is required.", 404);
        return;
    }

    public static function redirect($race_id) {
        // status code 302, indicates that the resource requested has been temporarily moved
        redirect(Config::$g_baseadr . "race_info_show.php?id_zav=" . $race_id);
    }

    public static function races() {
        $from_date = Input::key("from_date", required: false, filter: Input::$FILTER_ISO_DATE);

        if (!isset($from_date)) {
            $from_date = Utils::getCurrentDate();
        } else {
            $from_date = strtotime($from_date);
        }

        $output = Database::query("SELECT `id` FROM `" . Tables::$TBL_RACE . "` WHERE `datum` >= ? || `datum2` >= ? ORDER BY `datum`", $from_date, $from_date);

        $result = [];
        while ($race = $output->fetch_assoc()) {
            $result[] = RaceUtils::get_race_info($race["id"]);
        }

        response()->json($result);
    }

    public static function detail($race_id) {
        $result = RaceUtils::get_race_info($race_id);

        // provide information about signed in users

        // transport: 0 = No; 1 = Yes; 2 = Auto Yes; 3 = Shared;
        // accommodation: 0 = No; 1 = Yes; 2 = Auto Yes;
        $result["everyone"] = Database::fetch_assoc_all("
            SELECT
                user.id AS user_id,
                user.jmeno AS name,
                user.prijmeni AS surname,
                user.reg,
                zavxus.kat AS category,
                zavxus.pozn AS note,
                zavxus.pozn_in AS note_internal,
                zavxus.transport,
                zavxus.sedadel AS transport_shared,
                zavxus.ubytovani AS accommodation,
                IF (zavxus.si_chip != 0, zavxus.si_chip, user.si_chip) AS si_chip
            FROM `" . Tables::$TBL_ZAVXUS . "` AS zavxus
            LEFT JOIN `" . Tables::$TBL_USER . "` AS user ON zavxus.id_user = user.id
            WHERE zavxus.id_zavod = ?
        ", $race_id);

        response()->json($result);
    }

    public static function relations($race_id) {
        $result = Database::fetch_assoc_all("
            SELECT
                user.id AS user_id,
                user.jmeno AS name,
                user.prijmeni AS surname,
                user.sort_name,
                user.reg,
                zavxus.id_zavod AS race_id,
                zavxus.kat AS category,
                zavxus.pozn AS note,
                zavxus.pozn_in AS note_internal,
                zavxus.transport,
                zavxus.sedadel AS transport_shared,
                zavxus.ubytovani AS accommodation,
                IF (zavxus.id IS NULL, FALSE, TRUE) AS is_signed_in,
                IF (zavxus.si_chip != 0, zavxus.si_chip, user.si_chip) AS si_chip
            FROM `" . Tables::$TBL_USER . "` AS user
            LEFT JOIN `" . Tables::$TBL_ZAVXUS . "` AS zavxus ON zavxus.id_user = user.id AND zavxus.id_zavod = ?
            WHERE user.id = ? OR user.chief_pay = ?
        ", $race_id, Session::$user_id, Session::$user_id);

        response()->json($result);
    }

    public static function signin($race_id, $user_id) {
        $input = new Input(required: true);
        $input->add("category", "kat");
        $input->add("note", "pozn");
        $input->add("note_internal", "pozn_in");
        $input->add("transport", filter: Input::$FILTER_BOOL);
        $input->add("accommodation", "ubytovani", filter: Input::$FILTER_BOOL);
        $input = $input->collect();

        if ($input["kat"] === "") {
            throw new ApiException("Please enter category.", 404);
        }

        $input["sedadel"] = null;

        $output = Database::fetch_assoc("SELECT transport FROM " . Tables::$TBL_RACE . " WHERE id = ?", $race_id);

        if ($output === null) {
            throw new ApiException("The race does not exists.", 404);
        }

        // transport is shared
        if ($output["transport"] == 3) {
            $input["sedadel"] = Input::key("transport_shared", "sedadel", filter: Input::$FILTER_INT, nullable: true);

            // user does not want shared transport, so we disable transport
            if (!$input["sedadel"]) {
                $input["transport"] = 0;
            }
        }

        SessionUtils::require_managing_this_user($user_id);
        SessionUtils::require_entry_is_not_locked($user_id);

        if (($termin = RaceUtils::get_time_to_registration($race_id)) === 0) {
            throw new ApiException("The deadline for entry has been exceeded.", 404);
            return;
        }

        RaceUtils::require_race_is_not_cancelled($race_id);

        $input["termin"] = $termin;

        // check if the user is already signed in, if yes, extract id
        if (($output = Database::fetch_assoc("SELECT id FROM `" . Tables::$TBL_ZAVXUS . "` WHERE id_zavod = ? AND id_user = ? LIMIT 1", $race_id, $user_id)) === null) {
            Database::insert(Tables::$TBL_ZAVXUS, array_merge($input, [
                "id_user" => $user_id,
                "id_zavod" => $race_id,
            ]));
        } else {
            Database::update(Tables::$TBL_ZAVXUS, $input, "WHERE id = ?", $output["id"]);
        }
    }

    public static function signout($race_id, $user_id) {
        // the id of who is being signed out
        SessionUtils::require_managing_this_user($user_id);
        SessionUtils::require_entry_is_not_locked($user_id);
        RaceUtils::require_race_is_not_cancelled($race_id);

        Database::query("DELETE FROM `" . Tables::$TBL_ZAVXUS . "` WHERE `id_zavod` = ? AND `id_user` = ?", $race_id, $user_id);
    }

    public static function notify($race_id) {
        if (!Session::$policy_mng_big) {
            throw new ApiException("You must be at least big manager to send a notification.", 403);
            return;
        }

        $title = Input::key("title");
        $body = Input::key("body");
        $image = Input::key("image", required: false, nullable: true, filter: FILTER_VALIDATE_URL);

        $content = new NotifyContent($title, $body);
        $content->topic(Session::$clubname);
        $content->custom_event(NotifyContent::$EVENT_RACE, $race_id);
        $content->image($image);

        $result = Notifications::send($content->export());

        response()->json($result);
    }

    public static function payments($race_id) {
        if (!Session::all(Session::$MASK_MNG_BIG | Session::$MASK_FIN)) {
            throw new ApiException("You must have big manager and finance permissions to proceed.", 403);
            return;
        }

        $result = Database::fetch_assoc_all("
            SELECT
                fin.id AS `fin_id`,
                fin.id_users_editor AS `editor_user_id`,
                editor.sort_name AS `editor_sort_name`,
                user.id AS `user_id`,
                user.sort_name AS `user_sort_name`,
                user.chief_pay,
                fin.note,
                fin.amount,
                fin.date,
                fin.storno,
                fin.storno_by AS `storno_user_id`,
                storno.sort_name AS `storno_sort_name`,
                fin.storno_date,
                fin.storno_note,
                fin.claim
            FROM `" . Tables::$TBL_FINANCE . "` AS fin 
            LEFT JOIN `" . Tables::$TBL_USER . "` editor ON fin.id_users_editor = editor.id
            LEFT JOIN `" . Tables::$TBL_USER . "` user ON fin.id_users_user = user.id
            LEFT JOIN `" . Tables::$TBL_USER . "` storno ON fin.storno_by = storno.id
            WHERE fin.id_zavod = ?
            ORDER BY fin.date DESC, fin.id DESC
        ", $race_id);

        response()->json($result);
    }
};
