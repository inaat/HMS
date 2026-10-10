{{-- Cloud sync without Task Scheduler or commands: while any POS page is open on the shop PC, ask for a sync every
     2 minutes. The server starts it in the background only when none is running (App\Services\MobileSync\SyncStatus). --}}
@if (in_array(config('mobile_sync.role'), ['local', 'single'], true) && auth()->check())
<script>
    (function () {
        var url = '{{ action([\App\Http\Controllers\MobileOrderController::class, 'syncNow']) }}';
        function tick() {
            if (document.hidden && Math.random() < 0.5) return; // background tabs ask less often
            $.post(url, {_token: $('meta[name="csrf-token"]').attr('content'), auto: 1});
        }
        setTimeout(tick, 10000);
        setInterval(tick, 120000);
    })();
</script>
@endif
