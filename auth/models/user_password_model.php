<?php

class NAILS_User_password_model extends CI_Model
{
    //  Class traits
    use NAILS_COMMON_TRAIT_ERROR_HANDLING;
    use NAILS_COMMON_TRAIT_CACHING;

    protected $user_model;
    protected $pwCharsetSymbol;
    protected $pwCharsetLowerAlpha;
    protected $pwCharsetUpperAlpha;
    protected $pwCharsetNumber;
    protected $pwGenerateSeperator;
    protected $pwGenerateSegmentLength;

    // --------------------------------------------------------------------------

    /**
     * Constructs the model
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        //  Set defaults
        $this->pwCharsetSymbol          = utf8_encode('!@$^&*(){}":?<>~-=[];\'\\/.,');
        $this->pwCharsetLowerAlpha      = utf8_encode('abcdefghijklmnopqrstuvwxyz');
        $this->pwCharsetUpperAlpha      = utf8_encode('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        $this->pwCharsetNumber          = utf8_encode('0123456789');
        $this->pwGenerateSeperator      = '-';
        $this->pwGenerateSegmentLength  = 4;
    }

    // --------------------------------------------------------------------------

    /**
     * Inject the user object, private by convention - only really used by a few
     * core Nails classes
     * @param stdClass &$user The User Object
     */
    public function setUserObject(&$user)
    {
        $this->user_model = $user;
    }

    // --------------------------------------------------------------------------

    /**
     * Changes a password for a particular user
     * @param  int    $userId   The user ID whose password to change
     * @param  steing $password The raw, unencrypted new password
     * @return boolean
     */
    public function change($userId, $password)
    {
        //  @TODO
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether a password is correct for a particular user.
     * @param  int     $userId   The user ID to check for
     * @param  string  $password The raw, unencrypted password to check
     * @return boolean
     */
    public function isCorrect($userId, $password)
    {
        if (empty($userId) || empty($password)) {

            return false;
        }

        // --------------------------------------------------------------------------

        $this->db->select('u.password, u.password_engine, u.salt');
        $this->db->where('u.id', $userId);
        $this->db->limit(1);
        $result = $this->db->get(NAILS_DB_PREFIX . 'user u');

        // --------------------------------------------------------------------------

        if ($result->num_rows() !== 1) {

            return false;
        }

        // --------------------------------------------------------------------------

        /**
         * @TODO: use the appropriate driver to determine password correctness, but
         * for now, do it the old way
         */

        $hash = sha1(sha1($password) . $result->row()->salt);

        return $result->row()->password === $hash;
    }

    // --------------------------------------------------------------------------

    /**
     * Create a password hash, checks to ensure a password is strong enough according
     * to the password rules defined by the app.
     * @param  string $password The raw, unencrypted password
     * @return mixed            stdClass on success, false on failure
     */
    public function generateHash($password)
    {
        if (empty($password)) {

            $this->_set_error('No password to hash');
            return false;
        }

        // --------------------------------------------------------------------------

        //  Check password satisfies password rules
        $_password_rules = $this->getRules();

        //  Lgng enough?
        if (strlen($password) < $_password_rules['min_length']) {

            $this->_set_error('Password is too short.');
            return false;
        }

        //  Too long?
        if ($_password_rules['max_length']) {

            if (strlen($password) > $_password_rules['max_length']) {

                $this->_set_error('Password is too long.');
                return false;
            }
        }

        //  Contains at least 1 character from each of the charsets
        foreach ($_password_rules['charsets'] as $slug => $charset) {

            $_chars     = str_split($charset);
            $_is_valid  = false;

            foreach ($_chars as $char) {

                if (strstr($password, $char)) {

                    $_is_valid = true;
                    break;
                }
            }

            if (!$_is_valid) {

                switch ($slug) {

                    case 'symbol':

                        $_item = 'a symbol';
                        break;

                    case 'lower_alpha':

                        $_item = 'a lower case letter';
                        break;

                    case 'upper_alpha':

                        $_item = 'an upper case letter';
                        break;

                    case 'number':

                        $_item = 'a number';
                        break;
                }

                $this->_set_error('Password must contain ' . $_item . '.');
                return false;
            }
        }

        //  Not be a bad password?
        foreach ($_password_rules['is_not'] as $str) {

            if (strtolower($password) == strtolower($str)) {

                $this->_set_error('Password cannot be "' . $str . '"');
                return false;
            }
        }

        // --------------------------------------------------------------------------

        //  Password is valid, generate a salt
        return self::generateHashObject($password);
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a null password hash
     * @return mixed stdClass on success, false on failure
     */
    public function generateNullHash()
    {
        return self::generateHashObject(null);
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a password hash, no strength checks
     * @param  string $password The password to generate the hash for
     * @return stdClass
     */
    public static function generateHashObject($password)
    {
        $salt = self::salt();

        // --------------------------------------------------------------------------

        $_out               = new stdClass();
        $_out->password     = sha1(sha1($password) . $salt);
        $_out->password_md5 = md5($_out->password);
        $_out->salt         = $salt;
        $_out->engine       = 'NAILS_1';

        return $_out;
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a password which is sufficiently secure according to the app's password rules
     * @param  string $seperator The seperator to use, if any
     * @return string
     */
    public function generate($seperator = null)
    {
        $_password_rules = $this->getRules();
        $_pw_out         = array();

        // --------------------------------------------------------------------------

        if (is_null($seperator)) {
            $seperator = $this->pwGenerateSeperator;
        }

        // --------------------------------------------------------------------------

        /**
         * We're generating a password, so ensure that we've got all the charsets;
         * also make sure we include any additional charsets which have been defined.
         */

        $_charsets                = array();
        $_charsets['symbol']      = $this->pwCharsetSymbol;
        $_charsets['lower_alpha'] = $this->pwCharsetLowerAlpha;
        $_charsets['upper_alpha'] = $this->pwCharsetUpperAlpha;
        $_charsets['number']      = $this->pwCharsetNumber;

        foreach ($_charsets as $set => $chars) {

            if (isset($_password_rules['charsets'][$set])) {

                $_password_rules['charsets'][$set] = $chars;
            }
        }

        //  If there're no charsets defined, then define a default set
        if (empty($_password_rules['charsets'])) {

            $_password_rules['charsets']['lower_alpha'] = $this->pwCharsetLowerAlpha;
            $_password_rules['charsets']['upper_alpha'] = $this->pwCharsetUpperAlpha;
            $_password_rules['charsets']['number']      = $this->pwCharsetNumber;

        }

        // --------------------------------------------------------------------------

        //  Work out the max length, if it's not been set
        if (!$_password_rules['min_length'] && !$_password_rules['max_length']) {

            $_password_rules['max_length'] = count($_password_rules['charsets']) * 2;

        } elseif ($_password_rules['min_length'] && !$_password_rules['max_length']) {

            $_password_rules['max_length'] = $_password_rules['min_length'] + count($_password_rules['charsets']);

        } elseif ($_password_rules['min_length'] > $_password_rules['max_length']) {

            $_password_rules['max_length'] = $_password_rules['min_length'] + count($_password_rules['charsets']);
        }

        // --------------------------------------------------------------------------

        //  We now have a max_length and all our chars, generate password!
        $_password_valid = true;
        do {

            do {

                foreach ($_password_rules['charsets'] as $charset) {

                    $_character = rand(0, strlen($charset) - 1);
                    $_pw_out[]  = $charset[$_character];
                }

            } while (count($_pw_out) < $_password_rules['max_length']);

            //  Check password isn't a prohibited string
            foreach ($_password_rules['is_not'] as $str) {

                if (strtolower(implode('', $_pw_out)) == strtolower($str)) {

                    $_password_valid = false;
                    break;
                }
            }

        } while (!$_password_valid);

        // --------------------------------------------------------------------------

        //  Shuffle the string
        shuffle($_pw_out);

        // --------------------------------------------------------------------------

        /**
         * Replace some chgaracters with the seperator (so as to maintain the correct
         * password length)
         */

        $segmentLength = $this->pwGenerateSegmentLength + 1;
        for ($i=0; $i<count($_pw_out); $i++) {

            if (($i % $segmentLength) == ($segmentLength-1)) {

                $_pw_out[$i] = $this->pwGenerateSeperator;
            }
        }

        return implode('', $_pw_out);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the app's raw password rules as an array
     * @return array
     */
    protected function getRules()
    {
        $this->config->load('auth/auth');

        $_pw_str     = '';
        $_pw_rules   = $this->config->item('auth_password_rules');
        $_pw_rules   = !is_array($_pw_rules) ? array() : $_pw_rules;
        $_min_length = 0;
        $_max_length = false;
        $_contains   = array();
        $_is_not     = array();

        foreach ($_pw_rules as $rule => $val) {

            switch ($rule) {

                case 'min_length':

                    $_min_length = (int) $val;
                    break;

                case 'max_length':

                    $_max_length = (int) $val;
                    break;

                case 'contains':

                    foreach ($val as $str) {

                        $_contains[] = (string) $str;
                    }
                    break;

                case 'is_not':

                    foreach ($val as $str) {

                        $_is_not[] = (string) $str;
                    }
                    break;
            }
        }

        // --------------------------------------------------------------------------

        $_contains = array_filter($_contains);
        $_contains = array_unique($_contains);

        $_is_not = array_filter($_is_not);
        $_is_not = array_unique($_is_not);

        // --------------------------------------------------------------------------

        //  Generate the lsit of characters to use
        $_chars = array();
        foreach ($_contains as $charset) {

            switch ($charset) {

                case 'symbol':

                    $_chars[$charset] = $this->pwCharsetSymbol;
                    break;

                case 'lower_alpha':

                    $_chars[$charset] = $this->pwCharsetLowerAlpha;
                    break;

                case 'upper_alpha':

                    $_chars[$charset] = $this->pwCharsetUpperAlpha;
                    break;

                case 'number':

                    $_chars[$charset] = $this->pwCharsetNumber;
                    break;


                /**
                 * Not a 'special' charset? Whatever this string is just set
                 * that as the chars to use
                 */

                default:

                    $_chars[] = utf8_encode($charset);
                    break;
            }
        }

        // --------------------------------------------------------------------------

        /**
         * Make sure min_length is >= count($_chars), so we can satisfy the
         * requirements of the chars
         */

        $_min_length = $_min_length < count($_chars) ? count($_chars) : $_min_length;

        $_out = array();
        $_out['min_length'] = $_min_length;
        $_out['max_length'] = $_max_length;
        $_out['charsets']   = $_chars;
        $_out['is_not']     = $_is_not;

        return $_out;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the app's password rules as a formatted string
     * @return string
     */
    public function getRulesAsString()
    {
        $rules  = $this->getRulesAsArray();

        if (empty($rules)) {

            return '';
        }

        $str = 'Passwords must ' . strtolower(implode(', ', $rules)) . '.';

        $this->load->helper('string');
        return str_lreplace(', ', ' and ', $str);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the app's password rules as a formatted string, with each rule as
     * an array element
     * @return array
     */
    public function getRulesAsArray()
    {
        $rules = $this->getRules();
        $out   = array();

        if (!empty($rules['min_length'])) {

            $out[] = 'Be at least ' . $rules['min_length'] . ' characters';
        }

        if (!empty($rules['max_length'])) {

            $out[] = 'Have at most ' . $rules['max_length'] . ' characters';
        }

        foreach ($rules['charsets'] as $charset => $value) {

            switch ($charset) {

                case 'symbol':

                    $out[] = 'Contain a symbol';
                    break;

                case 'lower_alpha':

                    $out[] = 'Contain a lowercase letter';
                    break;

                case 'upper_alpha':

                    $out[] = 'Contain an upper case letter';
                    break;

                case 'number':

                    $out[] = 'Contain a number';
                    break;
            }
        }

        return $out;
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a random salt
     * @param  string $pepper Additional data to inject into the salt
     * @return string
     */
    public static function salt($pepper = '')
    {
        return md5(uniqid($pepper . rand() . DEPLOY_PRIVATE_KEY . APP_PRIVATE_KEY, true));
    }

    // --------------------------------------------------------------------------

    /**
     * Sets a forgotten password token for a user
     * @param string $identifier The identifier to use for setting the token (set by APP_NATIVE_LOGIN_USING)
     * @return boolean
     */
    public function set_token($identifier)
    {
        if (empty($identifier)) {

            return false;
        }

        // --------------------------------------------------------------------------

        //  Generate code
        $_key = sha1(sha1(self::salt()) . self::salt() . APP_PRIVATE_KEY);
        $_ttl = time() + 86400; // 24 hours.

        // --------------------------------------------------------------------------

        //  Update the user
        switch (APP_NATIVE_LOGIN_USING) {

            case 'EMAIL':

                $_user = $this->user_model->get_by_email($identifier);
                break;

            // --------------------------------------------------------------------------

            case 'USERNAME':

                $_user = $this->user_model->get_by_username($identifier);
                break;

            // --------------------------------------------------------------------------

            default:

                if (valid_email($identifier)) {

                    $_user = $this->user_model->get_by_email($identifier);

                } else {

                    $_user = $this->user_model->get_by_username($identifier);
                }
                break;
        }

        if ($_user) {

            $_data = array(

                'forgotten_password_code' => $_ttl . ':' . $_key
            );

            return $this->user_model->update($_user->id, $_data);

        } else {

            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Validate a forgotten password code.
     * @param  string $code The token to validate
     * @param  string $generate_new_pw Whetehr or not to generate a new password (only if token is valid)
     * @return boolean
     */
    public function validate_token($code, $generate_new_pw)
    {
        if (empty($code)) {

            return false;
        }

        // --------------------------------------------------------------------------

        $this->db->like('forgotten_password_code', ':' . $code, 'before');
        $_q = $this->db->get(NAILS_DB_PREFIX . 'user');

        // --------------------------------------------------------------------------

        if ($_q->num_rows() != 1) {

            return false;
        }

        // --------------------------------------------------------------------------

        $_user = $_q->row();
        $_code = explode(':', $_user->forgotten_password_code);

        // --------------------------------------------------------------------------

        //  Check that the link is still valid
        if (time() > $_code[0]) {

            return 'EXPIRED';

        } else {

            //  Valid hash and hasn't expired.
            $_out            = array();
            $_out['user_id'] = $_user->id;

            //  Generate a new password?
            if ($generate_new_pw) {

                $_out['password']   = $this->generate();

                if (empty($_out['password'])) {

                    //  This should never happen, but just in case.
                    return false;
                }

                $_hash = $this->generateHash($_out['password']);

                if (!$_hash) {

                    //  Again, this should never happen, but just in case.
                    return false;
                }

                // --------------------------------------------------------------------------

                $_data['password']                = $_hash->password;
                $_data['password_md5']            = $_hash->password_md5;
                $_data['password_engine']         = $_hash->engine;
                $_data['salt']                    = $_hash->salt;
                $_data['temp_pw']                 = true;
                $_data['forgotten_password_code'] = null;

                $this->db->where('forgotten_password_code', $_user->forgotten_password_code);
                $this->db->set($_data);
                $this->db->update(NAILS_DB_PREFIX . 'user');
            }
        }

        return $_out;
    }
}


// --------------------------------------------------------------------------


/**
 * OVERLOADING NAILS' MODELS
 *
 * The following block of code makes it simple to extend one of the core
 * models. Some might argue it's a little hacky but it's a simple 'fix'
 * which negates the need to massively extend the CodeIgniter Loader class
 * even further (in all honesty I just can't face understanding the whole
 * Loader class well enough to change it 'properly').
 *
 * Here's how it works:
 *
 * CodeIgniter instantiate a class with the same name as the file, therefore
 * when we try to extend the parent class we get 'cannot redeclare class X' errors
 * and if we call our overloading class something else it will never get instantiated.
 *
 * We solve this by prefixing the main class with NAILS_ and then conditionally
 * declaring this helper class below; the helper gets instantiated et voila.
 *
 * If/when we want to extend the main class we simply define NAILS_ALLOW_EXTENSION
 * before including this PHP file and extend as normal (i.e in the same way as below);
 * the helper won't be declared so we can declare our own one, app specific.
 *
 **/

if (!defined('NAILS_ALLOW_EXTENSION_USER_PASSWORD_MODEL')) {

    class User_password_model extends NAILS_User_password_model
    {
    }
}
