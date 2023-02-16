<?php

/**
 * Plugin Name: Discord Linker
 * 
 * Plugin URI: https://localhost/
 * 
 * Description: This plugin allows you to link discord accounts to wordpress accounts
 * 
 * Version: 0.3.1
 * 
 * Author: Vbrawl
 */




define("LINK_TOKEN_DB", "dl_link_tokens");
define("TOKEN_SALT_LENGTH", 10);
define("TOKEN_HASH_ALGORITHM", 'sha256');
define("TOKEN_USAGE_DURATION_VALUE", 5);
define("TOKEN_USAGE_DURATION_TYPE", "MINUTE");
define("DL_LINK_TOKEN_SIZE", 64);

define("DL_DISCORD_ID_SIZE", 18);

define("LINK_DISCORD_ACCOUNTS", "link_discord_accounts");
define("UNLINK_DISCORD_ACCOUNTS", "unlink_discord_accounts");
define("CREATE_DISCORD_LINK_TOKENS", "create_discord_link_tokens");
define("DELETE_DISCORD_LINK_TOKENS", "delete_discord_link_tokens");
define("DISCORD_USER_IMPERSONATION", "discord_user_impersonation");
define("DL_LINK_USERMETA_KEY", "discord_account_id");



$REAL_WP_ID = null;
$IMPERSONATED_WP_ID = null;



require_once("errors.php");
require_once("AccountLink.php");



/**
 * Setup some important databases and custom roles from the plugin.
 *
 * @return void
 */
function discord_linker_setup() {
    global $wpdb;


    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS ".$wpdb->prefix.LINK_TOKEN_DB." (id BIGINT NOT NULL AUTO_INCREMENT, link_token VARCHAR(64) NOT NULL, expiration_date DATETIME NOT NULL, wp_user_id BIGINT NOT NULL, PRIMARY KEY(id));"
    );


    add_role(
        'discord_linker_bot',
        "Discord Linker Bot",
        array(
            'read' => true,
            LINK_DISCORD_ACCOUNTS => true,
            UNLINK_DISCORD_ACCOUNTS => true,
            CREATE_DISCORD_LINK_TOKENS => true,
            DELETE_DISCORD_LINK_TOKENS => true,
            DISCORD_USER_IMPERSONATION => true
        )
    );


    return 0;
}


/**
 * Unset some databases and custom roles that the plugin added.
 *
 * @return void
 */
function discord_linker_unset() {
    global $wpdb;

    $wpdb->query(
        "DROP TABLE IF EXISTS ".$wpdb->prefix.LINK_TOKEN_DB.";"
    );


    remove_role('discord_linker_bot');

    return 0;
}



/**
 * Delete a link token from the database.
 *
 * @param Object $request
 * @return void
 */
function delete_link_token($request) {
    global $wpdb;

    $link_token = $request->get_param('link_token');

    $rows_affected = $wpdb->query(
        $wpdb->prepare("DELETE FROM ".$wpdb->prefix.LINK_TOKEN_DB." WHERE link_token = %s;", $link_token)
    );
    if($rows_affected === false) {
        return dl_error_LINK_TOKEN_NOT_FOUND();
    }

    return array("code" => "SUCCESS");
}






/**
 * Create a link token and add it to the database
 *
 * @param Object $request
 * @return void
 */
function create_link_token($request) {
    global $wpdb;

    $user_id = $REAL_WP_ID;

    // Payload Calculation: hash([User ID] + [Epoch TimeStamp] + [Random Salt])

    // Generate payload
    $payload = strval($user_id) . strval(time()) . bin2hex(random_bytes(TOKEN_SALT_LENGTH));
    $payload = hash(TOKEN_HASH_ALGORITHM, $payload);

    // Add to database
    $rows_affected = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}".LINK_TOKEN_DB." (`link_token`, `expiration_date`, `wp_user_id`) VALUES (%s, date_add(NOW(), INTERVAL ".TOKEN_USAGE_DURATION_VALUE." ".TOKEN_USAGE_DURATION_TYPE."), %d);", $payload, $user_id));


    if($rows_affected == 0) {
        return dl_error_UNKNOWN_ERROR();
    }
    else {
        return array("code" => "SUCCESS", "link_token" => $payload);
    }
}




/**
 * Unlink a discord account from a wordpress user.
 *
 * @param Object $request
 * @return void
 */
function unlink_discord_from_user($request) {
    global $wpdb;
    $discord_id = $request->get_param('discord_id');

    $account_link = new dlAccountLink(null, $discord_id);
    $error = $account_link->unlink_accounts();

    if(is_wp_error($error)) {
        return $error;
    }
    else {
        return array("code" => "SUCCESS");
    }

}




/**
 * Link a discord account to a wordpress user.
 *
 * @param Object $request
 * @return void
 */
function link_discord_to_user($request) {
    global $wpdb;


    // The discord id of the user.
    $discord_id = $request->get_param('discord_id');

    // The link token of the user.
    $link_token = $request->get_param('link_token');



    // Check if token is still active
    $token_user_id = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM ".$wpdb->prefix.LINK_TOKEN_DB." WHERE link_token = %s AND expiration_date > NOW()", $link_token));
    if($token_user_id === null) {
        return dl_error_LINK_TOKEN_NOT_FOUND();
    }



    $account_link = new dlAccountLink($token_user_id, $discord_id);
    $error = $account_link->link_accounts();
    if(is_wp_error($error)) {
        return $error;
    }
    else {
        return array("code" => "SUCCESS");
    }
}



/**
 * Get data about the linked user.
 *
 * @param Object $request
 * @return void
 */
function get_account_details($request) {
    $discord_id = $request->get_param('discord_id');

    $account_link = new dlAccountLink(null, $discord_id);
    if(!$account_link->linked_together()) {
        return dl_error_ACCOUNT_NOT_LINKED();
    }


    $error_or_id = $account_link->impersonate();
    if(is_wp_error($error_or_id)) {
        return $error_or_id;
    }


    $user = wp_get_current_user();

    $details = array(
        "id" => $user->ID,
        "avatar" => get_avatar_url($user->ID),
        "username" => $user->display_name,
        "email" => $user->user_email,
    );


    $account_link->reset_impersonation();
    return array("code" => "SUCCESS", "details" => $details);
}






/**
 * Check if the parameter is a link token.
 *
 * @param string $value
 * @return boolean
 */
function is_link_token($value) {
    if(strlen($value) != DL_LINK_TOKEN_SIZE) {
        return dl_error_INVALID_TOKEN_SIZE($value, DL_LINK_TOKEN_SIZE);
    }

    return true;
}


/**
 * Check if the parameter is a discord id.
 *
 * @param string $value
 * @return void
 */
function dl_is_discord_id($value) {
    $dot_position = strpos($value, '.');
    if(is_numeric($value) === false || $dot_position !== false) {

        return dl_error_INVALID_DISCORD_ID_TYPE();
    }

    if(strlen($value) !== DL_DISCORD_ID_SIZE) {
        return dl_error_INVALID_DISCORD_ID_SIZE($value, DL_DISCORD_ID_SIZE);
    }

    return true;
}


/**
 * Initialize $REAL_WP_ID and stop from running again
 *
 * @return void
 */
function init_real_wp_id() {
    global $REAL_WP_ID;
    $REAL_WP_ID = get_current_user_id();
    remove_action('set_current_user', 'init_real_wp_id', 0);
}





function discord_linker_rest_api_init() {
    register_rest_route("discord_linker/v1/discord", "/link/(?P<discord_id>.*)/(?P<link_token>.*)", array(
        'methods' => "GET",
        'callback' => "link_discord_to_user",
        'args' => array(
            'discord_id' => array(
                'validate_callback' => 'dl_is_discord_id'
            ),
            "link_token" => array(
                'validate_callback' => 'is_link_token'
            )
        ),
        'permission_callback' => function() {
            return current_user_can(LINK_DISCORD_ACCOUNTS);
        }
    ));


    register_rest_route("discord_linker/v1/discord", "/unlink/(?P<discord_id>.*)", array(
        "methods" => "GET",
        "callback" => "unlink_discord_from_user",
        "args" => array(
            "discord_id" => array(
                "validate_callback" => 'dl_is_discord_id'
            )
        ),
        "permission_callback" => function() {
            return current_user_can(UNLINK_DISCORD_ACCOUNTS);
        }
    ));



    register_rest_route("discord_linker/v1/tokens", "/delete/(?P<link_token>.*)", array(
        'methods' => "GET",
        'callback' => "delete_link_token",
        'args' => array(
            'link_token' => array(
                'validate_callback' => 'is_link_token'
            )
        ),
        'permission_callback' => function() {
            return current_user_can(DELETE_DISCORD_LINK_TOKENS);
        }
    ));


    register_rest_route("discord_linker/v1/tokens", "/create", array(
        "methods" => "GET",
        "callback" => "create_link_token",
        "args" => array(),
        "permission_callback" => function() {
            return current_user_can(CREATE_DISCORD_LINK_TOKENS);
        }
    ));


    register_rest_route("discord_linker/v1/discord", "/get_account_details/(?P<discord_id>.*)", array(
        "methods" => "GET",
        "callback" => "get_account_details",
        "args" => array(
            "discord_id" => array(
                "validate_callback" => "dl_is_discord_id"
            )
        )
    ));
}






register_activation_hook(
    __FILE__,
    'discord_linker_setup'
);

register_deactivation_hook(
    __FILE__,
    'discord_linker_unset'
);
add_action("rest_api_init", "discord_linker_rest_api_init");



add_action('set_current_user', 'init_real_wp_id', 0);

?>