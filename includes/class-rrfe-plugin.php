<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RRFE_Plugin {
    private const OPTION_FORM_ID = 'rrfe_subscription_form_id';
    private const OPTION_CONSENT_VERSION = 'rrfe_consent_version';
    private const CAP = 'manage_options';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rr_subscribers';
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            email_key varchar(191) NOT NULL,
            consent_status varchar(20) NOT NULL DEFAULT 'collected',
            consent_version varchar(100) NOT NULL DEFAULT '',
            consent_at datetime NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email_key (email_key),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta( $sql );
        add_option( self::OPTION_FORM_ID, 0 );
        add_option( self::OPTION_CONSENT_VERSION, 'blog-updates-v1' );
    }

    public static function bootstrap() {
        add_action( 'wpcf7_before_send_mail', array( __CLASS__, 'capture' ), 10, 3 );
        // Validate before CF7 sends its success response. The database unique index
        // remains the final guard against concurrent duplicate requests.
        add_filter( 'wpcf7_validate_email*', array( __CLASS__, 'validate_unique_email' ), 20, 2 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
        add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'settings' ) );
        add_action( 'admin_post_rrfe_export', array( __CLASS__, 'export' ) );
        add_action( 'admin_post_rrfe_delete', array( __CLASS__, 'delete' ) );
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'privacy_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'privacy_eraser' ) );
    }

    /**
     * Restrict newsletter-specific behavior to the configured CF7 form.
     */
    private static function is_subscription_form() {
        $configured = absint( get_option( self::OPTION_FORM_ID, 0 ) );
        if ( ! $configured || ! class_exists( 'WPCF7_ContactForm' ) ) {
            return false;
        }
        $current = WPCF7_ContactForm::get_current();
        return $current && (int) $current->id() === $configured;
    }

    /**
     * Reject a duplicate at validation time instead of giving a false
     * signup-success response. Never disclose whether an address exists.
     */
    public static function validate_unique_email( $result, $tag ) {
        if ( ! self::is_subscription_form() || ! is_object( $tag ) || 'your-email' !== $tag->name ) {
            return $result;
        }
        $email = isset( $_POST['your-email'] ) && is_string( $_POST['your-email'] )
            ? strtolower( trim( sanitize_email( wp_unslash( $_POST['your-email'] ) ) ) )
            : '';
        if ( ! is_email( $email ) ) {
            return $result; // CF7's built-in email validation owns this error.
        }
        global $wpdb;
        $table = self::table();
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE email_key = %s LIMIT 1",
            hash( 'sha256', $email )
        ) );
        if ( $exists ) {
            $result->invalidate( $tag, __( 'We could not add this address. If you have subscribed before, you are already on the list.', 'rooted-roaming-form-entries' ) );
        }
        return $result;
    }

    /** Clear only our newsletter form, and only after CF7 reports success. */
    public static function enqueue_frontend() {
        if ( ! absint( get_option( self::OPTION_FORM_ID, 0 ) ) ) {
            return;
        }
        wp_enqueue_script(
            'rrfe-signup-reset',
            plugins_url( 'assets/js/signup-reset.js', RRFE_PLUGIN_DIR . 'rooted-roaming-form-entries.php' ),
            array(),
            RRFE_VERSION,
            true
        );
        wp_add_inline_script(
            'rrfe-signup-reset',
            'window.rrfeNewsletterFormId=' . wp_json_encode( absint( get_option( self::OPTION_FORM_ID, 0 ) ) ) . ';',
            'before'
        );
    }

    public static function settings() {
        register_setting( 'rrfe_settings', self::OPTION_FORM_ID, array(
            'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint',
        ) );
        register_setting( 'rrfe_settings', self::OPTION_CONSENT_VERSION, array(
            'type' => 'string', 'default' => 'blog-updates-v1',
            'sanitize_callback' => 'sanitize_text_field',
        ) );
    }

    public static function capture( $contact_form, &$abort = false, $submission = null ) {
        global $wpdb;
        $id = absint( get_option( self::OPTION_FORM_ID, 0 ) );
        if ( ! $id || ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) || (int) $contact_form->id() !== $id ) {
            return;
        }
        if ( ! $submission && class_exists( 'WPCF7_Submission' ) ) {
            $submission = WPCF7_Submission::get_instance();
        }
        if ( ! is_object( $submission ) || ! method_exists( $submission, 'get_posted_data' ) ) {
            return;
        }
        // This CF7 hook runs after validation and spam screening, but before sending mail.
        $data = $submission->get_posted_data();
        if ( ! is_array( $data ) ) {
            return;
        }
        $name  = isset( $data['your-name'] ) && is_string( $data['your-name'] ) ? sanitize_text_field( $data['your-name'] ) : '';
        $email = isset( $data['your-email'] ) && is_string( $data['your-email'] ) ? sanitize_email( $data['your-email'] ) : '';
        $email = strtolower( trim( $email ) );
        if ( '' === $name || ! is_email( $email ) ) {
            return;
        }
        // Explicit consent: only signups submitted through the specifically configured subscription form.
        // Do not interpret this as a double-opt-in email confirmation.
        $now     = current_time( 'mysql', true );
        $version = sanitize_text_field( (string) get_option( self::OPTION_CONSENT_VERSION, 'blog-updates-v1' ) );
        $key     = hash( 'sha256', $email );
        $table   = self::table();
        // Never silently reactivate a previously opted-out address or overwrite its consent record.
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE email_key = %s LIMIT 1", $key ) );
        if ( $exists ) {
            return;
        }
        $result = $wpdb->insert( $table, array(
            'form_id' => $id, 'name' => $name, 'email' => $email, 'email_key' => $key,
            'consent_status' => 'collected', 'consent_version' => $version,
            'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        if ( false === $result && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'RRFE: Failed to store subscriber; inspect database configuration.' );
        }
    }

    public static function menu() {
        add_menu_page( 'Subscribers', 'Subscribers', self::CAP, 'rrfe-subscribers', array( __CLASS__, 'entries_page' ), 'dashicons-email-alt2', 27 );
        add_submenu_page( 'rrfe-subscribers', 'Settings', 'Settings', self::CAP, 'rrfe-settings', array( __CLASS__, 'settings_page' ) );
    }

    public static function settings_page() {
        if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Not permitted.', 'rooted-roaming-form-entries' ) ); }
        ?>
        <div class="wrap"><h1>Form Entries — Settings</h1>
        <form method="post" action="options.php">
        <?php settings_fields( 'rrfe_settings' ); ?>
        <table class="form-table" role="presentation">
        <tr><th scope="row"><label for="rrfe_subscription_form_id">Contact Form 7 subscription form ID</label></th>
        <td><input type="number" min="0" id="rrfe_subscription_form_id" name="<?php echo esc_attr( self::OPTION_FORM_ID ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_FORM_ID, 0 ) ); ?>" class="regular-text" required>
        <p class="description">Only this form is captured. A value of 0 disables capture.</p></td></tr>
        <tr><th scope="row"><label for="rrfe_consent_version">Consent wording version</label></th>
        <td><input type="text" id="rrfe_consent_version" name="<?php echo esc_attr( self::OPTION_CONSENT_VERSION ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_CONSENT_VERSION, 'blog-updates-v1' ) ); ?>" class="regular-text">
        <p class="description">Update this when the subscription disclosure changes. Keep the exact published wording in your records.</p></td></tr>
        </table><?php submit_button(); ?>
        </form></div>
        <?php
    }

    public static function dashboard() {
        if ( current_user_can( self::CAP ) ) {
            wp_add_dashboard_widget( 'rrfe_recent_subscribers', 'Rooted and Roaming — Subscribers', array( __CLASS__, 'dashboard_widget' ) );
        }
    }

    public static function dashboard_widget() {
        global $wpdb;
        $table = self::table();
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        $rows  = $wpdb->get_results( "SELECT name, email, created_at FROM $table ORDER BY id DESC LIMIT 5" );
        echo '<p><strong>' . esc_html( number_format_i18n( $count ) ) . '</strong> collected addresses (not double-opt-in confirmed)</p>';
        if ( $rows ) {
            echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>UTC date</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                echo '<tr><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $row->email ) . '</td><td>' . esc_html( $row->created_at ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No subscribers collected yet.</p>';
        }
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=rrfe-subscribers' ) ) . '">View all subscribers</a></p>';
    }

    public static function entries_page() {
        if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Not permitted.', 'rooted-roaming-form-entries' ) ); }
        global $wpdb;
        $table = self::table();
        $page  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
        $limit = 25;
        $offset = ( $page - 1 ) * $limit;
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $where = '';
        $args  = array();
        if ( '' !== $search ) {
            $where = ' WHERE name LIKE %s OR email LIKE %s';
            $term = '%' . $wpdb->esc_like( $search ) . '%';
            $args = array( $term, $term );
        }
        $count_query = "SELECT COUNT(*) FROM $table$where";
        $total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_query, $args ) : $count_query );
        $query = "SELECT id, name, email, consent_status, consent_version, consent_at, created_at FROM $table$where ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows  = $wpdb->get_results( $wpdb->prepare( $query, array_merge( $args, array( $limit, $offset ) ) ) );
        $export_url = wp_nonce_url( admin_url( 'admin-post.php?action=rrfe_export' ), 'rrfe_export' );
        echo '<div class="wrap"><h1>Subscribers</h1><p>These are collected signup records, not evidence of double-opt-in confirmation.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="rrfe-subscribers"><label for="rrfe-search">Search subscribers</label> <input id="rrfe-search" name="s" value="' . esc_attr( $search ) . '"> <button class="button">Search</button></form>';
        echo '<p><a class="button button-primary" href="' . esc_url( $export_url ) . '">Export collected subscribers (CSV)</a></p>';
        echo '<table class="widefat striped"><thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Status</th><th scope="col">Consent version</th><th scope="col">UTC collected</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $row->email ) . '</td><td>' . esc_html( $row->consent_status ) . '</td><td>' . esc_html( $row->consent_version ) . '</td><td>' . esc_html( $row->consent_at ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Permanently delete this subscriber?\');"><input type="hidden" name="action" value="rrfe_delete"><input type="hidden" name="id" value="' . esc_attr( $row->id ) . '">' . wp_nonce_field( 'rrfe_delete_' . $row->id, '_wpnonce', true, false ) . '<button type="submit" class="button button-link-delete">Delete</button></form></td></tr>';
        }
        if ( ! $rows ) { echo '<tr><td colspan="6">No subscribers found.</td></tr>'; }
        echo '</tbody></table>';
        $pages = (int) ceil( $total / $limit );
        if ( $pages > 1 ) {
            echo '<nav aria-label="Subscriber pages"><p>';
            for ( $i = 1; $i <= $pages; $i++ ) {
                $url = add_query_arg( array( 'page' => 'rrfe-subscribers', 'paged' => $i, 's' => $search ), admin_url( 'admin.php' ) );
                echo ' <a class="button' . ( $i === $page ? ' button-primary' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $i === $page ? ' aria-current="page"' : '' ) . '>' . esc_html( (string) $i ) . '</a>';
            }
            echo '</p></nav>';
        }
        echo '</div>';
    }

    public static function delete() {
        if ( ! current_user_can( self::CAP ) ) { wp_die( 'Not permitted.', '', array( 'response' => 403 ) ); }
        $id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
        check_admin_referer( 'rrfe_delete_' . $id );
        if ( $id ) {
            global $wpdb;
            $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=rrfe-subscribers' ) );
        exit;
    }

    private static function safe_csv( $value ) {
        $value = (string) $value;
        // Prevent spreadsheet programs from interpreting user-controlled fields as formulas.
        if ( preg_match( '/^\s*[=+\-@\t\r]/u', $value ) ) {
            return "'" . $value;
        }
        return $value;
    }

    public static function export() {
        if ( ! current_user_can( self::CAP ) ) { wp_die( 'Not permitted.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'rrfe_export' );
        global $wpdb;
        $table = self::table();
        while ( ob_get_level() ) { ob_end_clean(); }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="rooted-roaming-subscribers-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'name', 'email', 'consent_status', 'consent_version', 'consent_at_utc', 'created_at_utc' ) );
        $last_id = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, email, consent_status, consent_version, consent_at, created_at FROM $table WHERE id > %d ORDER BY id ASC LIMIT 500", $last_id ) );
            foreach ( $rows as $row ) {
                fputcsv( $out, array_map( array( __CLASS__, 'safe_csv' ), array( $row->name, $row->email, $row->consent_status, $row->consent_version, $row->consent_at, $row->created_at ) ) );
                $last_id = (int) $row->id;
            }
        } while ( count( $rows ) === 500 );
        fclose( $out );
        exit;
    }

    public static function privacy_exporter( $exporters ) {
        $exporters['rrfe-subscribers'] = array( 'exporter_friendly_name' => 'Rooted and Roaming Subscribers', 'callback' => array( __CLASS__, 'export_personal_data' ) );
        return $exporters;
    }

    public static function privacy_eraser( $erasers ) {
        $erasers['rrfe-subscribers'] = array( 'eraser_friendly_name' => 'Rooted and Roaming Subscribers', 'callback' => array( __CLASS__, 'erase_personal_data' ) );
        return $erasers;
    }

    public static function export_personal_data( $email_address, $page = 1 ) {
        global $wpdb;
        $email = strtolower( trim( sanitize_email( $email_address ) ) );
        $table = self::table();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, email, consent_status, consent_version, consent_at, created_at FROM $table WHERE email_key = %s", hash( 'sha256', $email ) ) );
        $data = array();
        foreach ( $rows as $r ) {
            $items = array();
            foreach ( array( 'name', 'email', 'consent_status', 'consent_version', 'consent_at', 'created_at' ) as $field ) {
                $items[] = array( 'name' => $field, 'value' => $r->$field );
            }
            $data[] = array( 'group_id' => 'rrfe-subscribers', 'group_label' => 'Subscriber records', 'item_id' => 'subscriber-' . $r->id, 'data' => $items );
        }
        return array( 'data' => $data, 'done' => true );
    }

    public static function erase_personal_data( $email_address, $page = 1 ) {
        global $wpdb;
        $email = strtolower( trim( sanitize_email( $email_address ) ) );
        $table = self::table();
        $result = $wpdb->delete( $table, array( 'email_key' => hash( 'sha256', $email ) ), array( '%s' ) );
        return array( 'items_removed' => $result > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
    }
}
