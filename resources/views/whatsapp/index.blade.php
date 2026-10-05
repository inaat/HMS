@extends('layouts.app')
@section('title', 'WhatsApp')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">WhatsApp</h1>
</section>

<!-- Main content -->
<section class="content">
    @if($errors->any())
        <div class="alert alert-danger">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Devices'])
        @slot('tool')
            <div class="box-tools">
                <button type="button" class="tw-dw-btn tw-bg-gradient-to-r tw-from-indigo-600 tw-to-blue-500 tw-font-bold tw-text-white tw-border-none tw-rounded-full pull-right" data-toggle="modal" data-target="#wa_add_modal">
                    <i class="fas fa-plus"></i> Add device
                </button>
            </div>
        @endslot

        <p class="text-muted">
            Messages rotate between all <b>connected</b> devices: each message goes out from the one that was used least recently,
            so no single number sends too much.
        </p>

        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Number</th>
                        <th>Status</th>
                        <th>Last used</th>
                        <th>Gateway key</th>
                        <th>@lang('messages.action')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($devices as $device)
                        <tr data-id="{{ $device->id }}">
                            <td>{{ $device->name }}</td>
                            <td class="wa-number">{{ $device->number ?: '-' }}</td>
                            <td class="wa-status">
                                @if($device->status == 'connected')
                                    <span class="label label-success">Connected</span>
                                @elseif($device->status == 'disconnected')
                                    <span class="label label-danger">Disconnected</span>
                                @else
                                    <span class="label label-default">Not linked</span>
                                @endif
                            </td>
                            <td>{{ $device->last_used_at ? $device->last_used_at->diffForHumans() : '-' }}</td>
                            <td><code>{{ $device->instance }}</code></td>
                            <td>
                                <button type="button" class="btn btn-xs btn-success wa-connect" data-id="{{ $device->id }}" data-name="{{ $device->name }}">
                                    <i class="fas fa-qrcode"></i> Connect
                                </button>
                                <button type="button" class="btn btn-xs btn-primary wa-edit" data-id="{{ $device->id }}" data-name="{{ $device->name }}" data-instance="{{ $device->instance }}">
                                    <i class="glyphicon glyphicon-edit"></i> @lang('messages.edit')
                                </button>
                                {!! Form::open(['url' => action([\App\Http\Controllers\WhatsappController::class, 'destroy'], [$device->id]), 'method' => 'post', 'class' => 'wa-delete-form', 'style' => 'display: inline;']) !!}
                                    <button type="submit" class="btn btn-xs btn-danger">
                                        <i class="glyphicon glyphicon-trash"></i> @lang('messages.delete')
                                    </button>
                                {!! Form::close() !!}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endcomponent
</section>
<!-- /.content -->

<!-- Add device -->
<div class="modal fade" id="wa_add_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            {!! Form::open(['url' => action([\App\Http\Controllers\WhatsappController::class, 'store']), 'method' => 'post']) !!}
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">Add device</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        {!! Form::label('name', 'Name:') !!}
                        {!! Form::text('name', null, ['class' => 'form-control', 'required', 'maxlength' => 191, 'placeholder' => 'e.g. Shop phone 2']) !!}
                    </div>
                    <div class="form-group">
                        {!! Form::label('instance', 'Gateway key:') !!}
                        {!! Form::text('instance', null, ['class' => 'form-control', 'maxlength' => 191, 'pattern' => '[A-Za-z0-9_\-]+', 'placeholder' => 'Leave blank to generate one']) !!}
                        <p class="help-block">Letters, numbers, <code>-</code> and <code>_</code> only. Enter an existing key (e.g. <code>Fine</code>) to reuse a session that is already linked on the gateway.</p>
                    </div>
                    <p class="text-muted"><small>After saving, press <b>Connect</b> on the new row and scan the QR code with that phone.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
                    <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">@lang('messages.close')</button>
                </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>

<!-- Rename device -->
<div class="modal fade" id="wa_edit_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            {!! Form::open(['url' => '#', 'method' => 'post', 'id' => 'wa_edit_form']) !!}
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">@lang('messages.edit')</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        {!! Form::label('wa_edit_name', 'Name:') !!}
                        {!! Form::text('name', null, ['class' => 'form-control', 'required', 'maxlength' => 191, 'id' => 'wa_edit_name']) !!}
                    </div>
                    <div class="form-group">
                        {!! Form::label('wa_edit_instance', 'Gateway key:') !!}
                        {!! Form::text('instance', null, ['class' => 'form-control', 'required', 'maxlength' => 191, 'pattern' => '[A-Za-z0-9_\-]+', 'id' => 'wa_edit_instance']) !!}
                        <p class="help-block">Changing the key switches this device to a different gateway session; press <b>Connect</b> afterwards to check it or scan a new QR code.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.update')</button>
                    <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">@lang('messages.close')</button>
                </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>

<!-- Connect (QR) -->
<div class="modal fade" id="wa_qr_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Connect <span id="wa_qr_title"></span></h4>
            </div>
            <div class="modal-body text-center">
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
            </div>
            <div class="modal-footer">
                <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">@lang('messages.close')</button>
            </div>
        </div>
    </div>
</div>
@stop

@section('javascript')
<script type="text/javascript">
    $(document).ready(function () {
        var wa_status_url = "{{ url('whatsapp/__ID__/qr-status') }}";
        var wa_update_url = "{{ url('whatsapp/__ID__/update') }}";
        var wa_device_id = null;
        var wa_timer = null;
        var wa_busy = false;

        function wa_row_status(id, connected, number) {
            var row = $('tr[data-id="' + id + '"]');
            row.find('.wa-status').html(connected
                ? '<span class="label label-success">Connected</span>'
                : '<span class="label label-danger">Disconnected</span>');
            if (number) {
                row.find('.wa-number').text(number);
            }
        }

        function wa_check() {
            if (wa_busy || ! wa_device_id) {
                return;
            }
            wa_busy = true;
            var id = wa_device_id;

            $.ajax({
                url: wa_status_url.replace('__ID__', id),
                dataType: 'json',
                success: function (res) {
                    if (id != wa_device_id) {
                        return;
                    }
                    wa_row_status(id, res.connected, res.number);

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

        $('.wa-connect').click(function () {
            wa_stop();
            wa_device_id = $(this).data('id');
            $('#wa_qr_title').text($(this).data('name'));
            $('#wa_status').attr('class', 'label label-default').text('Checking...');
            $('#wa_number').text('');
            $('#wa_qr_box').hide();
            $('#wa_qrcode').empty();
            $('#wa_qr_modal').modal('show');
            wa_check();
        });

        $('#wa_qr_modal').on('hidden.bs.modal', function () {
            wa_stop();
            wa_device_id = null;
        });

        $('.wa-edit').click(function () {
            $('#wa_edit_form').attr('action', wa_update_url.replace('__ID__', $(this).data('id')));
            $('#wa_edit_name').val($(this).data('name'));
            $('#wa_edit_instance').val($(this).data('instance'));
            $('#wa_edit_modal').modal('show');
        });

        $('.wa-delete-form').submit(function () {
            return confirm('Delete this device? Messages will stop going out from its number.');
        });
    });
</script>
@endsection
