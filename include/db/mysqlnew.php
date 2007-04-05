<?php

////////////////////////////////////////////////////////////////////////////////
//                                                                            //
//   Copyright (C) 2006  Phorum Development Team                              //
//   http://www.phorum.org                                                    //
//                                                                            //
//   This program is free software. You can redistribute it and/or modify     //
//   it under the terms of either the current Phorum License (viewable at     //
//   phorum.org) or the Phorum License that was distributed with this file    //
//                                                                            //
//   This program is distributed in the hope that it will be useful,          //
//   but WITHOUT ANY WARRANTY, without even the implied warranty of           //
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.                     //
//                                                                            //
//   You should have received a copy of the Phorum License                    //
//   along with this program.                                                 //
////////////////////////////////////////////////////////////////////////////////

// cvs-info: $Id: mysql.php 1683 2007-03-27 12:28:59Z mmakaay $

// TODO: phorum_user_access_allowed() is used in this layer, but the
// TODO: include file for that is not included here. Keep it like that
// TODO: or add the required include? Or is it functionality that doesn't
// TODO: belong here and could better go into the core maybe?

if (!defined("PHORUM")) return;

/**
 * The other Phorum code does not care how the messages are stored.
 * The only requirement is that they are returned from these functions
 * in the right way.  This means each database can use as many or as
 * few tables as it likes.  It can store the fields anyway it wants.
 * The only thing to worry about is the table_prefix for the tables.
 * all tables for a Phorum install should be prefixed with the
 * table_prefix that will be entered in include/db/config.php.  This
 * will allow multiple Phorum installations to use the same database.
 */

// ----------------------------------------------------------------------
// Definitions
// ----------------------------------------------------------------------

/**
 * These are the table names used for this database system.
 */

$prefix = $PHORUM['DBCONFIG']['table_prefix'];

// tables needed to be "partitioned"
$PHORUM["message_table"]            = $prefix . "_messages";
$PHORUM["user_newflags_table"]      = $prefix . "_user_newflags";
$PHORUM["subscribers_table"]        = $prefix . "_subscribers";
$PHORUM["files_table"]              = $prefix . "_files";
$PHORUM["search_table"]             = $prefix . "_search";

// tables common to all "partitions"
$PHORUM["settings_table"]           = $prefix . "_settings";
$PHORUM["forums_table"]             = $prefix . "_forums";
$PHORUM["user_table"]               = $prefix . "_users";
$PHORUM["user_permissions_table"]   = $prefix . "_user_permissions";
$PHORUM["groups_table"]             = $prefix . "_groups";
$PHORUM["forum_group_xref_table"]   = $prefix . "_forum_group_xref";
$PHORUM["user_group_xref_table"]    = $prefix . "_user_group_xref";
$PHORUM['user_custom_fields_table'] = $prefix . "_user_custom_fields";
$PHORUM["banlist_table"]            = $prefix . "_banlists";
$PHORUM["pm_messages_table"]        = $prefix . "_pm_messages";
$PHORUM["pm_folders_table"]         = $prefix . "_pm_folders";
$PHORUM["pm_xref_table"]            = $prefix . "_pm_xref";
$PHORUM["pm_buddies_table"]         = $prefix . "_pm_buddies";

/**
 * Message fields which are always strings, even if they contain numbers only.
 * Used in post-message and update-message, otherwise strange things happen.
 */
$PHORUM['string_fields_message'] = array('author', 'subject', 'body', 'email');

/**
 * Forum fields which are always strings, even if they contain numbers only.
 */
$PHORUM['string_fields_forum'] = array('name', 'description', 'template');

/**
 * Constants for the phorum_db_interact() function call $return parameter.
 */
define('DB_RETURN_CONN',     0);
define('DB_RETURN_QUOTED',   1);
define('DB_RETURN_RES',      2);
define('DB_RETURN_ROWS',     3);
define('DB_RETURN_ASSOC',    4);
define('DB_RETURN_VALUE',    5);
define('DB_RETURN_ROWCOUNT', 6);
define('DB_RETURN_NEWID',    7);

/**
 * Constants for the phorum_db_interact() function call $flags parameter.
 */
define('DB_NOCONNECTOK',     1);
define('DB_MISSINGTABLEOK',  2);

// ----------------------------------------------------------------------
// Utility functions
// ----------------------------------------------------------------------

/**
 * This function is the central function for handling database interaction.
 * The function can be used for setting up a database connection, for running
 * a SQL query and for returning query rows. Which of these actions the
 * function will handle and what the function return data will be, is
 * determined by the $return function parameter.
 *
 * @param $return   - What to return. Options are the following constants:
 *                    DB_RETURN_CONN     a db connection handle
 *                    DB_RETURN_QUOTED   a quoted parameter
 *                    DB_RETURN_RES      result resource handle
 *                    DB_RETURN_ROWS     rows as array arrays
 *                    DB_RETURN_ASSOC    rows as associative arrays
 *                    DB_RETURN_VALUE    single row, single column
 *                    DB_RETURN_ROWCOUNT number of selected rows
 *                    DB_RETURN_NEWID    new row id for insert query
 * @param $sql      - The SQL query to run or the parameter to quote if
 *                    DB_RETURN_QUOTED is used.
 * @param $keyfield - When returning an array of rows, the indexes are
 *                    numerical by default (0, 1, 2, etc.). However, if
 *                    the $keyfield parameter is set, then from each
 *                    row the $keyfield index is taken as the key for the
 *                    return array. This way, you can create a direct
 *                    mapping between some id field and its row in the
 *                    return data. Mind that there is no error checking
 *                    at all, so you have to make sure that you provide
 *                    a valid $keyfield here!
 * @param $flags    - Special flags for modifying the function's behavior.
 *                    These flags can be OR'ed if multiple flags are needed.
 *                    DB_NOCONNECTOK     failure to connect is not fatal but
 *                                       lets the call return FALSE (useful
 *                                       in combination with DB_RETURN_CONN)
 *                    DB_MISSINGTABLEOK  missing table is not fatal
 *
 * @return $res     - The result of the query, based on the $return parameter.
 */
function phorum_db_interact($return, $sql = NULL, $keyfield = NULL, $flags = 0)
{
    static $conn;

    // Return a quoted parameter.
    if ($return === DB_RETURN_QUOTED) {
        return mysql_escape_string($sql);
    }

    // Setup a database connection if no database connection is available yet.
    if (empty($conn))
    {
        $PHORUM = $GLOBALS["PHORUM"];

        $conn = mysql_connect(
            $PHORUM["DBCONFIG"]["server"],
            $PHORUM["DBCONFIG"]["user"],
            $PHORUM["DBCONFIG"]["password"],
            TRUE
        );
        if ($conn === FALSE) {
            if ($flags & DB_NOCONNECTOK) return FALSE;
            phorum_db_mysql_error("Failed to connect to the database.");
        }
        if (mysql_select_db($PHORUM["DBCONFIG"]["name"], $conn) === FALSE) {
            if ($flags & DB_NOCONNECTOK) return FALSE;
            phorum_db_mysql_error("Failed to select the database.");
        }
    }

    // RETURN: database connection handle
    if ($return === DB_RETURN_CONN) {
        return $conn;
    }

    // By now, we really need a SQL query.
    if ($sql === NULL) trigger_error(
        "Internal error: phorum_db_interact(): " .
        "missing sql query statement!", E_USER_ERROR
    );

    // Execute the SQL query.
    if (($res = mysql_query($sql, $conn)) === FALSE)
    {
        // See if the $flags tell us to ignore the error.
        $ignore_error = FALSE;
        switch (mysql_errno($conn))
        {
            // 1146: table does not exist
            case 1146:
              if ($flags & DB_MISSINGTABLEOK) $ignore_error = TRUE;
              break;
        }

        $err = mysql_error($conn);
        phorum_db_mysql_error("$err: $sql");
    }

    // RETURN: query resource handle
    if ($return === DB_RETURN_RES) {
        return $res;
    }

    // RETURN: number of rows
    elseif ($return === DB_RETURN_ROWCOUNT) {
        return mysql_num_rows($res);
    }

    // RETURN: array rows or single value
    elseif ($return === DB_RETURN_ROWS ||
            $return === DB_RETURN_VALUE)
    {
        $rows = array();
        while ($row = mysql_fetch_row($res)) {
            if ($keyfield === NULL) {
                $rows[] = $row;
            } else {
                $rows[$row[$keyfield]] = $row;
            }
        }

        // Return rows.
        if ($return === DB_RETURN_ROWS) {
            return $rows;
        }

        // Return value.
        if (count($rows) == 0) {
            return NULL;
        } else {
            return $rows[0][0];
        }
    }

    // RETURN: associative array rows
    elseif ($return === DB_RETURN_ASSOC)
    {
        $rows = array();
        while ($row = mysql_fetch_assoc($res)) {
            if ($keyfield === NULL) {
                $rows[] = $row;
            } else {
                $rows[$row[$keyfield]] = $row;
            }
        }
        return $rows;
    }

    // RETURN: new id after inserting a new record
    elseif ($return === DB_RETURN_NEWID) {
        return mysql_insert_id($conn);
    }

    trigger_error(
        "Internal error: phorum_db_interact(): " .
        "illegal return type specified!", E_USER_ERROR
    );
}

/**
 * A wrapper function for fetching associative records from a
 * SQL result resource. This function should be avoided
 * for performance reasons. If possible, let phorum_db_interact()
 * return the rows from the query instead.
 *
 * @param $res  - SQL result resource.
 *
 * @return $row - The next row in the result resource or NULL if no
 *                more rows are available.
 */
function phorum_db_fetch_assoc($res) {
    return mysql_fetch_assoc($res);
}


/**
 * DEPRECATED
 * A wrapper function for connecting to the database. This function should
 * be avoided. Instead the phorum_db_interact() function should be used
 * in combination with the DB_RETURN_CONN return type.
 *
 * @return $conn - A database connection resource handle.
 */
function phorum_db_connect() {
    return phorum_db_interact(DB_RETURN_CONN);
}


// ----------------------------------------------------------------------
// API functions
// ----------------------------------------------------------------------

/**
 * DEPRECATED?: we can save a function call by directly calling
 *              phorum_db_interact(). I'm also not sure if we need
 *              to do this check from common.php. We could take care
 *              of this in the db layer error handling too. Have to
 *              think about this ...
 *
 * Checks if a database connection can be made.
 *
 * @return boolean
 */
function phorum_db_check_connection()
{
    return phorum_db_interact(
        DB_RETURN_CONN,
        NULL, NULL,
        DB_NOCONNECTOK
    ) ? TRUE : FALSE;
}

/**
 * Load all Phorum key/value pair settings in $PHORUM and $PHORUM['SETTINGS'].
 * The settings are read from the settings table. In this settings
 * table, a type is provided for each setting. The supported types are:
 *
 * V = Value       the value of this field is used as is
 * S = Serialized  the value of this field is a serialzed PHP variable,
 *                 which will be unserialized before storing it in $PHORUM
 */
function phorum_db_load_settings()
{
    global $PHORUM;

    // At install time, there is no settings table. So from the
    // admin interface, we do not mind if we do not see that table.
    $settings = phorum_db_interact(
        DB_RETURN_ROWS,
        "SELECT name, data, type
         FROM {$PHORUM['settings_table']}",
        NULL,
        defined("PHORUM_ADMIN") ? DB_MISSINGTABLEOK : 0
    );

    foreach ($settings as $setting)
    {
        $val = $setting[2] == 'V'
             ? $setting[1]
             : unserialize($setting[1]);

        $PHORUM[$setting[0]] = $PHORUM['SETTINGS'][$setting[0]] = $val;
    }
}

/**
 * Store or update Phorum settings.
 *
 * @param $settings - An array containing key/value pairs that have to
 *                    be stored in the settings table. Values can be either
 *                    scalars or arrays. This function will automatically
 *                    serialize the arrays before storing them.
 *
 * @return $success - True if all settings were stored successfully. This
 *                    function will always return TRUE, so we could
 *                    do without a return value. The return value is
 *                    here for backward compatibility.
 */
function phorum_db_update_settings($settings)
{
    global $PHORUM;

    if (count($settings) > 0)
    {
        foreach ($settings as $field => $value)
        {
            if (is_array($value)) {
                $value = serialize($value);
                $type = 'S';
            } else {
                $type = 'V';
            }

            $field = phorum_db_interact(DB_RETURN_QUOTED, $field);
            $value = phorum_db_interact(DB_RETURN_QUOTED, $value);

            phorum_db_interact(
                DB_RETURN_RES,
                "REPLACE INTO {$PHORUM['settings_table']}
                 SET data = '$value',
                     type = '$type',
                     name = '$field'"
            );
        }
    }
    else trigger_error(
        "phorum_db_update_settings(): \$settings cannot be empty",
        E_USER_ERROR
    );

    return TRUE;
}


/**
 * Get a list of visible messages for a given page offset.
 *
 * By default, the message body is not included in the fetch queries.
 * To retrieve bodies as well, a true value has to be passed for the
 * $include_bodies parameter.
 *
 * NOTE: ALL dates must be returned as Unix timestamps
 *
 * @param $page           - The index of the page to return, starting with 0.
 * @param $include_bodies - Determines whether the message bodies
 *                          have to be included in the return data or not.
 *
 * @return $messages      - An array of recent messages, indexed by message id.
 */
function phorum_db_get_thread_list($page, $include_bodies=FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($page, "int");

    $table = $PHORUM["message_table"];

    // The messagefields that we want to fetch from the database.
    $messagefields =
       "$table.author,
        $table.datestamp,
        $table.email,
        $table.message_id,
        $table.forum_id,
        $table.meta,
        $table.moderator_post,
        $table.modifystamp,
        $table.parent_id,
        $table.msgid,
        $table.sort,
        $table.status,
        $table.subject,
        $table.thread,
        $table.thread_count,
        $table.user_id,
        $table.viewcount,
        $table.closed,
        $table.ip";

    // Include the message bodies in the thread list if requested.
    if ($include_bodies) {
        $messagefields .= ",$table.body";
    }

    // The sort mechanism to use.
    if($PHORUM["float_to_top"]){
            $sortfield = "modifystamp";
            $index = "list_page_float";
    } else{
            $sortfield = "thread";
            $index = "list_page_flat";
    }

    // Initialize the return array.
    $messages = array();

    // The groups of messages which we want to fetch from the database.
    // stickies : sticky messages (only on the first page)
    // threads  : thread starter messages (always)
    // replies  : thread reply messages (only in threaded read mode)
    $groups = array();
    if ($page == 0) $groups[] = "stickies";
    $groups[] = "threads";
    if ($PHORUM["threaded_list"]) $groups[] = "replies";

    // For remembering the message ids for which we want to fetch the replies.
    $replymsgids = array();

    // Process all groups.
    foreach ($groups as $group)
    {
        $sql = NULL;

        switch ($group)
        {
            // Stickies.
            case "stickies":

                $sql = "SELECT $messagefields
                        FROM   $table
                        WHERE  status=".PHORUM_STATUS_APPROVED." AND
                               parent_id=0 AND
                               sort=".PHORUM_SORT_STICKY." AND
                               forum_id={$PHORUM['forum_id']}
                        ORDER  BY sort, $sortfield desc";
                break;

            // Threads.
            case "threads":

                if ($PHORUM["threaded_list"]) {
                    $limit = $PHORUM['list_length_threaded'];
                } else {
                    $limit = $PHORUM['list_length_flat'];
                }
                $start = $page * $limit;

                $sql = "SELECT $messagefields
                        FROM   $table
                        USE    INDEX ($index)
                        WHERE  $sortfield > 0 AND
                               forum_id = {$PHORUM['forum_id']} AND
                               status = ".PHORUM_STATUS_APPROVED." AND
                               parent_id = 0 AND
                               sort > 1
                        ORDER  BY $sortfield DESC
                        LIMIT  $start, $limit";
                break;

            // Reply messages.
            case "replies":

                // We're done if we did not collect any messages with replies.
                if (! count($replymsgids)) break;

                $sortorder = "sort, $sortfield DESC, message_id";
                if (isset($PHORUM["reverse_threading"]) &&
                    $PHORUM["reverse_threading"])
                    $sortorder.=" DESC";

                $sql = "SELECT $messagefields
                        FROM   $table
                        WHERE  status = ".PHORUM_STATUS_APPROVED." AND
                               thread in (" . implode(",",$replymsgids) .")
                        ORDER  BY $sortorder";
                break;

        } // End of switch ($group)

        // Continue with the next group if no SQL query was formulated.
        if (is_null($sql)) continue;

        // Query the messages for the current group.
        $rows = phorum_db_interact(DB_RETURN_ASSOC, $sql, "message_id");
        foreach ($rows as $id => $row)
        {
            // Unpack the thread message meta data.
            $row["meta"] = empty($row["meta"])
                         ? array()
                         : unserialize($row["meta"]);

            // Add the row to the list of messages.
            $messages[$id] = $row;

            // We need the message ids for fetching reply messages.
            if ($group == 'threads' && $row["thread_count"] > 1) {
                $replymsgids[] = $id;
            }
        }
    }

    return $messages;
}

/**
 * Get a list of recent messages for all forums for which the user has
 * read permission, for a particular forum, for a list of forums or for a
 * particular thread. Optionally, only top level thread messages can be
 * retrieved.
 *
 * The original version of this function came from Jim Winstead of mysql.com
 *
 * @param $count        - Limit the number of returned messages to this number.
 * @param $forum_id     - A forum_id or array of forum_ids.
 * @param $thread       - A thread id.
 * @param $threads_only - If set to TRUE, only the top message from each
 *                        thread is returned.
 *
 * @return $messages    - An array of recent messages, indexed by message_id.
 *                        One special key "users" is set too. This one
 *                        contains an array of all involved user_ids.
 */
function phorum_db_get_recent_messages($count, $forum_id = 0, $thread = 0, $threads_only = FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($count, "int");
    settype($thread, "int");
    settype($threads_only, "bool");
    phorum_db_sanitize_mixed($forum_id, "int");

    $messages = array();
    $allowed_forums = array();

    // We need to differentiate on which key to use.
    if ($thread) {
        $use_key = 'thread_message';
    } else {
        $use_key = 'post_count';
    }

    $sql = "SELECT  {$PHORUM['message_table']}.*
            FROM    {$PHORUM['message_table']}
            USE     KEY ($use_key)
            WHERE   status=".PHORUM_STATUS_APPROVED;

    // We have to check what forums the current user can read first.
    // Even if $thread is passed, we have to make sure that the user
    // can read the containing forum.
    // First case: any forum.
    if ($forum_id <= 0)
    {
        $allowed_forums = phorum_user_access_list(PHORUM_USER_ALLOW_READ);

        // If the user is not allowed to see any forum,
        // then return the emtpy $messages;
        if (empty($allowed_forums)) return $messages;
    }
    // Second case: a specified array of forum_ids.
    elseif (is_array($forum_id))
    {
        // Check access for each forum separately.
        foreach ($forum_id as $id) {
            if (phorum_user_access_allowed(PHORUM_USER_ALLOW_READ, $id)) {
                $allowed_forums[]=$id;
            }
        }

        // If the user is not allowed to see any forum,
        // then return the emtpy $messages;
        if (empty($allowed_forums)) return $messages;
    }
    // Third case: a single specified forum_id.
    else
    {
        // A quick one-step way for returning if the user does not have
        // read access for the forum_id. This is the quickest route.
        if (!phorum_user_access_allowed(PHORUM_USER_ALLOW_READ, $forum_id)) {
            return $messages;
        }
    }

    if (count($allowed_forums)) {
        $sql .= " AND forum_id IN (".implode(",", $allowed_forums).")";
    } else {
        $sql .= " AND forum_id=$forum_id";
    }

    if ($thread){
        $sql.=" AND thread=$thread";
    }

    if($threads_only) {
        $sql .= " AND parent_id = 0 ORDER BY thread DESC";
    } else {
        $sql .= " ORDER BY message_id DESC";
    }

    if ($count){
        $sql .= " LIMIT $count";
    }

    // Retrieve matching messages from the database.
    $messages = phorum_db_interact(DB_RETURN_ASSOC, $sql, "message_id");

    // Post processing of received messages.
    $messages["users"] = array();
    foreach ($messages as $id => $message)
    {
        // Unpack the message meta data.
        $messages[$id]["meta"] = empty($message["meta"])
                               ? array()
                               : unserialize($message["meta"]);

        // Collect all involved users.
        if ($message["user_id"]) {
            $messages["users"][$message["user_id"]] = $message["user_id"];
        }
    }

    return $messages;
}

/**
 * Get a list of messages which have not yet been approved by a moderator.
 *
 * NOTE: ALL dates must be returned as Unix timestamps
 *
 * @param $forum_id     - The forum id to work with or NULL in case all
 *                        forums have to be searched. You can also pass an
 *                        array of forum ids.
 * @param $on_hold_only - Only take into account messages which have to
 *                        be approved directly after posting. Do not include
 *                        messages which were hidden by a moderator.
 * @param $moddays      - Limit the search to the last $moddays number of days.
 *
 * @return $messages    - An array of messages, indexed by message id.
 */
function phorum_db_get_unapproved_list($forum_id = NULL, $on_hold_only=FALSE, $moddays=0, $countonly = FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($on_hold_only, "bool");
    settype($moddays, "int");
    settype($countonly, "bool");
    phorum_db_sanitize_mixed($forum_id, "int");

    // Select a message count or full message records?
    $sql = "SELECT " . ($countonly ? "count(*) " : "* ") .
           "FROM " . $PHORUM["message_table"] . " WHERE ";

    if (is_array($forum_id)) {
        $sql .= "forum_id IN (" . implode(",", $forum_id) . ") AND ";
    } elseif (! is_null($forum_id)){
        $sql .= "forum_id = $forum_id AND ";
    }

    if($moddays > 0) {
        $checktime = time() - (86400*$moddays);
        $sql .= " datestamp > $checktime AND";
    }

    if($on_hold_only){
        $sql .= " status=".PHORUM_STATUS_HOLD;
    } else {
        // Use an UNION for speed. This is much faster than using
        // a (status=X or status=Y) query.
        $sql = "($sql status=".PHORUM_STATUS_HOLD.") UNION " .
               "($sql status=".PHORUM_STATUS_HIDDEN.")";
    }

    if (!$countonly) {
        $sql .=" ORDER BY thread, message_id";
    }

    // Retrieve and return data for counting unapproved messages.
    if ($countonly) {
        $count_per_status = phorum_db_interact(DB_RETURN_ROWS, $sql);
        $sum = 0;
        foreach ($count_per_status as $count) $sum += $count[0];
        return $sum;
    }

    // Retrieve unapproved messages.
    $messages = phorum_db_interact(DB_RETURN_ASSOC, $sql, "message_id");

    // Post processing of received messages.
    foreach ($messages as $id => $message) {
        $messages[$id]["meta"] = empty($message["meta"])
                               ? array()
                               : unserialize($message["meta"]);
    }

    return $messages;
}

/**
 * Store a new message in the database.
 *
 * The message will not be posted if it is a duplicate and if
 * $PHORUM['check_duplicate'] is set.
 *
 * The $message is passed by reference and in case the function completes
 * successfully, the "message_id" index will be set to the new value.
 * If the "thread" index is set to zero, a new thread will be started and the
 * "thread" index will be filled with the new thread id upon return.
 *
 * @param $message - The message to post. This is an array, which should
 *                   contain the following fields:
 *                   forum_id, thread, parent_id, author, subject, email,
 *                   ip, user_id, moderator_post, status, sort, msgid,
 *                   body, closed. Additionally, the following optional
 *                   fields can be set: meta, modifystamp, viewcount.
 * @param $convert - True in case the message is being inserted by
 *                   a database conversion script. This will let you set
 *                   the datestamp and message_id of the message from the
 *                   $message data. Also, the duplicate message check will
 *                   be fully skipped.
 *
 * @return         - TRUE on success, FALSE on failure, 0 on duplicate posts.
 *                   TODO: can this ever return FALSE?
 */
function phorum_db_post_message(&$message, $convert=FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];
    $table = $PHORUM["message_table"];

    settype($convert, "bool");

    $success = FALSE;

    foreach($message as $key => $value) {
        if (is_numeric($value) &&
            !in_array($key,$PHORUM['string_fields_message'])){
            $message[$key] = (int)$value;
        } elseif(is_array($value)) {
            $value = serialize($value);
            $message[$key] = phorum_db_interact(DB_RETURN_QUOTED, $value);
        } else{
            $message[$key] = phorum_db_interact(DB_RETURN_QUOTED, $value);
        }
    }

    // When converting messages, the post time should be in the message.
    $NOW = $convert ? $message['datestamp'] : time();

    // Check for duplicate posting of messages, unless we are converting a db.
    if (isset($PHORUM['check_duplicate']) && $PHORUM['check_duplicate'] && !$convert) {
        // Check for duplicate messages in the last hour.
        $check_timestamp = $NOW - 3600;
        $sql = "SELECT message_id
                FROM   $table
                WHERE  forum_id  = {$message['forum_id']} AND
                       author    ='{$message['author']}' AND
                       subject   ='{$message['subject']}' AND
                       body      ='{$message['body']}' AND
                       datestamp > $check_timestamp";

        // Return 0 if at least one message can be found.
        if (phorum_db_interact(DB_RETURN_ROWCOUNT, $sql) > 0) return 0;
    }

    // The meta field is optional.
    $metaval = isset($message["meta"]) ? ",meta='{$message['meta']}'" : "";

    $sql = "INSERT INTO $table
            SET    forum_id       = {$message['forum_id']},
                   datestamp      = $NOW,
                   thread         = {$message['thread']},
                   parent_id      = {$message['parent_id']},
                   author         = '{$message['author']}',
                   subject        = '{$message['subject']}',
                   email          = '{$message['email']}',
                   ip             = '{$message['ip']}',
                   user_id        = {$message['user_id']},
                   moderator_post = {$message['moderator_post']},
                   status         = {$message['status']},
                   sort           = {$message['sort']},
                   msgid          = '{$message['msgid']}',
                   body           = '{$message['body']}',
                   closed         = {$message['closed']}
                   $metaval";

    // When handling a conversion, the message_id can be set.
    if ($convert && isset($message['message_id'])) {
        $sql .= ",message_id=".$message['message_id'];
    }

    if(isset($message['modifystamp'])) {
        $sql.=",modifystamp=".$message['modifystamp'];
    }

    if(isset($message['viewcount'])) {
        $sql.=",viewcount=".$message['viewcount'];
    }

    // Insert the message and get the new message_id.
    $newid = phorum_db_interact(DB_RETURN_NEWID, $sql);

    if (!empty($newid))
    {
        $message["message_id"] = $newid;
        $message["datestamp"]  = $NOW;

        // Updates for thread starter messages.
        if ($message["thread"] == 0)
        {
            phorum_db_interact(
                DB_RETURN_RES,
                "UPDATE $table
                 SET    thread=$newid
                 WHERE  message_id=$newid"
            );

            $message["thread"] = $newid;
        }

        // Full text searching updates.
        if (isset($PHORUM["DBCONFIG"]["mysql_use_ft"]) &&
            $PHORUM["DBCONFIG"]["mysql_use_ft"])
        {
            $search_text = $message["author"]  .' | '.
                           $message["subject"] .' | '.
                           $message["body"];

            phorum_db_interact(
                DB_RETURN_RES,
                "INSERT DELAYED INTO {$PHORUM['search_table']}
                 SET    message_id  = {$message['message_id']},
                        forum_id    = {$message['forum_id']},
                        search_text = '$search_text'"
            );
        }

        $success = TRUE;
    }

    return $success;
}

/**
 * Update a message in the database.
 *
 * Note: an update of the full text searching database is only handled
 * if all fields that we incorporate in full text searching (author,
 * subject and body) are in the update fields. If one of the fields is
 * provided, without providing the other two, then changes in the field
 * will not reflect in the full text searching info.
 *
 * @param $message_id - The message_id of the message to update.
 * @param $message    - An array containing the data for the message fields
 *                      that have to be updated. You can pass as many or
 *                      as few message fields as you wish to update.
 */
function phorum_db_update_message($message_id, $message)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($message_id, "int");

    if (count($message) == 0) trigger_error(
        "\$message cannot be empty in phorum_update_message()",
        E_USER_ERROR
    );

    foreach ($message as $field => $value)
    {
        if (phorum_db_validate_field($field))
        {
            if (is_numeric($value) &&
                !in_array($field, $PHORUM['string_fields_message'])){
                $fields[] = "$field=$value";
            } elseif (is_array($value)) {
                $value = phorum_db_interact(DB_RETURN_QUOTED,serialize($value));
                $message[$field] = $value;
                $fields[] = "$field='$value'";
            } else {
                $value = phorum_db_interact(DB_RETURN_QUOTED, $value);
                $message[$field] = $value;
                $fields[] = "$field='$value'";
            }
        }
    }

    phorum_db_interact(
        DB_RETURN_RES,
        "UPDATE {$PHORUM['message_table']}
        SET " . implode(", ", $fields) . "
        WHERE message_id=$message_id"
    );

    // Full text searching updates.
    if (isset($message["author"]) &&
        isset($message["subject"]) &&
        isset($message["body"])) {

        $search_text = $message["author"]  .' | '.
                       $message["subject"] .' | '.
                       $message["body"];

        phorum_db_interact(
            DB_RETURN_RES,
            "REPLACE DELAYED INTO {$PHORUM['search_table']}
             SET     message_id  = {$message_id},
                     forum_id    = {$message['forum_id']},
                     search_text = '$search_text'"
        );
    }
}

/**
 * Delete a message or a message tree from the database.
 *
 * @param $message_id - The message_id of the message which should be deleted.
 * @param $mode       - The mode of deletion. This is one of:
 *                      PHORUM_DELETE_MESSAGE  Delete a message and reconnect
 *                                             its reply messages to the
 *                                             parent of the deleted message.
 *                      PHORUM_DELETE_TREE     Delete a message and all its
 *                                             reply messages.
 */
function phorum_db_delete_message($message_id, $mode = PHORUM_DELETE_MESSAGE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($message_id, "int");
    settype($mode, "int");

    // Find the info for the message that has to be deleted.
    $recs = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT forum_id, message_id, thread, parent_id
         FROM   {$PHORUM['message_table']}
         WHERE  message_id = $message_id"
    );
    if (empty($recs)) trigger_error(
        "No message found for message_id $message_id", E_USER_ERROR
    );
    $rec = $recs[0];

    // Find all message_ids that have to be deleted, based on the mode.
    if ($mode == PHORUM_DELETE_TREE) {
        $mids = phorum_db_get_messagetree($message_id, $rec['forum_id']);
        $where = "message_id IN ($mids)";
        $mids = explode(",", $mids);
    }else{
        $mids = array($message_id);
        $where = "message_id = $message_id";
    }

    // First, the messages are unapproved, so replies will not get posted
    // during the time that we need for deleting them. There is still a
    // race condition here, but this already makes things quite reliable.
    phorum_db_interact(
        DB_RETURN_RES,
        "UPDATE {$PHORUM['message_table']}
         SET    status=".PHORUM_STATUS_HOLD."
         WHERE  $where"
    );

    $thread = $rec['thread'];

    // Change reply messages to point to the parent of the deleted message.
    if ($mode == PHORUM_DELETE_MESSAGE)
    {
        // The forum_id is in here for speeding up the query
        // (with the forum_id a lookup key will be used).
        phorum_db_interact(
            DB_RETURN_RES,
            "UPDATE {$PHORUM['message_table']}
             SET    parent_id = {$rec['parent_id']}
             WHERE  forum_id  = {$rec['forum_id']} AND
                    parent_id = {$rec['message_id']}"
        );
    }

    // Delete the messages.
    phorum_db_interact(
        DB_RETURN_RES,
        "DELETE FROM {$PHORUM['message_table']}
         WHERE $where"
    );

    // Full text searching updates.
    phorum_db_interact(
        DB_RETURN_RES,
        "DELETE FROM {$PHORUM['search_table']}
         WHERE $where"
    );

    // It kind of sucks to have this here, but it is the best way
    // to ensure that thread info gets updated if messages are deleted.
    // Leave this include down here, so it is included conditionally.
    include_once("./include/thread_info.php");
    phorum_update_thread_info($thread);

    // We need to delete the subscriptions for the thread too.
    phorum_db_interact(
        DB_RETURN_RES,
        "DELETE FROM {$PHORUM['subscribers_table']}
         WHERE forum_id > 0 AND thread=$thread"
    );

    // This function will be slow with a lot of messages.
    phorum_db_update_forum_stats(TRUE);

    return $mids;
}

/**
 * Build a tree of all child (reply) messages below a message_id.
 *
 * @param $message_id - The message_id for which to build the message tree.
 * @param $forum_id   - The forum_id for the message.
 *
 * @return $tree      - A string containing a comma separated list
 *                      of child message_ids for the message_id.
 */
function phorum_db_get_messagetree($message_id, $forum_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($message_id, "int");
    settype($forum_id, "int");

    // Find all children for the provided message_id.
    $child_ids = phorum_db_interact(
        DB_RETURN_ROWS,
        "SELECT message_id
         FROM {$PHORUM['message_table']}
         WHERE forum_id  = $forum_id AND
               parent_id = $message_id"
    );

    // Recursively build the message tree.
    $tree = "$message_id";
    foreach ($child_ids as $child_id) {
        $tree .= "," . phorum_db_get_messagetree($child_id[0], $forum_id);
    }

    return $tree;
}

/**
 * Retrieve message(s) from the messages table by comparing value(s)
 * for a specified field in that table.
 *
 * You can provide either a single value or an array of values to search
 * for. If a single value is provided, then the function will return the
 * first matching message in the table. If an array of values is provided,
 * the function will return all matching messages in an array.
 *
 * @param $value           - The value that you want to search on in the
 *                           messages table. This can be either a single
 *                           value or an array of values.
 * @param $field           - The message field (database column) to search on.
 * @param $ignore_forum_id - By default, this function will only search for
 *                           messages within the active forum (as defined
 *                           by $PHORUM["forum_id"). By setting this
 *                           parameter to a true value, the function will
 *                           search in any forum.
 *
 * @return                 - Either a single message or an array of
 *                           messages (indexed by message_id), depending on
 *                           the $value parameter.
 */
function phorum_db_get_message($value, $field="message_id", $ignore_forum_id=FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    phorum_db_sanitize_mixed($value, "string");
    settype($ignore_forum_id, "bool");
    if (!phorum_db_validate_field($field)) raise_error(
        "phorum_db_get_message(): Illegal database field \"" .
        htmlspecialchars($field) . "\"", E_USER_ERROR
    );

    $forum_id_check = "";
    if (!$ignore_forum_id && !empty($PHORUM["forum_id"])) {
        $forum_id_check = "forum_id = {$PHORUM['forum_id']} AND ";
    }

    if (is_array($value)) {
        $multiple = TRUE;
        $checkvar = "$field IN ('".implode("','",$value)."')";
        $limit = "";
    } else {
        $multiple=FALSE;
        $checkvar = "$field='$value'";
        $limit = "LIMIT 1";
    }

    $return = $multiple ? array() : NULL;

    $messages = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT *
         FROM   {$PHORUM['message_table']}
         WHERE  $forum_id_check $checkvar
         $limit"
    );

    foreach ($messages as $message)
    {
        $message["meta"] = empty($message["meta"])
                         ? array()
                         : unserialize($message["meta"]);

        if (! $multiple) {
            $return = $message;
            break;
        }

        $return[$message["message_id"]] = $message;
    }

    return $return;
}

/**
 * Retrieve messages from a specific thread.
 *
 * @param $thread           - The id of the thread.
 * @param $page             - A page offset (based on the configured
 *                            read_length) starting with 1. All messages
 *                            are returned in case $page is 0.
 * @param $ignore_mod_perms - If this parameter is set to a true value, then
 *                            the function will return hidden messages, even
 *                            if the active Phorum user is not a moderator.
 *
 * @return $messages        - An array of messages, indexed by message_id.
 *                            One special key "users" is set too. This one
 *                            contains an array of all involved user_ids.
 */
function phorum_db_get_messages($thread, $page=0, $ignore_mod_perms=FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($thread, "int");
    settype($page, "int");
    settype($ignore_mod_perms, "int");

    // Check if the forum_id has to be checked.
    $forum_id_check = "";
    if (!empty($PHORUM["forum_id"])) {
        $forum_id_check = "forum_id = {$PHORUM['forum_id']} AND";
    }

    // Determine if not approved messages should be displayed.
    $approvedval = "";
    if (!$ignore_mod_perms &&
        !phorum_user_access_allowed(PHORUM_USER_ALLOW_MODERATE_MESSAGES)) {
        $approvedval = "AND status =".PHORUM_STATUS_APPROVED;
    }

    $sql = "SELECT *
            FROM   {$PHORUM['message_table']}
            WHERE  $forum_id_check
                   thread = $thread
                   $approvedval
            ORDER  BY message_id";

    if ($page > 0) {
       // Handle the page offset.
       $start = $PHORUM["read_length"] * ($page-1);
       $sql .= " LIMIT $start,".$PHORUM["read_length"];
    } else {
       // Handle reverse threading. This is only done if $page is 0.
       // In that case, the messages for threaded read are retrieved.
       if (isset($PHORUM["reverse_threading"]) && $PHORUM["reverse_threading"])
           $sql.=" desc";
    }

    $messages = phorum_db_interact(DB_RETURN_ASSOC, $sql, "message_id");
    $messages["users"] = array();

    foreach ($messages as $id => $message)
    {
        // Unpack the message meta data.
        $messages[$id]["meta"] = empty($message["meta"])
                               ? array()
                               : unserialize($message["meta"]);

        // Collect all involved users.
        if ($message["user_id"]) {
            $messages["users"][$message["user_id"]] = $message["user_id"];
        }
    }

    // Always include the thread starter message in the return data.
    // It might not be in the messagelist if a page offset is used
    // (since the thread starter is only on the first page).
    if (count($messages) && !isset($messages[$thread]))
    {
        $starter = phorum_db_interact(
            DB_RETURN_ASSOC,
            "SELECT *
             FROM   {$PHORUM['message_table']}
             WHERE  $forum_id_check
                    message_id = $thread
                    $approvedval"
        );

        if (isset($starter[0])) {
            // Unpack the message meta data.
            $starter[0]["meta"] = empty($starter[0]["meta"])
                                ? array()
                                : unserialize($starter[0]["meta"]);

            $messages[$thread] = $starter[0];
        }
    }

    return $messages;
}

/**
 * Get the index of a message in a thread.
 *
 * @param $thread     - The thread id.
 * @param $message_id - The message id.
 *
 * @return $index     - The index of the message, starting with 0.
 */
function phorum_db_get_message_index($thread=0, $message_id=0)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // check for valid values
    if(empty($thread) || empty($message_id)) {
        return 0;
    }

    settype($thread, "int");
    settype($message_id, "int");

    $forum_id_check = "";
    if (!empty($PHORUM["forum_id"])){
        $forum_id_check = "forum_id = {$PHORUM['forum_id']} AND";
    }

    $approvedval = "";
    if(!phorum_user_access_allowed(PHORUM_USER_ALLOW_MODERATE_MESSAGES)) {
        $approvedval="AND status =".PHORUM_STATUS_APPROVED;
    }

    $index = phorum_db_interact(
        DB_RETURN_VALUE,
        "SELECT count(*)
         FROM   {$PHORUM['message_table']}
         WHERE  $forum_id_check
                thread = $thread
                $approvedval AND
                message_id <= $message_id
         ORDER  BY message_id"
    );

    return $index;
}

/**
 * Search the database using the provided search criteria and return
 * an array containing the total count of matches and the visible
 * messages based on the page $offset and $length.
 *
 * @param $search      - The query to search on.
 * @param $offset      - The result page offset starting with 0.
 * @param $length      - The result page length (nr. of results per page).
 * @param $type        - The type of search. This can be one of:
 *                       AUTHOR   search for an author name
 *                       USER_ID  search for an author id
 *                       ALL      search on all of the words
 *                       ANY      search on any of the words
 *                       PHRASE   search for an exact phrase
 * @param $days        - The number of days to go back in the database for
 *                       searching (last [x] days) or zero to search within
 *                       all dates.
 * @param $forum       - The forum restriction. This can be one of:
 *                       THISONE  search only in the active forum
 *                       ALL      search in any of the forums
 *
 * @return $return     - An array containing two fields: "count" contains
 *                       the total number of matching messages and
 *                       "rows" contains the messages that are visible,
 *                       based on the page $offset and page $length.
 *                       The messages are indexed by message_id.
 */
function phorum_db_search($search, $offset, $length, $type, $days, $forum)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $fulltext_mode = isset($PHORUM["DBCONFIG"]["mysql_use_ft"]) &&
                     $PHORUM["DBCONFIG"]["mysql_use_ft"];

    settype($offset, "int");
    settype($length, "int");
    settype($days, "int");

    // These variable are used to hold information about matching
    // messages during the search query processing.
    $message_ids = array();  // ids of messages that have to be returned
    $total_count = 0;        // the total amount of matching messages

    // Initialize the return data.
    $return = array("count" => 0, "rows" => array());

    // Check what forums the current user can read.
    $allowed_forums = phorum_user_access_list(PHORUM_USER_ALLOW_READ);

    // If the user is not allowed to search any forum or the current
    // active forum, then return the emtpy search results array.
    if (empty($allowed_forums) ||
        ($PHORUM['forum_id']>0 &&
         !in_array($PHORUM['forum_id'], $allowed_forums))) return $return;

    // For spreading search results over multiple pages.
    $start = $offset * $PHORUM["list_length"];

    // Prepare forum_id restriction.
    if ($forum == "ALL") {
        $forum_where = " AND forum_id IN (".implode(",", $allowed_forums).")";
    } elseif ($forum == "THISONE") {
        $forum_where=" AND forum_id = {$PHORUM['forum_id']}";
    } else raise_error(
        "phorum_db_search(): Illegal forum specification \"" .
        htmlspecialchars($type) . "\"", E_USER_ERROR
    );

    // Prepare day limit restriction.
    if ($days>0) {
        $ts = time() - 86400*$days;
        $days_where = " AND datestamp >= $ts";
    } else {
        $days_where = '';
    }

    // Prepare the search terms.
    switch ($type)
    {
        // Search for an author's name.
        case "AUTHOR":
            $terms = phorum_db_interact(DB_RETURN_QUOTED, $search);
            break;

        // Search for an author's user_id.
        case "USER_ID":
            $terms = (int)$search;
            break;

        // Search for an exact phrase.
        case "PHRASE":
            // The code below will handle correct SQL quoting.
            $terms = $fulltext_mode ? array('"'.$search.'"') : array($search);
            break;

        // Search for a query string, which can contain quoted phrases,
        // possibly prefixed by a "-" for negating the phrase.
        case "ALL":
        case "ANY":
            $quoted_terms = array();

            // First, pull out all the double quoted strings
            // (e.g. '"iMac DV" or -"iMac DV"')
            if (strstr($search, '"' )) {
                preg_match_all('/-*"(.*?)"/', $search, $match);
                $search = preg_replace('/-*".*?"/', '', $search);
                $quoted_terms = $match[0];
            }

            // Finally, pull out the rest of the words in the string.
            $terms = preg_split( "/\s+/", $search, 0, PREG_SPLIT_NO_EMPTY );

            // Merge them all together.
            $terms = array_merge($terms, $quoted_terms);
            break;

        // Safety net.
        default:
            raise_error("phorum_db_search(): Illegal search type \"" .
                        htmlspecialchars($type) . "\"", E_USER_ERROR);
    }

    // --------------------------
    // HANDLE FULL TEXT MATCHING
    // --------------------------

    if ($fulltext_mode)
    {
        // First step in the full text searching is generating a
        // temporary table which holds all message_ids for messages
        // that match the search parameters.

        if ($type == "AUTHOR" || $type == "USER_ID")
        {
            $infix = $type == "AUTHOR" ? "author" : "userid";
            $id_table = "{$PHORUM['search_table']}_{$infix}_".md5(microtime());
            $field = $type=="AUTHOR" ? "author" : "user_id";

            phorum_db_interact(
                DB_RETURN_RES,
                "CREATE TEMPORARY TABLE $id_table (
                     KEY(message_id)
                 ) ENGINE=HEAP
                 SELECT message_id
                 FROM   {$PHORUM['message_table']}
                 WHERE  $field = '$terms'
                        $forum_where
                        $days_where"
            );

        } elseif (count($terms)) {

            $use_key = "";
            $against = "";
            $extra_where = "";

            /* Using this code on larger forums has shown to make the
               search faster. However, on smaller forums, it does not
               appear to help and in fact appears to slow down searches.

               TODO Maybe use $PHORUM["message_count"] for dynamically
               TODO including this code in the query?

            if ($days) {
                $min_time = time() - 86400*$days;
                $sql = "SELECT MIN(message_id)
                        FROM {$PHORUM['message_table']}
                        WHERE datestamp >= $min_time";
                $min_id = phorum_db_interact(DB_RETURN_VALUE, $sql);
                $use_key=" USE KEY (primary)";
                $extra_where="AND message_id >= $min_id";
            }
            */

            $id_table = $PHORUM['search_table']."_terms_".md5(microtime());

            if ($type=="ALL" && count($terms)>1) {
                foreach ($terms as $term) {
                    if($term[0] == "+" || $term[0] == "-"){
                        $against .= phorum_db_interact(DB_RETURN_QUOTED, $term)." ";
                    } else {
                        $against .= "+".phorum_db_interact(DB_RETURN_QUOTED, $term)." ";
                    }
                }
                $against = trim($against);
            } else {
                $against = phorum_db_interact(DB_RETURN_QUOTED, implode(" ", $terms));
            }

            phorum_db_interact(
                DB_RETURN_RES,
                "CREATE TEMPORARY TABLE $id_table (
                        key(message_id)
                 ) ENGINE=HEAP
                 SELECT message_id
                 FROM   {$PHORUM['search_table']} $use_key
                 WHERE  MATCH (search_text)
                        AGAINST ('$against' IN BOOLEAN MODE)
                        $extra_where"
            );
        }

        // Second step in the full text searching is creating a temporary
        // table which holds the forum_id, status, datestamp and message_id
        // for all message_ids that are now in the id table. After that,
        // the number of found messages and the set of message_ids that
        // has to be displayed  (based on datestamp, page offset and page
        // length) is retrieved from this temporary table.

        if (isset($id_table))
        {
            // Create the temporary table.
            $table = $PHORUM['search_table']."_".md5(microtime());
            phorum_db_interact(
                DB_RETURN_RES,
                "CREATE TEMPORARY TABLE $table (
                    key (forum_id, status, datestamp)
                 ) ENGINE=HEAP
                 SELECT {$PHORUM['message_table']}.message_id,
                        {$PHORUM['message_table']}.datestamp,
                        status,
                        forum_id
                 FROM   {$PHORUM['message_table']}
                        INNER JOIN $id_table USING (message_id)
                 WHERE  status = ".PHORUM_STATUS_APPROVED."
                        $forum_where
                        $days_where"
            );

            // Retrieve the total number of search results.
            $total_count = phorum_db_interact(
                DB_RETURN_VALUE,
                "SELECT count(*)
                 FROM   $table"
            );

            // Retrieve the message_ids for the offset/length.
            $message_ids = phorum_db_interact(
                DB_RETURN_ROWS,
                "SELECT message_id
                 FROM   $table
                 ORDER  BY datestamp DESC
                 LIMIT  $start, $length",
                0 // keyfield 0 is the message_id
            );
        }
    }

    // ----------------------
    // HANDLE BASIC MATCHING
    // ----------------------

    else
    {
        if ($type == "AUTHOR" || $type == "USER_ID")
        {
            $field = $type=="AUTHOR" ? "author" : "user_id";
            $value = $type=="AUTHOR" ? "'$terms'" : $terms;
            $sql_core = "FROM {$PHORUM['message_table']}
                         WHERE $field = $value
                               $forum_where
                               $days_where";

            // Retrieve the total number of search results.
            $total_count = phorum_db_interact(
                DB_RETURN_VALUE,
                "SELECT count(*) $sql_core"
            );

            // Retrieve the message_ids for the offset/length.
            $message_ids = phorum_db_interact(
                DB_RETURN_ROWS,
                "SELECT message_id
                        $sql_core
                 ORDER  BY datestamp DESC
                 LIMIT  $start, $length",
                0 // keyfield 0 is the message_id
            );

        } elseif (count($terms)) {

            // Quote the search terms for use in SQL.
            foreach ($terms as $id => $term) {
                $terms[$id] = phorum_db_interact(DB_RETURN_QUOTED, $term);
            }

            // Prepare the SQL search clause. A match will be
            // performed against the concatenated author, subject and body
            // fields. This way, the query string will be matched against
            // all three fields.
            $conj = $type == "ALL" ? "AND" : "OR";
            $concat = "concat(author, subject, body)";
            $clause = "( $concat LIKE '%" .
                       implode("%' $conj $concat LIKE '%", $terms) .
                       "%' )";

            // Retrieve the total number of search results.
            $total_count = phorum_db_interact(
                DB_RETURN_VALUE,
                "SELECT count(*)
                 FROM {$PHORUM['message_table']}
                 WHERE status = ".PHORUM_STATUS_APPROVED." AND
                       $clause
                       $forum_where
                       $days_where"
            );

            // Retrieve the message_ids for the offset/length.
            $message_ids = phorum_db_interact(
                DB_RETURN_ROWS,
                "SELECT message_id
                 FROM   {$PHORUM['message_table']}
                 WHERE  status = ".PHORUM_STATUS_APPROVED." AND
                        $clause
                        $forum_where
                        $days_where
                 ORDER  BY datestamp DESC
                 LIMIT  $start, $length",
                0 // keyfield 0 is the message_id
            );
        }
    }

    // -------------------
    // BUILD RETURN ARRAY
    // -------------------

    // If matching message_ids were found, then retrieve all
    // messages for those message_ids from the database. Add those
    // messages to the $return array.

    if (count($message_ids))
    {
        $rows = phorum_db_interact(
            DB_RETURN_ASSOC,
            "SELECT *
             FROM   {$PHORUM['message_table']}
             WHERE  message_id IN (".implode(",", array_keys($message_ids)).")
             ORDER  BY datestamp DESC",
            "message_id"
        );

        $return["count"] = $total_count;
        $return["rows"]  = $rows;
    }

    return $return;
}

/**
 * Get the closest neighbour thread. What "neighbour" is, depends on the
 * float to top setting. If float to top is enabled, then the
 * modifystamp is used for comparison (so the time at which the last
 * message was posted to a thread). Otherwise, the thread id is used
 * (so the time at which a thread was started).
 *
 * @param $key       - The key value of the message for which the neighbour
 *                     must be returned. The key value is either the
 *                     modifystamp (if float to top is enabled) or the
 *                     thread id.
 * @param $direction - Either "older" or "newer".
 *
 * @return $thread   - The thread id for the requested neigbour thread or
 *                     0 (zero) if there's no neighbour available.
 */
function phorum_db_get_neighbour_thread($key, $direction)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($key, "int");

    $keyfield = ($PHORUM["float_to_top"]) ? "modifystamp" : "thread";

    switch ($direction) {
        case "newer": $compare = ">"; $orderdir = "ASC";  break;
        case "older": $compare = "<"; $orderdir = "DESC"; break;
        default:
            raise_error(
                "phorum_db_get_neighbour_thread(): " .
                "Illegal direction \"".htmlspecialchars($direction)."\"",
                E_USER_ERROR
            );
    }

    // If the current user is not a moderator for the forum, then
    // the neighbour message should be approved.
    $approvedval = "";
    if (!phorum_user_access_allowed(PHORUM_USER_ALLOW_MODERATE_MESSAGES)) {
        $approvedval = "AND status = ".PHORUM_STATUS_APPROVED;
    }

    // Select the neighbour from the database.
    $thread = phorum_db_interact(
        DB_RETURN_VALUE,
        "SELECT thread
         FROM   {$PHORUM['message_table']}
         WHERE  forum_id = {$PHORUM['forum_id']} AND
                parent_id = 0
                $approvedval AND
                $keyfield $compare $key
         ORDER  BY $keyfield $orderdir
         LIMIT  1"
    );

    return $thread;
}

/**
 * Get a list of forums. The forums which are returned can be filtered
 * through the function parameters. Note that only one parameter is
 * effective at a time.
 *
 * @param $forum_ids   - A single forum_id or an array of forum_ids for which
 *                       to retrieve the forum data. If this parameter is
 *                       0 (zero), then the $parent_id parameter will be
 *                       checked.
 * @param $parent_id   - Retrieve the forum data for all forums that have
 *                       their parent_id set to $parent_id. If this parameter
 *                       is NULL, then the $vroot parameter will be checked.
 * @param $vroot       - Retrieve the forum data for all forums that are in
 *                       the given $vroot. If this parameter is NULL, then the
 *                       $inherit_id parameter will be checked.
 * @param $inherit_id  - Retrieve the forum data for all forums that inherit
 *                       their settings from the forum with id $inherit_id.
 *
 * @return $forums     - An array of forums, indexed by forum_id.
 */
function phorum_db_get_forums($forum_ids = 0, $parent_id = NULL, $vroot = NULL, $inherit_id = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    phorum_db_sanitize_mixed($forum_ids, "int");
    if ($parent_id  !== NULL) settype($parent_id, "int");
    if ($vroot      !== NULL) settype($vroot, "int");
    if ($inherit_id !== NULL) settype($inherit_id, "int");

    // Backward compatibility: previously, -1 was used for $parent_id
    // instead of NULL for indicating "any parent_id".
    // TODO: go over the Phorum code and change the core calls to
    // TODO: phorum_db_get_forums() to use NULL and not -1.
    if ($parent_id !== NULL && $parent_id == -1) $parent_id = NULL;

    $where = '';
    if (!empty($forum_ids)){
        if (is_array($forum_ids)) {
            $where .= "forum_id IN (".implode(",", $forum_ids).")";
        } else {
            $where .= "forum_id = $forum_ids";
        }
    } elseif ($inherit_id !== NULL) {
        $where .= "inherit_id = $inherit_id";
        if(!defined("PHORUM_ADMIN")) $where.=" AND active=1";
    } elseif ($parent_id !== NULL) {
        $where .= "parent_id = $parent_id";
        if(!defined("PHORUM_ADMIN")) $where.=" AND active=1";
    } elseif($vroot !== NULL) {
        $where .= "vroot = $vroot";
    } else {
        $where .= "forum_id <> 0";
    }

    $forums = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT *
         FROM   {$PHORUM['forums_table']}
         WHERE  $where
         ORDER  BY display_order ASC, name",
        "forum_id"
    );

    return $forums;
}

/**
 * Update the forums stats. This function will update the thread count,
 * message count, sticky message count and last post timestamp for a forum.
 * The function can either process delta values for the stats (this is
 * the most friendly way of updating) or fill the stats from scratch by
 * querying the database for the correct value.
 *
 * When the forum stats are updated, the cache_version for the forum
 * will be raised by one. This will flag the cache system that cached
 * data for the forum has to be refreshed.
 *
 * @param $refresh             - If TRUE, the all stats will be filled from
 *                               scratch by querying the database.
 * @param $msg_count_change    - Delta for the message count or zero to
 *                               query the value from the database.
 * @param $timestamp           - The post time of the last message or zero to
 *                               query the value from the database.
 * @param $thread_count_change - Delta for the thread count or zero to
 *                               query the value from the database.
 * @param $sticky_count_change - Delta for the sticky message count or zero to
 *                               query the value from the database.
 */
function phorum_db_update_forum_stats($refresh=FALSE, $msg_count_change=0, $timestamp=0, $thread_count_change=0, $sticky_count_change=0)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($refresh, "bool");
    settype($msg_count_change, "int");
    settype($timestamp, "int");
    settype($thread_count_change, "int");
    settype($sticky_count_change, "int");

    // Always do a full refresh on small forums.
    if (isset($PHORUM["message_count"]) && $PHORUM["message_count"]<1000) {
        $refresh = TRUE;
    }

    if ($refresh || empty($msg_count_change)) {
        $message_count = phorum_db_interact(
            DB_RETURN_VALUE,
            "SELECT count(*)
             FROM   {$PHORUM['message_table']}
             WHERE  forum_id = {$PHORUM['forum_id']} AND
                    status   = ".PHORUM_STATUS_APPROVED
        );
    } else {
        $message_count = "message_count+$msg_count_change";
    }

    if ($refresh || empty($timestamp)) {
        $last_post_time = phorum_db_interact(
            DB_RETURN_VALUE,
            "SELECT max(modifystamp)
             FROM   {$PHORUM['message_table']}
             WHERE  status   = ".PHORUM_STATUS_APPROVED." AND
                    forum_id = {$PHORUM['forum_id']}"
        );
    } else {
        $last_post_time = $timestamp;
    }

    if ($refresh || empty($thread_count_change)) {
        $thread_count = phorum_db_interact(
            DB_RETURN_VALUE,
            "SELECT count(*)
             FROM   {$PHORUM['message_table']}
             WHERE  forum_id  = {$PHORUM['forum_id']} AND
                    parent_id = 0 AND
                    status    = ".PHORUM_STATUS_APPROVED
        );
    } else {
        $thread_count = "thread_count+$thread_count_change";
    }

    if ($refresh || empty($sticky_count_change)) {
        $sticky_count = phorum_db_interact(
            DB_RETURN_VALUE,
            "SELECT count(*)
             FROM   {$PHORUM['message_table']}
             WHERE  forum_id  = {$PHORUM['forum_id']} AND
                    sort      = ".PHORUM_SORT_STICKY." AND
                    parent_id = 0 AND
                    status    = ".PHORUM_STATUS_APPROVED
        );
    } else {
        $sticky_count = "sticky_count+$sticky_count_change";
    }

    phorum_db_interact(
        DB_RETURN_RES,
        "UPDATE {$PHORUM['forums_table']}
         SET    cache_version  = cache_version + 1,
                thread_count   = $thread_count,
                message_count  = $message_count,
                sticky_count   = $sticky_count,
                last_post_time = $last_post_time
         WHERE  forum_id = {$PHORUM['forum_id']}"
    );
}

/**
 * Move a thread to another forum.
 *
 * @param $thread_id - The id of the thread that has to be moved.
 * @param $toforum   - The id of the destination forum.
 */
function phorum_db_move_thread($thread_id, $toforum)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($thread_id, "int");
    settype($toforum, "int");

    if ($toforum > 0 && $thread_id > 0)
    {
        // Retrieve the messages from the thread, so we know for which
        // messages we have to update the newflags and search data below.
        $thread_messages = phorum_db_get_messages($thread_id);
        unset($thread_messages['users']);

        // All we have to do to move the thread to a different forum,
        // is update the forum_id for the messages in that thread.
        // Simple, isn't it?
        phorum_db_interact(
            DB_RETURN_RES,
            "UPDATE {$PHORUM['message_table']}
             SET    forum_id = $toforum
             WHERE  thread   = $thread_id"
        );

        // Update the stats for the source forum.
        phorum_db_update_forum_stats(TRUE);

        // Update the stats for the destination forum.
        $old_id = $GLOBALS["PHORUM"]["forum_id"];
        $GLOBALS["PHORUM"]["forum_id"] = $toforum;
        phorum_db_update_forum_stats(TRUE);
        $GLOBALS["PHORUM"]["forum_id"] = $old_id;

        // Move the newflags and search data to the destination forum.

        // TODO: in the following code, there are some flaws. The
        // TODO: newflags for the user that is moving the message
        // TODO: are used as the source for deciding what flags
        // TODO: to delete or move for all other users. This results
        // TODO: in strange newflag problems.
        // TODO:
        // TODO: This main issue here is that the newflags should be
        // TODO: handled separately for each user; no updates should be
        // TODO: based on the newflags for the active user. The current
        // TODO: algorithm will only make sure that the newflags will look
        // TODO: correct for that specific user. The problem is that we
        // TODO: do not yet have an idea on how to handle this with
        // TODO: enough performance.

        // First, gather information for doing the updates.
        $new_newflags = phorum_db_newflag_get_flags($toforum);
        $message_ids  = array();
        $delete_ids   = array();
        $search_ids   = array();
        foreach($thread_messages as $mid => $data)
        {
            // Gather information for updating the newflags.
            // Moving the newflag is only useful if it is higher than the
            // min_id of the target forum.
            if ($mid > $new_newflags['min_id'][$toforum]) {
                $message_ids[] = $mid;
            } else {
            // Other newflags can be deleted.
                $delete_ids[] = $mid;
            }

            // gather the information for updating the search table
            $search_ids[] = $mid;
        }

        // Move newflags.
        if(count($message_ids)) {
            phorum_db_newflag_update_forum($message_ids);
        }

        // Update subscriptions.
        if (count($message_ids)) {
            $ids_str = implode(",",$message_ids);
            phorum_db_interact(
                DB_RETURN_RES,
                "UPDATE {$PHORUM['subscribers_table']}
                 SET    forum_id = $toforum
                 WHERE  thread IN ($ids_str)"
            );
        }

        // Delete newflags.
        if(count($delete_ids)) {
            $ids_str=implode(",",$delete_ids);
            phorum_db_interact(
                DB_RETURN_RES,
                "DELETE FROM {$PHORUM['user_newflags_table']}
                 WHERE  message_id IN($ids_str)"
            );
        }

        // Update search data.
        if (count($search_ids)) {
            $ids_str = implode(",",$search_ids);
            phorum_db_interact(
                DB_RETURN_RES,
                "UPDATE {$PHORUM['search_table']}
                 SET    forum_id = $toforum
                 WHERE  message_id in ($ids_str)"
            );
        }
    }
}

/**
 * Close a thread for posting.
 *
 * @param $thread_id - The id of the thread that has to be closed.
 */
function phorum_db_close_thread($thread_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($thread_id, "int");

    if($thread_id > 0) {
        phorum_db_interact(
            DB_RETURN_RES,
            "UPDATE {$PHORUM['message_table']}
             SET    closed = 1
             WHERE  thread = $thread_id"
        );
    }
}

/**
 * (Re)open a thread for posting.
 *
 * @param $thread_id - The id of the thread that has to be opened.
 */
function phorum_db_reopen_thread($thread_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($thread_id, "int");

    if($thread_id > 0){
        phorum_db_interact(
            DB_RETURN_RES,
            "UPDATE {$PHORUM['message_table']}
             SET    closed = 0
             WHERE  thread = $thread_id"
        );
    }
}

/**
 * Create a forum.
 *
 * @param $forum     - The forum to create. This is an array, which should
 *                     contain the following fields: name, active, description,
 *                     template, folder_flag, parent_id, list_length_flat,
 *                     list_length_threaded, read_length, moderation,
 *                     threaded_list, threaded_read, float_to_top,
 *                     display_ip_address, allow_email_notify, language,
 *                     email_moderators, display_order, edit_post, pub_perms,
 *                     reg_perms.
 *
 * @return $forum_id - The forum_id that was assigned to the new forum.
 */
function phorum_db_add_forum($forum)
{
    $PHORUM = $GLOBALS["PHORUM"];

    foreach($forum as $key => $value)
    {
        if (phorum_db_validate_field($key))
        {
            if (is_numeric($value) &&
                !in_array($key,$PHORUM['string_fields_forum'])) {
                $value = (int)$value;
                $fields[] = "$key=$value";
            // TODO: Wouldn't it be better to have this one set to a real
            // TODO: NULL value from the script that calls this function?
            // TODO: If for some reason somebody wants to use the string
            // TODO: 'NULL' for a value (a geek setting up a Phorum
            // TODO: probably ;-), then strange things will happen.
            } elseif($value=="NULL") {
                $fields[] = "$key=$value";
            }else{
                $value = phorum_db_interact(DB_RETURN_QUOTED, $value);
                $fields[] = "$key='$value'";
            }
        }
    }

    $forum_id = phorum_db_interact(
        DB_RETURN_NEWID,
        "INSERT INTO {$PHORUM['forums_table']}
         SET " . implode(", ", $fields)
    );

    return $forum_id;
}

/**
 * Drop a forum and all of its messages.
 *
 * @param $forum_id - The id of the forum to drop.
 */
function phorum_db_drop_forum($forum_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($forum_id, "int");

    // These are the tables that hold forum related data.
    $tables = array (
        $PHORUM['message_table'],
        $PHORUM['user_permissions_table'],
        $PHORUM['user_newflags_table'],
        $PHORUM['subscribers_table'],
        $PHORUM['forum_group_xref_table'],
        $PHORUM['forums_table'],
        $PHORUM['banlist_table'],
        $PHORUM['search_table']
    );

    // Delete the data for the $forum_id from all those tables.
    foreach ($tables as $table) {
        phorum_db_interact(
            DB_RETURN_RES,
            "DELETE FROM $table
             WHERE forum_id = $forum_id"
        );
    }

    // Collect all orphin message attachment files from the database.
    // These are all messages that are linked to a message, but for which
    // the message_id does not exist in the message table (anymore).
    // This might catch some more messages than only the ones for the
    // deleted forum alone. That should never be a problem.
    $files = phorum_db_interact(
        DB_RETURN_ROWS,
        "SELECT file_id
         FROM   {$PHORUM['files_table']}
                LEFT JOIN {$PHORUM['message_table']}
                USING (message_id)
         WHERE  {$PHORUM['files_table']}.message_id > 0 AND
                link = '" . PHORUM_LINK_MESSAGE . "' AND
                {$PHORUM['message_table']}.message_id is NULL",
         0 // keyfield 0 is the file_id
    );

    // Delete all orphan message attachment files.
    if (!empty($files)) {
        phorum_db_interact(
            DB_RETURN_RES,
            "DELETE FROM {$PHORUM['files_table']}
             WHERE  file_id IN (".implode(",", array_keys($files)).")"
        );
    }
}

/**
 * Drop a forum folder. If the folder contains child forums or folders,
 * then the parent_id for those will be updated to point to the parent
 * of the folder that is being dropped.
 *
 * @param $forum_id - The id of the folder to drop.
 */
function phorum_db_drop_folder($forum_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($forum_id, "int");

    // See if the $forum_id is really a folder and find its
    // parent_id, which we can use to reattach children of the folder.
    $new_parent_id = phorum_db_interact(
        DB_RETURN_VALUE,
        "SELECT parent_id
         FROM   {$PHORUM['forums_table']}
         WHERE  forum_id = $forum_id AND
                folder_flag = 1"
    );
    if ($new_parent_id) raise_error(
        "phorum_db_drop_folder(): id $forum_id not found or not a folder",
        E_USER_ERROR
    );

    // Start with reattaching the folder's children to the new parent.
    phorum_db_interact(
        DB_RETURN_RES,
        "UPDATE {$PHORUM['forums_table']}
         SET    parent_id = $new_parent_id
         WHERE  parent_id = $forum_id"
    );

    // Now, drop the folder.
    phorum_db_interact(
        DB_RETURN_RES,
        "DELETE FROM {$PHORUM['forums_table']}
         WHERE  forum_id = $forum_id"
    );
}

/**
 * Update the settings for one or more forums.
 *
 * @param $forum    - The forum to update. This is an array, which should
 *                    contain at least the field "forum_id" to indicate what 
 *                    forum to update. Next to that, one or more of the other
 *                    fields from phorum_db_add_forum() can be used to 
 *                    describe changed values. The "forum_id" field can also
 *                    contain an array of forum_ids. By using that, the
 *                    settings can be updated for all the forum_ids at once.
 *
 * @return $success - True if all settings were stored successfully. This
 *                    function will always return TRUE, so we could
 *                    do without a return value. The return value is
 *                    here for backward compatibility.
 */
function phorum_db_update_forum($forum)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // Check if the forum_id is set.
    if (!isset($forum["forum_id"]) || empty($forum["forum_id"])) raise_error(
        "phorum_db_update_forum(): \$forum[\"forum_id\"] cannot be empty",
        E_USER_ERROR
    );

    phorum_db_sanitize_mixed($forum["forum_id"], "int");

    // See what forum(s) to update.
    if (is_array($forum["forum_id"])) {
        $forumwhere = "forum_id IN (".implode(",",$forum["forum_id"]).")";
    } else {
        $forumwhere = "forum_id = ".$forum["forum_id"];
    }
    unset($forum["forum_id"]);

    // Prepare the SQL code for updating the fields.
    $fields = array();
    foreach ($forum as $key => $value)
    {
        if (phorum_db_validate_field($key))
        {
            if (is_numeric($value) &&
                !in_array($key,$PHORUM['string_fields_forum'])) {
                $value = (int)$value;
                $fields[] = "$key=$value";
            } elseif($value=="NULL") {
                $fields[] = "$key=$value";
            } else {
                $value = phorum_db_interact(DB_RETURN_QUOTED, $value);
                $fields[] = "$key='$value'";
            }
        }
    }

    // Run the update, if there are fields to update.
    if (count($fields)) {
        phorum_db_interact(
            DB_RETURN_RES,
            "UPDATE {$PHORUM['forums_table']}
             SET "  .implode(", ", $fields) . " 
             WHERE  $forumwhere"
        );
    }

    return TRUE;
}

/**
 * Get all groups or one specific group.
 *
 * @param $group_id - The id of the group to retrieve. If this parameter is
 *                    0 (zero), then all groups will be returned.
 *
 * @return $group   - An array of groups, indexed by group_id.
 */
function phorum_db_get_groups($group_id=0)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($group_id, "int");

    $group_where = $group_id == 0 ? "" : "WHERE group_id = $group_id";

    // Retrieve the group(s) from the database.
    $groups = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT * FROM {$PHORUM['groups_table']} $group_where",
        "group_id"
    );

    // Retrieve the group permissions from the database.
    $perms = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT * FROM {$PHORUM['forum_group_xref_table']} $group_where"
    );

    // Add the permissions to the group(s).
    foreach ($groups as &$group) $group["permissions"] = array(); 
    foreach ($perms as $perm)
    {
        // Little safety net against orphin records (shouldn't happen).
        if (!isset($groups[$perm["group_id"]])) continue;

        $groups[$perm["group_id"]]["permissions"][$perm["forum_id"]] 
            = $perm["permission"];
    }

    return $groups;
}

/**
 * Get a list of members for a group or for a list of groups.
 *
 * If the member list for a list of groups is requested, any member matching
 * the specified status in any of the groups will be included in the return
 * array. There will be no group info in the return array however, so this
 * function cannot be used to retrieve a full group to member mapping. This
 * specific functionality is used from the Phorum scripts to see if there are
 * unapproved group members in any of the forums for which the active user
 * can moderate the group members.
 *
 * @param $group_id - A single group_id or an array of group_ids, for which
 *                    to retrieve the members.
 * @param $status   - A specific member status to look for. Defaults to all.
 *                    Possible statuses are:
 *                    PHORUM_USER_GROUP_SUSPENDED   (temporarily) deactivated
 *                    PHORUM_USER_GROUP_UNAPPROVED  on hold, not yet approved
 *                    PHORUM_USER_GROUP_APPROVED    active in the group
 *                    PHORUM_USER_GROUP_MODERATOR   active + member moderator
 *
 * @return $members - An array containing members for the specified group(s).
 *                    The array contains a simple mapping from user_id to
 *                    group permission. Note that the permission is only 
 *                    useful in case a single group was requested (see
 *                    the function description).
 */
function phorum_db_get_group_members($group_id, $status = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    phorum_db_sanitize_mixed($group_id, "int");
    if ($status !== NULL) settype($status, "int");

    if (is_array($group_id)){
        $group_where = "AND group_id IN (" . implode(",", $group_id) . ")";
    } else {
        $group_where = "AND group_id = $group_id";
    }

    if ($status !== NULL) {
        $status_where = "AND xref.status = $status";
    } else {
        $status_where = "";
    }

    // This join is only here so that the list of members comes out sorted.
    // If phorum_db_user_get() sorts results itself, this join can go away.
    $members = phorum_db_interact(
        DB_RETURN_ROWS,
        "SELECT xref.user_id,
                xref.status
         FROM   {$PHORUM['user_table']} AS users,
                {$PHORUM['user_group_xref_table']} AS xref
         WHERE  users.user_id = xref.user_id
                $group_where
                $status_where
         ORDER  BY username ASC",
         "user_id"
    );

    // The records are full rows, but we want a user_id -> status mapping.
    foreach($members as &$member) $member = $member[1];

    return $members;
}

/**
 * Add a group. This will merely create the group in the database. For
 * changing settings for the group, the function phorum_db_update_group()
 * has to be used.
 *
 * @param $group_name - The name to assign to the group.
 * @param $group_id   - The group id to assign to the group or 0 (zero) to
 *                      assign a new group id. Assigning a specific group
 *                      id is and should only be used by conversion scripts.
 *
 * @return $group_id  - The group id of the newly created group.
 */
function phorum_db_add_group($group_name, $group_id=0)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($group_id, "int");
    $group_name = phorum_db_interact(DB_RETURN_QUOTED, $group_name);

    $group_id = phorum_db_interact(
        DB_RETURN_NEWID,
        "INSERT INTO {$PHORUM['groups_table']}
         SET    name = '$group_name'" .
                ($group_id > 0 ? ", group_id = $group_id" : "")
    );

    return $group_id;
}

/**
 * Update the settings for a group.
 *
 * @param $group    - The group to update. This is an array, which should
 *                    contain at least the field "group_id" to indicate what 
 *                    group to update. Next to that, one or more of the
 *                    following fields can be used:
 *                    name         The name for the group.
 *                    open         This field determines how new members are
 *                                 added to the group. Options are:
 *                                 PHORUM_GROUP_CLOSED
 *                                     Only the administrator can add users
 *                                     to this group.
 *                                 PHORUM_GROUP_OPEN
 *                                     The group is open for membership
 *                                     requests by users and membership is
 *                                     assigned on request immediately.
 *                                 PHORUM_GROUP_REQUIRE_APPROVAL
 *                                     The group is open for membership
 *                                     requests by users, but membership has
 *                                     to be approved by an administrator or
 *                                     a user moderator for the group.
 *                    permissions  An array containing forum permissions
 *                                 for the group (key = forum_id and 
 *                                 value = permission value).
 *
 * @return $success - True if all settings were stored successfully. This
 *                    function will always return TRUE, so we could
 *                    do without a return value. The return value is
 *                    here for backward compatibility.
 */
function phorum_db_update_group($group)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // Check if the group_id is set.
    if (!isset($group["group_id"]) || empty($group["group_id"])) raise_error(
        "phorum_db_update_group(): \$group[\"group_id\"] cannot be empty",
        E_USER_ERROR
    );

    settype($group["group_id"], "int");
    $group_where = "group_id = " . $group['group_id'];

    // See what group fields we have to update.
    $fields = array();
    if (isset($group["name"])) {
        $fields = "name = " . 
                  phorum_db_interact(DB_RETURN_QUOTED, $phorum["name"]);
    }
    if (isset($group["open"])) {
        $fields = "open = " . (int)$group["open"];
    }

    // Update group fields.
    if (count($fields)) {
        phorum_db_interact(
            DB_RETURN_RES,
            "UPDATE {$PHORUM['groups_table']}
             SET ". implode(",", $fields) . "
             WHERE  $group_where"
        );
    }

    // Update the group permissions if requested.
    if (isset($group["permissions"]))
    {
        // First, all existing forum permissions for the group are deleted.
        phorum_db_interact(
            DB_RETURN_RES,
            "DELETE FROM {$PHORUM['forum_group_xref_table']}
             WHERE  $group_where"
        );

        // Second, all new permissions are inserted.
        foreach($group["permissions"] as $forum_id => $permission)
        {
            settype($forum_id, "int");
            settype($permission, "int");
            
            phorum_db_interact(
                DB_RETURN_RES,
                "INSERT INTO {$PHORUM['forum_group_xref_table']}
                 SET    group_id   = {$group['group_id']},
                        permission = $permission,
                        forum_id   = $forum_id"
            );
        }
    }

    return TRUE;
}

/**
 * Delete a group.
 *
 * @param $group_id - The id of the group to delete.
 */
function phorum_db_delete_group($group_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($group_id, "int");

    // These are the tables that hold group related data.
    $tables = array (
        $PHORUM['groups_table'],
        $PHORUM['user_group_xref_table'],
        $PHORUM['forum_group_xref_table']
    );

    // Delete the data for the $group_id from all those tables.
    foreach ($tables as $table) {
        phorum_db_interact(
            DB_RETURN_RES,
            "DELETE FROM $table
             WHERE group_id = $forum_id"
        );
    }
}

/**
 * Get a list of moderators for a particular forum.
 *
 * @param $forum_id      - The forum for which to retrieve the moderators.
 * @param $exclude_admin - If this parameter has a true value, then the
 *                         admin users are kept out of the list.
 * @param $for_email     - If this parameter has a true value, then a list
 *                         of moderators is created for sending moderator
 *                         mail messages. Moderators which have disabled the
 *                         moderation_email option will be excluded from 
 *                         the list in this case.
 *
 * @return $moderators   - An array of moderators. The keys are user_ids
 *                         and the values are email addresses.
 */
function phorum_db_user_get_moderators($forum_id, $exclude_admin=FALSE, $for_email=FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($forum_id, "int");
    settype($ignore_user_perms, "bool");
    settype($for_email, "bool");

    // Exclude admins from the list, if requested.
    $or_where_admin = $exclude_admin ? "" : "OR user.admin=1";

    // If we are gathering email addresses for mailing the moderators,
    // then honour the moderation_email setting for the user.
    $where_moderation_mail = $for_email ? "AND user.moderation_email = 1" : "";

    $moderators = array();

    // Look up moderators which are configured through user permissions.
    $usermods = phorum_db_interact(
        DB_RETURN_ROWS,
        "SELECT DISTINCT user.user_id,
                user.email
         FROM   {$PHORUM['user_table']} AS user
                LEFT JOIN {$PHORUM['user_permissions_table']} AS perm
                ON perm.user_id = user.user_id
         WHERE  ((perm.permission>=".PHORUM_USER_ALLOW_MODERATE_MESSAGES." AND
                  (perm.permission & ".PHORUM_USER_ALLOW_MODERATE_MESSAGES.">0)
                  AND perm.forum_id = $forum_id) $or_where_admin)
                $where_moderation_mail"
    );

    // Add them to the moderator list.
    foreach ($usermods as $mod) $moderators[$mod[0]] = $mod[1];
    unset($usermods);

    // Look up moderators which are configured through group permissions.
    $groupmods = phorum_db_interact(
        DB_RETURN_ROWS,
        "SELECT DISTINCT user.user_id,
                user.email
         FROM   {$PHORUM['user_table']} AS user,
                {$PHORUM['groups_table']} AS groups,
                {$PHORUM['user_group_xref_table']} AS usergroup,
                {$PHORUM['forum_group_xref_table']} AS forumgroup
         WHERE  user.user_id       = usergroup.user_id AND
                usergroup.group_id = groups.group_id AND
                groups.group_id    = forumgroup.group_id AND
                forum_id           = $forum_id AND
                permission & ".PHORUM_USER_ALLOW_MODERATE_MESSAGES." > 0 AND
                usergroup.status  >= ".PHORUM_USER_GROUP_APPROVED."
                $where_moderation_mail"
    );

    // Add them to the moderator list.
    foreach ($groupmods as $mod) $moderators[$mod[0]] = $mod[1];
    unset($groupmods);
   
    return $moderators;
}

/**
 * Get one or more users.
 *
 * @param $user_id  - The user_id or an array of user_ids for which to
 *                    retrieve the user data.
 * @param $detailed - If this parameter has a true value, then the user's
 *                    permissions and groups are included in the return data.
 * @param $sortkey  - The user table field to sort the records by.
 * @param $sortdir  - The direction for sorting. This parameter must be either
 *                    "ASC" (ascending sort) or "DESC" (descending sort).
 *
 * @return $return  - If $user_id is a single user_id, then either a 
 *                    single user or NULL (in case the user_id was not found
 *                    in the database) is returned.
 *                    If $user_id is an array of user_ids, then an array of
 *                    users is returned, indexed by user_id. For user_ids that
 *                    cannot be found, there will be no array element at all.
 */
function phorum_db_user_get($user_id, $detailed = FALSE, $sortkey=NULL, $sortdir=NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    phorum_db_sanitize_mixed($user_id, "int");
    
    if (is_array($user_id)) {
        if (count($user_id)) {
            $user_where = "user_id IN (".implode(",", $user_id).")";
        } else {
            return array();
        }
    } else {
        $user_where = "user_id = $user_id";
    }

    $orderby = "";
    if ($sortkey !== NULL)
    {
        if (!phorum_db_validate_field($sortkey)) trigger_error(
            "phorum_db_user_get(): Illegal sortkey parameter",
            E_USER_ERROR
        );
        $orderby = "ORDER BY " . $sortkey;

        if ($sortdir !== NULL) {
            $sortdir = strtoupper($sortdir);
            if ($sortdir != 'ASC' && $sortdir != 'DESC') trigger_error(
                "phorum_db_user_get(): Illegal sortdir parameter (must be " .
                "either \"ASC\" or \"DESC\")",
                E_USER_ERROR
            );
            $orderby .= " " . $sortdir;
        }
    }

    // Retrieve the requested user(s) from the database.
    $users = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT *
         FROM   {$PHORUM['user_table']}
         WHERE  $user_where
         $orderby",
        "user_id"
    );

    // Unpack the settings_data.
    foreach ($users as $id => $user) {
        $users[$id]["settings_data"] = empty($user["settings_data"])
                                     ? array()
                                     : unserialize($user["settings_data"]);
    }

    // Retrieve detailed group and permission information for the user(s).
    if ($detailed)
    {
        // Retrieve forum user permissions for the requested users.
        $forum_permissions = phorum_db_interact(
            DB_RETURN_ROWS,
            "SELECT user_id,
                    forum_id,
                    permission
             FROM   {$PHORUM['user_permissions_table']}
             WHERE  $user_where"
        );

        // Add forum user permissions to the users.
        foreach ($forum_permissions as $perm) {
            $users[$perm[0]]["forum_permissions"][$perm[1]] = $perm[2];
        }

        // Retrieve forum group permissions and groups for the requested users.
        $group_permissions = phorum_db_interact(
            DB_RETURN_ROWS,
            "SELECT user_id,
                    userxref.group_id,
                    forum_id,
                    permission
             FROM   {$PHORUM['user_group_xref_table']} AS userxref
                    LEFT JOIN {$PHORUM['forum_group_xref_table']} AS forumxref
                    USING (group_id)
             WHERE  $user_where AND
                    userxref.status >= ".PHORUM_USER_GROUP_APPROVED
        );

        // Add groups and forum group permissions to the users.
        foreach ($group_permissions as $perm)
        {
            // Add the group_id to the user data.
            $users[$perm[0]]["groups"][$perm[1]] = $perm[$perm[1]]; 

            // Are we handling a group permissions record?
            if (!empty($perm[2]))
            {
                // Initialize group permissions for the forum_id in the
                // user data.
                if (!isset($users[$perm[0]]["group_permissions"][$perm[2]])) {
                    $users[$perm[0]]["group_permissions"][$perm[2]] = 0;
                }

                // Merge the group permission by logical OR-ing the permission
                // with the permissions that we've got so far for the forum_id
                // in the user data.
                $users[$perm[0]]["group_permissions"][$perm[2]] |= $perm[3];
            }
        }
    }

    // Retrieve custom user profile fields for the requested users.
    $custom_fields = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT *
         FROM   {$PHORUM['user_custom_fields_table']}
         WHERE  $user_where"
    );

    // Add custom user profile fields to the users.
    foreach ($custom_fields as $fld)
    {
        // Skip unknown custom profile fields.
        if (! isset($PHORUM["PROFILE_FIELDS"][$fld["type"]])) continue;

        // Fetch the name for the custom profile field.
        $name = $PHORUM["PROFILE_FIELDS"][$fld["type"]]["name"];

        // For "html_disabled" fields, the data is XSS protected by
        // replacing special HTML characters with their HTML entities.
        if ($PHORUM["PROFILE_FIELDS"][$fld["type"]]["html_disabled"]) {
            $users[$fld["user_id"]][$name] = htmlspecialchars($fld["data"]);
            continue;
        }

        // Other fields can either contain raw values or serialized
        // arrays. For serialized arrays, the field data is prefixed with
        // a magic "P_SER:" (Phorum serialized) marker.
        if (substr($fld["data"],0,6) == "P_SER:") {
            $users[$fld["user_id"]][$name]=unserialize(substr($fld["data"],6));
            continue;
        }

        // The rest of the fields contain raw field data.
        $users[$fld["user_id"]][$name] = $fld["data"];
    }

    if (is_array($user_id)) {
        return $users;
    } else {
        return isset($users[$user_id]) ? $users[$user_id] : NULL;
    }
}

/**
 * Get the data for a couple of user table fields for one or more users.
 *
 * @param $user_id  - The user_id or an array of user_ids for which to
 *                    retrieve the field data.
 * @param $fields   - The field or an array of fields for which to 
 *                    retrieve the data.
 *
 * @return $users   - An array of users (no matter what type of variable
 *                    $user_id is), indexed by user_id. For user_ids that
 *                    cannot be found, there will be no array element at all.
 */
function phorum_db_user_get_fields($user_id, $fields)
{
    $PHORUM = $GLOBALS["PHORUM"];

    phorum_db_sanitize_mixed($user_id, "int");

    if (is_array($user_id)) {
        if (count($user_id)) {
            $user_where = "user_id IN (".implode(",", $user_id).")";
        } else {
            return array();
        }
    } else {
        $user_where = "user_id = $user_id";
    }

    if(!is_array($fields)) {
        $fields = array($fields);
    }
    foreach($fields as $key => $field){
        if(!phorum_db_validate_field($field)){
            unset($fields[$key]);
        }
    }

    $users = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT user_id, ".implode(",", $fields)."
         FROM   {$PHORUM['user_table']}
         WHERE  $user_where",
        "user_id"
    );

    return $users;
}

/**
 * Get a list of all users for a given type.
 *
 * @param $type   - The type of users to retrieve:
 *                  0 = all users
 *                  1 = all active users
 *                  2 = all inactive users
 *
 * @return $users - An array of users, indexed by user_id. The values
 *                  are arrays containing the fields "user_id", "username" and 
 *                  "displayname".
 */
function phorum_db_user_get_list($type = 0)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($type, "int");

    $where = '';
    if     ($type == 1) $where = "WHERE active  = 1";
    elseif ($type == 2) $where = "WHERE active != 1";
 
    $users = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT user_id,
                username,
                username AS displayname
         FROM   {$PHORUM['user_table']}
                $where
         ORDER  BY username ASC",
        "user_id"
    );

    return $users;
}

/**
 * Check if a user's password is correct.
 *
 * @param $username      - The username for the user.
 * @param $password      - The password for the user.
 * @param $temp_password - If this parameter has a true value, the 
 *                         password_temp field will be checked instead of
 *                         the password field.
 *
 * @return $user_id      -  The user_id if the password is correct or
 *                          0 (zero) if the password is wrong.
 */
function phorum_db_user_check_pass($username, $password, $temp_password=FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($temp_password, "bool");
    $username = phorum_db_interact(DB_RETURN_QUOTED, $username);
    $password = phorum_db_interact(DB_RETURN_QUOTED, $password);

    $pass_field = $temp_password ? "password_temp" : "password";

    $user_id = phorum_db_interact(
        DB_RETURN_VALUE,
        "SELECT user_id
         FROM   {$PHORUM['user_table']}
         WHERE  username    = '$username' AND
                $pass_field = '$password'"
    );

    return $user_id ? $user_id : 0;
}

/**
 * Search for users, based on a simple search condition,
 * which can be used to search on any field in the user table.
 *
 * The parameters $field, $value and $operator (which are used for defining
 * the search condition) can be arrays or single values. If arrays are used,
 * then all three parameter arrays must contain the same number of elements
 * and the key values in the arrays must be the same.
 * 
 * @param $field        - The user table field / fields to search on.
 * @param $value        - The value / values to search for.
 * @param $operator     - The operator / operators to use. Valid operators are
 *                        "=", "!=", "<>", "<", ">", ">=" and "<=", "*". The
 *                        "*" operator is for executing a "LIKE" match query.
 * @param $return_array - If this parameter has a true value, then an array
 *                        of all matching user_ids will be returned. Else, a 
 *                        single user_id will be returned.
 * @param $type         - The type of search to perform. This can be one of:
 *                        AND  match against all fields
 *                        OR   match against any of the fields
 *
 * @return $users       - An array of user_ids or a single user_id, based on
 *                        the $return_array parameter. If no user_ids can
 *                        be found at all, then 0 (zero) will be returned.
 */
function phorum_db_user_check_field($field, $value, $operator="=", $return_array=FALSE, $type = "AND")
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($return_array, "bool");

    // Convert all search condition parameters to arrays.
    if (!is_array($field))    $field    = array($field);
    if (!is_array($value))    $value    = array($value);
    if (!is_array($operator)) $operator = array($operator);

    // Basic check to see if all condition arrays contain the
    // same number of elements.
    if (count($field) != count($value) ||
        count($field) != count($operator)) raise_error(
        "phorum_db_user_check_field(): array parameters \$field, \$value, " .
        "and \$operator do not contain the same number of elements",
        E_USER_ERROR
    );

    $type = strtoupper($type);
    if ($type != "AND" && $type != "OR") raise_error(
        "phorum_db_user_check_field(): Illegal search type parameter (must " .
        "be either \"AND\" or \"OR\")",
        E_USER_ERROR
    );

    $valid_operators = array("=", "<>", "!=", ">", "<", ">=", "<=", "*");

    // Construct the required "WHERE" clauses.
    foreach($field as $key => $name) {
        if (in_array($operator[$key], $valid_operators) && 
            phorum_db_validate_field($name)) {
            $value[$key] = phorum_db_interact(DB_RETURN_QUOTED, $value[$key]);
            if ($operator[$key] == '*') {
                $clauses[] = "$name LIKE '%$value[$key]%'";
            } else {
                $clauses[] = "$name $operator[$key] '$value[$key]'";
            }
        }
    }

    // If we do not need to return an array, the we can limit the 
    // query results to only one record.
    $limit = $return_array ? "" : "LIMIT 1";

    // Retrieve the matching user_ids from the database.
    $user_ids = phorum_db_interact(
        DB_RETURN_ROWS,
        "SELECT user_id
         FROM   {$PHORUM['user_table']}
         WHERE ".implode(" $type ", $clauses)."
         $limit",
        0
    );

    // No user_ids found at all?
    if (count($user_ids) == 0) return 0;

    // Return an array of user_ids.
    if ($return_array) {
        foreach ($user_ids as &$user_id) $user_id = $user_id[0];
        return $user_ids;
    }

    // Return a single user_id.
    list ($user_id, $dummy) = each($user_ids);
    return $user_id;
}

/**
 * Get users whose username or email match the search string.
 *
 * TODO: This function is now fully implemented by calling other db layer
 * TODO: functions, so it could be considered deprecated. It's only used
 * TODO: by include/admin/users.php in the core, so modifying the core
 * TODO: would be pretty simple.
 *
 * @param $search - The string to search on. If empty, all users
 *                  will be returned.
 *
 * @return $users - An array of users.
 */
function phorum_db_search_users($search)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // Find a list of matching user_ids.
    $user_ids = phorum_db_user_check_field(
        array("username", "email"),
        array($search,    $search),
        array("*",        "*"),
        TRUE, "OR"
    );

    // No results found? Then return an empty array.
    if (count($user_ids) == 0) return array();

    // Retrieve the data for the users.
    $users = phorum_db_user_get($user_ids, FALSE, "username");

    return $users;
}

/**
 * Add a user.
 *
 * @param $userdata - An array containing the fields to insert into the
 *                    user table. The array can contain two special fields:
 *                    forum_permissions  This field can contain an array
 *                                       with forum permissions for the user.
 *                                       The keys are forum_ids and the
 *                                       values are permission values.
 *                    user_data          This field can contain an array
 *                                       of key/value pairs which will be
 *                                       inserted in the database as custom
 *                                       profile fields. The keys are profile
 *                                       type ids (as defined by
 *                                       $PHORUM["PROFILE_FIELDS"]).
 *
 * @return $user_id - The user_id that was assigned to the new user.
 *
 * TODO: maybe we should use the user_save function for handling
 * TODO: updating forum_permissions and user_data, because now there's
 * TODO: quite some overlap between add and save. The add function could
 * TODO: call the save function after creating the initial basic user record.
 */
function phorum_db_user_add($userdata)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // Pull some non user table fields from the userdata.
    if (isset($userdata["forum_permissions"])) {
        if (!empty($userdata["forum_permissions"])) {
            $forum_perms = $userdata["forum_permissions"];
        }
        unset($userdata["forum_permissions"]);
    }
    if (isset($userdata["user_data"])) {
        if (!empty($userdata["user_data"])) {
            $user_data = $userdata["user_data"];
        }
        unset($userdata["user_data"]);
    }

    // Prepare the user table fields.
    $values = array();
    foreach($userdata as $key => $value){
        if (phorum_db_validate_field($key)){
            if (!is_numeric($value)){
                $value = phorum_db_interact(DB_RETURN_QUOTED, $value);
                $values[] = "$key='$value'";
            }else{
                $values[] = "$key=$value";
            }
        }
    }
    
    // Insert the user record in the database.
    $user_id = phorum_db_interact(
        DB_RETURN_NEWID,
        "INSERT INTO {$PHORUM['user_table']}
         SET ".implode(", ", $values)
    );

    // Assign user forum permissions.
    if (isset($forum_perms)) {
        foreach($forum_perms as $forum_id => $permission) {
            phorum_db_interact(
                DB_RETURN_RES,
                "INSERT INTO {$PHORUM['user_permissions_table']}
                 SET    user_id    = $user_id,
                        forum_id   = $forum_id,
                        permission = $permission"
            );
        }
    }

    // Assign custom profile fields.
    if (isset($user_data)) {
        foreach ($user_data as $key => $val)
        {
            // Arrays need to be serialized. The serialized data is prefixed
            // with "P_SER:" as a marker for serialization.
            if (is_array($val)) {
                $val = 'P_SER:'.serialize($val);
            } else {
                $val = phorum_db_interact(DB_RETURN_QUOTED, $val);
            }

            phorum_db_interact(
                DB_RETURN_RES,
                "INSERT INTO {$PHORUM['user_custom_fields_table']}
                 SET    user_id = $user_id,
                        type    = $key,
                        data    = '$val'"
            );
        }
    }

    return $user_id;
}

/**
 * Update a user.
 *
 * @param $userdata - An array containing the fields to update in the
 *                    user table. The array should contain at least 
 *                    the user_id field to identify the user for which
 *                    to update the data.
 *
 * @return $success - True if all settings were stored successfully. This
 *                    function will always return TRUE, so we could
 *                    do without a return value. The return value is
 *                    here for backward compatibility.
 *
 * TODO: for consistency in function names, this function might have
 * TODO: be named phorum_db_user_update(). But we should look at all
 * TODO: function names to see if renaming them makes sense.
 */
function phorum_db_user_save($userdata)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // Pull some non user table fields from the userdata.
    if (isset($userdata["permissions"])) {
        unset($userdata["permissions"]);
    }
    if (isset($userdata["forum_permissions"])) {
        if (!empty($userdata["forum_permissions"])) {
            $forum_perms = $userdata["forum_permissions"];
        }
        unset($userdata["forum_permissions"]);
    }
    if (isset($userdata["groups"])) {
        $groups = $userdata["groups"];
        unset($userdata["groups"]);
    }
    if (isset($userdata["group_permissions"])) {
        unset($userdata["group_permissions"]);
    }
    if (isset($userdata["user_data"])) {
        $user_data = $userdata["user_data"];
        unset($userdata["user_data"]);
    }

    // The user_id is required as a separate variable.
    if (!isset($userdata["user_id"])) raise_error(
        "phorum_db_user_save(): the user_id field is missing in the " .
        "\$userdata argument",
        E_USER_ERROR
    );
    $user_id = $userdata["user_id"];
    unset($userdata["user_id"]);

    // If there are standard user table fields in the userdata then
    // update the user table for the user.
    if(count($userdata))
    {
        // Prepare the user table fields.
        $values = array();
        foreach($userdata as $key => $value) {
            if (!phorum_db_validate_field($key)) continue;
            if ($key === "settings_data") {
                if (is_array($value)) {
                    $value = serialize($value);
                } else trigger_error(
                    "Internal error: settings_data field for " .
                    "phorum_db_user_save() must be an array", E_USER_ERROR
                );
            }
            $value = phorum_db_interact(DB_RETURN_QUOTED, $value); 
            $values[] = "$key='$value'";
        }

        // Update the fields in the database.
        phorum_db_interact(
            DB_RETURN_RES,
            "UPDATE {$PHORUM['user_table']}
             SET    ".implode(", ", $values)."
             WHERE  user_id = $user_id"
        );
    }

    // Update forum permissions for the user.
    if (isset($forum_perms))
    {
        // Delete all the existing forum permissions.
        phorum_db_interact(
            DB_RETURN_RES,
            "DELETE FROM {$PHORUM['user_permissions_table']}
             WHERE  user_id = $user_id"
        );

        // Add new forum permissions.
        foreach ($forum_perms as $forum_id => $permission) {
            phorum_db_interact(
                DB_RETURN_RES,
                "INSERT INTO {$PHORUM['user_permissions_table']}
                 SET    user_id    = $user_id,
                        forum_id   = $fid,
                        permission = $perms"
            );
        }
    }

    // Update custom user fields for the user.
    if (isset($user_data))
    {
        // Delete all the existing custom profile fields.
        phorum_db_interact(
            DB_RETURN_RES,
            "DELETE FROM {$PHORUM['user_custom_fields_table']}
             WHERE  user_id = $user_id"
        );

        // Insert new custom profile fields.
        foreach ($user_data as $key => $val)
        {
            // Arrays need to be serialized. The serialized data is prefixed
            // with "P_SER:" as a marker for serialization.
            if (is_array($val)) {
                $val = 'P_SER:'.serialize($val);
            } else {
                $val = phorum_db_interact(DB_RETURN_QUOTED, $val);
            }

            phorum_db_interact(
                DB_RETURN_RES,
                "INSERT INTO {$PHORUM['user_custom_fields_table']}
                 SET    user_id = $user_id,
                        type    = $key,
                        data    = '$val'"
            );
        }
    }

    return TRUE;
}

/**
 * Save the groups memberships for a user.
 *
 * @param $user_id  - The user_id for which to save the group memberships.
 * @param $groups   - The group memberships to save. This is an array in
 *                    which the keys are group_ids and the values are group
 *                    statuses.
 *
 * @return $success - True if all settings were stored successfully. This
 *                    function will always return TRUE, so we could
 *                    do without a return value. The return value is
 *                    here for backward compatibility.
 */
function phorum_db_user_save_groups($user_id, $groups)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($user_id, "int");

    // Delete all existing group memberships.
    phorum_db_interact(
        DB_RETURN_RES,
        "DELETE FROM {$PHORUM['user_group_xref_table']}
         WHERE  user_id = $user_id"
    );

    // Insert new group memberships.
    foreach ($groups as $group_id => $group_status) {
        $group_id = (int)$group_id;
        $group_status = (int)$group_status;
        phorum_db_interact(
            DB_RETURN_RES,
            "INSERT INTO {$PHORUM['user_group_xref_table']}
             SET    user_id  = $user_id,
                    group_id = $group_id,
                    status   = $group_status"
        );
    }

    return TRUE;
}

/**
 * Subscribe a user to a forum/thread.
 *
 * Remark: currently, there's no active support for subscription type
 * PHORUM_SUBSCRIPTION_DIGEST in the Phorum core. 
 *
 * @param $user_id  - The id of the user to create a suscription for.
 * @param $forum_id - The id of the forum to subscribe to
 * @param $thread   - The id of the thread to subscribe to.
 * @param $type     - The type of subscription. Available types are:
 *                    PHORUM_SUBSCRIPTION_MESSAGE
 *                        Subscribe to the thread and retrieve a mail message
 *                        for every new message in the thread.
 *                    PHORUM_SUBSCRIPTION_DIGEST
 *                        Subscribe to the thread and have it included
 *                        in periodic mail digests of new messages 
 *                        on the forum(s).
 *                    PHORUM_SUBSCRIPTION_BOOKMARK
 *                        Subscribe to the thread so it's available on the
 *                        followed threads page.
 *
 * @return $success - True if the subscription was stored successfully.
 */
function phorum_db_user_subscribe($user_id, $forum_id, $thread, $type)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($user_id, "int");
    settype($forum_id, "int");
    settype($thread, "int");
    settype($type, "int");

    phorum_db_interact(
        DB_RETURN_RES,
        "REPLACE INTO {$PHORUM['subscribers_table']}
         SET     user_id  = $user_id,
                 forum_id = $forum_id,
                 thread   = $thread,
                 sub_type = $type"
    );

    return TRUE;
}

/**
 * Unsubscribe a user from a forum/thread.
 *
 * @param $user_id  - The id of the user to remove a suscription for.
 * @param $thread   - The id of the thread to unsubscribe from.
 * @param $forum_id - The id of the forum to unsubscribe from (or 0 (zero)
 *                    to simply unsubscribe by the thread id alone).
 *
 * @return $success - True if the subscription was stored successfully.
 */
function phorum_db_user_unsubscribe($user_id, $thread, $forum_id=0)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($user_id, "int");
    settype($forum_id, "int");
    settype($thread, "int");

    $forum_where = $forum_id ? "AND forum_id = $forum_id" : "";

    phorum_db_interact(
        DB_RETURN_RES,
        "DELETE FROM {$PHORUM['subscribers_table']}
         WHERE  user_id = $user_id AND
                thread  = $thread
                $forum_where"
    );

    return TRUE;
}

/**
 * Increment the posts counter for a user.
 *
 * @param $user_id  - The user_id for which to increment the posts counter.
 *
 * @return $success - TRUE if the posts counter was updated successfully.
 */
function phorum_db_user_addpost($user_id)
{
    settype($user_id, "int");

    if (!empty($user_id)) {
        phorum_db_interact(
            DB_RETURN_RES,
            "UPDATE {$GLOBALS['PHORUM']['user_table']}
             SET    posts = posts + 1
             WHERE  user_id = $user_id"
        );
    }

    return TRUE;
}

/**
 * Get a list of group memberships and their statuses for a user.
 *
 * @param $user_id - The user id for which to retrieve the groups.
 *
 * @return $groups - An array of groups for the user. The keys are 
 *                   group_ids and the values are the membership statuses.
 */
function phorum_db_user_get_groups($user_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($user_id, "int");

    // Retrieve the groups for the user_id from the database.
    $groups = phorum_db_interact(
        DB_RETURN_ROWS,
        "SELECT group_id,
                status
         FROM   {$PHORUM['user_group_xref_table']}
         WHERE  user_id = $user_id
         ORDER  BY status DESC",
        "group_id"
    );

    // The records are full rows, but we want a group_id -> status mapping.
    foreach ($groups as &$group) $group = $group[1];
    
    return $groups;
}

/**
 * Get the users that await signup approval.
 *
 * @return $users - An array or users, indexed by user_id, that await approval.
 *                  The elements of the array are arrays containing the fields:
 *                  user_id, username and email.
 */
function phorum_db_user_get_unapproved()
{
    $PHORUM = $GLOBALS["PHORUM"];

    $users = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT user_id,
                username,
                email
         FROM   {$PHORUM['user_table']}
         WHERE  active in (".PHORUM_USER_PENDING_BOTH.",
                           ".PHORUM_USER_PENDING_MOD.")
         ORDER  BY username",
        "user_id"
    );

    return $users;
}

/**
 * Delete a user completely. Messages that were posted by the user in the
 * forums, will be changed into anonymous messages (user_id = 0). If the
 * constant PHORUM_DELETE_CHANGE_AUTHOR is set to a true value, then the
 * author name of all postings will be set to {LANG->AnonymousUser}. If
 * it is set to a false value, then the original author name will be kept.
 *
 * @param $user_id  - The id of the user to delete.
 *
 * @return $success - True if the user was deleted successfully.
 */
function phorum_db_user_delete($user_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($user_id, "int");

    // These are tables that hold user related data.
    $tables = array (
        $PHORUM['user_table'],
        $PHORUM['user_permissions_table'],
        $PHORUM['user_newflags_table'],
        $PHORUM['subscribers_table'],
        $PHORUM['user_group_xref_table'],
        $PHORUM['pm_buddies_table'],
        $PHORUM['pm_folders_table'],
        $PHORUM['pm_xref_table'],
        $PHORUM['user_custom_fields_table']
    );

    // Delete the data for the $user_id from all those tables.
    foreach ($tables as $table) {
        phorum_db_interact(
            DB_RETURN_RES,
            "DELETE FROM $table
             WHERE user_id = $user_id"
        );
    }

    // By deleting the private message xrefs for this user, we might now
    // have orphin private messages lying around (in case there were only
    // links to a private message from the xref entries for this user).
    // Collect all orphin private messages from the database. This might
    // catch some more orphin messages than the ones for the deleted user
    // alone.
    // TODO: We do have a race condition here (if a new PM is inserted and 
    // TODO: this cleanup is run just before the new PM xrefs are inserted).
    // TODO: Some locking might be useful to keep things straight, but I
    // TODO: don't think we've got a really big risk here. We could add
    // TODO: an extra field to the pm messages which indicates the posting
    // TODO: status. That field could be changed to some ready state after
    // TODO: posting the PM and linking all xrefs. The query below can
    // TODO: then ignore the private messages which are not yet fully posted.
    $pms = phorum_db_interact(
        DB_RETURN_ROWS,
        "SELECT pm_message_id
         FROM   {$PHORUM['pm_messages_table']}
                LEFT JOIN {$PHORUM['pm_xref_table']}
                USING (pm_message_id)
         WHERE pm_xref_id IS NULL",
         0 // keyfield 0 is the pm_message_id
    ); 
    // Delete all orphan private messages.
    if (!empty($pms)) {
        phorum_db_interact(
            DB_RETURN_RES,
            "DELETE FROM {$PHORUM['pm_messages_table']}
             WHERE  pm_message_id IN (".implode(",", array_keys($pms)).")"
        );
    }

    // Delete personal files that are linked to this user.
    phorum_db_interact(
        DB_RETURN_RES,
        "DELETE FROM {$PHORUM['files_table']}
         WHERE  user_id    = $user_id AND
                message_id = 0 AND
                link       = '".PHORUM_LINK_USER."'"
    );

    // Change the forum postings into anonymous postings.
    // If PHORUM_DELETE_CHANGE_AUTHOR is set, then the author field is 
    // updated to {LANG->AnonymousUser}.
    $author = "author";
    if (defined('PHORUM_DELETE_CHANGE_AUTHOR') && PHORUM_DELETE_CHANGE_AUTHOR){
        $anonymous = $PHORUM['DATA']['LANG']['AnonymousUser'];
        $author = "'".phorum_db_interact(DB_RETURN_QUOTED, $anonymous)."'";
    }
    phorum_db_interact(
        DB_RETURN_RES,
        "UPDATE {$PHORUM['message_table']}
         SET    user_id = 0,
                email   = '',
                author  = $author
         WHERE  user_id = $user_id"
    );

    return TRUE;
}

/**
 * Get a list of personal files for a user.
 *
 * @param $user_id - The user id for which to retrieve the file list.
 *
 * @return $files  - An array of personal user files, indexed by file_id.
 *                   The array elements are arrays containing the fields:
 *                   file_id, filename, filesize and add_datetime.
 */
function phorum_db_get_user_file_list($user_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($user_id, "int");

    // Select the personal user files from the database.
    $files = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT file_id,
                filename,
                filesize,
                add_datetime
         FROM   {$PHORUM['files_table']}
         WHERE  user_id    = $user_id AND
                message_id = 0 AND
                link       = '".PHORUM_LINK_USER."'",
        "file_id"
    );

    return $files;
}

/**
 * Get a list of files for a message (a.k.a. attachments).
 *
 * @param $message_id - The message id for which to retrieve the file list.
 *
 * @return $files     - An array of message files, indexed by file_id.
 *                      The array elements are arrays containing the fields:
 *                      file_id, filename, filesize and add_datetime.
 */
function phorum_db_get_message_file_list($message_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($message_id, "int");

    // Select the message files from the database.
    $files = phorum_db_interact(
        "SELECT file_id,
                filename,
                filesize,
                add_datetime
         FROM   {$PHORUM['files_table']}
         WHERE  message_id = $message_id AND
                link       = '".PHORUM_LINK_MESSAGE."'",
        "file_id"
    );

    return $files;
}

/**
 * Get a file.
 *
 * @param $file_id - The file id to retrieve from the database.
 *
 * @return $file   - An array, containing the data for all file table fields.
 *                   If the file id cannot be found in the database, an empty
 *                   array will be returned instead.
 *                   TODO: Maybe it'd be nicer if we'd return NULL here,
 *                   TODO: but we should look at how calls to this function
 *                   TODO: are handled throughout the code for that.
 */
function phorum_db_file_get($file_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($file_id, "int");

    // Select the file from the database.
    $files = phorum_db_interact(
        DB_RETURN_ASSOC,
        "SELECT *
         FROM   {$PHORUM['files_table']}
         WHERE  file_id = $file_id"
    );

    if (count($files) == 0) {
        return array();
    } else {
        return $files[0];
    }
}

/**
 * Add a file.
 *
 * @param $user_id    - The id of the user for which to add the file.
 *                      If this file is linked to a message by an anonymous
 *                      user, then this value can be 0 (zero).
 * @param $filename   - The name of the file.
 * @param $filesize   - The size of the file in bytes.
 * @param $buffer     - The file contents. This should be data which is
 *                      safe to store in a TEXT field in the database. The
 *                      calling application has to take care of this.
 *                      The database layer will simply store and retrieve
 *                      the file data as provided by the caller.
 * @param $message_id - The message_id to link the file to. If this file
 *                      is not linked to a posted message (the link type
 *                      PHORUM_LINK_MESSAGE) then this value can be 0 (zero).
 * @param $link       - A file can be linked to a number of different 
 *                      types of objects. The available link types are:
 *                      PHORUM_LINK_USER
 *                          The file is linked to a user. This means that the
 *                          file is available from within the files section
 *                          in the user's Control Center.
 *                      PHORUM_LINK_MESSAGE
 *                          The file is linked to a message. This means that
 *                          the file is available as an attachment on a
 *                          posted forum message.
 *                      PHORUM_LINK_EDITOR
 *                          The file is linked to the editor. This means that
 *                          the file was uploaded as part of editing a 
 *                          forum message. This message was not yet posted.
 *                          As soon as the message is posted for final, the
 *                          link type for the message will be updated to
 *                          PHORUM_LINK_MESSAGE.
 *                      Note: these are the official link types. Calling 
 *                      functions are allowed to provide different custom link
 *                      types if they need to.
 *
 * @return $file_id   - The file_id that was assigned to the new file.
 */
function phorum_db_file_save($user_id, $filename, $filesize, $buffer, $message_id=0, $link=NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // If a link type is not provided, we'll guess for the type of link.
    // This is done to provide some backward compatibility.
    if ($link === NULL) {
        if     ($message_id) $link = PHORUM_LINK_MESSAGE;
        elseif ($user_id)    $link = PHORUM_LINK_USER;
        else raise_error(
            "phorum_db_file_save(): Missing \$link parameter in the call",
            E_USER_ERROR
        );
    }

    settype($user_id, "int");
    settype($message_id, "int");
    settype($filesize, "int");

    $link     = phorum_db_interact(DB_RETURN_QUOTED, $link);
    $filename = phorum_db_interact(DB_RETURN_QUOTED, $filename);
    $buffer   = phorum_db_interact(DB_RETURN_QUOTED, $buffer);

    $file_id = phorum_db_interact(
        DB_RETURN_NEWID,
        "INSERT INTO {$PHORUM['files_table']}
         SET    user_id      = $user_id,
                message_id   = $message_id,
                link         = '$link',
                filename     = '$filename',
                filesize     = $filesize,
                file_data    = '$buffer',
                add_datetime = ".time()
    );

    return $file_id;
}

/**
 * Delete a file.
 *
 * @param $file_id - The id of the file to delete.
 *
 * @return $success - True if the file was deleted successfully.
 */
function phorum_db_file_delete($file_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($file_id, "int");

    phorum_db_interact(
        DB_RETURN_QUOTED,
        "DELETE FROM {$PHORUM['files_table']}
         WHERE  file_id = $file_id"
    );

    return TRUE;
}

/**
 * Update the message to which a file is linked and/or the link type.
 *
 * @param $file_id    - The id of the file to update.
 * @param $message_id - The id of the message to link the file to.
 * @param $link       - A file can be linked to a number of different 
 *                      types of objects. See phorum_db_file_save() for
 *                      the possible link types.
 *
 * @return $success   - True if the file was updated successfully.
 */
function phorum_db_file_link($file_id, $message_id, $link = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    settype($file_id, "int");
    settype($message_id, "int");

    $link = $link === NULL
          ? PHORUM_LINK_MESSAGE
          : phorum_db_interact(DB_RETURN_QUOTED, $link);

    phorum_db_interact(
        DB_RETURN_RES,
        "UPDATE {$PHORUM['files_table']}
         SET    message_id = $message_id,
                link       = '$link' 
         WHERE  file_id    = $file_id"
    );

    return TRUE;
}

/**
 * Get the total size for all personal files for a user.
 *
 * @param $user_id - The user to compute the total size for.
 *
 * @return $size   - The total size in bytes.
 */
function phorum_db_get_user_filesize_total($user_id)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($user_id, "int");

    $size = phorum_db_interact(
        DB_RETURN_VALUE,
        "SELECT SUM(filesize)
         FROM   {$PHORUM['files_table']}
         WHERE  user_id    = $user_id AND
                message_id = 0 AND
                link       = '".PHORUM_LINK_USER."'"
    );

    return $size;
}

/**
 * Clean up stale files from the database. Stale files are files that
 * are not linked to anything. These can for example be caused by users
 * that are writing a message with attachments, but never post it.
 *
 * @param $live_run - If set to FALSE (default), the function will return
 *                    a list of files that will be purged. If set to TRUE,
 *                    files will be purged for real.
 * @return $return  - If $live_run is set to a true value, TRUE will be 
 *                    returned. Else an array of files that would be deleted
 *                    (indexed by file_id) will be returned.
 */
function phorum_db_file_purge_stale_files($live_run = FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($live_run, "bool");

    // WHERE statement for selecting  files that are linked to
    // the editor and were added a while ago. These are from
    // posts that were abandoned before posting.
    $orphin_editor_where = 
        "link = '".PHORUM_LINK_EDITOR."' " .
        "AND add_datetime < ". (time()-PHORUM_MAX_EDIT_TIME);

    // Purge files.
    if ($live_run) {

        // Delete orphin editor files.
        phorum_db_interact(
            DB_RETURN_RES,
            "DELETE FROM {$PHORUM['files_table']} 
             WHERE  $orphin_editor_where"
        );

        return TRUE;
    }

    // Only select a list of files that can be purged.
    else
    {
        // Select orphin editor files.
        $purge_files = phorum_db_interact(
            DB_RETURN_ASSOC,
            "SELECT file_id,
                    filename,
                    filesize,
                    add_datetime,
                    'Orphin editor file' AS reason
             FROM   {$PHORUM['files_table']}
             WHERE  $orphin_editor_where"
        );

        return $purge_files;
    }
}

/**
 * Mark all messages for a forum read for the active Phorum user.
 *
 * @param $forum_id - The forum to mark read or 0 (zero) to mark
 *                    the current forum read. 
 */
function phorum_db_newflag_allread($forum_id=0)
{
    $PHORUM = $GLOBALS['PHORUM'];

    if (empty($forum_id)) $forum_id = $PHORUM["forum_id"];
    settype($forum_id, "int");

    // Delete all the existing newflags for this user for this forum.
    phorum_db_newflag_delete(0, $forum_id);

    // Get the maximum message_id in this forum.
    $max_id = phorum_db_interact(
        DB_RETURN_VALUE,
        "SELECT max(message_id)
         FROM   {$PHORUM['message_table']}
         WHERE  forum_id = $forum_id"
    );

    // Set this message_id as the min-id for the forum.
    if ($max_id) {
        phorum_db_newflag_add_read(array(
            0 => array(
              'id'    => $max_id,
              'forum' => $forum_id
            )
        ));
    }
}

/**
 * Get the read messages for a forum for the active Phorum user.
 *
 * @param $forum_id   - The forum to retrieve the read messages for or
 *                      0 (zero) to retrieve them for the current forum.
 *
 * @return $read_msgs - An array containing the message_ids that have been
 *                      read for the forum (key and value are both the
 *                      message_id). Also an element for the key "min_id",
 *                      which holds the minimum read message_id. All
 *                      message_ids lower than the min_id are also considered
 *                      to be read.
 */
function phorum_db_newflag_get_flags($forum_id=NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    if ($forum_id === NULL) $forum_id=$PHORUM["forum_id"];
    settype($forum_id, "int");

    // Initialize the read messages array.
    $read_msgs = array('min_id' => 0);

    // Select the read messages from the newflags table.
    $newflags = phorum_db_interact(
        DB_RETURN_ROWS,
        "SELECT message_id 
         FROM   {$PHORUM['user_newflags_table']}
         WHERE  user_id  = {$PHORUM['user']['user_id']} AND
                forum_id = $forum_id
         ORDER  BY message_id ASC"
    );

    // Add the newflags to the $read_msgs.
    // The first newflags element also determines the min_id.
    foreach ($newflags as $index => $newflag) {
        if ($index == 0) $read_msgs["min_id"] = $newflag[0];
        $read_msgs[$newflag[0]] = $newflag[0];
    }

    return $read_msgs;
}

/**
 * Get the number of new threads and messages for a forum for the
 * active Phorum user.
 *
 * @param $forum_id    - The forum to retrieve the new counts for or
 *                       0 (zero) to retrieve them for the current forum.
 *
 * @return $counts     - An array containing two elements. The first element
 *                       is the number of new messages. The second one is
 *                       the number of new threads.
 */
function phorum_db_newflag_get_unread_count($forum_id=NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    if ($forum_id === NULL) $forum_id=$PHORUM["forum_id"];
    settype($forum_id, "int");

    // Retrieve the minimum message_id from newflags for the forum.
    $min_message_id = phorum_db_interact(
        DB_RETURN_VALUE,
        "SELECT  min(message_id)
         FROM    {$PHORUM['user_newflags_table']}
         WHERE   user_id  = {$PHORUM['user']['user_id']} AND
                 forum_id = {$forum_id}"
    );

    // No result found? Then we know that the user never read a 
    // message from this forum. We won't count the new messages
    // in that case. Return an empty result.
    if (!$min_message_id) return array(0,0);

    // Retrieve the unread thread count.
    $new_threads = phorum_db_interact(
        DB_RETURN_VALUE,
        "SELECT count(*)
         FROM {$PHORUM['message_table']} AS m
              LEFT JOIN {$PHORUM['user_newflags_table']} AS n ON
               m.message_id = n.message_id AND
               n.user_id    = {$PHORUM['user']['user_id']}
         WHERE m.forum_id   = {$forum_id} AND
               m.message_id > $min_message_id AND
               n.message_id IS NULL AND
               m.parent_id  = 0 AND
               m.status     = ".PHORUM_STATUS_APPROVED." AND
               m.thread     = m.message_id"
    );

    // Retrieve the unread message count.
    $new_messages = phorum_db_interact(
        DB_RETURN_VALUE,
        "SELECT count(*)
         FROM   {$PHORUM['message_table']} AS m 
                LEFT JOIN {$PHORUM['user_newflags_table']} AS n ON
                m.message_id = n.message_id AND
                m.forum_id   = n.forum_id AND
                n.user_id    = {$PHORUM['user']['user_id']}
         WHERE  m.forum_id   = {$forum_id} AND
                m.message_id > $min_message_id AND
                n.message_id IS NULL AND
                m.status = ".PHORUM_STATUS_APPROVED
    );

    $counts = array(
        $new_messages,
        $new_threads
    );

    return $counts;
}

/**
 * Mark a message as read for the active Phorum user.
 *
 * @param $message_ids - The message_id of the message to mark read in the
 *                       active forum or an array description of messages
 *                       to mark read. Elements in this array can be:
 *                       - Simple message_id values, for marking messages
 *                         read in the active forum.
 *                       - An array containing two fields: "forum" containing
 *                         a forum_id and "id" containing a message_id. This
 *                         notation can be used to mark messages read in 
 *                         other forums than te active one.
 */
function phorum_db_newflag_add_read($message_ids)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // Find the number of newflags for the user
    $num_newflags = phorum_db_newflag_get_count();

    if (!is_array($message_ids)) {
        $message_ids = array(0 => $message_ids);
    }

    // Delete newflags which would exceed the maximum number of
    // newflags that are allowed in the database per user.
    $num_end = $num_newflags + count($message_ids);
    if ($num_end > PHORUM_MAX_NEW_INFO) {
        phorum_db_newflag_delete($num_end - PHORUM_MAX_NEW_INFO);
    }

    // Prepare the rows for the query.
    $values = array();
    foreach($message_ids as $id => $data)
    {
        if(is_array($data)) {
            $data["forum"] = (int)$data["forum"];
            $data["id"]    = (int)$data["id"];
            $values[] = 
                "(" . $PHORUM['user']['user_id'] . "," .
                      $data['forum'] . "," .
                      $data['id'] . ")";
        } else {
            $data = (int)$data;
            $values[] =
                "(" . $PHORUM['user']['user_id'] . "," .
                      $PHORUM['forum_id'] . "," . 
                      $data . ")";
        }
    }

    // No values prepared? Then there's no need in running the query.
    if (! count($values)) return;

    phorum_db_interact(
        DB_RETURN_RES,
        "INSERT IGNORE INTO {$PHORUM['user_newflags_table']}
                (user_id, forum_id, message_id)
         VALUES ".join(",",$values)
    );
}

/**
 * Get the total number of newflags for a forum for the active Phorum user.
 *
 * @param $forum_id    - The forum to retrieve the count for or
 *                       0 (zero) to retrieve it for the current forum.
 *
 * @return $count      - The total number of newflags.
 */
function phorum_db_newflag_get_count($forum_id=0)
{
    $PHORUM = $GLOBALS["PHORUM"];

    if(empty($forum_id)) $forum_id=$PHORUM["forum_id"];
    settype($forum_id, "int");

    $count = phorum_db_interact(
        DB_RETURN_VALUE,
        "SELECT count(*)
         FROM   {$PHORUM['user_newflags_table']}
         WHERE  user_id  = {$PHORUM['user']['user_id']} AND
                forum_id = {$forum_id}"
    );

    return $count;
}

/**
* Remove newflags for a forum for the active Phorum user.
*
* @param $numdelete - The number of newflags to delete or 0 (zero) to
*                     delete them all.
* @param $forum_id  - The forum for which to delete the newflags or
*                     0 (zero) to delete them for the current forum.
*/
function phorum_db_newflag_delete($numdelete=0,$forum_id=0)
{
    $PHORUM = $GLOBALS["PHORUM"];

    if(empty($forum_id)) $forum_id=$PHORUM["forum_id"];
    settype($numdelete, "int");
    settype($forum_id, "int");

    $limit = $numdelete > 0 ? "ORDER BY message_id ASC LIMIT $numdelete" : "";

    // Delete the provided amount of newflags.
    phorum_db_interact(
        DB_RETURN_RES,
        "DELETE FROM {$PHORUM['user_newflags_table']}
         WHERE  user_id  = {$PHORUM['user']['user_id']} AND
                forum_id = {$forum_id}
         $limit"
    );
}

/**
 * Update the forum_id for the newflags. The newsflags are updated by setting
 * their forum_ids to the forum_ids of the referenced message table records.
 *
 * @param $message_ids - An array of message_ids which should be updated.
 */
function phorum_db_newflag_update_forum($message_ids)
{
    phorum_db_sanitize_mixed($message_ids, "int");
    $ids_str = implode(",", $message_ids);

    phorum_db_interact(
        DB_RETURN_RES,
        "UPDATE IGNORE {$GLOBALS['PHORUM']['user_newflags_table']} AS flags,
                       {$GLOBALS['PHORUM']['message_table']} AS msg
         SET    flags.forum_id   = msg.forum_id
         WHERE  flags.message_id = msg.message_id AND
                flags.message_id IN ($ids_str)"
    );
}

// --------------------------------------------------------------------- //
// TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO //
// --------------------------------------------------------------------- //

/**
 * Get the user ids of the users subscribed to a forum/thread.
 *
 * @param int $forum_id
 * @param int $thread
 * @param int $type
 *
 * @return array
 */
function phorum_db_get_subscribed_users($forum_id, $thread, $type){
    $PHORUM = $GLOBALS["PHORUM"];

    settype($forum_id, "int");
    settype($thread, "int");
    settype($type, "int");

    $conn = phorum_db_mysql_connect();

    $userignore="";
    if ($PHORUM["DATA"]["LOGGEDIN"])
       $userignore="and b.user_id != {$PHORUM['user']['user_id']}";

    $sql = "select DISTINCT(b.email),user_language from {$PHORUM['subscribers_table']} as a,{$PHORUM['user_table']} as b where a.forum_id=$forum_id and (a.thread=$thread or a.thread=0) and a.sub_type=$type and b.user_id=a.user_id $userignore";

    $res = mysql_query($sql, $conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

        $arr=array();

    while ($rec = mysql_fetch_row($res)){
        if(!empty($rec[1])) // user-language is set
            $arr[$rec[1]][] = $rec[0];
        else // no user-language is set
            $arr[$PHORUM['language']][]= $rec[0];
    }

    return $arr;
}

/**
 * Get the subscriptions of a user-id, together with the forum-id
 * and subjects of the threads.
 *
 * @param int $user_id
 * @param int $days
 *
 * @return array
 */
function phorum_db_get_message_subscriptions($user_id,$days=2,$forum_ids=null)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($user_id, "int");
    settype($days, "int");
    if ($forum_ids != null) phorum_db_sanitize_mixed($forums_ids, "int");

    $conn = phorum_db_mysql_connect();

    if($days > 0) {
         $timestr=" AND (".time()." - b.modifystamp) <= ($days * 86400)";
    } else {
        $timestr="";
    }

    if ($forum_ids != null and is_array($forum_ids)) {
        $forumidstr = " AND a.forum_id IN (" . implode(",", $forum_ids) . ")";
    } else {
        $forumidstr = "";
    }

    $sql = "select a.thread, a.forum_id, a.sub_type, b.subject,b.modifystamp,b.author,b.user_id,b.email,b.meta from {$PHORUM['subscribers_table']} as a,{$PHORUM['message_table']} as b where a.user_id=$user_id and b.message_id=a.thread and (a.sub_type=".PHORUM_SUBSCRIPTION_MESSAGE." or a.sub_type=".PHORUM_SUBSCRIPTION_BOOKMARK.")"."$timestr $forumidstr ORDER BY b.modifystamp desc";

    $res = mysql_query($sql, $conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    $arr=array();
    $forum_ids=array();

    while ($rec = mysql_fetch_assoc($res)){
        $rec["meta"] = unserialize($rec["meta"]);
        $unsub_url=phorum_get_url(PHORUM_CONTROLCENTER_URL, "panel=".PHORUM_CC_SUBSCRIPTION_THREADS, "unsub_id=".$rec['thread'], "unsub_forum=".$rec['forum_id'], "unsub_type=".$rec['sub_type']);
        $rec['unsubscribe_url']=$unsub_url;
        $arr[] = $rec;
        $forum_ids[]=$rec['forum_id'];
    }
    $arr['forum_ids']=$forum_ids;

    return $arr;
}

/**
 * Find out if a user is subscribed to a thread.
 *
 * @param int $forum_id
 * @param int $thread
 * @param int $user_id
 * @param int $type
 *
 * @return boolean
 */
function phorum_db_get_if_subscribed($forum_id, $thread, $user_id, $type=PHORUM_SUBSCRIPTION_MESSAGE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    settype($forum_id, "int");
    settype($thread, "int");
    settype($user_id, "int");
    settype($type, "int");

    $conn = phorum_db_mysql_connect();

    $sql = "select user_id from {$PHORUM['subscribers_table']} where forum_id=$forum_id and thread=$thread and user_id=$user_id and sub_type=$type";

    $res = mysql_query($sql, $conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    if (mysql_num_rows($res) > 0){
        $retval = TRUE;
    }else{
        $retval = FALSE;
    }

    return $retval;
}


/**
 * Retrieve the banlists for the current forum.
 * @param boolean $ordered
 * @return array
 */
function phorum_db_get_banlists($ordered=FALSE) {
    $PHORUM = $GLOBALS["PHORUM"];

    settype($ordered, "bool");

    $retarr = array();
    $forumstr = "";

    $conn = phorum_db_mysql_connect();

    // forum_id = 0 is for GLOBAL ban items.
    if(isset($PHORUM['forum_id']) && !empty($PHORUM['forum_id']))
        $forumstr = "WHERE forum_id = {$PHORUM['forum_id']} OR forum_id = 0";

    if(isset($PHORUM['vroot']) && !empty($PHORUM['vroot']))
        $forumstr .= " OR forum_id = {$PHORUM['vroot']}";

    $sql = "SELECT * FROM {$PHORUM['banlist_table']} $forumstr";

    if($ordered) {
        $sql.= " ORDER BY type, string";
    }

    $res = mysql_query($sql, $conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    if (mysql_num_rows($res) > 0){
        while($row = mysql_fetch_assoc($res)) {
            $retarr[$row['type']][$row['id']]=array('pcre'=>$row['pcre'],'string'=>$row['string'],'forum_id'=>$row['forum_id']);
        }
    }
    return $retarr;
}


/**
 * Retrieve an item from the banlists.
 *
 * @param int $banid
 *
 * @return array
 */
function phorum_db_get_banitem($banid) {
    $PHORUM = $GLOBALS["PHORUM"];

    $retarr = array();

    $conn = phorum_db_mysql_connect();

    settype($banid, "int");

    $sql = "SELECT * FROM {$PHORUM['banlist_table']} WHERE id = $banid";

    $res = mysql_query($sql, $conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    if (mysql_num_rows($res) > 0){
        while($row = mysql_fetch_assoc($res)) {
            $retarr=array('pcre'=>$row['pcre'],'string'=>$row['string'],'forumid'=>$row['forum_id'],'type'=>$row['type']);
        }
    }
    return $retarr;
}


/**
 * Delete one item from the banlists.
 * @param int $banid
 * @return boolean
 */
function phorum_db_del_banitem($banid) {
    $PHORUM = $GLOBALS["PHORUM"];

    settype($banid, "int");

    $conn = phorum_db_mysql_connect();

    $sql = "DELETE FROM {$PHORUM['banlist_table']} WHERE id = $banid";

    $res = mysql_query($sql, $conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    if(mysql_affected_rows($conn) > 0) {
        return TRUE;
    } else {
        return FALSE;
    }
}


/**
 * Add or modify a banlist-entry.
 *
 * @param int $type
 * @param int $pcre
 * @param string $string
 * @param int $forum_id
 * @param int $id
 *
 * @return boolean
 */
function phorum_db_mod_banlists($type,$pcre,$string,$forum_id,$id=0) {
    $PHORUM = $GLOBALS["PHORUM"];

    $retarr = array();

    $conn = phorum_db_mysql_connect();

    settype($type, "int");
    settype($pcre, "int");
    settype($forum_id, "int");
    settype($id, "int");

    if($id > 0) { // modifying an entry
        $sql = "UPDATE {$PHORUM['banlist_table']} SET forum_id = $forum_id, type = $type, pcre = $pcre, string = '".mysql_escape_string($string)."' where id = $id";
    } else { // adding an entry
        $sql = "INSERT INTO {$PHORUM['banlist_table']} (forum_id,type,pcre,string) VALUES($forum_id,$type,$pcre,'".mysql_escape_string($string)."')";
    }

    $res = mysql_query($sql, $conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    if(mysql_affected_rows($conn) > 0) {
        return TRUE;
    } else {
        return FALSE;
    }
}



/**
 * Get all private messages in a folder.
 *
 * @param mixed $folder - The folder to use. Either a special folder
 *                 (PHORUM_PM_INBOX or PHORUM_PM_OUTBOX) or the
 *                 id of a user's custom folder.
 * @param int $user_id - The user to retrieve messages for or NULL
 *                 to use the current user (default).
 * @param boolean $reverse - If set to a true value (default), sorting
 *                 of messages is done in reverse (newest first).
 *
 * @return array
 */

function phorum_db_pm_list($folder, $user_id = NULL, $reverse = TRUE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");
    settype($reverse, "bool");

    $folder_sql = "user_id = $user_id AND ";
    if (is_numeric($folder)) {
        $folder_sql .= "pm_folder_id=$folder";
    } elseif ($folder == PHORUM_PM_INBOX || $folder == PHORUM_PM_OUTBOX) {
        $folder_sql .= "pm_folder_id=0 AND special_folder='$folder'";
    } else {
        die ("Illegal folder '$folder' requested for user id '$user_id'");
    }

    $sql = "SELECT m.pm_message_id, from_user_id, from_username, subject, " .
           "datestamp, meta, pm_xref_id, user_id, pm_folder_id, " .
           "special_folder, read_flag, reply_flag " .
           "FROM {$PHORUM['pm_messages_table']} as m, {$PHORUM['pm_xref_table']} as x " .
           "WHERE $folder_sql " .
           "AND x.pm_message_id = m.pm_message_id " .
           "ORDER BY x.pm_message_id " . ($reverse ? "DESC" : "ASC");
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    $list = array();
    if (mysql_num_rows($res) > 0){
        while($row = mysql_fetch_assoc($res)) {

            // Add the recipient information unserialized to the message..
            $meta = unserialize($row['meta']);
            $row['recipients'] = $meta['recipients'];

            $list[$row["pm_message_id"]]=$row;
        }
    }

    return $list;
}

/**
 * Retrieve a private message from the database.
 *
 * @param int $pm_id - The id for the private message to retrieve.
 * @param string $folder - The folder to retrieve the message from or
 *                    NULL if the folder does not matter.
 * @param int $user_id - The user to retrieve messages for or NULL
 *                 to use the current user (default).
 *
 * @return mixed
 *              Return an array (the message) on success,
 *              return NULL if the message was not found.
 */
function phorum_db_pm_get($pm_id, $folder = NULL, $user_id = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");
    settype($pm_id, "int");

    if (is_null($folder)) {
        $folder_sql = '';
    } elseif (is_numeric($folder)) {
        $folder_sql = "pm_folder_id=$folder AND ";
    } elseif ($folder == PHORUM_PM_INBOX || $folder == PHORUM_PM_OUTBOX) {
        $folder_sql = "pm_folder_id=0 AND special_folder='$folder' AND ";
    } else {
        die ("Illegal folder '$folder' requested for message id '$pm_id'");
    }

    $sql = "SELECT * " .
           "FROM {$PHORUM['pm_messages_table']} as m, {$PHORUM['pm_xref_table']} as x " .
           "WHERE $folder_sql x.pm_message_id = $pm_id AND x.user_id = $user_id " .
           "AND x.pm_message_id = m.pm_message_id";

    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    if (mysql_num_rows($res) > 0){
        $row = mysql_fetch_assoc($res);

        // Add the recipient information unserialized to the message..
        $meta = unserialize($row['meta']);
        $row['recipients'] = $meta['recipients'];

        return $row;
    } else {
        return NULL;
    }
}

/**
 * Create a new folder for a user.
 *
 * @param string $foldername - The name of the folder to create.
 * @param int $user_id - The user to create the folder for or
 *                  NULL to use the current user (default).
 *
 * @return the folder id of the created folder
 */
function phorum_db_pm_create_folder($foldername, $user_id = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");

    $sql = "INSERT INTO {$PHORUM['pm_folders_table']} SET " .
           "user_id=$user_id, " .
           "foldername='".mysql_escape_string($foldername)."'";

    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    $folder_id = 0;
    if ($res){
        $folder_id = mysql_insert_id($conn);
    }

    return $folder_id;
}

/**
 * Rename a folder for a user.
 *
 * @param int $folder_id - The id of the folder to rename.
 * @param string $newname - The new name for the folder.
 * @param int $user_id - The user to rename the folder for or
 *                  NULL to use the current user (default).
 *
 * @return boolean
 */
function phorum_db_pm_rename_folder($folder_id, $newname, $user_id = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");
    settype($folder_id, "int");

    $sql = "UPDATE {$PHORUM['pm_folders_table']} " .
           "SET foldername = '".mysql_escape_string($newname)."' " .
           "WHERE pm_folder_id = $folder_id AND user_id = $user_id";

    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");
    return $res;
}



/**
 * Delete a folder for a user. Along with the folder, all contained
 * messages are deleted as well.
 *
 * @param int $folder_id - The id of the folder to delete.
 * @param int $user_id - The user to delete the folder for or
 *                  NULL to use the current user (default).
 *
 * @return boolean
 */
function phorum_db_pm_delete_folder($folder_id, $user_id = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");
    settype($folder_id, "int");

    // Get messages in this folder and delete them.
    $list = phorum_db_pm_list($folder_id, $user_id);
    foreach ($list as $id => $data) {
        phorum_db_pm_delete($id, $folder_id, $user_id);
    }

    // Delete the folder itself.
    $sql = "DELETE FROM {$PHORUM['pm_folders_table']} " .
           "WHERE pm_folder_id = $folder_id AND user_id = $user_id";
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");
    return $res;
}

/**
 * Retrieve the list of folders for a user.
 *
 * @param int $user_id - The user to retrieve folders for or NULL
 *                 to use the current user (default).
 * @param boolean $count_messages - Count the number of messages for the
 *                 folders. Default, this is not done.
 *
 * @return array
 */
function phorum_db_pm_getfolders($user_id = NULL, $count_messages = FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");
    settype($count_messages, "bool");

    // Setup the list of folders. Our special folders are
    // not in the database, so these are added here.
    $folders = array(
        PHORUM_PM_INBOX => array(
            'id'   => PHORUM_PM_INBOX,
            'name' => $PHORUM["DATA"]["LANG"]["INBOX"],
        ),
    );

    // Select all custom folders for the user.
    $sql = "SELECT * FROM {$PHORUM['pm_folders_table']} " .
           "WHERE user_id = $user_id ORDER BY foldername";
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    // Add them to the folderlist.
    if (mysql_num_rows($res) > 0){
        while (($row = mysql_fetch_assoc($res))) {
            $folders[$row["pm_folder_id"]] = array(
                'id' => $row["pm_folder_id"],
                'name' => $row["foldername"],
            );
        }
    }

    // Add the outgoing box.
    $folders[PHORUM_PM_OUTBOX] = array(
        'id'   => PHORUM_PM_OUTBOX,
        'name' => $PHORUM["DATA"]["LANG"]["SentItems"],
    );

    // Count messages if requested.
    if ($count_messages)
    {
        // Initialize counters.
        foreach ($folders as $id => $data) {
            $folders[$id]["total"] = $folders[$id]["new"] = 0;
        }

        // Collect count information.
        $sql = "SELECT pm_folder_id, special_folder, " .
               "count(*) as total, (count(*) - sum(read_flag)) as new " .
               "FROM {$PHORUM['pm_xref_table']}  " .
               "WHERE user_id = $user_id " .
               "GROUP BY pm_folder_id, special_folder";
        $res = mysql_query($sql, $conn);
        if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

        // Add counters to the folderlist.
        if (mysql_num_rows($res) > 0){
            while (($row = mysql_fetch_assoc($res))) {
                $folder_id = $row["pm_folder_id"] ? $row["pm_folder_id"] : $row["special_folder"];
                // If there are stale messages, we do not want them
                // to create non-existant mailboxes in the list.
                if (isset($folders[$folder_id])) {
                    $folders[$folder_id]["total"] = $row["total"];
                    $folders[$folder_id]["new"] = $row["new"];
                }
            }
        }
    }

    return $folders;
}

/**
 * Compute the number of private messages a user has
 * and return both the total and the number unread.
 *
 * @param mixed $folder - The folder to use. Either a special folder
 *                 (PHORUM_PM_INBOX or PHORUM_PM_OUTBOX), the
 *                 id of a user's custom folder or
 *                 PHORUM_PM_ALLFOLDERS for all folders.
 * @param int $user_id - The user to retrieve messages for or NULL
 *                 to use the current user (default).
 *
 * @return array
 */
function phorum_db_pm_messagecount($folder, $user_id = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");

    if (is_numeric($folder)) {
        $folder_sql = "pm_folder_id=$folder AND";
    } elseif ($folder == PHORUM_PM_INBOX || $folder == PHORUM_PM_OUTBOX) {
        $folder_sql = "pm_folder_id=0 AND special_folder='$folder' AND";
    } elseif ($folder == PHORUM_PM_ALLFOLDERS) {
        $folder_sql = '';
    } else {
        die ("Illegal folder '$folder' requested for user id '$user_id'");
    }

    $sql = "SELECT count(*) as total, (count(*) - sum(read_flag)) as new " .
           "FROM {$PHORUM['pm_xref_table']}  " .
           "WHERE $folder_sql user_id = $user_id";

    $messagecount=array("total" => 0, "new" => 0);

    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    if (mysql_num_rows($res) > 0){
        $row = mysql_fetch_assoc($res);
        $messagecount["total"] = $row["total"];
        $messagecount["new"] = ($row["new"] >= 1) ? $row["new"] : 0;
    }

    return $messagecount;
}

/**
 * Check if the user has new private messages.
 * This is useful in case you only want to know whether the user has
 * new messages or not and when you are not interested in the exact amount
 * of new messages.
 *
 * @param int $user_id - The user to retrieve messages for or NULL
 *                 to use the current user (default).
 * @return boolean
 *              A true value, in case there are new messages available.
 */
function phorum_db_pm_checknew($user_id = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");

    $sql = "SELECT user_id " .
           "FROM {$PHORUM['pm_xref_table']} " .
           "WHERE user_id = $user_id AND read_flag = 0 LIMIT 1";
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    return mysql_num_rows($res);
}

/**
 * Insert a private message in the database. The return value
 * is the pm_message_id of the created message.
 *
 * @param string $subject - The subject for the private message.
 * @param string $message - The message text for the private message.
 * @param mixed $to - A single user_id or an array of user_ids for the recipients.
 * @param int $from - The user_id of the sender. The current user is used in case
 *               the parameter is set to NULL (default).
 * @param boolean $keepcopy - If set to a true value, a copy of the mail will be put in
 *                   the outbox of the user. Default value is FALSE.
 *
 * @return int
 */
function phorum_db_pm_send($subject, $message, $to, $from=NULL, $keepcopy=FALSE)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    // Prepare the sender.
    if ($from == NULL) $from = $PHORUM['user']['user_id'];
    settype($from, "int");
    $fromuser = phorum_db_user_get($from, FALSE);
    if (! $fromuser) trigger_error(
        "Unknown sender user_id '$from'", E_USER_ERROR
    );

    // This array will be filled with xref database entries.
    $xref_entries = array();

    // Prepare the list of recipients.
    $rcpts = array();
    if (! is_array($to)) $to = array($to);
    foreach ($to as $user_id) {
        settype($user_id, "int");
        $user = phorum_db_user_get($user_id, FALSE);
        if (! $user) trigger_error(
            "Unknown recipient user_id '$user_id'", E_USER_ERROR
        );
        $rcpts[$user_id] = array(
            'user_id' => $user_id,
            'username' => $user["username"],
            'read_flag' => 0,
        );
        $xref_entries[] = array(
            'user_id' => $user_id,
            'pm_folder_id' => 0,
            'special_folder' => PHORUM_PM_INBOX,
            'read_flag' => 0,
        );
    }

    // Keep copy of this message in outbox?
    if ($keepcopy) {
        $xref_entries[] = array(
            'user_id' => $from,
            'pm_folder_id' => 0,
            'special_folder' => PHORUM_PM_OUTBOX,
            'read_flag' => 1,
        );
    }

    // Prepare message meta data.
    $meta = mysql_escape_string(serialize(array(
        'recipients' => $rcpts
    )));

    // Create the message.
    $sql = "INSERT INTO {$PHORUM['pm_messages_table']} SET " .
           "from_user_id = $from, " .
           "from_username = '".mysql_escape_string($fromuser["username"])."', " .
           "subject = '".mysql_escape_string($subject)."', " .
           "message = '".mysql_escape_string($message)."', " .
           "datestamp = '".time()."', " .
           "meta = '$meta'";
    mysql_query($sql, $conn);
    if ($err = mysql_error()) {
        phorum_db_mysql_error("$err: $sql");
        return;
    }

    // Get the message id.
    $pm_message_id = mysql_insert_id($conn);

    // Put the message in the recipient inboxes.
    foreach ($xref_entries as $xref) {
        $sql = "INSERT INTO {$PHORUM['pm_xref_table']} SET " .
               "user_id = {$xref['user_id']}, " .
               "pm_folder_id={$xref['pm_folder_id']}, " .
               "special_folder='{$xref['special_folder']}', " .
               "pm_message_id=$pm_message_id, " .
               "read_flag = {$xref['read_flag']}, " .
               "reply_flag = 0";
        mysql_query($sql, $conn);
        if ($err = mysql_error()) {
            phorum_db_mysql_error("$err: $sql");
            return;
        }

    }

    return $pm_message_id;
}

/**
 * Update a flag for a private message.
 *
 * @param int $pm_id - The id of the message to update.
 * @param int $flag - The flag to update. Options are PHORUM_PM_READ_FLAG
 *               and PHORUM_PM_REPLY_FLAG.
 * @param boolean $value - The value for the flag (TRUE or FALSE).
 * @param int $user_id - The user to set a flag for or NULL
 *                 to use the current user (default).
 *
 * @return boolean
 */
function phorum_db_pm_setflag($pm_id, $flag, $value, $user_id = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    settype($pm_id, "int");

    if ($flag != PHORUM_PM_READ_FLAG && $flag != PHORUM_PM_REPLY_FLAG) {
        trigger_error("Invalid value for \$flag in function phorum_db_pm_setflag(): $flag", E_USER_WARNING);
        return 0;
    }

    $value = $value ? 1 : 0;

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");

    // Update the flag in the database.
    $sql = "UPDATE {$PHORUM['pm_xref_table']} " .
           "SET $flag = $value " .
           "WHERE pm_message_id = $pm_id AND user_id = $user_id";
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    // Update message counters.
    if ($flag == PHORUM_PM_READ_FLAG) {
        phorum_db_pm_update_message_info($pm_id);
    }

    return $res;
}

/**
 * Delete a private message from a folder.
 *
 * @param int $pm_id - The id of the private message to delete
 * @param string $folder - The folder from which to delete the message
 * @param int $user_id - The user to delete the message for or NULL
 *                 to use the current user (default).
 *
 * @return boolean
 */
function phorum_db_pm_delete($pm_id, $folder, $user_id = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    settype($pm_id, "int");

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");

    if (is_numeric($folder)) {
        $folder_sql = "pm_folder_id=$folder AND";
    } elseif ($folder == PHORUM_PM_INBOX || $folder == PHORUM_PM_OUTBOX) {
        $folder_sql = "pm_folder_id=0 AND special_folder='$folder' AND";
    } else {
        die ("Illegal folder '$folder' requested for user id '$user_id'");
    }

    $sql = "DELETE FROM {$PHORUM['pm_xref_table']} " .
           "WHERE $folder_sql " .
           "user_id = $user_id AND pm_message_id = $pm_id";

    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    // Update message counters.
    phorum_db_pm_update_message_info($pm_id);

    return $res;
}

/**
 * Move a private message to a different folder.
 *
 * @param int $pm_id - The id of the private message to move.
 * @param int $from - The folder to move the message from.
 * @param int $to - The folder to move the message to.
 * @param int $user_id - The user to move the message for or NULL
 *                 to use the current user (default).
 *
 * @return boolean
 */
function phorum_db_pm_move($pm_id, $from, $to, $user_id = NULL)
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    settype($pm_id, "int");

    if ($user_id == NULL) $user_id = $PHORUM['user']['user_id'];
    settype($user_id, "int");

    if (is_numeric($from)) {
        $folder_sql = "pm_folder_id=$from AND";
    } elseif ($from == PHORUM_PM_INBOX || $from == PHORUM_PM_OUTBOX) {
        $folder_sql = "pm_folder_id=0 AND special_folder='$from' AND";
    } else {
        die ("Illegal source folder '$from' specified");
    }

    if (is_numeric($to)) {
        $pm_folder_id = $to;
        $special_folder = 'NULL';
    } elseif ($to == PHORUM_PM_INBOX || $to == PHORUM_PM_OUTBOX) {
        $pm_folder_id = 0;
        $special_folder = "'$to'";
    } else {
        die ("Illegal target folder '$to' specified");
    }

    $sql = "UPDATE {$PHORUM['pm_xref_table']} SET " .
           "pm_folder_id = $pm_folder_id, " .
           "special_folder = $special_folder " .
           "WHERE $folder_sql user_id = $user_id AND pm_message_id = $pm_id";

    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");
    return $res;
}

/**
 * Update the meta information for a message.  If we
 * detect that no xrefs are available for the message anymore,
 * the message will be deleted from the database. So this function
 * has to be called after setting the read_flag and after deleting
 * a message.
 * PMTODO maybe we need some locking here to prevent concurrent
 * updates of the message info.
 *
 * @param int $pm_id
 *
 * @return boolean
 */
function phorum_db_pm_update_message_info($pm_id)
{
    $PHORUM = $GLOBALS['PHORUM'];

    $conn = phorum_db_mysql_connect();

    settype($pm_id, "int");

    // Find the message record. Return immediately if no message is found.
    $sql = "SELECT * " .
           "FROM {$PHORUM['pm_messages_table']} " .
           "WHERE pm_message_id = $pm_id";
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");
    if (mysql_num_rows($res) == 0) return $res;
    $pm = mysql_fetch_assoc($res);

    // Find the xrefs for this message.
    $sql = "SELECT * " .
           "FROM {$PHORUM['pm_xref_table']} " .
           "WHERE pm_message_id = $pm_id";
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    // No xrefs left? Then the message can be fully deleted.
    if (mysql_num_rows($res) == 0) {
        $sql = "DELETE FROM {$PHORUM['pm_messages_table']} " .
               "WHERE pm_message_id = $pm_id";
        $res = mysql_query($sql, $conn);
        if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");
        return $res;
    }

    // Update the read flags for the recipients in the meta data.
    $meta = unserialize($pm["meta"]);
    $rcpts = $meta["recipients"];
    while ($row = mysql_fetch_assoc($res)) {
        // Only update if available. A kept copy in the outbox will
        // not be in the meta list, so if the copy is read, the
        // meta data does not have to be updated here.
        if (isset($rcpts[$row["user_id"]])) {
            $rcpts[$row["user_id"]]["read_flag"] = $row["read_flag"];
        }
    }
    $meta["recipients"] = $rcpts;

    // Store the new meta data.
    $meta = mysql_escape_string(serialize($meta));
    $sql = "UPDATE {$PHORUM['pm_messages_table']} " .
           "SET meta = '$meta' " .
           "WHERE pm_message_id = $pm_id";
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");
    return $res;
}

/**
 * Check if a certain user is buddy of another user.
 * The function return the pm_buddy_id in case the user is a buddy
 * or NULL in case the user isn't.
 *
 * @param int $buddy_user_id - The user_id to check for if it's a buddy.
 * @param int $user_id - The user_id for which the buddy list must be
 *                  checked or NULL to use the current user (default).
 *
 * @return boolean
 */
function phorum_db_pm_is_buddy($buddy_user_id, $user_id = NULL)
{
    $PHORUM = $GLOBALS['PHORUM'];
    $conn = phorum_db_mysql_connect();
    settype($buddy_user_id, "int");
    if (is_null($user_id)) $user_id = $PHORUM["user"]["user_id"];
    settype($user_id, "int");

    $sql = "SELECT pm_buddy_id FROM {$PHORUM['pm_buddies_table']} " .
           "WHERE user_id = $user_id AND buddy_user_id = $buddy_user_id";

    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");
    if (mysql_num_rows($res)) {
        $row = mysql_fetch_array($res);
        return $row[0];
    } else {
        return NULL;
    }
}

/**
 * Add a buddy for a user. It will return the
 * pm_buddy_id for the new buddy. If the buddy already exists,
 * it will return the existing pm_buddy_id. If a non-existant
 * user_id is used for the buddy_user_id, the function will
 * return NULL.
 *
 * @param int $buddy_user_id - The user_id that has to be added as a buddy.
 * @param int $user_id - The user_id the buddy has to be added for or
 *                  NULL to use the current user (default).
 *
 * @return mixed
 */
function phorum_db_pm_buddy_add($buddy_user_id, $user_id = NULL)
{
    $PHORUM = $GLOBALS['PHORUM'];
    $conn = phorum_db_mysql_connect();
    settype($buddy_user_id, "int");
    if (is_null($user_id)) $user_id = $PHORUM["user"]["user_id"];
    settype($user_id, "int");

    // Check if the buddy_user_id is a valid user_id.
    $valid = phorum_db_user_get($buddy_user_id, FALSE);
    if (! $valid) return NULL;

    $pm_buddy_id = phorum_db_pm_is_buddy($buddy_user_id);
    if (is_null($pm_buddy_id)) {
        $sql = "INSERT INTO {$PHORUM['pm_buddies_table']} SET " .
               "user_id = $user_id, " .
               "buddy_user_id = $buddy_user_id";
        $res = mysql_query($sql, $conn);
        if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");
        $pm_buddy_id = mysql_insert_id($conn);
    }

    return $pm_buddy_id;
}

/**
 * Delete a buddy for a user.
 *
 * @param int $buddy_user_id - The user_id that has to be deleted as a buddy.
 * @param int $user_id - The user_id the buddy has to be delete for or
 *                  NULL to use the current user (default).
 *
 * @return boolean
 */
function phorum_db_pm_buddy_delete($buddy_user_id, $user_id = NULL)
{
    $PHORUM = $GLOBALS['PHORUM'];
    $conn = phorum_db_mysql_connect();
    settype($buddy_user_id, "int");
    if (is_null($user_id)) $user_id = $PHORUM["user"]["user_id"];
    settype($user_id, "int");

    $sql = "DELETE FROM {$PHORUM['pm_buddies_table']} WHERE " .
           "buddy_user_id = $buddy_user_id AND user_id = $user_id";
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");
    return $res;
}

/**
 * Retrieve a list of buddies for a user.
 *
 * @param int $user_id - The user_id for which to retrieve the buddies
 *                  or NULL to user the current user (default).
 * @param boolean $find_mutual - Wheter to find mutual buddies or not (default not).
 *
 * @return array
 */
function phorum_db_pm_buddy_list($user_id = NULL, $find_mutual = FALSE)
{
    $PHORUM = $GLOBALS['PHORUM'];
    $conn = phorum_db_mysql_connect();
    if (is_null($user_id)) $user_id = $PHORUM["user"]["user_id"];
    settype($user_id, "int");

    // Get all buddies for this user.
    $sql = "SELECT buddy_user_id FROM {$PHORUM['pm_buddies_table']} " .
           "WHERE user_id = $user_id";
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    $buddies = array();
    if (mysql_num_rows($res)) {
        while ($row = mysql_fetch_array($res)) {
            $buddies[$row[0]] = array (
                'user_id' => $row[0]
            );
        }
    }

    // If we do not have to lookup mutual buddies, we're done.
    if (! $find_mutual) return $buddies;

    // Initialize mutual buddy value.
    foreach ($buddies as $id => $data) {
        $buddies[$id]["mutual"] = FALSE;
    }

    // Get all mutual buddies.
    $sql = "SELECT DISTINCT a.buddy_user_id " .
           "FROM {$PHORUM['pm_buddies_table']} as a, {$PHORUM['pm_buddies_table']} as b " .
           "WHERE a.user_id=$user_id " .
           "AND b.user_id=a.buddy_user_id " .
           "AND b.buddy_user_id=$user_id";
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    if (mysql_num_rows($res)) {
        while ($row = mysql_fetch_array($res)) {
            $buddies[$row[0]]["mutual"] = TRUE;
        }
    }

    return $buddies;
}

/**
 * Delete old messages.
 *
 * @param timestamp $time  - holds the timestamp the comparison is done against
 * @param int $forum - delete threads from this forum
 * @param int $mode  - should we compare against datestamp (1) or modifystamp (2)
 *
 * @return int
 */
function phorum_db_prune_oldThreads($time,$forum=0,$mode=1)
{
    $PHORUM = $GLOBALS['PHORUM'];

    settype($time, "int");
    settype($forum, "int");
    settype($mode, "int");

    $conn = phorum_db_mysql_connect();
    $numdeleted=0;

    $compare_field = "datestamp";
    if($mode == 2) {
      $compare_field = "modifystamp";
    }

    $forummode="";
    if($forum > 0) {
      $forummode=" AND forum_id = $forum";
    }

    // retrieving which threads to delete
    $sql = "select thread from {$PHORUM['message_table']} where $compare_field < $time AND parent_id=0 $forummode";

    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    $ret=array();
    while($row=mysql_fetch_row($res)) {
        $ret[]=$row[0];
    }

    $thread_ids=implode(",",$ret);

    if(count($ret)) {
      // deleting the messages/threads
      $sql="delete from {$PHORUM['message_table']} where thread IN ($thread_ids)";
      $res = mysql_query($sql, $conn);
      if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

      $numdeleted = mysql_affected_rows($conn);
      if($numdeleted < 0) {
        $numdeleted=0;
      }

      // deleting the associated notification-entries
      $sql="delete from {$PHORUM['subscribers_table']} where thread IN ($thread_ids)";
      $res = mysql_query($sql, $conn);
      if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");


      // optimizing the message-table
      $sql="optimize table {$PHORUM['message_table']}";
      $res = mysql_query($sql, $conn);
      if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");
    }

    return $numdeleted;
}

/**
 * Split a thread.
 *
 * @param int $message
 * @param int $forum_id
 *
 * @return void
 */
function phorum_db_split_thread($message, $forum_id)
{
    settype($message, "int");
    settype($forum_id, "int");

    if($message > 0 && $forum_id > 0){
        // get message tree for update thread id
        $tree =phorum_db_get_messagetree($message, $forum_id);
        $queries =array();
        $queries[0]="UPDATE {$GLOBALS['PHORUM']['message_table']} SET thread='$message', parent_id='0' WHERE message_id ='$message'";
        $queries[1]="UPDATE {$GLOBALS['PHORUM']['message_table']} SET thread='$message' WHERE message_id IN ($tree)";
        phorum_db_run_queries($queries);
    }
}

/**
 * Returns the maximum message-id in the database.
 * @return int
 */
function phorum_db_get_max_messageid() {
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();
    $maxid = 0;

    $sql="SELECT max(message_id) from ".$PHORUM["message_table"];
    $res = mysql_query($sql, $conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    if (mysql_num_rows($res) > 0){
        $row = mysql_fetch_row($res);
        $maxid = $row[0];
    }

    return $maxid;
}

/**
 * Increments the viewcount for a post.
 *
 * @param int $message_id
 *
 * @return boolean
 */
function phorum_db_viewcount_inc($message_id) {
    if($message_id < 1 || !is_numeric($message_id)) {
        return FALSE;
    }

    $conn = phorum_db_mysql_connect();
    $sql="UPDATE ".$GLOBALS['PHORUM']['message_table']." SET viewcount=viewcount+1 WHERE message_id=$message_id";
    $res = mysql_query($sql, $conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");


    return TRUE;

}

/**
 * Rebuilds the search-data - called from the admin currently
 *
 * @param
 *
 * @return boolean
 */

function phorum_db_rebuild_search_data() {
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    $sql="truncate {$PHORUM['search_table']}";
    mysql_query($sql,$conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");


    $sql="insert into {$PHORUM['search_table']} (message_id,search_text,forum_id)
          select message_id, concat(author, ' | ', subject, ' | ', body), forum_id from {$PHORUM['message_table']}";

    mysql_query($sql,$conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    return TRUE;
}

function phorum_db_rebuild_user_posts() {
    $PHORUM = $GLOBALS["PHORUM"];

    $all_users = phorum_user_get_list();

    $conn = phorum_db_mysql_connect();

    $sql="select user_id,count(*) as postcnt from {$PHORUM['message_table']} group by user_id";

    $res = mysql_query($sql,$conn);

    while($row = mysql_fetch_assoc($res)) {
        $all_users[$row['user_id']]['postcnt'] = $row['postcnt'];
    }

    // need to do this second loop to include users which don't have posts (anymore)
    foreach($all_users as $user_id => $userdata) {
        $posts = 0;
        if(isset($userdata['postcnt'])) {
            $posts = $userdata['postcnt'];
        }
        $user=array("user_id"=>$user_id,"posts"=>$posts);
        phorum_user_save_simple($user);
    }


    return TRUE;

}

/**
 * Find users that have a certain string in one of the custom fields.
 *
 * @param int $field_id
 *              The custom field to search.
 * @param string $field_content
 *              The string to search for.
 * @param boolean $match
 *              If FALSE, the $field_content must match exactly,
 *              if TRUE, the $field_content can be a substring of the custom field.
 * @return mixed
 *              Return an array of users if any matched, or NULL if there were no
 *              matches.
 */
function phorum_db_get_custom_field_users($field_id,$field_content,$match) {


    $field_id=(int)$field_id;
    $field_content=mysql_escape_string($field_content);

    $conn = phorum_db_mysql_connect();

    if($match) {
        $compval="LIKE";
    } else {
        $compval="=";
    }

    $sql = "select user_id from {$GLOBALS['PHORUM']['user_custom_fields_table']} where type=$field_id and data $compval '$field_content'";
    $res = mysql_query($sql, $conn);

    if ($err = mysql_error()) phorum_db_mysql_error("$err: $sql");

    if(mysql_num_rows($res)) {
        $retval=array();
        while ($row = mysql_fetch_row($res)){
            $retval[$row[0]]=$row[0];
        }
    } else {
        $retval=NULL;
    }

    return $retval;
}

/**
 * Translates a message searching meta query into a real SQL WHERE
 * statement for this database backend. The meta query can be used to
 * define extended SQL queries, based on a meta description of the
 * search that has to be performed on the database.
 *
 * The meta query is an array, containing:
 * - query conditions
 * - grouping using "(" and ")"
 * - AND/OR specifications using "AND" and "OR".
 *
 * The query conditions are arrays, containing the following elements:
 *
 * - condition
 *
 *   A description of a condition. The syntax for this is:
 *   <field name to query> <operator> <match specification>
 *
 *   The <field name to query> is a field in the message query that
 *   we are running in this function.
 *
 *   The <operator> can be one of "=", "!=", "<", "<=", ">", ">=".
 *   Note that there is nothing like "LIKE" or "NOT LIKE". If a "LIKE"
 *   query has to be done, then that is setup through the
 *   <match specification> (see below).
 *
 *   The <match specification> tells us with what the field should be
 *   matched. The string "QUERY" inside the specification is preserved to
 *   specify at which spot in the query the "query" element from the
 *   condition array should be inserted. If "QUERY" is not available in
 *   the specification, then a match is made on the exact value in the
 *   specification. To perform "LIKE" searches (case insensitive wildcard
 *   searches), you can use the "*" wildcard character in the specification
 *   to do so.
 *
 * - query
 *
 *   The data to use in the query, in case the condition element has a
 *   <match specification> that uses "QUERY" in it.
 *
 * Example:
 *
 * $metaquery = array(
 *     array(
 *         "condition"  =>  "field1 = *QUERY*",
 *         "query"      =>  "test data"
 *     ),
 *     "AND",
 *     "(",
 *     array("condition"  => "field2 = whatever"),
 *     "OR",
 *     array("condition"  => "field2 = something else"),
 *     ")"
 * );
 *
 * For MySQL, this would be turned into the MySQL WHERE statement:
 * ... WHERE field1 LIKE '%test data%'
 *     AND (field2 = 'whatever' OR field2 = 'something else')
 *
 * @param $metaquery - A meta query description array.
 * @return $return - An array containing two elements. The first element
 *                   is either TRUE or FALSE, based on the success state
 *                   of the function call (FALSE means that there was an
 *                   error). The second argument contains either a
 *                   WHERE statement or an error message.
 */
function phorum_db_metaquery_compile($metaquery)
{
    $where = '';

    $expect_condition  = TRUE;
    $expect_groupstart = TRUE;
    $expect_groupend   = FALSE;
    $expect_combine    = FALSE;
    $in_group          = 0;

    foreach ($metaquery as $part)
    {
        // Found a new condition.
        if ($expect_condition && is_array($part))
        {
            $cond = trim($part["condition"]);
            if (preg_match('/^([\w_\.]+)\s+(!?=|<=?|>=?)\s+(\S*)$/', $cond, $m))
            {
                $field = $m[1];
                $comp  = $m[2];
                $match = $m[3];

                $matchtokens = preg_split(
                    '/(\*|QUERY|NULL)/',
                    $match, -1,
                    PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY
                );

                $matchsql = "'";
                $is_like_query = FALSE;
                foreach ($matchtokens as $m) {
                    if ($m == '*') {
                        $is_like_query = TRUE;
                        $matchsql .= '%';
                    } elseif ($m == 'QUERY') {
                        $matchsql .= mysql_escape_string($part["query"]);
                    } else {
                        $matchsql .= mysql_escape_string($m);
                    }
                }
                $matchsql .= "'";

                if ($is_like_query)
                {
                    if ($comp == '=') { $comp = ' LIKE '; }
                    elseif ($comp == '!=') { $comp = ' NOT LIKE '; }
                    else return array(
                        FALSE,
                        "Illegal metaquery token " . htmlspecialchars($cond) .
                        ": wildcard match does not combine with $comp operator"
                    );
                }

                $where .= "$field $comp $matchsql ";
            } else {
                return array(
                    FALSE,
                    "Illegal metaquery token " . htmlspecialchars($cond) .
                    ": condition does not match the required format"
                );
            }

            $expect_condition   = FALSE;
            $expect_groupstart  = FALSE;
            $expect_groupend    = $in_group;
            $expect_combine     = TRUE;
        }
        // Found a new group start.
        elseif ($expect_groupstart && $part == '(')
        {
            $where .= "(";
            $in_group ++;

            $expect_condition   = TRUE;
            $expect_groupstart  = FALSE;
            $expect_groupend    = FALSE;
            $expect_combine     = FALSE;
        }
        // Found a new group end.
        elseif ($expect_groupend && $part == ')')
        {
            $where .= ") ";
            $in_group --;

            $expect_condition   = FALSE;
            $expect_groupstart  = FALSE;
            $expect_groupend    = $in_group;
            $expect_combine     = TRUE;
        }
        // Found a combine token (AND or OR).
        elseif ($expect_combine && preg_match('/^(OR|AND)$/i', $part, $m))
        {
            $where .= strtoupper($m[1]) . " ";

            $expect_condition   = TRUE;
            $expect_groupstart  = TRUE;
            $expect_groupend    = FALSE;
            $expect_combine     = FALSE;
        }
        // Unexpected or illegal token.
        else trigger_error(
            "Internal error: unexpected token in metaquery description: " .
            (is_array($part) ? "condition" : htmlspecialchars($part)),
            E_USER_ERROR
        );
    }

    if ($expect_groupend) die ("Internal error: unclosed group in metaquery");

    // If the metaquery is empty, then provide a safe true WHERE statement.
    if ($where == '') { $where = "1 = 1"; }

    return array(TRUE, $where);
}

/**
 * Run a search on the messages, using a metaquery. See the documentation
 * for the phorum_db_metaquery_compile() function for more info on the
 * metaquery syntax.
 *
 * The query that is run here, does create a view on the messages, which
 * includes some thread and user info. This is used so these can also
 * be taken into account when selecting messages. For the condition elements
 * in the meta query, you can use fully qualified field names for the
 * <field name to query>. You can use message.*, user.* and thread.* for this.
 *
 * The primary goal for this function is to provide a backend for the
 * message pruning interface.
 *
 * @param $metaquery - A metaquery array.
 * @return $messages - An array of message records.
 */
function phorum_db_metaquery_messagesearch($metaquery)
{
    $PHORUM = $GLOBALS["PHORUM"];

    // Compile the metaquery into a where statement.
    list($success, $where) = phorum_db_metaquery_compile($metaquery);
    if (!$success) trigger_error($where, E_USER_ERROR);

    // Build the SQL query.
    $sql = "
      SELECT message.message_id,
             message.thread,
             message.parent_id,
             message.forum_id,
             message.subject,
             message.author,
             message.datestamp,
             message.body,
             message.ip,
             message.status,
             message.user_id,
             user.username       user_username,
             thread.closed       thread_closed,
             thread.modifystamp  thread_modifystamp,
             thread.thread_count thread_count
      FROM   {$PHORUM["message_table"]} as thread,
             {$PHORUM["message_table"]} as message
                 LEFT JOIN {$PHORUM["user_table"]} user
                 ON message.user_id = user.user_id
      WHERE  message.thread  = thread.message_id AND
             ($where)
      ORDER BY message_id ASC
    ";

    $conn = phorum_db_mysql_connect();
    $res = mysql_query($sql, $conn);
    if ($err = mysql_error()) {
        phorum_db_mysql_error("$err: $sql");
        return NULL;
    } else {
        $messages = array();
        if(mysql_num_rows($res)) {
            while ($row = mysql_fetch_assoc($res)) {
                $messages[$row["message_id"]] = $row;
            }
        }
        return $messages;
    }
}


/**
 * Create the tables needed in the database.
 * @return string
 *              Return the empty string on success, error message on failure.
 */
function phorum_db_create_tables()
{
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    $retmsg = "";

    $queries = array(

        // create tables
        "CREATE TABLE {$PHORUM['forums_table']} ( forum_id int(10) unsigned NOT NULL auto_increment, name varchar(50) NOT NULL default '', active smallint(6) NOT NULL default '0', description text NOT NULL default '', template varchar(50) NOT NULL default '', folder_flag tinyint(1) NOT NULL default '0', parent_id int(10) unsigned NOT NULL default '0', list_length_flat int(10) unsigned NOT NULL default '0', list_length_threaded int(10) unsigned NOT NULL default '0', moderation int(10) unsigned NOT NULL default '0', threaded_list tinyint(4) NOT NULL default '0', threaded_read tinyint(4) NOT NULL default '0', float_to_top tinyint(4) NOT NULL default '0', check_duplicate tinyint(4) NOT NULL default '0', allow_attachment_types varchar(100) NOT NULL default '', max_attachment_size int(10) unsigned NOT NULL default '0', max_totalattachment_size int(10) unsigned NOT NULL default '0', max_attachments int(10) unsigned NOT NULL default '0', pub_perms int(10) unsigned NOT NULL default '0', reg_perms int(10) unsigned NOT NULL default '0', display_ip_address smallint(5) unsigned NOT NULL default '1', allow_email_notify smallint(5) unsigned NOT NULL default '1', language varchar(100) NOT NULL default 'english', email_moderators tinyint(1) NOT NULL default '0', message_count int(10) unsigned NOT NULL default '0', sticky_count int(10) unsigned NOT NULL default '0', thread_count int(10) unsigned NOT NULL default '0', last_post_time int(10) unsigned NOT NULL default '0', display_order int(10) unsigned NOT NULL default '0', read_length int(10) unsigned NOT NULL default '0', vroot int(10) unsigned NOT NULL default '0', edit_post tinyint(1) NOT NULL default '1',template_settings text NOT NULL default '', count_views tinyint(1) unsigned NOT NULL default '0', display_fixed tinyint(1) unsigned NOT NULL default '0', reverse_threading tinyint(1) NOT NULL default '0',inherit_id int(10) unsigned NULL default NULL, cache_version int(10) NOT NULL default 0, PRIMARY KEY (forum_id), KEY name (name), KEY active (active,parent_id), KEY group_id (parent_id)) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['message_table']} ( message_id int(10) unsigned NOT NULL auto_increment, forum_id int(10) unsigned NOT NULL default '0', thread int(10) unsigned NOT NULL default '0', parent_id int(10) unsigned NOT NULL default '0', author varchar(37) NOT NULL default '', subject varchar(255) NOT NULL default '', body text NOT NULL, email varchar(100) NOT NULL default '', ip varchar(255) NOT NULL default '', status tinyint(4) NOT NULL default '2', msgid varchar(100) NOT NULL default '', modifystamp int(10) unsigned NOT NULL default '0', user_id int(10) unsigned NOT NULL default '0', thread_count int(10) unsigned NOT NULL default '0', moderator_post tinyint(3) unsigned NOT NULL default '0', sort tinyint(4) NOT NULL default '2', datestamp int(10) unsigned NOT NULL default '0', meta mediumtext NULL, viewcount int(10) unsigned NOT NULL default '0', closed tinyint(4) NOT NULL default '0', PRIMARY KEY (message_id), KEY thread_message (thread,message_id), KEY thread_forum (thread,forum_id), KEY special_threads (sort,forum_id), KEY status_forum (status,forum_id), KEY list_page_float (forum_id,parent_id,modifystamp), KEY list_page_flat (forum_id,parent_id,thread), KEY post_count (forum_id,status,parent_id), KEY dup_check (forum_id,author,subject,datestamp), KEY forum_max_message (forum_id,message_id,status,parent_id), KEY last_post_time (forum_id,status,modifystamp), KEY next_prev_thread (forum_id,status,thread), KEY user_id (user_id)  ) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['settings_table']} ( name varchar(255) NOT NULL default '', type enum('V','S') NOT NULL default 'V', data text NOT NULL, PRIMARY KEY (name)) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['subscribers_table']} ( user_id int(10) unsigned NOT NULL default '0', forum_id int(10) unsigned NOT NULL default '0', sub_type int(10) unsigned NOT NULL default '0', thread int(10) unsigned NOT NULL default '0', PRIMARY KEY (user_id,forum_id,thread), KEY forum_id (forum_id,thread,sub_type)) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['user_permissions_table']} ( user_id int(10) unsigned NOT NULL default '0', forum_id int(10) unsigned NOT NULL default '0', permission int(10) unsigned NOT NULL default '0', PRIMARY KEY  (user_id,forum_id), KEY forum_id (forum_id,permission) ) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['user_table']} ( user_id int(10) unsigned NOT NULL auto_increment, username varchar(50) NOT NULL default '', password varchar(50) NOT NULL default '',cookie_sessid_lt varchar(50) NOT NULL default '', sessid_st varchar(50) NOT NULL default '', sessid_st_timeout int(10) unsigned NOT NULL default 0, password_temp varchar(50) NOT NULL default '', email varchar(100) NOT NULL default '',  email_temp varchar(110) NOT NULL default '', hide_email tinyint(1) NOT NULL default '0', active tinyint(1) NOT NULL default '0', signature text NOT NULL default '', threaded_list tinyint(4) NOT NULL default '0', posts int(10) NOT NULL default '0', admin tinyint(1) NOT NULL default '0', threaded_read tinyint(4) NOT NULL default '0', date_added int(10) unsigned NOT NULL default '0', date_last_active int(10) unsigned NOT NULL default '0', last_active_forum int(10) unsigned NOT NULL default '0', hide_activity tinyint(1) NOT NULL default '0',show_signature TINYINT( 1 ) DEFAULT '0' NOT NULL, email_notify TINYINT( 1 ) DEFAULT '0' NOT NULL, pm_email_notify TINYINT ( 1 ) DEFAULT '1' NOT NULL, tz_offset TINYINT( 2 ) DEFAULT '-99' NOT NULL,is_dst TINYINT( 1 ) DEFAULT '0' NOT NULL ,user_language VARCHAR( 100 ) NOT NULL default '',user_template VARCHAR( 100 ) NOT NULL default '', moderator_data text NOT NULL default '', moderation_email tinyint(2) unsigned not null default 1, settings_data mediumtext NOT NULL DEFAULT '', PRIMARY KEY (user_id), UNIQUE KEY username (username), KEY active (active), KEY userpass (username,password), KEY sessid_st (sessid_st), KEY cookie_sessid_lt (cookie_sessid_lt), KEY activity (date_last_active,hide_activity,last_active_forum), KEY date_added (date_added), KEY email_temp (email_temp) ) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['user_newflags_table']} ( user_id int(11) NOT NULL default '0', forum_id int(11) NOT NULL default '0', message_id int(11) NOT NULL default '0', PRIMARY KEY  (user_id,forum_id,message_id), key move (message_id, forum_id) ) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['groups_table']} ( group_id int(11) NOT NULL auto_increment, name varchar(255) NOT NULL default '0', open tinyint(3) NOT NULL default '0', PRIMARY KEY  (group_id) ) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['forum_group_xref_table']} ( forum_id int(11) NOT NULL default '0', group_id int(11) NOT NULL default '0', permission int(10) unsigned NOT NULL default '0', PRIMARY KEY  (forum_id,group_id), KEY group_id (group_id) ) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['user_group_xref_table']} ( user_id int(11) NOT NULL default '0', group_id int(11) NOT NULL default '0', status tinyint(3) NOT NULL default '1', PRIMARY KEY  (user_id,group_id) ) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['files_table']} ( file_id int(11) NOT NULL auto_increment, user_id int(11) NOT NULL default '0', filename varchar(255) NOT NULL default '', filesize int(11) NOT NULL default '0', file_data mediumtext NOT NULL default '', add_datetime int(10) unsigned NOT NULL default '0', message_id int(10) unsigned NOT NULL default '0', link varchar(10) NOT NULL default '', PRIMARY KEY (file_id), KEY add_datetime (add_datetime), KEY message_id_link (message_id,link)) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['banlist_table']} ( id int(11) NOT NULL auto_increment, forum_id int(11) NOT NULL default '0', type tinyint(4) NOT NULL default '0', pcre tinyint(4) NOT NULL default '0', string varchar(255) NOT NULL default '', PRIMARY KEY  (id), KEY forum_id (forum_id)) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['search_table']} ( message_id int(10) unsigned NOT NULL default '0', forum_id int(10) unsigned NOT NULL default '0',search_text mediumtext NOT NULL default '', PRIMARY KEY  (message_id), KEY forum_id (forum_id), FULLTEXT KEY search_text (search_text) ) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['user_custom_fields_table']} ( user_id INT DEFAULT '0' NOT NULL , type INT DEFAULT '0' NOT NULL , data TEXT NOT NULL default '', PRIMARY KEY ( user_id , type )) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['pm_messages_table']} ( pm_message_id int(10) unsigned NOT NULL auto_increment, from_user_id int(10) unsigned NOT NULL default '0', from_username varchar(50) NOT NULL default '', subject varchar(100) NOT NULL default '', message text NOT NULL default '', datestamp int(10) unsigned NOT NULL default '0', meta mediumtext NOT NULL default '', PRIMARY KEY(pm_message_id)) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['pm_folders_table']} ( pm_folder_id int(10) unsigned NOT NULL auto_increment, user_id int(10) unsigned NOT NULL default '0', foldername varchar(20) NOT NULL default '', PRIMARY KEY (pm_folder_id)) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['pm_xref_table']} ( pm_xref_id int(10) unsigned NOT NULL auto_increment, user_id int(10) unsigned NOT NULL default '0', pm_folder_id int(10) unsigned NOT NULL default '0', special_folder varchar(10), pm_message_id int(10) unsigned NOT NULL default '0', read_flag tinyint(1) NOT NULL default '0', reply_flag tinyint(1) NOT NULL default '0', PRIMARY KEY (pm_xref_id), KEY xref (user_id,pm_folder_id,pm_message_id), KEY read_flag (read_flag)) TYPE=MyISAM",
        "CREATE TABLE {$PHORUM['pm_buddies_table']} ( pm_buddy_id int(10) unsigned NOT NULL auto_increment, user_id int(10) unsigned NOT NULL default '0', buddy_user_id int(10) unsigned NOT NULL default '0', PRIMARY KEY pm_buddy_id (pm_buddy_id), UNIQUE KEY userids (user_id, buddy_user_id), KEY buddy_user_id (buddy_user_id)) TYPE=MyISAM",

    );
    foreach($queries as $sql){
        $res = mysql_query($sql, $conn);
        if ($err = mysql_error()){
            $retmsg = "$err<br />";
            phorum_db_mysql_error("$err: $sql");
            break;
        }
    }

    return $retmsg;
}

/**
 * Execute an array of queries.
 *
 * @param array $queries
 *
 * @return string
 */
function phorum_db_run_queries($queries){
    $PHORUM = $GLOBALS["PHORUM"];

    $conn = phorum_db_mysql_connect();

    $retmsg = "";

    foreach($queries as $sql){
        $res = mysql_query($sql, $conn);
        if ($err = mysql_error()){
            // skip duplicate column and key name errors
            if(!stristr($err, "duplicate column") &&
               !stristr($err, "duplicate key")){
                $retmsg.= "$err<br />";
                phorum_db_mysql_error("$err: $sql");
            }
        }
    }

    return $retmsg;
}

/**
 * Handy little connection function.  This allows us to not connect to the
 * server until a query is actually run.
 * NOTE: This is not a required part of abstraction
 *
 * @return resource
 */
function phorum_db_mysql_connect(){
    $PHORUM = $GLOBALS["PHORUM"];

    static $conn;
    if (empty($conn)){
        $conn = mysql_connect($PHORUM["DBCONFIG"]["server"], $PHORUM["DBCONFIG"]["user"], $PHORUM["DBCONFIG"]["password"], TRUE);
        mysql_select_db($PHORUM["DBCONFIG"]["name"], $conn);
    }
    return $conn;
}

/**
 * Error handling function.
 * NOTE: This is not a required part of abstraction
 *
 * @param string $err
 *
 * @return void
 */
function phorum_db_mysql_error($err){

    if(isset($GLOBALS['PHORUM']['error_logging'])) {
        $logsetting = $GLOBALS['PHORUM']['error_logging'];
    } else {
        $logsetting = "";
    }
    $adminemail = $GLOBALS['PHORUM']['system_email_from_address'];
    $cache_dir  = $GLOBALS['PHORUM']['cache'];

    if (!defined("PHORUM_ADMIN")){
        if($logsetting == 'mail') {
            include_once("./include/email_functions.php");

            $data=array('mailmessage'=>"An SQL-error occured in your phorum-installation.\n\nThe error-message was:\n$err\n\n",
                        'mailsubject'=>'Phorum: an SQL-error occured');
            phorum_email_user(array($adminemail),$data);

        } elseif($logsetting == 'file') {
            $fp = fopen($cache_dir."/phorum-sql-errors.log",'a');
            fputs($fp,time().": $err\n");
            fclose($fp);

        } else {
            echo htmlspecialchars($err);
        }
        exit();
    }else{
        echo "<!-- $err -->";
    }
}

/**
 * This function will sanitize a mixed variable of data based on type
 *
 * @param   $var    The variable to be sanitized.  Passed by reference.
 * @param   $type   Either int or not int.
 * @return  null
 *
 */
function phorum_db_sanitize_mixed(&$var, $type){
    if(is_array($var)){
        foreach($var as &$val){
            if($type=="int"){
                $val = (int)$val;
            } else {
                $val = mysql_escape_string($val);
            }
        }
    } else {
        if($type=="int"){
            $var = (int)$var;
        } else {
            $var = mysql_escape_string($var);
        }
    }
}

/**
 * Checks that a value to be used as a field name contains only characters
 * that would appear in a field name.
 *
 * @param   $field_name     string to be checked
 * @return  bool
 *
 */
function phorum_db_validate_field($field_name){
    return (bool)preg_match('!^[a-zA-Z0-9_]+$!', $field_name);
}


/**
 * This function is used by the sanity checking system in the
 * admin interface to determine how much data can be transferred
 * in one query. This is used to detect problems with uploads that
 * are larger than the database server can handle.
 * The function returns the size in bytes. For database implementations
 * which do not have this kind of limit, NULL can be returned.
 *
 * @return int
 */
function phorum_db_maxpacketsize ()
{
    $conn = phorum_db_mysql_connect();
    $res = mysql_query("SELECT @@global.max_allowed_packet",$conn);
    if (!$res) return NULL;
    if (mysql_num_rows($res)) {
        $row = mysql_fetch_array($res);
        return $row[0];
    }
    return NULL;
}

/**
 * This function is used by the sanity checking system to let the
 * database layer do sanity checks of its own. This function can
 * be used by every database layer to implement specific checks.
 *
 * The return value for this function should be exactly the same
 * as the return value expected for regular sanity checking
 * function (see include/admin/sanity_checks.php for information).
 *
 * There's no need to load the sanity_check.php file for the needed
 * constants, because this function should only be called from the
 * sanity checking system.
 *
 * @return array
 */
function phorum_db_sanitychecks()
{
    $PHORUM = $GLOBALS["PHORUM"];

    // Retrieve the MySQL server version.
    $conn = phorum_db_mysql_connect();
    $res = mysql_query("SELECT @@global.version",$conn);
    if (!$res) return array(
        PHORUM_SANITY_WARN,
        "The database layer could not retrieve the version of the
         running MySQL server",
        "This probably means that you are running a really old MySQL
         server, which does not support \"SELECT @@global.version\"
         as an SQL command. If you are not running a MySQL server
         with version 4.0.18 or higher, then please upgrade your
         MySQL server. Else, contact the Phorum developers to see
         where this warning is coming from"
    );

    if (mysql_num_rows($res))
    {
        $row = mysql_fetch_array($res);
        $verstr = preg_replace('/-.*$/', '', $row[0]);
        $ver = explode(".", $verstr);

        // Version numbering format which is not recognized.
        if (count($ver) != 3) return array(
            PHORUM_SANITY_WARN,
            "The database layer was unable to recognize the MySQL server's
             version number \"" . htmlspecialchars($row[0]) . "\". Therefore,
             checking if the right version of MySQL is used is not possible.",
            "Contact the Phorum developers and report this specific
             version number, so the checking scripts can be updated."
        );

        settype($ver[0], 'int');
        settype($ver[1], 'int');
        settype($ver[2], 'int');

        // MySQL before version 4.
        if ($ver[0] < 4) return array(
            PHORUM_SANITY_CRIT,
            "The MySQL database server that is used is too old. The
             running version is \"" . htmlspecialchars($row[0]) . "\",
             while MySQL version 4.0.18 or higher is recommended.",
            "Upgrade your MySQL server to a newer version. If your
             website is hosted with a service provider, please contact
             the service provider to upgrade your MySQL database."
        );

        // MySQL before version 4.0.18, with full text search enabled.
        if (isset($PHORUM["DBCONFIG"]["mysql_use_ft"]) &&
            $PHORUM["DBCONFIG"]["mysql_use_ft"] &&
            $ver[0] == 4 && $ver[1] == 0 && $ver[2] < 18) return array(
            PHORUM_SANITY_WARN,
            "The MySQL database server that is used does not
             support all Phorum features. The running version is
             \"" . htmlspecialchars($row[0]) . "\", while MySQL version
             4.0.18 or higher is recommended.",
            "Upgrade your MySQL server to a newer version. If your
             website is hosted with a service provider, please contact
             the service provider to upgrade your MySQL database."
        );

        // All checks are okay.
        return array (PHORUM_SANITY_OK, NULL);
    }

    return array(
        PHORUM_SANITY_CRIT,
        "An unexpected problem was found in running the sanity
         check function phorum_db_sanitychecks().",
        "Contact the Phorum developers to find out what the problem is."
    );
}

?>
