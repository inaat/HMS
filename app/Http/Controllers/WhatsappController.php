<?php

namespace App\Http\Controllers;

use App\Services\WhatsappApiService;

class WhatsappController extends Controller
{
    // same instance key TransactionUtil sends invoices/messages through
    const INSTANCE = 'Fine';

    protected $whatsappApiService;

    public function __construct(WhatsappApiService $whatsappApiService)
    {
        $this->whatsappApiService = $whatsappApiService;
    }

    public function index()
    {
        if (! auth()->user()->can('send_notification')) {
            abort(403, 'Unauthorized action.');
        }

        return view('whatsapp.index')->with('instance', self::INSTANCE);
    }

    /**
     * Polled by the page: reports whether the phone is connected, otherwise
     * returns a fresh QR code (base64 image) to scan.
     */
    public function qrStatus()
    {
        if (! auth()->user()->can('send_notification')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $info = $this->whatsappApiService->instanceInfo(self::INSTANCE);

            // instance not created on the gateway yet
            if (empty($info) || ! empty($info['error'])) {
                $this->whatsappApiService->instanceInit(self::INSTANCE);
            } elseif (! empty($info['instance_data']['phone_connected'])) {
                return response()->json([
                    'connected' => true,
                    'number' => explode(':', $info['instance_data']['user']['id'] ?? '')[0],
                ]);
            }

            $qr = $this->whatsappApiService->getQrCodebase64(self::INSTANCE);

            // the gateway answers qrbase64 with error=true once the phone is connected
            if (! empty($qr['error'])) {
                return response()->json(['connected' => true]);
            }

            return response()->json([
                'connected' => false,
                'qrcode' => $qr['qrcode'] ?? null,
            ]);
        } catch (\Exception $e) {
            \Log::emergency('WhatsApp QR: '.$e->getMessage());

            return response()->json(['connected' => false, 'qrcode' => null, 'msg' => __('messages.something_went_wrong')], 500);
        }
    }
}
