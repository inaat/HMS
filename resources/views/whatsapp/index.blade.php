@extends('layouts.app')
@section('title', 'WhatsApp')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">WhatsApp</h1>
</section>

<!-- Main content -->
<section class="content">
    <div class="row">
        <div class="col-md-6 col-md-offset-3">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'WhatsApp (' . $instance . ')'])
                <div class="text-center">
                    <p>
                        Status:
                        <span id="wa_status" class="label label-default">Checking...</span>
                    </p>
                    <p id="wa_number"></p>

                    <div id="wa_qr_box" style="display: none;">
                        <p>Open WhatsApp on your phone &rarr; <b>Linked devices</b> &rarr; <b>Link a device</b> and scan this code:</p>
                        <div id="wa_qrcode" style="min-height: 264px;"></div>
                        <p class="text-muted"><small>The code refreshes automatically.</small></p>
                    </div>

                    <button type="button" id="wa_refresh" class="tw-dw-btn tw-dw-btn-primary tw-text-white">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            @endcomponent
        </div>
    </div>
</section>
<!-- /.content -->
@stop

@section('javascript')
<script type="text/javascript">
    $(document).ready(function () {
        var wa_timer = null;
        var wa_busy = false;

        function wa_check() {
            if (wa_busy) {
                return;
            }
            wa_busy = true;

            $.ajax({
                url: "{{ action([\App\Http\Controllers\WhatsappController::class, 'qrStatus']) }}",
                dataType: 'json',
                success: function (res) {
                    if (res.connected) {
                        $('#wa_status').attr('class', 'label label-success').text('Connected');
                        $('#wa_number').text(res.number ? 'Number: ' + res.number : '');
                        $('#wa_qr_box').hide();
                        $('#wa_qrcode').empty();
                        wa_stop();
                        return;
                    }

                    $('#wa_status').attr('class', 'label label-danger').text('Not connected');
                    $('#wa_number').text('');
                    $('#wa_qr_box').show();

                    if (res.qrcode) {
                        $('#wa_qrcode').html($('<img>').attr('src', res.qrcode).css('max-width', '100%'));
                    } else {
                        $('#wa_qrcode').text('Generating QR code...');
                    }
                    wa_start();
                },
                error: function (xhr) {
                    $('#wa_status').attr('class', 'label label-warning').text('Gateway unreachable');
                    var msg = xhr.responseJSON && xhr.responseJSON.msg;
                    if (msg) {
                        toastr.error(msg);
                    }
                    wa_start();
                },
                complete: function () {
                    wa_busy = false;
                }
            });
        }

        // QR codes expire every ~20s on the gateway, so keep polling until connected
        function wa_start() {
            if (! wa_timer) {
                wa_timer = setInterval(wa_check, 4000);
            }
        }

        function wa_stop() {
            clearInterval(wa_timer);
            wa_timer = null;
        }

        $('#wa_refresh').click(wa_check);

        wa_check();
    });
</script>
@endsection
