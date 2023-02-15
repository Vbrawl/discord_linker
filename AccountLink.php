<?php



class dlAccountLink {

    /**
     * Store the values in the object and try to
     * fill all data gaps.
     *
     * @param int $user_id
     * @param int $discord_id
     */
    function __construct($user_id, $discord_id) {
        $this->user_id = $user_id;
        $this->discord_id = $discord_id;


        if($this->user_id === null && $this->discord_id !== null) {
            $this->get_user_id();
        }
        else if($this->user_id !== null && $this->discord_id === null) {
            $this->get_discord_id();
        }
    }


    /**
     * Fetch the discord id of the wordpress user,
     * update the stored value in the object and return
     * the fetched value.
     *
     * @return int: On success return the ID of the discord user.
     * @return null: On failure return null.
     */
    function get_discord_id() {
        global $wpdb;

        if($this->user_id === null) {
            return null;
        }

        $this->discord_id = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->prefix}usermeta WHERE meta_key = %s AND user_id = %d", DL_LINK_USERMETA_KEY, $this->user_id));

        if($this->discord_id !== null) {
            return intval($this->discord_id);
        }
        return null;
    }


    /**
     * Fetch the wordpress id of the discord user,
     * update the stored value in the object and return
     * the fetched value.
     *
     * @return int: On success return the ID of the wordpress user.
     * @return null: On failure return null.
     */
    function get_user_id() {
        global $wpdb;

        if($this->discord_id === null) {
            return null;
        }

        $this->user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = %s AND meta_value = %s", DL_LINK_USERMETA_KEY, $this->discord_id));

        if($this->user_id !== null) {
            return intval($this->user_id);
        }
        return null;
    }


    /**
     * Check if there is a link between
     * the discord id and the wordpress id.
     *
     * @return boolean
     */
    function linked_together() {
        global $wpdb;
        $umeta_id = $wpdb->get_var($wpdb->prepare("SELECT umeta_id FROM {$wpdb->prefix}usermeta WHERE meta_key = %s AND meta_value = %d AND user_id = %d", DL_LINK_USERMETA_KEY, $this->discord_id, $this->user_id));

        return ($umeta_id !== null);
    }


    /**
     * Check if it's possible to link those discord
     * and wordpress accounts together.
     *
     * @return boolean
     */
    function available_link() {
        global $wpdb;
        $discord_link_meta_id = $wpdb->get_var($wpdb->prepare("SELECT umeta_id FROM {$wpdb->prefix}usermeta WHERE meta_key = %s AND meta_value = %d", DL_LINK_USERMETA_KEY, $this->discord_id));
        $user_link_meta_id = $wpdb->get_var($wpdb->prepare("SELECT umeta_id FROM {$wpdb->prefix}usermeta WHERE mete_key = %s AND user_id = %d", DL_LINK_USERMETA_KEY, $this->user_id));

        return ($discord_link_meta_id === null) && ($user_link_meta_id === null);
    }


    /**
     * Try to link the accounts together.
     *
     * @throws WP_Error[ALREADY_LINKED]: When an account is already linked.
     * @return void
     */
    function link_accounts() {
        global $wpdb;
        if($this->available_link()) {
            $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}usermeta (user_id, meta_key, meta_value) VALUES (%d, %s, %d)", $this->user_id, DL_LINK_USERMETA_KEY, $this->discord_id));
        }
        else {
            return new WP_Error("ALREADY_LINKED", "There is some active link in an account", array("user id" => $this->user_id, "discord id" => $this->discord_id));
        }
    }


    /**
     * Try to unlink the accounts.
     *
     * @throws WP_Error[NOT_LINKED_TOGETHER]: When the accounts are not linked together.
     * @return void
     */
    function unlink_accounts() {
        global $wpdb;
        if($this->linked_together()) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}usermeta WHERE meta_key = %s AND meta_value = %s AND user_id = %d", DL_LINK_USERMETA_KEY, $this->discord_id, $this->user_id));
        }
        else {
            return new WP_Error("NOT_LINKED_TOGETHER", "The accounts are not linked together", array("user id" => $this->user_id, "discord id" => $this->discord_id));
        }
    }




    /**
     * Check if the real user has the priviledges
     * required to impersonate another user.
     *
     * @return boolean
     */
    function can_impersonate() {
        global $REAL_WP_ID;
        $current_id = get_current_user_id();

        return ($current_id !== $REAL_WP_ID) || current_user_can(DISCORD_USER_IMPERSONATION);
    }


    /**
     * Impersonate another user.
     * 
     * @throws WP_Error[USER_NOT_FOUND]: When the user we try to impersonate doesn't exist.
     * @throws WP_Error[INSUFFICIENT_PERMISSIONS]: When the current user doesn't have enough permissions to impersonate someone else.
     * @return int
     */
    function impersonate() {
        global $wpdb;
        global $IMPERSONATED_WP_ID;

        // Check if user has the permissions to impersonate other users.
        if($this->can_impersonate()) {
            $fake_wp_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = %s AND meta_value = %s;", DL_LINK_USERMETA_KEY, $this->discord_id));

            if($fake_wp_id !== null) {
                $IMPERSONATED_WP_ID = intval($fake_wp_id);
                wp_set_current_user($IMPERSONATED_WP_ID);
            }
            else {
                return new WP_Error("USER_NOT_FOUND", "The User you are trying to impersonate doesn't exist", array("discord id" => $this->discord_id));
            }
        }
        else {
            return new WP_Error("INSUFFICIENT_PERMISSIONS", "You don't have enough permissions to impersonate other users", array("DISCORD_USER_IMPERSONATION" => $this->can_impersonate));
        }

        return $IMPERSONATED_WP_ID;
    }


    /**
     * Reset to the real user (before impersonating)
     *
     * @throws WP_Error[NOT_IMPERSONATING]: When the user doesn't impersonate anyone.
     * @return int
     */
    function reset_impersonation() {
        global $REAL_WP_ID;
        global $IMPERSONATED_WP_ID;

        if($IMPERSONATED_WP_ID !== null) {
            wp_set_current_user($REAL_WP_ID);
            $IMPERSONATED_WP_ID = null;
        }
        else {
            throw new WP_Error("NOT_IMPERSONATING", "You don't impersonate anyone");
        }

        return $REAL_WP_ID;
    }



}









