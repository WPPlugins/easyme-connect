<?php
/*
Plugin Name: EasyMe Connect
Plugin URI: https://easyme.com/
Description: Connect your EasyMe account to Wordpress and offer your services directly from your own Web site.
Author: EasyMe
Version: 1.0.2
Text Domain: easyme
Domain Path: /lang
*/

class EasyMe {

    private static $_optsKey = 'easyme_connect_options';
    private static $_menuSlug = 'easyme_connect';
    private static $_noticePool = [];
    
    private static function getEasyMeServer() {
        if(array_key_exists('EM_SERVER', $_SERVER)) {
            return $_SERVER['EM_SERVER'];
        }
        return 'https://secure.easyme.biz';
    }

    private static function showNotice($msg, $type = 'warning') {
        
        add_action('admin_notices', function() use ($msg, $type) {        
                include_once(__DIR__ . '/message.html');
            });
        
    }

    private static function handleError($error) {

        if(is_wp_error($error)) {
            error_log($wpError->get_error_message());
        } elseif(array_key_exists('http_response', $error)) {
            // http error
            error_log(var_export($error['response'], true));
        }

    }
    
    public static function easymeActivate() {        
        add_option(self::$_optsKey, []);
    }

    public static function easymeDeactivate() {

        $set = get_option(self::$_optsKey);

        if(is_array($set) && $set['access_token']) {
            $http = wp_remote_get(self::getEasyMeServer() . '/connect/oauth/token_revoke', ['headers' => self::getHeadersForHTTP()]);
        }
        
        delete_option(self::$_optsKey);

    }

    public static function addMenu() {
        add_management_page(
            'EasyMe Connect',
            'EasyMe Connect',
            'manage_options',
            self::$_menuSlug,
            'EasyMe::menu'
        );
    }

    public static function menu() {

        $scheme = 'http';
        if(array_key_exists('REQUEST_SCHEME', $_SERVER)) {
            $scheme = strtolower($_SERVER['REQUEST_SCHEME']);
        } elseif( array_key_exists('SERVER_PORT', $_SERVER) && 443 == $_SERVER['SERVER_PORT']) {
            $scheme = 'https';
        } elseif( array_key_exists('HTTPS', $_SERVER) && !empty($_SERVER['HTTPS'])) {
            $scheme = 'https';
        }
        
        $thisPage = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?page=' . self::$_menuSlug;

        $qs = [
            'client_id' => 'easyme_connect_wp',
            'response_type' => 'code',
            'redirect_uri' => $thisPage,
            'scope' => 'connect'
        ];

        $_page = [
            'auth_url' => self::getEasyMeServer() . '/connect/oauth/authorize' . '?' . http_build_query($qs)
        ];
        
        if(array_key_exists('code', $_GET)) {

            $fields = [
                'grant_type' => 'authorization_code',
                'client_id' => 'easyme_connect_wp',
                'redirect_uri' => $thisPage,
                'code' => filter_input(INPUT_GET, 'code')
            ];

            $http = wp_remote_post(self::getEasyMeServer() . '/connect/oauth/access_token', ['body' => $fields]);

            if(is_wp_error($http)) {
                self::handleError($http);
                return;
            } 
            
            switch($http['response']['code']) {

            case '200':
                $json = $http['body'];

                $res = json_decode($json, TRUE);

                self::updateSetting('access_token', $res['access_token']);
                self::updateSetting('refresh_token', $res['refresh_token']);                
                self::updateSetting('access_token_expires', ($res['expires_in'] + $_SERVER['REQUEST_TIME']));

                // caches the client
                self::getEasyMeClient();

                $tokenInfo = self::getTokenInfo();
                self::updateSetting('site', $tokenInfo['site']);
                break;

            default:
                self::handleError($http);                                
                
            }            
            
        }
        
        $set = get_option(self::$_optsKey);

        if(!empty($set['access_token'])) {
            $_page['site'] = $set['site'];
            include(__DIR__ . '/connected.html');                    
        } else {
            include(__DIR__ . '/disconnected.html');                            
        }
        
    }

    private static function updateSetting($key, $val) {

        $set = get_option(self::$_optsKey);

        $set[ $key ] = $val;

        update_option(self::$_optsKey, $set);
        
    }
    
    private static function getTokenInfo() {

        $http = wp_remote_get(self::getEasyMeServer() . '/connect/oauth/token_info', ['headers' => self::getHeadersForHTTP()]);

        if(is_wp_error($http)) {
            self::handleError($http);
            return [];
        }
        
        switch($http['response']['code']) {

        case 200:
            return json_decode($http['body'], TRUE);
            break;

        default:
            self::handleError($http);            
            
        }

        return [];

    }

    private static function getEasyMeClient() {

        $set = get_option(self::$_optsKey);

        if(is_array($set) && array_key_exists('client_expires', $set) && $set['client_expires'] > $_SERVER['REQUEST_TIME']) {
            return $set['client'];
        }        

        if(!$set['refresh_token']) {
            return '<!-- EasyMe Connect: No valid access_token found -->';
        }
        
        self::checkTokenValidity();
        
        $http = wp_remote_get(self::getEasyMeServer() . '/connect/oauth/client', ['headers' => self::getHeadersForHTTP()]);

        if(is_wp_error($http)) {

            self::handleError($http);

        } else {
        
            switch($http['response']['code']) {

            case 200:
                $res = json_decode($http['body'], TRUE);
                self::updateSetting('client', $res['html']);
                self::updateSetting('client_expires', ($_SERVER['REQUEST_TIME'] + $res['ttl']));
                break;

            default:

                self::handleError($http);                

            }

        }
        
        $set = get_option(self::$_optsKey);        

        return $set['client'];
        
    }

    private static function getHeadersForHTTP() {
        $set = get_option(self::$_optsKey);
        return ['Authorization' => 'Bearer ' . $set['access_token']];
    }
    
    private static function refreshToken() {

        $set = get_option(self::$_optsKey);
        
        $fields = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $set['refresh_token'],
            'client_id' => 'easyme_connect_wp',
            'client_secret' => 'hest'
        ];

        $http = wp_remote_post(self::getEasyMeServer() . '/connect/oauth/access_token', ['body' => $fields]);

        if(is_wp_error($http)) {

            self::handleError($http);

        } else {
            
            switch($http['response']['code']) {

            case '200':

                $res = json_decode($http['body'], TRUE);

                self::updateSetting('access_token', $res['access_token']);
                self::updateSetting('refresh_token', $res['refresh_token']);                
                self::updateSetting('access_token_expires', ($res['expires_in'] + $_SERVER['REQUEST_TIME']));

                break;

            default:

                self::handleError($http);                

            }
        } 
        
    }

    private static function checkTokenValidity() {

        $set = get_option(self::$_optsKey);

        if(array_key_exists('access_token_expires', $set) && $set['access_token_expires'] <= $_SERVER['REQUEST_TIME']) {
            self::refreshToken();
        }

    }
    
    public static function addClient() {
        echo self::getEasyMeClient();
    }

    public static function loadTranslations() {
        load_plugin_textdomain( 'easyme', FALSE, basename( __DIR__ ) . '/lang/' );
    }
    
    public static function run() {

        self::loadTranslations();
        
        register_activation_hook(__FILE__, get_class() . '::easymeActivate');
        register_deactivation_hook(__FILE__, get_class() . '::easymeDeactivate');
        add_action('admin_menu', get_class() . '::addMenu');
        add_action('wp_footer', get_class() . '::addClient');
        
        if(version_compare(PHP_VERSION, '5.4.0') < 0) {
            self::showNotice( __('You must run PHP version 5.4.0 or newer for the EasyMe Connect plugin to work', 'easyme'), 'error' );
        }

        if('easyme_connect' != filter_input(INPUT_GET, 'page')) {

            $set = get_option(self::$_optsKey);

            if(!is_array($set) || !array_key_exists('access_token', $set)) {
                self::showNotice( sprintf(__('You still need to <a href="%s">connect Wordpress to your EasyMe account</a> before you are ready to use the plugin', 'easyme'), '/wp-admin/tools.php?page=easyme_connect'), 'warning' );
            }
            
        }

    }
    
}

EasyMe::run();

?>