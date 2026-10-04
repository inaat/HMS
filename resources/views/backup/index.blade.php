@extends('layouts.app')
@section('title', __('lang_v1.backup'))

@section('content')
@php
    $connected = $google_drive->isConnected();
    // signed in, but Drive access was left unticked on Google's screen
    $no_access = $connected && ! $google_drive->hasDriveAccess();
    // names of the zips already in Drive, so server rows don't offer to send them again
    $in_drive = collect($drive_files)->pluck('name')->flip();
    $last_backup = $backups[0]['last_modified'] ?? null;
@endphp

<style>
    .bk-page .bk-chips { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
    .bk-page .bk-chip { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 8px 14px; min-width: 180px; }
    .bk-page .bk-chip small { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; }
    .bk-page .bk-chip b { font-size: 15px; color: #111827; }
    .bk-page .bk-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .bk-page .bk-progress { margin-top: 16px; padding: 14px 16px; border: 1px solid #e5e7eb; border-radius: 12px; background: #f9fafb; }
    .bk-page .bk-progress .progress { height: 12px; border-radius: 999px; margin: 8px 0 0; }
    .bk-page .bk-progress .progress-bar { border-radius: 999px; transition: width .4s ease; }
    .bk-page .bk-progress .bk-pct { font-weight: 800; font-size: 18px; font-variant-numeric: tabular-nums; }
    .bk-page .bk-table td:first-child { word-break: break-all; }
    .bk-page .bk-table td { vertical-align: middle !important; }
    .bk-page .bk-empty { text-align: center; color: #6b7280; padding: 20px 0; }
    .bk-page .bk-empty i { font-size: 28px; display: block; opacity: .5; margin-bottom: 4px; }
</style>

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('lang_v1.backup')
    </h1>
</section>

<!-- Main content -->
<section class="content bk-page">

  @if (session('notification') || !empty($notification))
    <div class="row">
        <div class="col-sm-12">
            <div class="alert alert-danger alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                @if(!empty($notification['msg']))
                    {{$notification['msg']}}
                @elseif(session('notification.msg'))
                    {{ session('notification.msg') }}
                @endif
              </div>
          </div>
      </div>
  @endif

  <div class="bk-chips">
    <div class="bk-chip"><small>Last backup</small><b>{{ $last_backup ? Carbon::createFromTimestamp($last_backup)->diffForHumans() : 'Never' }}</b></div>
    <div class="bk-chip"><small>On this server</small><b>{{ count($backups) }}</b></div>
    <div class="bk-chip"><small>Google Drive</small><b>{{ $no_access ? 'No Drive access' : ($connected ? 'Connected' : 'Not connected') }}</b></div>
    @if ($connected && $google_drive->last_upload_at)
      <div class="bk-chip"><small>Last sent to Drive</small><b>{{ $google_drive->last_upload_at->diffForHumans() }}</b></div>
    @endif
  </div>

  {{-- backups kept on this server --}}
  <div class="row">
    <div class="col-sm-12">
      @component('components.widget', ['class' => 'box-primary', 'title' => 'Backups on this server'])
        <div class="bk-actions">
          <button type="button" class="tw-dw-btn tw-bg-gradient-to-r tw-from-indigo-600 tw-to-blue-500 tw-font-bold tw-text-white tw-border-none tw-rounded-full js-job"
                  data-url="{{ action([\App\Http\Controllers\BackUpController::class, 'run']) }}" data-drive="0">
            <i class="fa fa-play-circle"></i> Back up now
          </button>
          @if ($connected && ! $no_access)
            <button type="button" class="tw-dw-btn tw-dw-btn-accent tw-font-bold tw-text-white tw-rounded-full js-job"
                    data-url="{{ action([\App\Http\Controllers\BackUpController::class, 'run']) }}" data-drive="1">
              <i class="fab fa-google"></i> Back up + send to Drive
            </button>
          @endif
        </div>

        {{-- live progress of the running action --}}
        <div id="job_progress" class="bk-progress" style="display: none;">
          <div class="clearfix">
            <span class="pull-left">
              <i id="job_progress_icon" class="fa fa-spinner fa-spin text-primary"></i>
              <b id="job_progress_text">Starting...</b>
            </span>
            <span id="job_progress_pct" class="bk-pct pull-right">0%</span>
          </div>
          <div class="progress">
            <div class="progress-bar progress-bar-primary progress-bar-striped active" role="progressbar" style="width: 0%;"></div>
          </div>
        </div>

        <br>
        @if (count($backups))
          <div class="table-responsive">
            <table class="table table-striped table-bordered bk-table">
              <thead>
              <tr>
                  <th>@lang('lang_v1.file')</th>
                  <th>@lang('lang_v1.size')</th>
                  <th>@lang('lang_v1.date')</th>
                  <th>@lang('lang_v1.age')</th>
                  <th>@lang('messages.actions')</th>
              </tr>
              </thead>
              <tbody>
                @foreach($backups as $backup)
                  <tr>
                      <td>{{ $backup['file_name'] }}</td>
                      <td>{{ humanFilesize($backup['file_size']) }}</td>
                      <td>
                          {{ Carbon::createFromTimestamp($backup['last_modified'])->toDateTimeString() }}
                      </td>
                      <td>
                          {{ Carbon::createFromTimestamp($backup['last_modified'])->diffForHumans(Carbon::now()) }}
                      </td>
                      <td class="text-nowrap">
                        <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-accent"
                             href="{{action([\App\Http\Controllers\BackUpController::class, 'download'], [$backup['file_name']])}}"><i
                                  class="fa fa-cloud-download"></i> @lang('lang_v1.download')</a>
                        @if ($connected && ! $no_access && $in_drive->has($backup['file_name']))
                          <span class="label label-success" title="Already in Google Drive"><i class="fa fa-check"></i> In Drive</span>
                        @elseif ($connected && ! $no_access)
                          <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-success js-job"
                                  data-url="{{ action([\App\Http\Controllers\GoogleDriveController::class, 'send'], [$backup['file_name']]) }}">
                            <i class="fab fa-google"></i> Send to Drive</button>
                        @endif
                          <a class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error link_confirmation" data-button-type="delete"
                             href="{{ route('delete_backup', $backup['file_name']) }}"><i class="fa fa-trash-o"></i>
                              @lang('messages.delete') </a>
                      </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @else
          <div class="bk-empty"><i class="fa fa-archive"></i>There are no backups</div>
        @endif
        <br>
        <strong>@lang('lang_v1.auto_backup_instruction'):</strong><br>
        <code>{{$cron_job_command}}</code> <br>
      @endcomponent
    </div>
  </div>

  {{-- Google Drive --}}
  <div class="row">
    <div class="col-sm-12">
      @component('components.widget', ['class' => 'box-primary', 'title' => 'Google Drive'])
        @slot('tool')
          <div class="box-tools">
            @if ($connected)
              <form method="POST" action="{{ action([\App\Http\Controllers\GoogleDriveController::class, 'disconnect']) }}" style="display: inline;"
                    onsubmit="return confirm('Disconnect Google Drive? Files already in Drive are kept.')">
                @csrf
                <button type="submit" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-error">
                  <i class="fa fa-unlink"></i> Disconnect
                </button>
              </form>
            @elseif ($drive_ready)
              <a class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white" href="{{ action([\App\Http\Controllers\GoogleDriveController::class, 'connect']) }}">
                <i class="fab fa-google"></i> Connect Google Drive
              </a>
            @endif
          </div>
        @endslot

        @if (! $connected)
          <div class="bk-empty">
            <i class="fab fa-google"></i>
            @if ($drive_ready)
              Connect a Google account to keep a copy of your backups outside this server<br>
              (they go to a folder called <b>{{ config('app.name', 'POS') }} Backups</b>).
            @else
              Google Drive is not set up yet: add GOOGLE_DRIVE_CLIENT_ID and GOOGLE_DRIVE_CLIENT_SECRET to .env.
            @endif
          </div>
        @elseif ($no_access)
          <div class="alert alert-warning">
            <p>
              <b>{{ $google_drive->connected_email }}</b> is signed in, but Google Drive access was not allowed,
              so backups can't be uploaded.
            </p>
            <p>Click <b>Reconnect</b> and, on the Google screen, make sure the box for <b>Google Drive files</b> is ticked.</p>
            <br>
            <a class="tw-dw-btn tw-dw-btn-primary tw-text-white" href="{{ action([\App\Http\Controllers\GoogleDriveController::class, 'connect']) }}">
              <i class="fab fa-google"></i> Reconnect Google Drive
            </a>
          </div>
        @else
          <p>
            <span class="label label-success">Connected</span>
            {{ $google_drive->connected_email }}
          </p>
          <p class="text-muted">Scheduled backups are sent to the <b>{{ config('app.name', 'POS') }} Backups</b> folder in this Drive automatically.</p>

          @if ($google_drive->last_upload_at)
            <p>
              <strong>Last upload:</strong>
              {{ $google_drive->last_upload_at->toDateTimeString() }}
              ({{ $google_drive->last_upload_at->diffForHumans() }})<br>
              <span class="{{ \Illuminate\Support\Str::startsWith($google_drive->last_upload_status, 'OK') ? 'text-success' : 'text-danger' }}">
                {{ $google_drive->last_upload_status }}
              </span>
            </p>
          @endif

          @if ($drive_error)
            <div class="alert alert-danger">Could not read Google Drive: {{ $drive_error }}</div>
          @else
            <div class="table-responsive">
              <table class="table table-striped table-bordered bk-table">
                <thead>
                  <tr>
                    <th>@lang('lang_v1.file')</th>
                    <th>@lang('lang_v1.size')</th>
                    <th>@lang('lang_v1.date')</th>
                    <th>@lang('messages.actions')</th>
                  </tr>
                </thead>
                <tbody>
                  @forelse ($drive_files as $file)
                    <tr>
                      <td>{{ $file['name'] }}</td>
                      <td>{{ humanFilesize($file['size']) }}</td>
                      <td>{{ $file['date']->toDateTimeString() }}<br><small class="text-muted">{{ $file['date']->diffForHumans() }}</small></td>
                      <td class="text-nowrap">
                        <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-accent"
                           href="{{ action([\App\Http\Controllers\GoogleDriveController::class, 'downloadFile'], [$file['id']]) }}">
                          <i class="fa fa-cloud-download"></i> @lang('lang_v1.download')</a>
                        @if ($file['link'])
                          <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline" href="{{ $file['link'] }}" target="_blank" rel="noopener">
                            <i class="fa fa-external-link"></i> Open in Drive</a>
                        @endif
                        <form method="POST" action="{{ action([\App\Http\Controllers\GoogleDriveController::class, 'deleteFile'], [$file['id']]) }}" style="display: inline;"
                              onsubmit="return confirm('Delete this backup from Google Drive?')">
                          @csrf
                          <button type="submit" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error">
                            <i class="fa fa-trash-o"></i> @lang('messages.delete')</button>
                        </form>
                      </td>
                    </tr>
                  @empty
                    <tr><td colspan="4"><div class="bk-empty"><i class="fa fa-cloud"></i>Nothing in Drive yet. Use "Back up + send to Drive" above.</div></td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          @endif
        @endif

        <hr>
        <form method="POST" action="{{ action([\App\Http\Controllers\GoogleDriveController::class, 'saveCredentials']) }}">
          @csrf
          <div class="row">
            <div class="col-sm-4">
              <div class="form-group">
                <label for="client_id">Client ID</label>
                <input type="text" name="client_id" id="client_id" class="form-control" value="{{ $google_drive->client_id }}"
                       placeholder="{{ config('services.google_drive.client_id') ? 'Blank = use GOOGLE_DRIVE_CLIENT_ID from .env' : 'From Google Cloud Console' }}">
              </div>
            </div>
            <div class="col-sm-4">
              <div class="form-group">
                <label for="client_secret">Client Secret</label>
                <input type="password" name="client_secret" id="client_secret" class="form-control" autocomplete="new-password"
                       placeholder="{{ $google_drive->client_id && $google_drive->client_secret ? 'Saved - blank keeps it' : 'Only with your own Client ID' }}">
              </div>
            </div>
            <div class="col-sm-2">
              <div class="form-group">
                <label for="keep_last">Keep in Drive</label>
                <input type="number" min="1" max="1000" name="keep_last" id="keep_last" class="form-control" value="{{ $google_drive->keep_last }}">
              </div>
            </div>
            <div class="col-sm-2">
              <label>&nbsp;</label><br>
              <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
            </div>
          </div>
          <p class="text-muted"><small>
            Redirect URI to register in Google Cloud Console: <code>{{ app(\App\Services\GoogleDriveService::class)->redirectUri() }}</code>
            @if (config('services.google_drive.redirect'))
              (a relay that forwards back to <code>{{ url('oauth/google/callback') }}</code>)
            @endif
          </small></p>
        </form>
      @endcomponent
    </div>
  </div>
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    // backup / send-to-Drive actions run over ajax; while the request is open
    // the page polls backup/progress/{token} to fill the progress bar
    $(document).on('click', '.js-job', function () {
        var btn = $(this);
        var box = $('#job_progress').show();
        var bar = box.find('.progress-bar');
        var text = $('#job_progress_text'), pct = $('#job_progress_pct'), icon = $('#job_progress_icon');
        var token = Date.now().toString(36) + Math.random().toString(36).slice(2);
        var finished = false;
        $('html, body').animate({ scrollTop: box.offset().top - 90 }, 200);

        function show(p) {
            var value = Math.max(0, Math.min(100, p.percent || 0));
            var ended = p.state === 'done' || p.state === 'failed';
            bar.css('width', value + '%')
               .toggleClass('progress-bar-danger', p.state === 'failed')
               .toggleClass('progress-bar-success', p.state === 'done')
               .toggleClass('progress-bar-primary', ! ended)
               .toggleClass('progress-bar-striped active', ! ended);
            pct.text(value + '%');
            text.text(p.text || '')
                .toggleClass('text-success', p.state === 'done')
                .toggleClass('text-danger', p.state === 'failed');
            icon.attr('class', 'fa ' + (p.state === 'done' ? 'fa-check-circle text-success' : (p.state === 'failed' ? 'fa-exclamation-circle text-danger' : 'fa-spinner fa-spin text-primary')));
        }

        $('.js-job').prop('disabled', true);
        show({ percent: 0, text: 'Starting...', state: 'working' });

        var poll = setInterval(function () {
            if (finished) { return; }
            $.getJSON('{{ url('backup/progress') }}/' + token, function (p) { if (! finished) { show(p); } });
        }, 1000);

        $.ajax({
            method: 'POST',
            url: btn.data('url'),
            dataType: 'json',
            data: { _token: '{{ csrf_token() }}', progress: token, drive: btn.data('drive') },
            success: function (res) {
                finished = true;
                clearInterval(poll);
                show({ percent: res.ok ? 100 : 0, text: res.message, state: res.ok ? 'done' : 'failed' });
                $('.js-job').prop('disabled', false);
                if (res.ok) { setTimeout(function () { window.location.reload(); }, 2000); }
            },
            error: function () {
                finished = true;
                clearInterval(poll);
                show({ percent: 0, text: 'Lost connection to the server. It may still finish; refresh in a minute.', state: 'failed' });
                $('.js-job').prop('disabled', false);
            }
        });
    });
</script>
@endsection
