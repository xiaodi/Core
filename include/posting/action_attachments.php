<?php
////////////////////////////////////////////////////////////////////////////////
//                                                                            //
//   Copyright (C) 2008  Phorum Development Team                              //
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
//                                                                            //
////////////////////////////////////////////////////////////////////////////////

if (!defined("PHORUM")) return;

require_once('./include/api/base.php');
require_once('./include/api/file_storage.php');

if ($do_detach)
{
    // Find attachments to detach.
    foreach ($message["attachments"] as $id => $info)
    {
        if ($info["file_id"] == $do_detach && $info["keep"])
        {
            // Attachments which are not yet linked to a message
            // can be deleted immediately. Linked attachments should
            // be kept in the db, in case the users clicks "Cancel".
            if (! $info["linked"]) {
                if (phorum_api_file_check_delete_access($info["file_id"])) {
                    phorum_api_file_delete($info["file_id"]);
                }
                unset($message["attachments"][$id]);
            } else {
                $message["attachments"][$id]["keep"] = false;
            }

            // Run the after_detach hook.
            if (isset($PHORUM["hooks"]["after_detach"]))
                list($message,$info) =
                    phorum_hook("after_detach", array($message,$info));

            $attach_count--;

            break;
        }
    }
}

// Attachment(s) uploaded.
elseif ($do_attach && ! empty($_FILES))
{
    // Find the maximum allowed attachment size.
    require_once('./include/upload_functions.php');
    $system_max_upload = phorum_get_system_max_upload();
    if ($PHORUM["max_attachment_size"] == 0)
        $PHORUM["max_attachment_size"] = $system_max_upload[0] / 1024;
    $PHORUM["max_attachment_size"] = min(
        $PHORUM["max_attachment_size"],
        $system_max_upload[0] / 1024
    );

    // The editor template that I use only supports one upload
    // at a time. This code does support multiple uploads though.
    // This can be done by simply adding multiple file upload
    // fields to the posting form.
    $attached = 0;
    foreach ($_FILES as $file)
    {
        // Check if the maximum number of attachments isn't exceeded.
        if ($attach_count >= $PHORUM["max_attachments"]) break;

        // Only continue if the tempfile is really an uploaded file?
        if (! is_uploaded_file($file["tmp_name"])) continue;

        // Handle PHP upload errors.
        // PHP 4.2.0 and later can set an error field for the file
        // upload, indicating a specific error. In Phorum 5.1, we only
        // have an error message for too large uploads. Other error
        // messages will get a generic file upload error.
        if (isset($file["error"]) && $file["error"]) {
            if ($file["error"] == UPLOAD_ERR_INI_SIZE ||
                $file["error"] == UPLOAD_ERR_FORM_SIZE) {
                // File too large. Tweak the file size to let the 
                // file storage API return a file too large error.
                $newfile["filesize"] = $PHORUM["max_attachment_size"] * 2;
            } else {
                // Tweak the file size for a generic upload error.
                $file["size"] = 0;
            }
        }

        // Some problems in uploading result in files which are
        // zero in size. We asume that people who upload zero byte
        // files will almost always have problems uploading. We simply
        // skip 0 byte files here, so after this loop we'll show a
        // generic upload error if no files were uploaded in the end.
        if ($file["size"] == 0) continue;

        // Let the file storage API run some upload access checks
        // (maximum attachment file size and file type).
        if (!phorum_api_file_check_write_access(array(
            "link"       => PHORUM_LINK_EDITOR,
            "filename"   => $file["name"],
            "filesize"   => $file["size"]
        ))) {
            $PHORUM["DATA"]["ERROR"] = phorum_api_strerror();
            break;
        }

        // Check if the total cumulative attachment size isn't too large.
        if ($PHORUM["max_totalattachment_size"] > 0 &&
            ($file["size"] + $attach_totalsize) > $PHORUM["max_totalattachment_size"]*1024) {
            $PHORUM["DATA"]["ERROR"] = str_replace(
                '%size%',
                phorum_filesize($PHORUM["max_totalattachment_size"] * 1024),
                $PHORUM["DATA"]["LANG"]["AttachTotalFileSize"]
            );
            break;
        }

        // Add the file data and user_id to the file info for the hook call.
        $file["data"] = @file_get_contents($file["tmp_name"]);
        $file["user_id"]=$PHORUM["user"]["user_id"];

        // Run the before_attach hook.
        if (isset($PHORUM["hooks"]["before_attach"]))
            list($message, $file) =
                phorum_hook("before_attach", array($message, $file));

        // Store the file. We add it using message_id 0 (zero). Only when
        // the message gets saved definitely, the message_id will be updated
        // to link the file to the forum message. This is mainly done so we
        // can support attachments for new messages, which do not yet have
        // a message_id assigned.
        $file = phorum_api_file_store(array(
            "filename"   => $file["name"],
            "file_data"  => $file["data"],
            "filesize"   => $file["size"],
            "link"       => PHORUM_LINK_EDITOR,
            "user_id"    => 0,
            "message_id" => 0
        ));
        
        if ($file !== FALSE)
        {
            // Create new attachment information.
            $new_attachment = array(
                "file_id" => $file["file_id"],
                "name"    => $file["filename"],
                "size"    => $file["filesize"],
                "keep"    => true,
                "linked"  => false,
            );

            // Run the after_attach hook.
            if (isset($PHORUM["hooks"]["after_attach"]))
                list($message, $new_attachment) =
                    phorum_hook("after_attach", array($message, $new_attachment));

            // Add the attachment to the message.
            $message['attachments'][] = $new_attachment;
            $attach_totalsize += $new_attachment["size"];
            $attach_count++;
            $attached++;
        }
    }

    // Show a generic error message if nothing was attached and
    // no specific message was set.
    if (! $PHORUM["DATA"]["ERROR"] && ! $attached) {
        $PHORUM["DATA"]["ERROR"] =
            $PHORUM["DATA"]["LANG"]["AttachmentsMissing"];
    }

    // Show a success message in case an attachment is added.
    if ( $attached) {
        $PHORUM["DATA"]["OKMSG"] = $PHORUM["DATA"]["LANG"]["AttachmentAdded"];
    }
}
?>
