<?php

/**
 * Authentication Wrapper
 *
 * @since 2.0
 * @copyright 2009-2014 YOURLS - MIT
 */

namespace YOURLS;

use Hautelook\Phpass\PasswordHash;

/**
 * Summary of Authentication
 */
class Authentication {

    /**
     * Check for valid user via login form or stored cookie. Returns true or an error message
     *
     */
    public function is_valid_user() {
        static $valid = false;

        if( $valid )

            return true;

        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_is_valid_user', null );
        if ( null !== $pre ) {
            $valid = ( $pre === true ) ;

            return $pre;
        }

        // $unfiltered_valid : are credentials valid? Boolean value. It's "unfiltered" to allow plugins to eventually filter it.
        $unfiltered_valid = false;

        // Logout request
        if( isset( $_GET['action'] ) && $_GET['action'] == 'logout' ) {
            do_action( 'logout' );
            $this->store_cookie( null );

            return array( _( 'Logged out successfully' ), 'success' );
        }

        // Check cookies or login request. Login form has precedence.

        do_action( 'pre_login' );

        // Determine auth method and check credentials
        if
            // API only: Secure (no login or pwd) and time limited token
            // ?timestamp=12345678&signature=md5(totoblah12345678)
            ( is_API() &&
              isset( $_REQUEST['timestamp'] ) && !empty($_REQUEST['timestamp'] ) &&
              isset( $_REQUEST['signature'] ) && !empty($_REQUEST['signature'] )
            )
        {
            do_action( 'pre_login_signature_timestamp' );
            $unfiltered_valid = $this->check_signature_timestamp();
        }

        elseif
            // API only: Secure (no login or pwd)
            // ?signature=md5(totoblah)
            ( is_API() &&
              !isset( $_REQUEST['timestamp'] ) &&
              isset( $_REQUEST['signature'] ) && !empty( $_REQUEST['signature'] )
            )
        {
            do_action( 'pre_login_signature' );
            $unfiltered_valid = $this->check_signature();
        }

        elseif
            // API or normal: login with username & pwd
            ( isset( $_REQUEST['username'] ) && isset( $_REQUEST['password'] )
              && !empty( $_REQUEST['username'] ) && !empty( $_REQUEST['password']  ) )
        {
            do_action( 'pre_login_username_password' );
            $unfiltered_valid = $this->check_username_password();
        }

        elseif
            // Normal only: cookies
            ( !is_API() &&
              isset( $_YOURLS_COOKIE['username'] ) )
        {
            do_action( 'pre_login_cookie' );
            $unfiltered_valid = $this->check_auth_cookie();
        }

        // Regardless of validity, allow plugins to filter the boolean and have final word
        $valid = apply_filter( 'is_valid_user', $unfiltered_valid );

        // Login for the win!
        if ( $valid ) {
            do_action( 'login' );

            // (Re)store encrypted cookie if needed
            if ( !is_API() ) {
                $this->store_cookie( YOURLS_USER );

                // Login form : redirect to requested URL to avoid re-submitting the login form on page reload
                if( isset( $_REQUEST['username'] ) && isset( $_REQUEST['password'] ) && isset( $_SERVER['REQUEST_URI'] ) ) {
                    $url = $_SERVER['REQUEST_URI'];
                    redirect( $url );
                }
            }

            // Login successful
            return true;
        }

        // Login failed
        do_action( 'login_failed' );

        if ( isset( $_REQUEST['username'] ) || isset( $_REQUEST['password'] ) ) {
            return array( _( 'Invalid username or password' ), 'error' );
        } else {
            return array( _( 'Please log in' ), 'warning' );
        }
    }

    /**
     * Check auth against list of login=>pwd. Sets user if applicable, returns bool
     *
     */
    public function check_username_password() {
        global $user_passwords;
        if( isset( $user_passwords[ $_REQUEST['username'] ] ) && $this->check_password_hash( $_REQUEST['username'], $_REQUEST['password'] ) ) {
            $this->set_user( $_REQUEST['username'] );

            return true;
        }

        return false;
    }

    /**
     * Check a submitted password sent in plain text against stored password which can be a salted hash
     *
     */
    public function check_password_hash( $user, $submitted_password ) {
        global $user_passwords;

        if( !isset( $user_passwords[ $user ] ) )

            return false;

        if ( $this->has_phpass_password( $user ) ) {
            // Stored password is hashed with phpass
            list( , $hash ) = explode( ':', $user_passwords[ $user ] );
            $hash = str_replace( '!', '$', $hash );

            return ( $this->phpass_check( $submitted_password, $hash ) );
        } else if( $this->has_md5_password( $user ) ) {
            // Stored password is a salted md5 hash: "md5:<$r = rand(10000,99999)>:<md5($r.'thepassword')>"
            list( , $salt, ) = explode( ':', $user_passwords[ $user ] );

            return( $user_passwords[ $user ] == 'md5:'.$salt.':'.md5( $salt . $submitted_password ) );
        } else {
            // Password stored in clear text
            return( $user_passwords[ $user ] == $submitted_password );
        }
    }

    /**
     * Overwrite plaintext passwords in config file with phpassed versions.
     *
     * @since 1.7
     * @param string $config_file Full path to file
     * @return true if overwrite was successful, an error message otherwise
     */
    public function hash_passwords_now( $config_file ) {
        if( !is_readable( $config_file ) )

            return 'cannot read file'; // not sure that can actually happen...

        if( !is_writable( $config_file ) )

            return 'cannot write file';

        // Include file to read value of $user_passwords
        // Temporary suppress error reporting to avoid notices about redeclared constants
        $errlevel = error_reporting();
        error_reporting( 0 );
        require $config_file;
        error_reporting( $errlevel );

        $configdata = file_get_contents( $config_file );
        if( $configdata == false )

            return 'could not read file';

        $to_hash = 0; // keep track of number of passwords that need hashing
        foreach ( $user_passwords as $user => $password ) {
            if ( !$this->has_phpass_password( $user ) && !$this->has_md5_password( $user ) ) {
                $to_hash++;
                $hash = $this->phpass_hash( $password );
                // PHP would interpret $ as a variable, so replace it in storage.
                $hash = str_replace( '$', '!', $hash );
                $quotes = "'" . '"';
                $pattern = "/[$quotes]${user}[$quotes]\s*=>\s*[$quotes]" . preg_quote( $password, '-' ) . "[$quotes]/";
                $replace = "'$user' => 'phpass:$hash' /* Password encrypted by YOURLS */ ";
                $count = 0;
                $configdata = preg_replace( $pattern, $replace, $configdata, -1, $count );
                // There should be exactly one replacement. Otherwise, fast fail.
                if ( $count != 1 ) {
                    debug_log( "Problem with preg_replace for password hash of user $user" );

                    return 'preg_replace problem';
                }
            }
        }

        if( $to_hash == 0 )

            return 0; // There was no password to encrypt

        $success = file_put_contents( $config_file, $configdata );
        if ( $success === FALSE ) {
            debug_log( 'Failed writing to ' . $config_file );

            return 'could not write file';
        }

        return true;
    }

    /**
     * Hash a password using phpass
     *
     * @since 1.7
     * @param string $password password to hash
     * @return string hashed password
     */
    public function phpass_hash( $password ) {
        $hasher = $this->phpass_instance();

        return $hasher->HashPassword( $password );
    }

    /**
     * Check a clear password against a phpass hash
     *
     * @since 1.7
     * @param string $password clear (eg submitted in a form) password
     * @param string $hash hash supposedly generated by phpass
     * @return bool true if the hash matches the password once hashed by phpass, false otherwise
     */
    public function phpass_check( $password, $hash ) {
        $hasher = $this->phpass_instance();

        return $hasher->CheckPassword( $password, $hash );
    }

    /**
     * Helper function: create new instance or return existing instance of phpass class
     *
     * @since 1.7
     * @param int $iteration iteration count - 8 is default in phpass
     * @param bool $portable flag to force portable (cross platform and system independant) hashes - false to use whatever the system can do best
     * @return object a PasswordHash instance
     */
    public function phpass_instance( $iteration = 8, $portable = false ) {
        $iteration = apply_filter( 'phpass_new_instance_iteration', $iteration );
        $portable  = apply_filter( 'phpass_new_instance_portable', $portable );

        static $instance = false;
        if( $instance == false ) {
            $instance = new PasswordHash( $iteration, $portable );
        }

        return $instance;
    }


    /**
     * Check to see if any passwords are stored as cleartext.
     *
     * @since 1.7
     * @return bool true if any passwords are cleartext
     */
    public function has_cleartext_passwords() {
        global $user_passwords;
        foreach ( $user_passwords as $user => $pwdata ) {
            if ( !$this->has_md5_password( $user ) && !$this->has_phpass_password( $user ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a user has a hashed password
     *
     * Check if a user password is 'md5:[38 chars]'.
     * TODO: deprecate this when/if we have proper user management with password hashes stored in the DB
     *
     * @since 1.7
     * @param string $user user login
     * @return bool true if password hashed, false otherwise
     */
    public function has_md5_password( $user ) {
        global $user_passwords;

        return(    isset( $user_passwords[ $user ] )
                && substr( $user_passwords[ $user ], 0, 4 ) == 'md5:'
                && strlen( $user_passwords[ $user ] ) == 42 // http://www.google.com/search?q=the+answer+to+life+the+universe+and+everything
               );
    }

    /**
     * Check if a user's password is hashed with PHPASS.
     *
     * Check if a user password is 'phpass:[lots of chars]'.
     * TODO: deprecate this when/if we have proper user management with password hashes stored in the DB
     *
     * @since 1.7
     * @param string $user user login
     * @return bool true if password hashed with PHPASS, otherwise false
     */
    public function has_phpass_password( $user ) {
        global $user_passwords;

        return( isset( $user_passwords[ $user ] )
                && substr( $user_passwords[ $user ], 0, 7 ) == 'phpass:'
        );
    }

    /**
     * Check auth against encrypted YOURLS_COOKIE data. Sets user if applicable, returns bool
     *
     */
    public function check_auth_cookie() {
        global $user_passwords;
        foreach( $user_passwords as $valid_user => $valid_password ) {
            if ( salt( $valid_user ) == $_YOURLS_COOKIE['username'] ) {
                $this->set_user( $valid_user );

                return true;
            }
        }

        return false;
    }

    /**
     * Check auth against signature and timestamp. Sets user if applicable, returns bool
     *
     */
    public function check_signature_timestamp() {
        // Timestamp in PHP : time()
        // Timestamp in JS: parseInt(new Date().getTime() / 1000)
        global $user_passwords;
        foreach( $user_passwords as $valid_user => $valid_password ) {
            if (
                (
                    md5( $_REQUEST['timestamp'].$this->auth_signature( $valid_user ) ) == $_REQUEST['signature']
                    or
                    md5( $this->auth_signature( $valid_user ).$_REQUEST['timestamp'] ) == $_REQUEST['signature']
                )
                &&
                $this->check_timestamp( $_REQUEST['timestamp'] )
                ) {
                $this->set_user( $valid_user );

                return true;
            }
        }

        return false;
    }

    /**
     * Check auth against signature. Sets user if applicable, returns bool
     *
     */
    public function check_signature() {
        global $user_passwords;
        foreach( $user_passwords as $valid_user => $valid_password ) {
            if ( $this->auth_signature( $valid_user ) == $_REQUEST['signature'] ) {
                $this->set_user( $valid_user );

                return true;
            }
        }

        return false;
    }

    /**
     * Generate secret signature hash
     *
     */
    public function auth_signature( $username = false ) {
        if( !$username && defined('YOURLS_USER') ) {
            $username = YOURLS_USER;
        }

        return ( $username ? substr( salt( $username ), 0, 10 ) : 'Cannot generate auth signature: no username' );
    }

    /**
     * Check if timestamp is not too old
     *
     */
    public function check_timestamp( $time ) {
        $now = time();
        // Allow timestamp to be a little in the future or the past -- see Issue 766
        return apply_filter( 'check_timestamp', abs( $now - $time ) < YOURLS_NONCE_LIFE, $time );
    }

    /**
     * Store new cookie. No $user will delete the cookie.
     *
     */
    public function store_cookie( $user = null ) {
        if( !$user ) {
            $pass = null;
            $time = time() - 3600;
        } else {
            global $user_passwords;
            if( isset($user_passwords[$user]) ) {
                $pass = $user_passwords[$user];
            } else {
                die( 'Stealing cookies?' ); // This should never happen
            }
            $time = time() + YOURLS_COOKIE_LIFE;
        }

        $domain   = apply_filter( 'setcookie_domain',   parse_url( SITE, 1 ) );
        $secure   = apply_filter( 'setcookie_secure',   is_ssl() );
        $httponly = apply_filter( 'setcookie_httponly', true );

        // Some browser refuse to store localhost cookie
        if ( $domain == 'localhost' )
            $domain = '';

        if ( !headers_sent() ) {
            // Set httponly if the php version is >= 5.2.0
            if( version_compare( phpversion(), '5.2.0', 'ge' ) ) {
                setcookie('username', salt( $user ), $time, '/', $domain, $secure, $httponly );
            } else {
                setcookie('username', salt( $user ), $time, '/', $domain, $secure );
            }
        } else {
            // For some reason cookies were not stored: action to be able to debug that
            do_action( 'setcookie_failed', $user );
        }
    }

    /**
     * Set user name
     *
     */
    public function set_user( $user ) {
        if( !defined( 'YOURLS_USER' ) )
            define( 'YOURLS_USER', $user );
    }

}