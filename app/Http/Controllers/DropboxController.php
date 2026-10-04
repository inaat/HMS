<?php

namespace App\Http\Controllers;

use App\Services\DropboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class DropboxController extends Controller
{
    protected $dropboxService;

    public function __construct(DropboxService $dropboxService)
    {
        $this->dropboxService = $dropboxService;
    }

    /**
     * Step 1: Redirect user to Dropbox OAuth authorization URL
     */
    public function redirectToDropbox()
    {
        $authorizeUrl = $this->dropboxService->startOAuthFlow();
        return redirect()->away($authorizeUrl);
    }

    /**
     * Step 2: Handle the callback from Dropbox after authorization
     */
    public function callback(Request $request)
    {
        $authorizationCode = $request->query('code');
      
        if (!$authorizationCode) {
            return response()->json(['error' => 'Authorization code missing'], 400);
        }

        try {
           $this->dropboxService->exchangeAuthorizationCodeForToken($authorizationCode);
           // $accountInfo = $this->dropboxService->getAccountInfo();

            // return response()->json([
            //     'message' => 'Authentication successful',
            //     'access_token' => $accessToken,
            //    // 'account_info' => $accountInfo
            // ]);

            return redirect('http://localhost/pos/public/backup');
            
                } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
}
