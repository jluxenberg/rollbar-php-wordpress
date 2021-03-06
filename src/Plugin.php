<?php
 
namespace Rollbar\Wordpress;

use \Rollbar\Payload\Level as Level;

// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

class Plugin {
    
    private static $instance;
    
    private $settings = null;
    
    private function __construct() {
        
        $this->fetchSettings();
        
    }
    
    public static function instance() {
        if( !self::$instance ) {
            self::$instance = new Plugin();
            self::$instance->loadTextdomain();
            self::$instance->hooks();
            self::$instance->initSettings();
        }

        return self::$instance;
    }
    
    public static function load() {
        return Plugin::instance();
    }
    
    private function initSettings() {
        Settings::init();
    }
    
    /**
     * Fetch settings provided in Admin -> Tools -> Rollbar
     * 
     * @returns array
     */
    private function fetchSettings() {
        
        $options = get_option( 'rollbar_wp' );
        
        if (empty($options['environment'])) {
            
            if ($wpEnv = getenv('WP_ENV')) {
                $options['environment'] = $wpEnv;
            }
            
        }
        
        $settings = array(
            
            'php_logging_enabled' => (!empty($options['php_logging_enabled'])) ? 1 : 0,
            
            'js_logging_enabled' => (!empty($options['js_logging_enabled'])) ? 1 : 0,
            
            'server_side_access_token' => (!empty($options['server_side_access_token'])) ? 
                esc_attr(trim($options['server_side_access_token'])) : 
                '',
                
            'client_side_access_token' => (!empty($options['client_side_access_token'])) ? 
                trim($options['client_side_access_token']) : 
                '',
            
            'environment' => (!empty($options['environment'])) ? 
                esc_attr(trim($options['environment'])) : 
                '',
            
            'logging_level' => (!empty($options['logging_level'])) ? 
                esc_attr(trim($options['logging_level'])) : 
                1024
        );
        
        $this->settings = $settings;
        
    }

    private function hooks() {
        \add_action('init', array(&$this, 'initPhpLogging'));
        \add_action('wp_head', array(&$this, 'initJsLogging'));
        $this->registerTestEndpoint();
    }
    
    private function registerTestEndpoint() {
        \add_action( 'rest_api_init', function () {
            \register_rest_route(
                'rollbar/v1', 
                '/test-php-logging',
                array(
                    'methods' => 'POST',
                    'callback' => '\Rollbar\Wordpress\Plugin::testPhpLogging',
                    'args' => array(
                        'server_side_access_token' => array(
                            'required' => true
                        ),
                        'environment' => array(
                            'required' => true
                        ),
                        'logging_level' => array(
                            'required' => true
                        )
                    )
                )
            );
        });
    }
    
    public static function testPhpLogging(\WP_REST_Request $request) {
        
        $plugin = self::instance();
        
        $plugin->settings['server_side_access_token'] = $request->get_param("server_side_access_token");
        $plugin->settings['environment'] = $request->get_param("environment");
        $plugin->settings['logging_level'] = $request->get_param("logging_level");
        
        try {
            $plugin->initPhpLogging();
            
            \Rollbar\Rollbar::log(
                Level::INFO,
                "Test message from Rollbar Wordpress plugin using PHP: ".
                "integration with Wordpress successful"
            );
        } catch( \Exception $exception ) {
            return new \WP_REST_Response(array(), 500);   
        }
        
        return new \WP_REST_Response(array(), 200);
        
    }

    public function loadTextdomain() {
        \load_plugin_textdomain( 'rollbar', false, dirname( \plugin_basename( __FILE__  ) ) . '/languages/' );
    }
    
    public function initPhpLogging()
    {
    
        // Return if logging is not enabled
        if ( $this->settings['php_logging_enabled'] === 0 ) {
            return;
        }
    
        // Return if access token is not set
        if ($this->settings['server_side_access_token'] == '')
            return;
    
        // Config
        $config = array(
            // required
            'access_token' => $this->settings['server_side_access_token'],
            // optional - environment name. any string will do.
            'environment' => $this->settings['environment'],
            // optional - path to directory your code is in. used for linking stack traces.
            'root' => ABSPATH,
            'max_errno' => $this->settings['logging_level']
        );
    
        // installs global error and exception handlers
        \Rollbar\Rollbar::init($config);
        
    }
    
    public function initJsLogging()
    {
        
        // Return if logging is not enabled
        if ( $this->settings['js_logging_enabled'] === 0 ) {
            return;
        }
    
        // Return if access token is not set
        if ($this->settings['client_side_access_token'] == '')
            return;
        
        $rollbarJs = \Rollbar\RollbarJsHelper::buildJs(
            array(
                'accessToken' => $this->settings['client_side_access_token'],
                "captureUncaught" => true,
                "payload" => array(
                    'environment' => $this->settings['environment']
                ),
            )
        );
        
        echo $rollbarJs;
        
    }
}

\add_action( 'plugins_loaded', '\Rollbar\Wordpress\Plugin::load' );
