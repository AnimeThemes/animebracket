<?php

namespace Api {

  use Lib;
  use OAuth2;

  class User extends Lib\Dal {

    protected $_dbTable = 'users';
    protected $_dbPrimaryKey = 'id';
    protected $_dbMap = [
      'id' => 'user_id',
      'name' => 'user_name',
      'admin' => 'user_admin',
      'ip' => 'user_ip',
      'age' => 'user_age'
    ];

    public $id = 0;
    public $name;
    public $admin = false;
    public $ip;
    public $csrfToken;

    const USER_CSRF_ENTROPY = 8;
    const LOGIN_REJECT_CACHE_PREFIX = 'reddit_login_reject_';

    /**
     * Reddit registration date of the account.
     * If 0, user is banned.
     */
    public $age;

    public function __construct($row = null) {
      parent::__construct($row);
      if (is_object($row)) {
        $this->admin = ((int) $row->user_admin) === 1;
      }
    }

    public static function getByName($userName) {
      $retVal = null;
      $result = Lib\Db::Query('SELECT * FROM users WHERE user_name LIKE :userName', [ ':userName' => $userName ]);
      if ($result && $result->count) {
        $retVal = new User(Lib\Db::Fetch($result));
      }
      return $retVal;
    }

    public static function getCurrentUser() {
      $user = Lib\Session::get('user');

      // If there is a user session, take a moment to verify the validity of that user
      if ($user instanceof User) {

      }

      return $user;
    }

    /**
     * Returns the login URL for OAuth2 authentication
     */
    public static function getLoginUrl($redirect = '') {
      $client = self::_createOAuth2();
      return $client->getLoginUrl('temporary', [
        'identity'
      ], $redirect);
    }

    public function logout() {
      Lib\Session::set('user', null);
    }

    /**
     * OAuth2 response handler
     */
    public static function authenticateUser($code) {
      $retVal = false;
      $client = self::_createOAuth2();

      if ($client->getToken($code)) {
        // get account data
        $data = $client->call('api/v1/me');
        if ($data && isset($data->name)) {
          // check if already in reject cache
          if (self::_hasLoginRejectCache($data->name)) {
            return false;
          }

          // check if suspended
          if (!empty($data->is_suspended)) {
            self::_setLoginRejectCache($data->name, CACHE_LONG);
            return false;
          }

          // check about data
          $appClient = self::_createAppOAuth2();
          $aboutData = $appClient->getUserAboutData($data->name);
          $status = $aboutData ? (int) $aboutData->status : 0;
          if ($status !== 200) {
            if ($status === 404) {
              self::_setLoginRejectCache($data->name, CACHE_LONG);
            } else if ($status === 429) {
              self::_setLoginRejectCache($data->name, CACHE_SHORT);
            }
            return false;
          }

          // get user from database
          $user = self::getByName($data->name);
          if (!$user) {
            $user = new User;
            $user->name = $data->name;
            $user->age = (int) $data->created;
            $user->ip = $_SERVER['REMOTE_ADDR'];
            $user->admin = 0;
            if (!$user->sync()) {
              $user = null;
            }
          } else {

            // Backfill legacy missing age, but make sure not to overwrite local ban
            if ($user->age === null) {
              $user->age = (int) $data->created;
              $user->sync();
            }
          }

          if ($user) {
            // Save the login attempt before verifying attempt count
            self::_logLoginAttempt($user->id);

            // Now verify that the user isn't banned and hasn't tried logging in too much
            if (
              $user->age > 0 &&
              self::_verifyLoginAttempts($user->id)
            ) {
              $user->csrfToken = bin2hex(openssl_random_pseudo_bytes(self::USER_CSRF_ENTROPY));
              Lib\Session::set('user', $user);
              $retVal = true;
            }
          }
        }
      }

      return $retVal;
    }

    private static function _loginRejectCacheKey($userName) {
      return self::LOGIN_REJECT_CACHE_PREFIX . strtolower($userName);
    }

    private static function _hasLoginRejectCache($userName) {
      return !!Lib\Cache::getInstance()->get(self::_loginRejectCacheKey($userName), true);
    }

    private static function _setLoginRejectCache($userName, $ttl) {
      Lib\Cache::getInstance()->set(self::_loginRejectCacheKey($userName), true, $ttl);
    }

    private static function _createOAuth2(User $user = null) {
      $retVal = new Lib\RedditOAuth(REDDIT_TOKEN, REDDIT_SECRET, HTTP_UA, REDDIT_HANDLER);

      // If we have stashed tokens, set those up as well
      if ($user && $user->token && $user->refreshToken && $user->tokenExpires) {
        $retVal->setToken($user->token);
        $retVal->setRefreshToken($user->refreshToken);
        $retVal->setExpiration($user->tokenExpires);
      }

      return $retVal;
    }

    private static function _createAppOAuth2() {
      return new Lib\AppRedditOAuth(REDDIT_TOKEN, REDDIT_SECRET, HTTP_UA);
    }

    /**
     * Logs a login attempt to the database
     */
    private static function _logLoginAttempt($userId) {
      $params = [
        'userId' => $userId,
        'date' => time(),
        'ip' => $_SERVER['REMOTE_ADDR']
      ];

      return Lib\Db::Query(
        'INSERT INTO `logins` (`user_id`, `login_date`, `login_ip`) VALUES (:userId, :date, :ip)',
        $params
      );
    }

    /**
     * Verifies that the user trying to login can do so given
     * the number of users per IP limitation
     */
    private static function _verifyLoginAttempts($userId) {
      // Return the number of login attempts from this IP that aren't this user
      // within the last 24 hours
      $params = [
        'userId' => $userId,
        'date' => time() - 86400,
        'ip' => $_SERVER['REMOTE_ADDR']
      ];
      $result = Lib\Db::Query(
        'SELECT COUNT(DISTINCT user_id) AS attempts FROM `logins` WHERE `user_id` != :userId AND login_ip=:ip AND login_date >= :date',
        $params
      );

      $row = Lib\Db::Fetch($result);

      // If the returned count is over the amount allowed (minus the currently logged in user), no es beuno
      return $row->attempts < MAX_USERS_SHARING_IP;
    }

  }

}
