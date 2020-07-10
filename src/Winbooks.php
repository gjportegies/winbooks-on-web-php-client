<?php

namespace Winbooks;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Winbooks\Exceptions\InvalidTokensException;
use Winbooks\Exceptions\UnauthenticatedException;
use Winbooks\Exceptions\UndefinedFolderException;

class Winbooks
{
    /**
     * The GuzzleHTTP Client instance
     *
     * @var Client
     */
    protected $guzzle;

    /**
     * The OAuth 2.0 access token
     *
     * @var string
     */
    private $access_token;

    /**
     * The OAuth 2.0 refresh token
     *
     * @var string
     */
    private $refresh_token;

    /**
     * The authentication e-mail
     *
     * @var string
     */
    private $email;

    /**
     * The API base url
     *
     * @var string
     */
    protected $api_host = 'https://prd.winbooksapis.be/wow/v2/';

    /**
     * The folder name
     *
     * @var string
     */
    protected $folder;

    public function __construct(string $access_token = null, string $refresh_token = null)
    {
        $this->access_token = $access_token;
        $this->refresh_token = $refresh_token;
    }

    /**
     * Check if the authentication tokens are set
     *
     * @return bool
     */
    public function authenticated(): bool
    {
        return !is_null($this->access_token) && !is_null($this->refresh_token);
    }

    /**
     * Authenticate with the e-mail and exchange token
     *
     * @param string $email
     * @param string $exchange_token
     * @return \stdClass
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function authenticate(string $email, string $exchange_token): \stdClass
    {
        $data = $this->getAccessToken($email, $exchange_token);

        $this->email = $email;
        $this->access_token = $data->access_token;
        $this->refresh_token = $data->refresh_token;

        return $data;
    }

    /**
     * Get the access and refresh tokens
     *
     * @param string $email
     * @param string $exchange_token
     * @return \stdClass
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getAccessToken(string $email, string $exchange_token): \stdClass
    {
        return $this->getAuth($email, 'exchange_token', $exchange_token);
    }

    /**
     * Get auth credentials
     *
     * @param string $email
     * @param string $grant_type
     * @param string $token
     * @return \stdClass
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function getAuth($email, $grant_type, $token): \stdClass
    {
        $guzzle = new Client([
            'base_uri' => $this->api_host,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($email),
                'Accept' => 'application/json'
            ]
        ]);

        $response = $guzzle->post('OAuth20/Token', [
            'form_params' => [
                'grant_type' => $grant_type,
                'code' => $token
            ]
        ]);

        return json_decode($response->getBody());
    }

    /**
     * Use the Refresh Token to get new Auth credentials
     *
     * @throws UnauthenticatedException
     */
    protected function refreshAuth()
    {
        $auth = $this->getAuth($this->email, 'refresh_token', $this->refresh_token);

        $this->access_token = $auth->access_token;
        $this->refresh_token = $auth->refresh_token;

        $this->initialize();
    }

    /**
     * Initialize the GuzzleHTTP instance
     *
     * @throws UnauthenticatedException
     */
    public function initialize()
    {
        if(!$this->authenticated()) {
            throw new UnauthenticatedException("Please authenticate first, by passing your e-mail and Exchange Token to the authenticate() method, or by providing your Access and Refresh Tokens to the constructor.");
        }

        $this->guzzle = new Client([
            'base_uri' => $this->api_host,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json'
            ]
        ]);
    }

    /**
     * Make sure Guzzle has been initialized and a folder has been set
     *
     * @throws UnauthenticatedException
     * @throws UndefinedFolderException
     */
    protected function ensureInitialized()
    {
        if(!$this->guzzle) {
            $this->initialize();
        }

        if(!$this->folder) {
            throw new UndefinedFolderException("Please specify a folder before making requests.");
        }
    }

    /**
     * Set the folder to use for the following requests.
     *
     * @param string $folder
     */
    public function folder($folder)
    {
        $this->folder = $folder;

        return $this;
    }

    /**
     * Get all objects from an object model namespace
     *
     * @param string $oms
     * @return mixed
     * @throws InvalidTokensException
     * @throws UnauthenticatedException
     * @throws UndefinedFolderException
     */
    public function all(string $oms)
    {
        $this->ensureInitialized();

        $response = $this->attempt(function() use ($oms) {
            return $this->guzzle->get("app/$oms/Folder/$this->folder");
        });

        if($response->getStatusCode() == 200) {
            return json_decode($response->getBody());
        }
    }

    /**
     * Get an object from an object model namespace
     *
     * @param string $om
     * @param string $code
     * @return mixed
     * @throws InvalidTokensException
     * @throws UnauthenticatedException
     * @throws UndefinedFolderException
     */
    public function get(string $om, string $code)
    {
        $this->ensureInitialized();

        $response = $this->attempt(function() use ($om, $code) {
            return $this->guzzle->get("app/$om/$code/Folder/$this->folder");
        });

        if($response->getStatusCode() == 200) {
            return json_decode($response->getBody());
        }
    }

    /**
     * Attempt to use the API, and try to refresh the access token if it is invalid
     *
     * @param callable $callback
     * @param bool $usingRefreshToken
     * @return mixed
     * @throws InvalidTokensException
     * @throws UnauthenticatedException
     */
    protected function attempt(callable $callback, $usingRefreshToken = false)
    {
        try {
            $response = $callback();
        }
        catch (ClientException $exception) {
            if($usingRefreshToken) {
                throw new InvalidTokensException('Access Token and Refresh Token are invalid');
            }

            if($this->isUnauthorized($exception)) {
                $this->refreshAuth();

                return $this->attempt($callback, true);
            }

            throw $exception;
        }

        return $response;
    }

    /**
     * Check if the guzzle exception is a 401 response
     *
     * @param ClientException $exception
     * @return bool
     */
    protected function isUnauthorized(ClientException $exception): bool
    {
        return $exception->getResponse()->getStatusCode() == '401';
    }

    /**
     * Set the access token. Mainly for testing purposes.
     *
     * @param string $access_token
     */
    public function setAccessToken($access_token)
    {
        $this->access_token = $access_token;
    }
}
