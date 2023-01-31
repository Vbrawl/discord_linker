<?php

/**
 * Plugin Name: Discord Linker
 * 
 * Plugin URI: https://localhost/
 * 
 * Description: This plugin allows you to link discord accounts to wordpress accounts
 * 
 * Version: 0.1.0
 * 
 * Author: Vbrawl
 */




define("LINK_TOKEN_DB", "dl_link_tokens");
define("TOKEN_SALT_LENGTH", 10);
define("TOKEN_HASH_ALGORITHM", 'sha256');
define("TOKEN_USAGE_DURATION_VALUE", 5);
define("TOKEN_USAGE_DURATION_TYPE", "MINUTE");

define("LINK_DISCORD_ACCOUNTS", "link_discord_accounts");
define("UNLINK_DISCORD_ACCOUNTS", "unlink_discord_accounts");
define("CREATE_DISCORD_LINK_TOKENS", "create_discord_link_tokens");
define("DELETE_DISCORD_LINK_TOKENS", "delete_discord_link_tokens");
define("DISCORD_USER_IMPERSONATION", "discord_user_impersonation");





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
 * User setters
 */
$REAL_WP_ID = null;
$IMPERSONATED_WP_ID = null;
function impersonate_user_by_discord_id($discord_id) {
    global $wpdb;
    global $REAL_WP_ID;
    global $IMPERSONATED_WP_ID;


    if($IMPERSONATED_WP_ID === null) {
        $REAL_WP_ID = get_current_user_id();


        $fake_user_id = $wpdb->get_var("SELECT user_id FROM {$wpdb->prefix} WHERE meta_key = 'discord_account_id' AND meta_value = %s", $discord_id);
        if($fake_user_id !== null) {
            $IMPERSONATED_WP_ID = intval($fake_user_id);
            wp_set_current_user($IMPERSONATED_WP_ID);
        }
        else {
            throw new WP_Error(1, "Insufficient priviledges to impersonate another user");
        }
    }
    else {
        throw new WP_Error(2, "User impersonation already active");
    }

    return $IMPERSONATED_WP_ID;
}

function reset_user_impersonation() {
    global $REAL_WP_ID;
    global $IMPERSONATED_WP_ID;

    if($IMPERSONATED_WP_ID !== null) {
        wp_set_current_user($REAL_WP_ID);
        $REAL_WP_ID = null;
        $IMPERSONATED_WP_ID = null;
    }
    else {
        throw new WP_Error(1, "User impersonation is not used");
    }

    return $REAL_WP_ID;
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
        return new WP_Error(1, "Link token doesn't exist!");
    }

    return array("code" => 0);
}


function create_link_token($request) {
    global $wpdb;

    $user_id = get_current_user_id();

    // Payload Calculation: hash([User ID] + [Epoch TimeStamp] + [Random Salt])

    // Generate payload
    $payload = strval($user_id) . strval(time()) . bin2hex(random_bytes(TOKEN_SALT_LENGTH));
    $payload = hash(TOKEN_HASH_ALGORITHM, $payload);

    // Add to database
    $query = $wpdb->prepare("INSERT INTO ".$wpdb->prefix.LINK_TOKEN_DB." (`link_token`, `expiration_date`, `wp_user_id`) VALUES (%s, date_add(NOW(), INTERVAL ".TOKEN_USAGE_DURATION_VALUE." ".TOKEN_USAGE_DURATION_TYPE."), %d);", $payload, $user_id);
    $rows_affected = $wpdb->query($query);

    if($rows_affected != 0) {
        return array("code" => 0, "link_token" => $payload);
    }
    else {
        return new WP_Error(1, "Unknown Error!", array("data" => $query));
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


    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}usermeta WHERE meta_key = 'discord_account_id' AND meta_value = %s;", $discord_id
        )
    );


    return array("code" => 0);
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
    $token = $wpdb->get_row($wpdb->prepare("SELECT id, wp_user_id FROM ".$wpdb->prefix.LINK_TOKEN_DB." WHERE link_token = %s AND expiration_date > NOW()", $link_token));
    if($token === null) {
        return new WP_Error(1, "Connection Token doesn't exist!");
    }

    // Check if discord is already linked
    $discord_linked = $wpdb->get_row($wpdb->prepare("SELECT umeta_id FROM {$wpdb->prefix}usermeta WHERE meta_key = 'discord_account_id' AND meta_value = %d", $discord_id));
    if($discord_linked !== null) {
        return new WP_Error(2, "Discord Already Linked!");
    }

    // Check if user has already a link to a discord account
    $user_linked = $wpdb->get_row($wpdb->prepare("SELECT umeta_id FROM {$wpdb->prefix}usermeta WHERE meta_key = 'discord_account_id' AND user_id = %d", $token->wp_user_id));
    if($user_linked !== null) {
        return new WP_Error(3, "Account Already Linked!");
    }

    // Link discord with user
    $wpdb->query(
        $wpdb->prepare("INSERT INTO {$wpdb->prefix}usermeta (user_id, meta_key, meta_value) VALUES (%d, 'discord_account_id', %d);", $token->wp_user_id, $discord_id)
    );

    return array("code" => 0);
}





function is_link_token($value) {
    if(strlen($value) != 64) {
        return new WP_Error(91, 'Wrong Link Token Size!', array('given token' => $value, 'size' => strlen($value), 'expected size' => 64));
    }

    return true;
}



function is_discord_id($value) {
    if(is_numeric($value) === false) {
        return new WP_Error(91, 'Discord ID Must Be Numerical!', array("Type" => "string"));
    }

    $dot_position = strpos($value, '.');
    if($dot_position !== false) {
        return new WP_Error(92, 'Discord ID Must Be An Integer!', array('Type' => 'float', 'given id' => $value, 'dot at offset (0-17)' => $dot_position));
    }

    if(strlen($value) !== 18) {
        return new WP_Error(93, 'Discord ID Must Be An 18-Digit Long Integer!', array('given id' => $value, 'size' => strlen($value), 'expected size' => 18));
    }

    return true;
}







function discord_linker_rest_api_init() {
    register_rest_route("discord_linker/v1/discord", "/link/(?P<discord_id>.*)/(?P<link_token>.*)", array(
        'methods' => "GET",
        'callback' => "link_discord_to_user",
        'args' => array(
            'discord_id' => array(
                'validate_callback' => 'is_discord_id'
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
                "validate_callback" => 'is_discord_id'
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




?>