<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsappApiService
{
    ///private $baseUrl = 'http://localhost:3334';
    //private $baseUrl = 'http://whatsapp_api.injazatsoftware.net';
    //private $baseUrl = 'http://sender.injazatsoftware.net';
    private $baseUrl = 'https://whatsapp.antalyaswat.com.pk';
    private $token = 'YOUR_TOKEN';
    private $adminToken='da71b564a1ed7e998204ca0d7cae38e791ca2154';


    public function instanceInit($instance)
    {
        $apiURL = $this->baseUrl . '/instance/init?';

        $postInput = [
            "key" => $instance,
            "browser" => "Chrome (Linux)",
            "webhook" => true,
            "base64" => true,
            "webhookUrl" => config('services.whatsapp.webhook_url'),
            "webhookEvents" => ["messages.upsert"],
            "ignoreGroups" => false,
            "messagesRead" => false
        ];

        return $this->makeApiCall($apiURL, 'post', $postInput);
    }

    public function getQrCodebase64($instance)
    {
        $apiURL = $this->baseUrl . '/instance/qrbase64?key=' . $instance;

        return $this->makeApiCall($apiURL, 'get');
    }

    // returns instance_data.phone_connected / instance_data.user; error=true when
    // the instance key doesn't exist on the gateway yet
    public function instanceInfo($instance)
    {
        $apiURL = $this->baseUrl . '/instance/info?key=' . $instance;

        return $this->makeApiCall($apiURL, 'get');
    }

    public function sendTestMsg($instance, $number, $text)
    {
        $apiURL = $this->baseUrl . '/message/text?key=' . $instance;

        $postInput = [
            "id" => $number,
            "typeId" => "user",
            "message" => $text,
            "options" => [
                "delay" => 0,
                "replyFrom" => ""
            ],
            "groupOptions" => [
                "markUser" => "ghostMention"
            ]
        ];

        return $this->makeApiCall($apiURL, 'post', $postInput);
    }

    // groups don't have phone numbers, so the caller can't address one directly;
    // this lists every group the device has joined (id + subject) so a group
    // name can be resolved to the "<digits>-<digits>@g.us" id sendGroupMsg needs
    public function getAllGroups($instance)
    {
        $apiURL = $this->baseUrl . '/group/getallgroups?key=' . $instance;

        return $this->makeApiCall($apiURL, 'get');
    }

    public function sendGroupMsg($instance, $groupId, $text)
    {
        $apiURL = $this->baseUrl . '/message/text?key=' . $instance;

        $postInput = [
            "id" => $groupId,
            "typeId" => "group",
            "message" => $text,
        ];

        return $this->makeApiCall($apiURL, 'post', $postInput);
    }
 public function sendDocument($instance,$filePath,$number,$filename,$caption,$typeId='user'){
    $apiURL = $this->baseUrl . '/message/doc?key=' . $instance;

    $response = Http::timeout(60)->attach(
        'file',
        file_get_contents($filePath),
        basename($filePath) // Use basename() to get the file name
    )->withToken($this->token)
    ->post($apiURL, [
        'id'              => $number,
        'filename'        => $filename,
        'userType'        => $typeId,
        'replyFrom'       => '',
        'caption'         => $caption
    ]);
    $responseData = $response->json();
    return $responseData;
 }

 public function sendImage($instance, $filePath, $number, $caption = null, $typeId = 'user')
 {
     // /message/image has no caption support at all (calls the server's
     // sendMediaFile(), which drops it) — /message/imagefile calls sendMedia()
     // instead, which does support caption/userType/replyFrom.
     return $this->sendMediaWithCaption('imagefile', $instance, $filePath, $number, $caption, $typeId);
 }

 public function sendAudio($instance, $filePath, $number, $caption = null, $typeId = 'user')
 {
     // same story as sendImage() above: /message/audio drops the caption,
     // /message/audiofile is the one that actually supports it.
     return $this->sendMediaWithCaption('audiofile', $instance, $filePath, $number, $caption, $typeId);
 }

 public function sendVideo($instance, $filePath, $number, $caption = null, $typeId = 'user')
 {
     return $this->sendMediaWithCaption('video', $instance, $filePath, $number, $caption, $typeId);
 }

 public function sendGroupDocument($instance, $filePath, $groupId, $filename, $caption = null)
 {
     return $this->sendDocument($instance, $filePath, $groupId, $filename, $caption, 'group');
 }

 public function sendGroupImage($instance, $filePath, $groupId, $caption = null)
 {
     return $this->sendImage($instance, $filePath, $groupId, $caption, 'group');
 }

 public function sendGroupAudio($instance, $filePath, $groupId, $caption = null)
 {
     return $this->sendAudio($instance, $filePath, $groupId, $caption, 'group');
 }

 public function sendGroupVideo($instance, $filePath, $groupId, $caption = null)
 {
     return $this->sendVideo($instance, $filePath, $groupId, $caption, 'group');
 }

 private function sendMediaWithCaption($endpoint, $instance, $filePath, $number, $caption = null, $typeId = 'user')
 {
     $apiURL = $this->baseUrl . '/message/' . $endpoint . '?key=' . $instance;

     $response = Http::timeout(60)->attach(
         'file',
         file_get_contents($filePath),
         basename($filePath)
     )->withToken($this->token)
     ->post($apiURL, [
         'id'        => $number,
         'filename'  => basename($filePath),
         'userType'  => $typeId,
         'replyFrom' => '',
         'caption'   => $caption
     ]);

     return $response->json();
 }

    private function makeApiCall($url, $method, $data = [])
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache',
            'Authorization' => 'Bearer ' . $this->token,
        ];

        // without an explicit timeout, an unreachable/hung gateway leaves this
        // call waiting indefinitely — on Windows nothing rescues it either, since
        // Laravel's own job $timeout relies on pcntl_alarm(), which doesn't exist
        // on Windows at all
        $http = Http::withoutVerifying()->timeout(60)->withHeaders($headers);

        if ($method === 'post') {
            return $http->post($url, $data)->json();
        } elseif ($method === 'get') {
            return $http->get($url)->json();
        }

        // Handle other HTTP methods if needed
        return null;
    }
}
