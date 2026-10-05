<?php

namespace App\Http\Controllers;

use App\Services\WhatsappApiService;
use App\WhatsappDevice;
use Illuminate\Http\Request;

class WhatsappController extends Controller
{
    protected $whatsappApiService;

    public function __construct(WhatsappApiService $whatsappApiService)
    {
        $this->whatsappApiService = $whatsappApiService;
    }

    public function index(Request $request)
    {
        if (! auth()->user()->can('send_notification')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');

        // makes sure the business has at least one device to link
        WhatsappDevice::forBusiness($business_id);

        $devices = WhatsappDevice::where('business_id', $business_id)->orderBy('id')->get();
        $send_as = app(\App\Utils\TransactionUtil::class)->whatsappSendAs($business_id);

        return view('whatsapp.index')->with(compact('devices', 'send_as'));
    }

    /**
     * Saves how ledgers and invoices are sent: image, pdf or both (same setting as Business settings > Contact)
     */
    public function saveSendAs(Request $request)
    {
        if (! auth()->user()->can('send_notification')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate(['send_as' => 'required|in:image,pdf,both']);

        $business = \App\Business::findOrFail($request->session()->get('user.business_id'));
        $common_settings = $business->common_settings ?: [];
        $common_settings['whatsapp_send_as'] = $request->input('send_as');
        $business->common_settings = $common_settings;
        $business->save();

        //Ledger page reads it from the session
        $request->session()->put('business.common_settings', $common_settings);

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'WhatsApp sending setting saved']);
    }

    public function store(Request $request)
    {
        if (! auth()->user()->can('send_notification')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'name' => 'required|string|max:191',
            'instance' => 'nullable|alpha_dash|max:191|unique:whatsapp_devices,instance',
        ]);

        WhatsappDevice::createFor($request->session()->get('user.business_id'), $request->input('name'), $request->input('instance'));

        return redirect()->back()->with('status', ['success' => 1, 'msg' => __('lang_v1.added_success')]);
    }

    public function update(Request $request, $id)
    {
        if (! auth()->user()->can('send_notification')) {
            abort(403, 'Unauthorized action.');
        }

        $device = $this->device($request, $id);

        $request->validate([
            'name' => 'required|string|max:191',
            'instance' => 'required|alpha_dash|max:191|unique:whatsapp_devices,instance,'.$device->id,
        ]);

        $data = $request->only(['name', 'instance']);
        // a different key is a different gateway session: its phone and status are unknown
        if ($data['instance'] != $device->instance) {
            $data['status'] = 'initiate';
            $data['number'] = null;
        }
        $device->update($data);

        return redirect()->back()->with('status', ['success' => 1, 'msg' => __('lang_v1.updated_success')]);
    }

    public function destroy(Request $request, $id)
    {
        if (! auth()->user()->can('send_notification')) {
            abort(403, 'Unauthorized action.');
        }

        $this->device($request, $id)->delete();

        return redirect()->back()->with('status', ['success' => 1, 'msg' => __('lang_v1.deleted_success')]);
    }

    /**
     * Polled by the page: reports whether the device's phone is connected,
     * otherwise returns a fresh QR code (base64 image) to scan.
     */
    public function qrStatus(Request $request, $id)
    {
        if (! auth()->user()->can('send_notification')) {
            abort(403, 'Unauthorized action.');
        }

        $device = $this->device($request, $id);

        try {
            $info = $this->whatsappApiService->instanceInfo($device->instance);

            // instance not created on the gateway yet
            if (empty($info) || ! empty($info['error'])) {
                $this->whatsappApiService->instanceInit($device->instance);
            } elseif (! empty($info['instance_data']['phone_connected'])) {
                $number = explode(':', $info['instance_data']['user']['id'] ?? '')[0];
                $device->update(['status' => 'connected', 'number' => $number ?: $device->number]);

                return response()->json([
                    'connected' => true,
                    'number' => $device->number,
                ]);
            }

            $qr = $this->whatsappApiService->getQrCodebase64($device->instance);

            // the gateway answers qrbase64 with error=true once the phone is connected
            if (! empty($qr['error'])) {
                $device->update(['status' => 'connected']);

                return response()->json(['connected' => true, 'number' => $device->number]);
            }

            if ($device->status == 'connected') {
                $device->update(['status' => 'disconnected']);
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

    protected function device(Request $request, $id): WhatsappDevice
    {
        return WhatsappDevice::where('business_id', $request->session()->get('user.business_id'))
            ->findOrFail($id);
    }
}
