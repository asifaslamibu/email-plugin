<?php
/**
 * Plugin Name: F450 Email Registration
 * Plugin URI: https://www.f450.com
 * Description: Simple email registration system that creates @f450.com email accounts via cPanel integration
 * Version: 1.0.0
 * Author: F450 Development Team
 * License: GPL v2 or later
 * Text Domain: f450-email
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('F450_EMAIL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('F450_EMAIL_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('F450_EMAIL_VERSION', '1.0.0');

class F450EmailRegistration {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_f450_register_email', array($this, 'handle_email_registration'));
        add_action('wp_ajax_nopriv_f450_register_email', array($this, 'handle_email_registration'));
        add_action('wp_ajax_f450_check_username', array($this, 'check_username_availability'));
        add_action('wp_ajax_nopriv_f450_check_username', array($this, 'check_username_availability'));
        add_action('wp_ajax_f450_admin_action', array($this, 'handle_admin_action'));
        add_shortcode('f450_email_form', array($this, 'display_registration_form'));
        
        // Login functionality
        add_shortcode('f450_login_form', array($this, 'display_login_form'));
        add_action('wp_ajax_f450_login', array($this, 'handle_login'));
        add_action('wp_ajax_nopriv_f450_login', array($this, 'handle_login'));
        add_action('wp_ajax_f450_logout', array($this, 'handle_logout'));
        add_action('wp_ajax_nopriv_f450_logout', array($this, 'handle_logout'));
        add_action('init', array($this, 'start_session'));
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function start_session() {
        if (!session_id()) {
            session_start();
        }
    }
    
    public function init() {
        $this->create_database_tables();
    }
    
    public function activate() {
        $this->create_database_tables();
        
        // Set default options
        add_option('f450_cpanel_host', '');
        add_option('f450_cpanel_user', '');
        add_option('f450_cpanel_token', '');
        add_option('f450_domain', 'f450.com');
        add_option('f450_default_quota', 250); // 250MB default
    }
    
    public function deactivate() {
        // Clean up if needed
    }
    
    private function create_database_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'f450_email_accounts';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            username varchar(100) NOT NULL,
            email varchar(150) NOT NULL,
            password_hash varchar(255) NOT NULL,
            status enum('active','disabled') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username),
            UNIQUE KEY email (email)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('f450-email-js', F450_EMAIL_PLUGIN_URL . 'assets/f450-email.js', array('jquery'), F450_EMAIL_VERSION, true);
        wp_enqueue_style('f450-email-css', F450_EMAIL_PLUGIN_URL . 'assets/f450-email.css', array(), F450_EMAIL_VERSION);
        
        wp_localize_script('f450-email-js', 'f450_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('f450_email_nonce')
        ));
    }
    
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'f450-email') === false) {
            return;
        }
        
        wp_enqueue_script('f450-admin-js', F450_EMAIL_PLUGIN_URL . 'assets/f450-admin.js', array('jquery'), F450_EMAIL_VERSION, true);
        wp_enqueue_style('f450-admin-css', F450_EMAIL_PLUGIN_URL . 'assets/f450-admin.css', array(), F450_EMAIL_VERSION);
        
        wp_localize_script('f450-admin-js', 'f450_admin_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('f450_admin_nonce')
        ));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'F450 Email Manager',
            'F450 Email',
            'manage_options',
            'f450-email-manager',
            array($this, 'admin_page'),
            'dashicons-email-alt',
            30
        );
        
        add_submenu_page(
            'f450-email-manager',
            'Settings',
            'Settings',
            'manage_options',
            'f450-email-settings',
            array($this, 'settings_page')
        );
    }
    
    public function display_registration_form($atts) {
        $atts = shortcode_atts(array(
            'title' => 'Get Your @f450.com Email',
            'button_text' => 'Create Account'
        ), $atts);
        
        ob_start();
        ?>
        <div class="f450-email-form-container">
            <div class="f450-email-form-header">
                <h3><?php echo esc_html($atts['title']); ?></h3>
                <p>Create your free @f450.com email address instantly</p>
            </div>
            
            <form id="f450-email-form" class="f450-email-form">
                <div class="f450-form-group">
                    <label for="f450-username">Choose Username</label>
                    <div class="f450-username-container">
                        <input type="text" id="f450-username" name="username" placeholder="yourname" required>
                        <span class="f450-domain">@f450.com</span>
                    </div>
                    <div id="f450-username-feedback" class="f450-feedback"></div>
                </div>
                
                <div class="f450-form-group">
                    <label for="f450-password">Password</label>
                    <input type="password" id="f450-password" name="password" placeholder="Create a strong password" required>
                    <div id="f450-password-strength" class="f450-password-strength"></div>
                </div>
                
                <div class="f450-form-group">
                    <label for="f450-confirm-password">Confirm Password</label>
                    <input type="password" id="f450-confirm-password" name="confirm_password" placeholder="Confirm your password" required>
                </div>
                
                <div class="f450-form-group">
                    <button type="submit" id="f450-submit-btn" class="f450-submit-btn">
                        <span class="f450-btn-text"><?php echo esc_html($atts['button_text']); ?></span>
                        <span class="f450-spinner" style="display: none;">Creating...</span>
                    </button>
                </div>
                
                <div id="f450-form-messages" class="f450-messages"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // Display login form
    public function display_login_form($atts) {
        $atts = shortcode_atts(array(
            'title' => 'Login to Your @f450.com Email',
            'button_text' => 'Login',
            'redirect_url' => 'https://p3plzcpnl505499.prod.phx3.secureserver.net:2096/webmail'
        ), $atts);
        
        // Check if user is already logged in
        if ($this->is_user_logged_in()) {
            $user_info = $this->get_current_user_info();
            ob_start();
            ?>
            <div class="f450-login-container">
                <div class="f450-user-dashboard">
                    <div class="f450-welcome-message">
                        <h3>Welcome, <?php echo esc_html($user_info['username']); ?>!</h3>
                        <p>You are logged in as: <strong><?php echo esc_html($user_info['email']); ?></strong></p>
                    </div>
                    
                    <div class="f450-user-actions">
                        <div class="f450-email-info">
                            <h4>Your Email Settings:</h4>
                            <p><strong>Email:</strong> <?php echo esc_html($user_info['email']); ?></p>
                            <p><strong>Status:</strong> <span class="status-<?php echo esc_attr($user_info['status']); ?>"><?php echo esc_html(ucfirst($user_info['status'])); ?></span></p>
                            <p><strong>Created:</strong> <?php echo esc_html(mysql2date('M j, Y', $user_info['created_at'])); ?></p>
                        </div>
                        
                        <div class="f450-webmail-access">
                            <h4>Access Your Email:</h4>
                            <a href="<?php echo esc_url($this->get_webmail_url()); ?>" target="_blank" class="f450-webmail-btn">
                                Open Webmail
                            </a>
                            <p class="f450-webmail-info">
                                <small>Use your email (<?php echo esc_html($user_info['email']); ?>) and password to login</small>
                            </p>
                        </div>
                        
                        <div class="f450-logout-section">
                            <button id="f450-logout-btn" class="f450-logout-btn">Logout</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        ob_start();
        ?>
        <div class="f450-login-container">
            <div class="f450-login-form-header">
                <h3><?php echo esc_html($atts['title']); ?></h3>
                <p>Login to access your email dashboard</p>
            </div>
            
            <form id="f450-login-form" class="f450-login-form">
                <div class="f450-form-group">
                    <label for="f450-login-username">Username or Email</label>
                    <input type="text" id="f450-login-username" name="username" placeholder="yourname or yourname@f450.com" required>
                </div>
                
                <div class="f450-form-group">
                    <label for="f450-login-password">Password</label>
                    <input type="password" id="f450-login-password" name="password" placeholder="Enter your password" required>
                </div>
                
                <div class="f450-form-group">
                    <button type="submit" id="f450-login-submit-btn" class="f450-submit-btn">
                        <span class="f450-btn-text"><?php echo esc_html($atts['button_text']); ?></span>
                        <span class="f450-spinner" style="display: none;">Logging in...</span>
                    </button>
                </div>
                
                <div id="f450-login-messages" class="f450-messages"></div>
                
                <input type="hidden" name="redirect_url" value="<?php echo esc_attr($atts['redirect_url']); ?>">
            </form>
            
            <div class="f450-form-footer">
                <p>Don't have an account? <a href="#register">Create one here</a></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function check_username_availability() {
        check_ajax_referer('f450_email_nonce', 'nonce');
        
        $username = sanitize_text_field($_POST['username']);
        
        if (empty($username)) {
            wp_send_json_error('Username is required');
        }
        
        if (!$this->validate_username($username)) {
            wp_send_json_error('Invalid username format');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'f450_email_accounts';
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE username = %s",
            $username
        ));
        
        if ($exists > 0) {
            wp_send_json_error('Username already taken');
        }
        
        wp_send_json_success('Username available');
    }
    
    public function handle_email_registration() {
        check_ajax_referer('f450_email_nonce', 'nonce');
        
        $username = sanitize_text_field($_POST['username']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validation
        if (empty($username) || empty($password) || empty($confirm_password)) {
            wp_send_json_error('All fields are required');
        }
        
        if ($password !== $confirm_password) {
            wp_send_json_error('Passwords do not match');
        }
        
        if (!$this->validate_username($username)) {
            wp_send_json_error('Invalid username format');
        }
        
        if (!$this->validate_password($password)) {
            wp_send_json_error('Password must be at least 8 characters long');
        }
        
        // Check if username exists
        global $wpdb;
        $table_name = $wpdb->prefix . 'f450_email_accounts';
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE username = %s",
            $username
        ));
        
        if ($exists > 0) {
            wp_send_json_error('Username already taken');
        }
        
        $domain = get_option('f450_domain', 'f450.com');
        $email = $username . '@' . $domain;
        
        // Create email account in cPanel
        $cpanel_result = $this->create_cpanel_email_account($username, $password);
        
        if (!$cpanel_result['success']) {
            wp_send_json_error('Failed to create email account: ' . $cpanel_result['message']);
        }
        
        // Store in database
        $password_hash = password_hash($password, PASSWORD_ARGON2ID);
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'username' => $username,
                'email' => $email,
                'password_hash' => $password_hash,
                'status' => 'active'
            ),
            array('%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to save account information');
        }
        
        wp_send_json_success(array(
            'message' => 'Email account created successfully!',
            'email' => $email
        ));
    }
    
    // Handle login
    public function handle_login() {
        check_ajax_referer('f450_email_nonce', 'nonce');
        
        $username_or_email = sanitize_text_field($_POST['username']);
        $password = $_POST['password'];
        $redirect_url = sanitize_url($_POST['redirect_url']);
        
        if (empty($username_or_email) || empty($password)) {
            wp_send_json_error('Username and password are required');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'f450_email_accounts';
        
        // Check if input is email or username
        if (strpos($username_or_email, '@') !== false) {
            // It's an email
            $user = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE email = %s AND status = 'active'",
                $username_or_email
            ));
        } else {
            // It's a username
            $user = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE username = %s AND status = 'active'",
                $username_or_email
            ));
        }
        
        if (!$user) {
            wp_send_json_error('Invalid username/email or account is disabled');
        }
        
        // Verify password
        if (!password_verify($password, $user->password_hash)) {
            wp_send_json_error('Invalid password');
        }
        
        // Create session
        $_SESSION['f450_user_id'] = $user->id;
        $_SESSION['f450_username'] = $user->username;
        $_SESSION['f450_email'] = $user->email;
        $_SESSION['f450_login_time'] = time();
        
        wp_send_json_success(array(
            'message' => 'Login successful!',
            'redirect_url' => $redirect_url ?: ''
        ));
    }
    
    // Handle logout
    public function handle_logout() {
        check_ajax_referer('f450_email_nonce', 'nonce');
        
        // Destroy F450 session data
        unset($_SESSION['f450_user_id']);
        unset($_SESSION['f450_username']);
        unset($_SESSION['f450_email']);
        unset($_SESSION['f450_login_time']);
        
        wp_send_json_success(array(
            'message' => 'Logged out successfully'
        ));
    }
    
    // Check if user is logged in
    private function is_user_logged_in() {
        return isset($_SESSION['f450_user_id']) && !empty($_SESSION['f450_user_id']);
    }
    
    // Get current user info
    private function get_current_user_info() {
        if (!$this->is_user_logged_in()) {
            return false;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'f450_email_accounts';
        
        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $_SESSION['f450_user_id']
        ));
        
        return $user ? (array) $user : false;
    }
    
    // Get webmail URL
    private function get_webmail_url() {
        $cpanel_host = get_option('f450_cpanel_host');
        $domain = get_option('f450_domain', 'f450.com');
        
        // Try common webmail URLs
        if (!empty($cpanel_host)) {
            return "https://$cpanel_host/webmail";
        } else {
            return "https://webmail.$domain";
        }
    }
    
    private function create_cpanel_email_account($username, $password) {
        $cpanel_host = get_option('f450_cpanel_host');
        $cpanel_user = get_option('f450_cpanel_user');
        $cpanel_token = get_option('f450_cpanel_token');
        $domain = get_option('f450_domain', 'f450.com');
        $quota = get_option('f450_default_quota', 250);
        
        if (empty($cpanel_host) || empty($cpanel_user) || empty($cpanel_token)) {
            return array('success' => false, 'message' => 'cPanel credentials not configured');
        }
        
        $url = "https://$cpanel_host:2083/execute/Email/add_pop";
        
        $post_data = array(
            'email' => $username,
            'password' => $password,
            'quota' => $quota,
            'domain' => $domain
        );
        
        $headers = array(
            'Authorization: cpanel ' . $cpanel_user . ':' . $cpanel_token,
            'Content-Type: application/x-www-form-urlencoded'
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false || $http_code !== 200) {
            return array('success' => false, 'message' => 'Failed to connect to cPanel');
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['status']) && $result['status'] === 1) {
            return array('success' => true, 'message' => 'Email account created successfully');
        } else {
            $error_message = isset($result['errors'][0]) ? $result['errors'][0] : 'Unknown error';
            return array('success' => false, 'message' => $error_message);
        }
    }
    
    private function validate_username($username) {
        // Username must be 3-30 characters, alphanumeric plus dots, hyphens, underscores
        return preg_match('/^[a-zA-Z0-9._-]{3,30}$/', $username);
    }
    
    private function validate_password($password) {
        // Minimum 8 characters
        return strlen($password) >= 8;
    }
    
    public function admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'f450_email_accounts';
        
        // Handle pagination
        $items_per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $items_per_page;
        
        // Get total count
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_items / $items_per_page);
        
        // Get accounts
        $accounts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $items_per_page,
            $offset
        ));
        
        ?>
        <div class="wrap">
            <h1>F450 Email Accounts</h1>
            
            <div class="f450-stats">
                <div class="f450-stat-box">
                    <h3><?php echo number_format($total_items); ?></h3>
                    <p>Total Accounts</p>
                </div>
                <div class="f450-stat-box">
                    <?php
                    $active_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'active'");
                    ?>
                    <h3><?php echo number_format($active_count); ?></h3>
                    <p>Active Accounts</p>
                </div>
            </div>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <p>Use shortcode <code>[f450_email_form]</code> to display the registration form and <code>[f450_login_form]</code> for login form on any page or post.</p>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($accounts)): ?>
                        <tr>
                            <td colspan="6">No email accounts found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td><?php echo esc_html($account->id); ?></td>
                                <td><?php echo esc_html($account->username); ?></td>
                                <td><?php echo esc_html($account->email); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($account->status); ?>">
                                        <?php echo esc_html(ucfirst($account->status)); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(mysql2date('M j, Y g:i A', $account->created_at)); ?></td>
                                <td>
                                    <?php if ($account->status === 'active'): ?>
                                        <button class="button f450-admin-action" data-action="disable" data-id="<?php echo esc_attr($account->id); ?>">Disable</button>
                                    <?php else: ?>
                                        <button class="button f450-admin-action" data-action="enable" data-id="<?php echo esc_attr($account->id); ?>">Enable</button>
                                    <?php endif; ?>
                                    <button class="button button-link-delete f450-admin-action" data-action="delete" data-id="<?php echo esc_attr($account->id); ?>" onclick="return confirm('Are you sure you want to delete this account?')">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $page
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('f450_settings_nonce');
            
            update_option('f450_cpanel_host', sanitize_text_field($_POST['cpanel_host']));
            update_option('f450_cpanel_user', sanitize_text_field($_POST['cpanel_user']));
            update_option('f450_cpanel_token', sanitize_text_field($_POST['cpanel_token']));
            update_option('f450_domain', sanitize_text_field($_POST['domain']));
            update_option('f450_default_quota', intval($_POST['default_quota']));
            
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
        
        $cpanel_host = get_option('f450_cpanel_host', '');
        $cpanel_user = get_option('f450_cpanel_user', '');
        $cpanel_token = get_option('f450_cpanel_token', '');
        $domain = get_option('f450_domain', 'f450.com');
        $default_quota = get_option('f450_default_quota', 250);
        
        ?>
        <div class="wrap">
            <h1>F450 Email Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('f450_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">cPanel Host</th>
                        <td>
                            <input type="text" name="cpanel_host" value="<?php echo esc_attr($cpanel_host); ?>" class="regular-text" placeholder="your-server.com" />
                            <p class="description">Your cPanel server hostname (without https://)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">cPanel Username</th>
                        <td>
                            <input type="text" name="cpanel_user" value="<?php echo esc_attr($cpanel_user); ?>" class="regular-text" />
                            <p class="description">Your cPanel username</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">cPanel API Token</th>
                        <td>
                            <input type="password" name="cpanel_token" value="<?php echo esc_attr($cpanel_token); ?>" class="regular-text" />
                            <p class="description">Your cPanel API token (create one in cPanel → Security → Manage API Tokens

                            <p class="description">Your cPanel API token (create one in cPanel → Security → Manage API Tokens)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Domain</th>
                        <td>
                            <input type="text" name="domain" value="<?php echo esc_attr($domain); ?>" class="regular-text" />
                            <p class="description">The domain for email accounts (e.g., f450.com)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Default Quota (MB)</th>
                        <td>
                            <input type="number" name="default_quota" value="<?php echo esc_attr($default_quota); ?>" class="small-text" min="50" max="10000" />
                            <p class="description">Default mailbox quota in megabytes</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <div class="card">
                <h2>Setup Instructions</h2>
                <ol>
                    <li>Log into your GoDaddy cPanel</li>
                    <li>Go to <strong>Security → Manage API Tokens</strong></li>
                    <li>Create a new API token with email permissions</li>
                    <li>Copy the token and paste it in the settings above</li>
                    <li>Add the shortcode <code>[f450_email_form]</code> to any page or post where you want the registration form to appear</li>
                    <li>Add the shortcode <code>[f450_login_form]</code> to any page or post where you want the login form to appear</li>
                </ol>
            </div>
        </div>
        <?php
    }
    
    public function handle_admin_action() {
        check_ajax_referer('f450_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $action = sanitize_text_field($_POST['action_type']);
        $account_id = intval($_POST['account_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'f450_email_accounts';
        
        switch ($action) {
            case 'enable':
                $result = $wpdb->update(
                    $table_name,
                    array('status' => 'active'),
                    array('id' => $account_id),
                    array('%s'),
                    array('%d')
                );
                break;
                
            case 'disable':
                $result = $wpdb->update(
                    $table_name,
                    array('status' => 'disabled'),
                    array('id' => $account_id),
                    array('%s'),
                    array('%d')
                );
                break;
                
            case 'delete':
                // Get account info before deleting
                $account = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_name WHERE id = %d",
                    $account_id
                ));
                
                if ($account) {
                    // Delete from cPanel (optional - implement if needed)
                    // $this->delete_cpanel_email_account($account->username);
                    
                    // Delete from database
                    $result = $wpdb->delete(
                        $table_name,
                        array('id' => $account_id),
                        array('%d')
                    );
                }
                break;
                
            default:
                wp_send_json_error('Invalid action');
        }
        
        if ($result !== false) {
            wp_send_json_success('Action completed successfully');
        } else {
            wp_send_json_error('Action failed');
        }
    }
}

// Initialize the plugin
new F450EmailRegistration();

// Create the CSS file content
if (!file_exists(F450_EMAIL_PLUGIN_PATH . 'assets/')) {
    wp_mkdir_p(F450_EMAIL_PLUGIN_PATH . 'assets/');
}

// CSS Content
$css_content = '
/* F450 Email Registration Styles */
.f450-email-form-container {
    max-width: 500px;
    margin: 0 auto;
    padding: 30px;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.f450-email-form-header {
    text-align: center;
    margin-bottom: 30px;
}

.f450-email-form-header h3 {
    color: #2c3e50;
    font-size: 24px;
    margin-bottom: 10px;
}

.f450-email-form-header p {
    color: #7f8c8d;
    font-size: 16px;
    margin: 0;
}

.f450-form-group {
    margin-bottom: 20px;
}

.f450-form-group label {
    display: block;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
    font-size: 14px;
}

.f450-username-container {
    display: flex;
    align-items: center;
    border: 2px solid #e1e8ed;
    border-radius: 8px;
    overflow: hidden;
    transition: border-color 0.3s ease;
}

.f450-username-container:focus-within {
    border-color: #3498db;
}

.f450-username-container input {
    flex: 1;
    padding: 12px 15px;
    border: none;
    outline: none;
    font-size: 16px;
    background: transparent;
}

.f450-domain {
    background: #f8f9fa;
    padding: 12px 15px;
    color: #6c757d;
    font-weight: 500;
    border-left: 1px solid #e1e8ed;
}

.f450-form-group input[type="password"] {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e1e8ed;
    border-radius: 8px;
    font-size: 16px;
    transition: border-color 0.3s ease;
    box-sizing: border-box;
}

.f450-form-group input[type="password"]:focus {
    outline: none;
    border-color: #3498db;
}

.f450-feedback {
    margin-top: 8px;
    font-size: 14px;
    min-height: 20px;
}

.f450-feedback.success {
    color: #27ae60;
}

.f450-feedback.error {
    color: #e74c3c;
}

.f450-password-strength {
    margin-top: 8px;
    font-size: 12px;
    min-height: 16px;
}

.f450-submit-btn {
    width: 100%;
    padding: 15px;
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
}

.f450-submit-btn:hover:not(:disabled) {
    background: linear-gradient(135deg, #2980b9, #2471a3);
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(52, 152, 219, 0.3);
}

.f450-submit-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
}

.f450-spinner {
    display: inline-block;
}

.f450-messages {
    margin-top: 20px;
    padding: 15px;
    border-radius: 8px;
    font-size: 14px;
    text-align: center;
    min-height: 20px;
}

.f450-messages.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.f450-messages.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Login Form Styles */
.f450-login-container {
    max-width: 500px;
    margin: 0 auto;
    padding: 30px;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.f450-login-form-header {
    text-align: center;
    margin-bottom: 30px;
}

.f450-login-form-header h3 {
    color: #2c3e50;
    font-size: 24px;
    margin-bottom: 10px;
}

.f450-login-form-header p {
    color: #7f8c8d;
    font-size: 16px;
    margin: 0;
}

.f450-login-form input[type="text"],
.f450-login-form input[type="password"] {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e1e8ed;
    border-radius: 8px;
    font-size: 16px;
    transition: border-color 0.3s ease;
    box-sizing: border-box;
}

.f450-login-form input[type="text"]:focus,
.f450-login-form input[type="password"]:focus {
    outline: none;
    border-color: #3498db;
}

.f450-form-footer {
    text-align: center;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e1e8ed;
}

.f450-form-footer a {
    color: #3498db;
    text-decoration: none;
}

.f450-form-footer a:hover {
    text-decoration: underline;
}

/* User Dashboard Styles */
.f450-user-dashboard {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 30px;
}

.f450-welcome-message {
    text-align: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e1e8ed;
}

.f450-welcome-message h3 {
    color: #2c3e50;
    font-size: 24px;
    margin-bottom: 10px;
}

.f450-welcome-message p {
    color: #7f8c8d;
    font-size: 16px;
    margin: 0;
}

.f450-user-actions {
    display: grid;
    gap: 25px;
}

.f450-email-info,
.f450-webmail-access,
.f450-logout-section {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.f450-email-info h4,
.f450-webmail-access h4 {
    color: #2c3e50;
    font-size: 18px;
    margin-bottom: 15px;
    border-bottom: 2px solid #3498db;
    padding-bottom: 8px;
}

.f450-email-info p {
    margin: 8px 0;
    color: #555;
}

.f450-webmail-btn {
    display: inline-block;
    padding: 12px 25px;
    background: linear-gradient(135deg, #27ae60, #2ecc71);
    color: white;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.3s ease;
    margin-bottom: 10px;
}

.f450-webmail-btn:hover {
    background: linear-gradient(135deg, #229954, #27ae60);
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(39, 174, 96, 0.3);
    color: white;
    text-decoration: none;
}

.f450-webmail-info {
    color: #7f8c8d;
    font-size: 14px;
    margin: 0;
}

.f450-logout-btn {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.f450-logout-btn:hover {
    background: linear-gradient(135deg, #c0392b, #a93226);
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(231, 76, 60, 0.3);
}

/* Admin Styles */
.f450-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}

.f450-stat-box {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    text-align: center;
    min-width: 120px;
}

.f450-stat-box h3 {
    font-size: 28px;
    color: #2c3e50;
    margin: 0 0 5px 0;
}

.f450-stat-box p {
    color: #7f8c8d;
    margin: 0;
    font-size: 14px;
}

.status-active {
    color: #27ae60;
    font-weight: 600;
}

.status-disabled {
    color: #e74c3c;
    font-weight: 600;
}

.f450-admin-action {
    margin-right: 5px;
}

/* Responsive Design */
@media (max-width: 768px) {
    .f450-user-actions {
        grid-template-columns: 1fr;
    }
    
    .f450-login-container,
    .f450-email-form-container {
        margin: 0 20px;
        padding: 20px;
    }
    
    .f450-stats {
        flex-direction: column;
    }
    
    .f450-stat-box {
        min-width: auto;
    }
}

@media (max-width: 600px) {
    .f450-email-form-container {
        margin: 0 20px;
        padding: 20px;
    }
    
    .f450-stats {
        flex-direction: column;
    }
    
    .f450-stat-box {
        min-width: auto;
    }
}
';

// Write CSS file
if (!file_exists(F450_EMAIL_PLUGIN_PATH . 'assets/f450-email.css')) {
    file_put_contents(F450_EMAIL_PLUGIN_PATH . 'assets/f450-email.css', $css_content);
}

// JavaScript Content
$js_content = '
jQuery(document).ready(function($) {
    var usernameTimeout;
    
    // Username availability check
    $("#f450-username").on("input", function() {
        var username = $(this).val().trim();
        var feedback = $("#f450-username-feedback");
        
        clearTimeout(usernameTimeout);
        
        if (username.length < 3) {
            feedback.removeClass("success error").text("");
            return;
        }
        
        if (!isValidUsername(username)) {
            feedback.removeClass("success").addClass("error").text("Username must be 3-30 characters, letters, numbers, dots, hyphens, underscores only");
            return;
        }
        
        feedback.removeClass("success error").text("Checking...");
        
        usernameTimeout = setTimeout(function() {
            $.post(f450_ajax.ajax_url, {
                action: "f450_check_username",
                username: username,
                nonce: f450_ajax.nonce
            }, function(response) {
                if (response.success) {
                    feedback.removeClass("error").addClass("success").text("✓ Username available");
                } else {
                    feedback.removeClass("success").addClass("error").text("✗ " + response.data);
                }
            });
        }, 500);
    });
    
    // Password strength indicator
    $("#f450-password").on("input", function() {
        var password = $(this).val();
        var strength = $("#f450-password-strength");
        var score = calculatePasswordStrength(password);
        
        if (password.length === 0) {
            strength.text("");
            return;
        }
        
        switch(score) {
            case 0:
            case 1:
                strength.text("Weak password").css("color", "#e74c3c");
                break;
            case 2:
                strength.text("Fair password").css("color", "#f39c12");
                break;
            case 3:
                strength.text("Good password").css("color", "#27ae60");
                break;
            case 4:
                strength.text("Strong password").css("color", "#2ecc71");
                break;
        }
    });
    
    // Form submission
    $("#f450-email-form").on("submit", function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitBtn = $("#f450-submit-btn");
        var btnText = submitBtn.find(".f450-btn-text");
        var spinner = submitBtn.find(".f450-spinner");
        var messages = $("#f450-form-messages");
        
        var username = $("#f450-username").val().trim();
        var password = $("#f450-password").val();
        var confirmPassword = $("#f450-confirm-password").val();
        
        // Basic validation
        if (!username || !password || !confirmPassword) {
            showMessage(messages, "error", "Please fill in all fields");
            return;
        }
        
        if (password !== confirmPassword) {
            showMessage(messages, "error", "Passwords do not match");
            return;
        }
        
        if (!isValidUsername(username)) {
            showMessage(messages, "error", "Invalid username format");
            return;
        }
        
        if (password.length < 8) {
            showMessage(messages, "error", "Password must be at least 8 characters long");
            return;
        }
        
        // Disable form and show loading
        submitBtn.prop("disabled", true);
        btnText.hide();
        spinner.show();
        messages.removeClass("success error").text("");
        
        // Submit form
        $.post(f450_ajax.ajax_url, {
            action: "f450_register_email",
            username: username,
            password: password,
            confirm_password: confirmPassword,
            nonce: f450_ajax.nonce
        }, function(response) {
            if (response.success) {
                showMessage(messages, "success", response.data.message);
                form[0].reset();
                $("#f450-username-feedback").text("");
                $("#f450-password-strength").text("");
            } else {
                showMessage(messages, "error", response.data);
            }
        }).fail(function() {
            showMessage(messages, "error", "Connection error. Please try again.");
        }).always(function() {
            submitBtn.prop("disabled", false);
            btnText.show();
            spinner.hide();
        });
    });
    
    // Login Form JavaScript
    if ($("#f450-login-form").length) {
        $("#f450-login-form").on("submit", function(e) {
            e.preventDefault();
            
            var form = $(this);
            var submitBtn = $("#f450-login-submit-btn");
            var btnText = submitBtn.find(".f450-btn-text");
            var spinner = submitBtn.find(".f450-spinner");
            var messages = $("#f450-login-messages");
            
            var username = $("#f450-login-username").val().trim();
            var password = $("#f450-login-password").val();
            var redirectUrl = form.find("input[name=redirect_url]").val();
            
            if (!username || !password) {
                showMessage(messages, "error", "Please fill in all fields");
                return;
            }
            
            // Disable form and show loading
            submitBtn.prop("disabled", true);
            btnText.hide();
            spinner.show();
            messages.removeClass("success error").text("");
            
            $.post(f450_ajax.ajax_url, {
                action: "f450_login",
                username: username,
                password: password,
                redirect_url: redirectUrl,
                nonce: f450_ajax.nonce
            }, function(response) {
                if (response.success) {
                    showMessage(messages, "success", response.data.message);
                    
                    // Redirect or reload page after successful login
                    setTimeout(function() {
                        if (response.data.redirect_url) {
                            window.location.href = response.data.redirect_url;
                        } else {
                            window.location.reload();
                        }
                    }, 1500);
                } else {
                    showMessage(messages, "error", response.data);
                }
            }).fail(function() {
                showMessage(messages, "error", "Connection error. Please try again.");
            }).always(function() {
                submitBtn.prop("disabled", false);
                btnText.show();
                spinner.hide();
            });
        });
    }
    
    // Logout functionality
    $("#f450-logout-btn").on("click", function() {
        var btn = $(this);
        var originalText = btn.text();
        
        if (confirm("Are you sure you want to logout?")) {
            btn.prop("disabled", true).text("Logging out...");
            
            $.post(f450_ajax.ajax_url, {
                action: "f450_logout",
                nonce: f450_ajax.nonce
            }, function(response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert("Error: " + response.data);
                    btn.prop("disabled", false).text(originalText);
                }
            }).fail(function() {
                alert("Connection error. Please try again.");
                btn.prop("disabled", false).text(originalText);
            });
        }
    });
    
    function isValidUsername(username) {
        return /^[a-zA-Z0-9._-]{3,30}$/.test(username);
    }
    
    function calculatePasswordStrength(password) {
        var score = 0;
        
        if (password.length >= 8) score++;
        if (password.match(/[a-z]/)) score++;
        if (password.match(/[A-Z]/)) score++;
        if (password.match(/[0-9]/)) score++;
        if (password.match(/[^a-zA-Z0-9]/)) score++;
        
        return Math.min(score, 4);
    }
    
    function showMessage(element, type, message) {
        element.removeClass("success error").addClass(type).text(message);
    }
});
';

// Write JS file
if (!file_exists(F450_EMAIL_PLUGIN_PATH . 'assets/f450-email.js')) {
    file_put_contents(F450_EMAIL_PLUGIN_PATH . 'assets/f450-email.js', $js_content);
}

// Admin JavaScript Content
$admin_js_content = '
jQuery(document).ready(function($) {
    // Admin actions
    $(".f450-admin-action").on("click", function() {
        var btn = $(this);
        var action = btn.data("action");
        var accountId = btn.data("id");
        var originalText = btn.text();
        
        btn.prop("disabled", true).text("Processing...");
        
        $.post(f450_admin_ajax.ajax_url, {
            action: "f450_admin_action",
            action_type: action,
            account_id: accountId,
            nonce: f450_admin_ajax.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert("Error: " + response.data);
                btn.prop("disabled", false).text(originalText);
            }
        }).fail(function() {
            alert("Connection error. Please try again.");
            btn.prop("disabled", false).text(originalText);
        });
    });
});
';

// Write Admin JS file
if (!file_exists(F450_EMAIL_PLUGIN_PATH . 'assets/f450-admin.js')) {
    file_put_contents(F450_EMAIL_PLUGIN_PATH . 'assets/f450-admin.js', $admin_js_content);
}

// Admin CSS Content
$admin_css_content = '
/* F450 Admin Styles */
.f450-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 30px;
}

.f450-stat-box {
    background: #fff;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    text-align: center;
    flex: 1;
    max-width: 200px;
}

.f450-stat-box h3 {
    font-size: 32px;
    color: #2c3e50;
    margin: 0 0 8px 0;
    font-weight: 700;
}

.f450-stat-box p {
    color: #7f8c8d;
    margin: 0;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    color: #27ae60;
    font-weight: 600;
    background: #d4edda;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    text-transform: uppercase;
}

.status-disabled {
    color: #e74c3c;
    font-weight: 600;
    background: #f8d7da;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    text-transform: uppercase;
}

.f450-admin-action {
    margin-right: 8px;
    font-size: 12px;
    padding: 6px 12px;
}

.wp-list-table th, 
.wp-list-table td {
    vertical-align: middle;
}

.card h2 {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}

.card ol {
    line-height: 1.6;
}

.card ol li {
    margin-bottom: 8px;
}

.form-table th {
    width: 200px;
}

.form-table input[type="text"],
.form-table input[type="password"],
.form-table input[type="number"] {
    border: 2px solid #e1e8ed;
    border-radius: 6px;
    padding: 10px 12px;
    transition: border-color 0.3s ease;
}

.form-table input[type="text"]:focus,
.form-table input[type="password"]:focus,
.form-table input[type="number"]:focus {
    border-color: #3498db;
    outline: none;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
}

@media (max-width: 782px) {
    .f450-stats {
        flex-direction: column;
    }
    
    .f450-stat-box {
        max-width: none;
    }
}
';

// Write Admin CSS file
if (!file_exists(F450_EMAIL_PLUGIN_PATH . 'assets/f450-admin.css')) {
    file_put_contents(F450_EMAIL_PLUGIN_PATH . 'assets/f450-admin.css', $admin_css_content);
}

?>