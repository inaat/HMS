@extends('layouts.app')
@section('title', 'Return & Advance Check')

@section('content')
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Return &amp; Advance Check
        <small>Fix returns that turned into advance, and returns not used on unpaid invoices</small>
    </h1>
</section>

<section class="content">
    {{-- 1. Fake "refund out + paid back in" --}}
    @component('components.widget', ['title' => '1. Returns turned into advance ('.count($fake_pairs).')'])
        <p class="text-muted">
            The return was "paid out" and the same amount "received back" from the customer, so the app saved the extra as
            <b>advance</b>. <b>Repair</b> removes both payments (the advance goes to 0) and lets the return pay the customer's
            invoices itself. The customer's net balance does not change.
        </p>
        @if(empty($fake_pairs))
            <div class="alert alert-success" style="margin: 0;"><i class="fa fa-check-circle"></i> No return left any advance. Nothing to repair.</div>
        @else
            <p>
                <button type="button" class="tw-dw-btn tw-dw-btn-warning tw-text-white" id="rac_repair_all">
                    <i class="fa fa-wrench"></i> Repair all ({{ count($fake_pairs) }}), advance total @format_currency(collect($fake_pairs)->sum('advance_left'))
                </button>
            </p>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Return</th>
                            <th>Payment out (on return)</th>
                            <th>Payment in (became advance)</th>
                            <th>Used for invoices</th>
                            <th>Advance it created</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($fake_pairs as $p)
                            <tr>
                                <td><a href="{{ action([\App\Http\Controllers\ContactController::class, 'show'], [$p->contact_id]) }}?view=ledger" target="_blank">{{ $p->contact }}</a></td>
                                <td>{{ $p->return_no }}<br><small class="text-muted">@format_currency($p->return_total)</small></td>
                                <td>{{ $p->out_ref }}<br><small class="text-muted">@format_currency($p->amount) · {{ $payment_types[$p->method] ?? $p->method }} · {{ @format_datetime($p->paid_on) }}</small></td>
                                <td>{{ $p->in_ref }}<br><small class="text-muted">@format_currency($p->amount) · {{ @format_datetime($p->in_paid_on) }}</small></td>
                                <td>
                                    @forelse($p->used_for as $u)
                                        {{ $u->invoice_no }} (@format_currency($u->amount))<br>
                                    @empty
                                        <span class="text-muted">-</span>
                                    @endforelse
                                </td>
                                <td><b>@format_currency($p->advance_left)</b></td>
                                <td>
                                    <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-warning tw-text-white rac-repair"
                                        data-out="{{ $p->out_id }}" data-in="{{ $p->in_id }}" data-return="{{ $p->return_no }}" data-contact="{{ $p->contact }}">
                                        <i class="fa fa-wrench"></i> Repair
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endcomponent

    {{-- 2. Return credit not used on unpaid invoices --}}
    @component('components.widget', ['title' => '2. Returns not used on unpaid invoices ('.count($unsettled).')'])
        <p class="text-muted">
            These returns still have credit while the same customer has unpaid invoices (usually returns saved before
            returns settled invoices automatically). <b>Settle</b> lets the return pay those invoices, oldest first.
        </p>
        @if($unsettled->isEmpty())
            <div class="alert alert-success" style="margin: 0;"><i class="fa fa-check-circle"></i> Nothing to settle.</div>
        @else
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Return</th>
                            <th>Date</th>
                            <th>Return total</th>
                            <th>Credit not used</th>
                            <th>Customer's unpaid invoices</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($unsettled as $r)
                            <tr>
                                <td><a href="{{ action([\App\Http\Controllers\ContactController::class, 'show'], [$r->contact_id]) }}?view=ledger" target="_blank">{{ $r->contact }}</a></td>
                                <td>{{ $r->invoice_no }}</td>
                                <td>{{ @format_date($r->transaction_date) }}</td>
                                <td>@format_currency($r->final_total)</td>
                                <td><b>@format_currency($r->credit)</b></td>
                                <td>@format_currency($r->customer_due)</td>
                                <td>
                                    <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white rac-settle"
                                        data-href="{{ action([\App\Http\Controllers\ReturnAdvanceCheckController::class, 'settle'], [$r->id]) }}" data-return="{{ $r->invoice_no }}">
                                        <i class="fa fa-check"></i> Settle
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endcomponent
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        function run(btn, url, data, question) {
            swal({ title: question, icon: 'warning', buttons: true, dangerMode: true }).then(function(ok) {
                if (! ok) {
                    return;
                }
                btn.prop('disabled', true);
                data._token = '{{ csrf_token() }}';
                $.ajax({
                    method: 'POST', url: url, data: data, dataType: 'json',
                    success: function(result) {
                        if (result.success) {
                            toastr.success(result.msg);
                            btn.closest('tr').fadeOut();
                        } else {
                            toastr.error(result.msg);
                            btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        toastr.error("{{ __('messages.something_went_wrong') }}");
                        btn.prop('disabled', false);
                    }
                });
            });
        }

        $(document).on('click', '.rac-repair', function() {
            var btn = $(this);
            run(btn, "{{ action([\App\Http\Controllers\ReturnAdvanceCheckController::class, 'repair']) }}",
                { out_id: btn.data('out'), in_id: btn.data('in') },
                'Repair ' + btn.data('return') + ' (' + btn.data('contact') + ')? Both payments are removed, the advance goes to 0 and the return pays the invoices.');
        });

        $(document).on('click', '#rac_repair_all', function() {
            var btn = $(this);
            swal({ title: 'Repair all cases listed? Their advance goes to 0 and the returns pay the invoices.', icon: 'warning', buttons: true, dangerMode: true }).then(function(ok) {
                if (! ok) {
                    return;
                }
                btn.prop('disabled', true);
                $.ajax({
                    method: 'POST', dataType: 'json', data: { _token: '{{ csrf_token() }}' },
                    url: "{{ action([\App\Http\Controllers\ReturnAdvanceCheckController::class, 'repairAll']) }}",
                    success: function(result) {
                        if (result.success) {
                            toastr.success(result.msg);
                            setTimeout(function() { location.reload(); }, 1200);
                        } else {
                            toastr.error(result.msg);
                            btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        toastr.error("{{ __('messages.something_went_wrong') }}");
                        btn.prop('disabled', false);
                    }
                });
            });
        });

        $(document).on('click', '.rac-settle', function() {
            var btn = $(this);
            run(btn, btn.data('href'), {}, 'Let return ' + btn.data('return') + ' pay this customer\'s unpaid invoices?');
        });
    });
</script>
@endsection
