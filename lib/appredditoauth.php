<?php

namespace Lib {

  class AppRedditOAuth extends RedditOAuth {

    const APP_TOKEN_CACHE_KEY = 'reddit_app_access_token';
    const TOKEN_EXPIRY_BUFFER_SECONDS = 60;

    public function __construct($clientId, $clientSecret, $userAgent) {
      parent::__construct($clientId, $clientSecret, $userAgent, null);
    }

    /**
     * Get an application (user-less) access token, cached across requests.
     */
    public function getAccessToken() {
      // if token is already set and valid, return it
      if ($this->token && $this->_isTokenUsable($this->expiration)) {
        return $this->token;
      }

      // now check cache for a valid token
      $cache = Cache::getInstance();
      $cached = $cache->get(self::APP_TOKEN_CACHE_KEY, true);
      if (
        $cached &&
        is_object($cached) &&
        !empty($cached->token) &&
        $this->_isTokenUsable($cached->expiration)
      ) {
        $this->token = $cached->token;
        $this->expiration = (int) $cached->expiration;
        return $this->token;
      }

      // get new token
      $response = $this->_post('access_token', [
        'grant_type' => 'client_credentials'
      ], false);

      $this->_updateToken($response);
      if (!$this->token || !$this->expiration) {
        $this->token = null;
        $this->expiration = null;
        return false;
      }

      // cache the token when enough time remains
      $ttl = (int) $this->expiration - time() - self::TOKEN_EXPIRY_BUFFER_SECONDS;
      if ($ttl > 0) {
        $cache->set(self::APP_TOKEN_CACHE_KEY, (object) [
          'token' => $this->token,
          'expiration' => $this->expiration
        ], $ttl);
      }

      return $this->token;
    }

    /**
     * Get user profile about data using an app access token.
     * @return object|false Object with status and body, or false on failure to authorize
     */
    public function getUserAboutData($username) {
      if (!$this->getAccessToken()) {
        return false;
      }

      return $this->_getWithStatus('user/' . rawurlencode($username) . '/about');
    }

    private function _getWithStatus($endpoint) {
      $c = $this->_createCurl($endpoint, true);
      $response = curl_exec($c);
      $status = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
      $body = $response ? json_decode($response) : false;

      return (object) [
        'status' => $status,
        'body' => $body
      ];
    }

    protected function _verifyTokenFresh() {
      return !!$this->getAccessToken();
    }

    private function _isTokenUsable($expiration) {
      return $expiration && time() < ((int) $expiration - self::TOKEN_EXPIRY_BUFFER_SECONDS);
    }
  }

}
