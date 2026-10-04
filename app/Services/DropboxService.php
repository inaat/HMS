<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
class DropboxService
{
    protected $appKey;
    protected $appSecret;
    protected $refreshToken;
    protected $accessToken;

    public function __construct()
    {
        $this->appKey = DB::table('dropbox_tokens')->first()->dropbox_app_key;
        $this->appSecret = DB::table('dropbox_tokens')->first()->dropbox_app_secret;
        $this->refreshToken = env('DROPBOX_REFRESH_TOKEN');
        $this->accessToken = DB::table('dropbox_tokens')->first()->dropbox_auth_token;
    }

    /**
     * Get the access token (refresh if expired)
     */
    public function getAccessToken()
    {
        Artisan::call('config:cache');
        if ($this->accessToken) {
            // If we already have an access token, use it
            return $this->accessToken;
        }

        // Otherwise, use the refresh token to get a new access token
        if ($this->refreshToken) {
            return $this->refreshAccessToken();
        }

        // If no refresh token, initiate the OAuth flow
        return $this->startOAuthFlow();
    }

    /**
     * Start the OAuth 2.0 Flow for Dropbox
     */
    public function startOAuthFlow()
    {
        
       // dd($this->appKey,env('DROPBOX_APP_KEY'));
        $authorizeUrl = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
            'client_id' => $this->appKey,
            'response_type' => 'code',
            'redirect_uri' => route('dropbox.callback'),
        ]);

        Log::info("Go to this URL to authenticate with Dropbox: $authorizeUrl");

        // Return the URL so that the user can manually authorize the app
        return $authorizeUrl;
    }

    /**
     * Exchange the authorization code for an access token and refresh token
     */
    public function exchangeAuthorizationCodeForToken($authorizationCode)
    {
        $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
            'code' => $authorizationCode,
            'grant_type' => 'authorization_code',
            'client_id' => $this->appKey,
            'client_secret' => $this->appSecret,
            'redirect_uri' => route('dropbox.callback'),
        ]);
       
        if ($response->successful()) {
            $tokens = $response->json();
         
            $this->accessToken = $tokens['access_token'];
            //$this->refreshToken = $tokens['refresh_token'];
            $token = DB::table('dropbox_tokens')->first();

            if ($token) {
                DB::table('dropbox_tokens')
                    ->where('id', $token->id)
                    ->update(['expires_at' => Carbon::now()->addSeconds($tokens['expires_in']),
                    'dropbox_auth_token' => $this->accessToken
                ]);
            }
        
            // Store the tokens in .env file or database for future use
            $this->storeTokens();

            return $this->accessToken;
        } else {
            throw new \Exception("Error exchanging authorization code for access token: " . $response->body());
        }
    }

    /**
     * Refresh the access token using the refresh token
     */
    public function refreshAccessToken()
    {
        $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->appKey,
            'client_secret' => $this->appSecret,
        ]);

        if ($response->successful()) {
            $tokens = $response->json();
            $this->accessToken = $tokens['access_token'];

            return $this->accessToken;
        } else {
            throw new \Exception("Error refreshing access token: " . $response->body());
        }
    }

    /**
     * Store the tokens in the .env file or a database
     */
    public function storeTokens()
    {
        $envFile = base_path('.env');  // Ensures you are targeting the correct path for .env file
        $envContents = file_get_contents($envFile);
    
        // Replace or add the new values
        $envContents = preg_replace(
            '/^DROPBOX_REFRESH_TOKEN=.*/m', 
            'DROPBOX_REFRESH_TOKEN=' . $this->refreshToken, 
            $envContents
        );
        
        Config::set('filesystems.dropbox.authorization_token', $this->refreshToken);
        $envContents = preg_replace(
            '/^DROPBOX_ACCESS_TOKEN=.*/m', 
            'DROPBOX_ACCESS_TOKEN=' . $this->accessToken, 
            $envContents
        );
    
        // Write the changes back to the .env file
        file_put_contents($envFile, $envContents);
    
        // Clear the config cache to apply changes
        Artisan::call('config:cache');
    }

    /**
     * Fetch account info using the access token
     */
    public function getAccountInfo()
    {
        $response = Http::withToken($this->accessToken)
            ->get('https://api.dropboxapi.com/2/users/get_current_account');

        if ($response->successful()) {
            return $response->json();
        } else {
            throw new \Exception("Error fetching account info: " . $response->body());
        }
    }
}
