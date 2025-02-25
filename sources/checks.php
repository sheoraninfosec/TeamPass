<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * ---
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * ---
 * @project   Teampass
 * @file      checks.php
 * ---
 * @author    Nils Laumaillé (nils@teampass.net)
 * @copyright 2009-2023 Teampass.net
 * @license   https://spdx.org/licenses/GPL-3.0-only.html#licenseText GPL-3.0
 * ---
 * @see       https://www.teampass.net
 */


require_once 'SecureHandler.php';

// Load config
try {
    include_once __DIR__.'/../includes/config/tp.config.php';
} catch (Exception $e) {
    throw new Exception("Error file '/includes/config/tp.config.php' not exists", 1);
    exit();
}

require_once $SETTINGS['cpassman_dir'] . '/includes/config/include.php';
require_once $SETTINGS['cpassman_dir'] . '/sources/main.functions.php';

// Prepare sanitization
$data = [
    'forbidenPfs' => isset($_POST['type']) === true ? $_POST['type'] : '',
];
$inputData = dataSanitizer(
    [
        'type' => isset($_POST['type']) === true ? $_POST['type'] : '',
    ],
    [
        'type' => 'trim|escape',
    ],
    $SETTINGS['cpassman_dir']
);

/*
Handle CASES
 */
switch ($inputData['type']) {
    case 'checkSessionExists':
        // Case permit to check if SESSION is still valid
        session_name('teampass_session');
        session_start();

        if (isset($_SESSION['CPM']) === true) {
            echo json_encode([
                'status' => true,
            ]);
        } else {
            // In case that no session is available
            // Force the page to be reloaded and attach the CSRFP info
            // Load CSRFP
            $csrfp_array = include '../includes/libraries/csrfp/libs/csrfp.config.php';

            // Send back CSRFP info
            echo $csrfp_array['CSRFP_TOKEN'] . ';' . filter_input(INPUT_POST, $csrfp_array['CSRFP_TOKEN'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        }

        break;
}

/**
 * Returns the page the user is visiting.
 *
 * @return string The page name
 */
function curPage($SETTINGS)
{
    // Load libraries
    include_once $SETTINGS['cpassman_dir'] . '/includes/libraries/protect/SuperGlobal/SuperGlobal.php';
    $superGlobal = new protect\SuperGlobal\SuperGlobal();

    // Parse the url
    parse_str(
        substr(
            (string) $superGlobal->get('REQUEST_URI', 'SERVER'),
            strpos((string) $superGlobal->get('REQUEST_URI', 'SERVER'), '?') + 1
        ),
        $result
    );

    return $result['page'];
}

/**
 * Checks if user is allowed to open the page.
 *
 * @param int    $userId      User's ID
 * @param int    $userKey     User's temporary key
 * @param string $pageVisited Page visited
 * @param array  $SETTINGS    Settings
 *
 * @return bool
 */
function checkUser($userId, $userKey, $pageVisited, $SETTINGS)
{
    // Should we start?
    if (empty($userId) === true || empty($pageVisited) === true || empty($userKey) === true) {
        return false;
    }

    // Definition
    $pagesRights = array(
        'user' => array(
            'home', 'items', 'search', 'kb', 'favourites', 'suggestion', 'profile', 'import', 'export', 'folders', 'offline',
        ),
        'manager' => array(
            'home', 'items', 'search', 'kb', 'favourites', 'suggestion', 'folders', 'roles', 'utilities', 'users', 'profile',
            'import', 'export', 'offline', 'process',
            'utilities.deletion', 'utilities.renewal', 'utilities.database', 'utilities.logs', 'tasks',
        ),
        'human_resources' => array(
            'home', 'items', 'search', 'kb', 'favourites', 'suggestion', 'folders', 'roles', 'utilities', 'users', 'profile',
            'import', 'export', 'offline', 'process',
            'utilities.deletion', 'utilities.renewal', 'utilities.database', 'utilities.logs', 'tasks',
        ),
        'admin' => array(
            'home', 'items', 'search', 'kb', 'favourites', 'suggestion', 'folders', 'manage_roles', 'manage_folders',
            'import', 'export', 'offline', 'process',
            'manage_views', 'manage_users', 'manage_settings', 'manage_main',
            'admin', '2fa', 'profile', '2fa', 'api', 'backups', 'emails', 'ldap', 'special',
            'statistics', 'fields', 'options', 'views', 'roles', 'folders', 'users', 'utilities',
            'utilities.deletion', 'utilities.renewal', 'utilities.database', 'utilities.logs', 'tasks',
        ),
    );
    // Convert to array
    $pageVisited = (is_array(json_decode($pageVisited, true)) === true) ? json_decode($pageVisited, true) : [$pageVisited];

    // Load
    include_once __DIR__ . '/../includes/config/include.php';
    include_once __DIR__ . '/../includes/config/settings.php';
    include_once __DIR__ . '/../includes/libraries/Database/Meekrodb/db.class.php';
    include_once 'SplClassLoader.php';
    include_once 'main.functions.php';

    // Connect to mysql server
    DB::$host = DB_HOST;
    DB::$user = DB_USER;
    DB::$password = defined('DB_PASSWD_CLEAR') === false ? defuseReturnDecrypted(DB_PASSWD, $SETTINGS) : DB_PASSWD_CLEAR;
    DB::$dbName = DB_NAME;
    DB::$port = DB_PORT;
    DB::$encoding = DB_ENCODING;
    DB::$ssl = DB_SSL;
    DB::$connect_options = DB_CONNECT_OPTIONS;

    // load user's data
    $data = DB::queryfirstrow(
        'SELECT login, key_tempo, admin, gestionnaire, can_manage_all_users FROM ' . prefixTable('users') . ' WHERE id = %i',
        $userId
    );

    // check if user exists and tempo key is coherant
    if (empty($data['login']) === true || empty($data['key_tempo']) === true || $data['key_tempo'] !== $userKey) {
        return false;
    }
    
    if (
        ((int) $data['admin'] === 1 && isInArray($pageVisited, $pagesRights['admin']) === true)
        ||
        (((int) $data['gestionnaire'] === 1 || (int) $data['can_manage_all_users'] === 1)
        && (isInArray($pageVisited, array_merge($pagesRights['manager'], $pagesRights['human_resources'])) === true))
        ||
        (isInArray($pageVisited, $pagesRights['user']) === true)
    ) {
        return true;
    }

    return false;
}

/**
 * Permits to check if at least one input is in array.
 *
 * @param array $pages Input
 * @param array $table Checked against this array
 *
 * @return bool
 */
function isInArray($pages, $table)
{
    foreach ($pages as $page) {
        if (in_array($page, $table) === true) {
            return true;
        }
    }

    return false;
}
