<?php


function dl_error_UNKNOWN_ERROR() {
    return new WP_Error("UNKNOWN_ERROR", "An unknown error occurred!");
}



function dl_error_LINK_TOKEN_NOT_FOUND() {
    return new WP_Error("LINK_TOKEN_NOT_FOUND", "Link token was not found!");
}



function dl_error_ACCOUNT_NOT_LINKED() {
    return new WP_Error("ACCOUNT_NOT_LINKED", "This discord is not linked to an account!");
}




function dl_error_INVALID_TOKEN_SIZE($token, $expected_token_size) {
    $token_size = strlen($given_token);

    return new WP_Error("INVALID_TOKEN_SIZE", "Wrong link token size!", array("token" => $token, "token_size" => $token_size, "expected_token_size" => $expected_token_size));
}


function dl_error_INVALID_DISCORD_ID_TYPE() {
    return new WP_Error("INVALID_DISCORD_ID_TYPE", "Discord ID must be an integer!");
}

function dl_error_INVALID_DISCORD_ID_SIZE($id, $expected_id_size) {
    $id_size = strlen($id);

    return new WP_Error("INVALID_DISCORD_ID_SIZE", "Discord ID must be an ".$expected_id_size."-digit integer", array("id" => $id, "id_size" => $id_size, "expected_id_size" => $expected_id_size));
}


function dl_error_ACCOUNT_ALREADY_LINKED() {
    return new WP_Error("ACCOUNT_ALREADY_LINKED", "The discord or the account is already linked!");
}


function dl_error_INSUFFICIENT_PERMISSIONS() {
    return new WP_Error("INSUFFICIENT_PERMISSIONS", "You don't have enough permissions to perform this action");
}


function dl_error_NOT_IMPERSONATING() {
    return WP_Error("NOT_IMPERSONATING", "You are not impersonating anyone!");
}



?>