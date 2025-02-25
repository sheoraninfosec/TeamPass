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
 * @file      tasks.queries.php
 * ---
 * @author    Nils Laumaillé (nils@teampass.net)
 * @copyright 2009-2023 Teampass.net
 * @license   https://spdx.org/licenses/GPL-3.0-only.html#licenseText GPL-3.0
 * ---
 * @see       https://www.teampass.net
 */

Use EZimuel\PHPSecureSession;
Use Symfony\Component\Process\Process;
Use TeampassClasses\PerformChecks\PerformChecks;

// Load functions
require_once 'main.functions.php';

// init
loadClasses('DB');
session_name('teampass_session');
session_start();
if (
    isset($_SESSION['CPM']) === false
    || $_SESSION['CPM'] !== 1
    || isset($_SESSION['user_id']) === false || empty($_SESSION['user_id'])
    || isset($_SESSION['key']) === false || empty($_SESSION['key'])
) {
    die('Hacking attempt...');
}

// Load config if $SETTINGS not defined
try {
    include_once __DIR__.'/../includes/config/tp.config.php';
} catch (Exception $e) {
    throw new Exception("Error file '/includes/config/tp.config.php' not exists", 1);
    exit();
}

// Do checks
// Instantiate the class with posted data
$checkUserAccess = new PerformChecks(
    dataSanitizer(
        [
            'type' => isset($_POST['type']) === true ? $_POST['type'] : '',
        ],
        [
            'type' => 'trim|escape',
        ],
    )
);
// Handle the case
$checkUserAccess->caseHandler();
if ($checkUserAccess->userAccessPage($_SESSION['user_id'], $_SESSION['key'], 'options', $SETTINGS) === false) {
    // Not allowed page
    $_SESSION['error']['code'] = ERR_NOT_ALLOWED;
    include $SETTINGS['cpassman_dir'] . '/error.php';
    exit;
}

require_once $SETTINGS['cpassman_dir'] . '/includes/language/' . $_SESSION['user']['user_language'] . '.php';

header('Content-type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');


// Prepare POST variables
$post_type = filter_input(INPUT_POST, 'type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$post_data = filter_input(INPUT_POST, 'data', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_FLAG_NO_ENCODE_QUOTES);
$post_key = filter_input(INPUT_POST, 'key', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$post_task = filter_input(INPUT_POST, 'task', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (null !== $post_type) {
    // Do checks
    if ($post_key !== $_SESSION['key']) {
        echo prepareExchangedData(
            array(
                'error' => true,
                'message' => langHdl('key_is_not_correct'),
            ),
            'encode'
        );
        return false;
    } elseif ($_SESSION['user_read_only'] === true) {
        echo prepareExchangedData(
            array(
                'error' => true,
                'message' => langHdl('error_not_allowed_to'),
            ),
            'encode'
        );
        return false;
    }

    // Get PHP binary
    $phpBinaryPath = getPHPBinary();

    switch ($post_type) {
        case 'perform_task':
            echo performTask($post_task, $SETTINGS['cpassman_dir'], $phpBinaryPath, $SETTINGS['date_format'].' '.$SETTINGS['time_format']);

            break;

        case 'load_last_tasks_execution':
            echo loadLastTasksExec($SETTINGS['date_format'].' '.$SETTINGS['time_format'], $SETTINGS['cpassman_dir']);

            break;  
    }
}

/**
 * Load the last tasks execution
 *
 * @param string $datetimeFormat
 * @param string $dir
 * @return string
 */
function loadLastTasksExec(string $datetimeFormat, string $dir): string
{
    $lastExec = [];

    // get exec from processes table
    $rows = DB::query(
        'SELECT max(finished_at), process_type
        FROM ' . prefixTable('processes') . '
        GROUP BY process_type'
    );
    foreach ($rows as $row) {
        array_push(
            $lastExec,
            [
                'task' => loadLastTasksExec_getBadge($row['process_type']),
                'datetime' => date($datetimeFormat, (int) $row['max(finished_at)'])
            ]
        );
    }

    // get exec from processes_log table
    $rows = DB::query(
        'SELECT max(finished_at), job as process_type
        FROM ' . prefixTable('processes_logs') . '
        GROUP BY process_type'
    );
    foreach ($rows as $row) {
        array_push(
            $lastExec,
            [
                'task' => loadLastTasksExec_getBadge($row['process_type']),
                'datetime' => date($datetimeFormat, (int) $row['max(finished_at)'])
            ]
        );
    }

    return prepareExchangedData(
        array(
            'error' => false,
            'task' => json_encode($lastExec),
        ),
        'encode'
    );
}

function loadLastTasksExec_getBadge(string $processLabel): string
{
    $existingTasks = [
        'do_maintenance - clean-orphan-objects' => [
            'db' => 'do_maintenance - clean-orphan-objects',
            'task' => 'clean_orphan_objects_task',
        ],
        'do_maintenance - purge-old-files' => [
            'db' => 'do_maintenance - purge-old-files',
            'task' => 'purge_temporary_files_task',
        ],
        'do_maintenance - rebuild-config-file' => [
            'db' => 'do_maintenance - rebuild-config-file',
            'task' => 'rebuild_config_file_task',
        ],
        'do_maintenance - reload-cache-table' => [
            'db' => 'do_maintenance - reload-cache-table',
            'task' => 'reload_cache_table_task',
        ],
        'do_maintenance - users-personal-folder' => [
            'db' => 'do_maintenance - users-personal-folder',
            'task' => 'users_personal_folder_task',
        ],
        'send_email' => [
            'db' => 'send_email',
            'task' => 'sending_emails_job_frequency',
        ],
        'do_calculation' => [
            'db' => 'do_calculation',
            'task' => 'items_statistics_job_frequency',
        ],
        'item_keys' => [
            'db' => 'item_keys',
            'task' => 'items_ops_job_frequency',
        ],
        'user_task' => [
            'db' => 'user_task',
            'task' => 'user_keys_job_frequency',
        ],
        'sending_email' => [
            'db' => 'sending_email',
            'task' => 'sending_emails_job_frequency',
        ],
    ];

    return isset($existingTasks[$processLabel]) === true ? $existingTasks[$processLabel]['task'] : $processLabel;
}

/**
 * Perform a task
 *
 * @param string $task
 * @param string $dir
 * @param string $phpBinaryPath
 * @param string $datetimeFormat
 * @return string
 */
function performTask(string $task, string $dir, string $phpBinaryPath, string $datetimeFormat): string
{
    switch ($task) {
        case 'users_personal_folder_task':

            $process = new Process([
                $phpBinaryPath,
                __DIR__.'/../scripts/task_maintenance_users_personal_folder.php',
            ]);

            break;

        case 'clean_orphan_objects_task':

            $process = new Process([
                $phpBinaryPath,
                __DIR__.'/../scripts/task_maintenance_clean_orphan_objects.php',
            ]);

            break;

        case 'purge_temporary_files_task':

            $process = new Process([
                $phpBinaryPath,
                __DIR__.'/../scripts/task_maintenance_purge_old_files.php',
            ]);

            break;

        case 'rebuild_config_file_task':

            $process = new Process([
                $phpBinaryPath,
                __DIR__.'/../scripts/task_maintenance_rebuild_config_file.php',
            ]);

            break;

        case 'reload_cache_table_task':

            $process = new Process([
                $phpBinaryPath,
                __DIR__.'/../scripts/task_maintenance_reload_cache_table.php',
            ]);

            break;
        }

    // execute the process
    if (isset($process) === true) {
        try {
            $process->start();

            while ($process->isRunning()) {
                // waiting for process to finish
            }

            $process->wait();
        
            $output = $process->getOutput();
            $error = false;
        } catch (ProcessFailedException $exception) {
            $error = true;
            $output = $exception->getMessage();
        }

        return prepareExchangedData(
            array(
                'error' => $error,
                'output' => $output,
                'datetime' => date($datetimeFormat, time()),
            ),
            'encode'
        );
    }

    return prepareExchangedData(
        array(
            'error' => true,
            'output' => '',
            'datetime' => date($datetimeFormat, time()),
        ),
        'encode'
    );
}