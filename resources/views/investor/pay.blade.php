@php
    $df = session('business.date_format');
    $p_start = \Carbon::parse($period['start'])->format($df);
    $p_end = \Carbon::parse($period['end'])->format($df);
    $p_payable = $period['payable'];
    $p_paid = $period['paid'];
    $p_due = $period['due'];
    $can_pay = empty($period['error']) && $p_due > 0;
    $pay_url = action([\App\Http\Controllers\InvestorController::class, 'pay'], [$investor->id]);
@endphp
<div class="modal-dialog modal-lg" role="document">
  <div class="modal-content">
    <form class="investor-ajax-form" method="POST" action="{{ action([\App\Http\Controllers\InvestorController::class, 'storePayout'], [$investor->id]) }}">
      @csrf
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        <h4 class="modal-title">Pay {{ $investor->name }}: profit share for a period</h4>
      </div>
      <div class="modal-body">
        {{-- 1. the period --}}
        <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin-bottom: 10px;">
          <div>
            <label style="display: block; margin-bottom: 2px;">Period from *</label>
            <input type="text" name="start" id="pay_start" class="form-control investor-date" value="{{ $p_start }}" readonly required style="width: 140px; background: #fff;">
          </div>
          <div>
            <label style="display: block; margin-bottom: 2px;">to *</label>
            <input type="text" name="end" id="pay_end" class="form-control investor-date" value="{{ $p_end }}" readonly required style="width: 140px; background: #fff;">
          </div>
          <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white" id="pay_period_show"><i class="fa fa-sync"></i> Calculate</button>
        </div>

        {{-- 2. what this period gives the investor --}}
        <table class="table table-condensed table-bordered" style="margin-bottom: 8px;">
          <tr style="background: #f5f5f5;">
            <th>Deal</th>
            <th class="text-right">Sales</th>
            <th class="text-right">Profit</th>
            <th class="text-right">Share %</th>
            <th class="text-right">Share</th>
            <th class="text-right">Old loss</th>
            <th class="text-right">Payable</th>
          </tr>
          @forelse ($report as $row)
            @php
              $l = $row->line;
              $l_sales = $row->pl ? $row->pl['total_sell'] : $row->products->sum('net_sales');
              $l_profit = $l->profit_base;
              $l_share = $l->share_amount;
              $l_bf = $l->loss_brought_forward;
              $l_payable = $l->payable;
            @endphp
            <tr>
              <td>{{ $l->scope_label }}</td>
              <td class="text-right">@format_currency($l_sales)</td>
              <td class="text-right">@format_currency($l_profit)</td>
              <td class="text-right">{{ number_format($l->share_percent_used, 2) }}%</td>
              <td class="text-right">@format_currency($l_share)</td>
              <td class="text-right">@if ($l_bf > 0) &minus;@format_currency($l_bf) @endif</td>
              <td class="text-right"><b>@format_currency($l_payable)</b></td>
            </tr>
          @empty
            <tr><td colspan="7" class="text-center text-muted">No active deal in this period</td></tr>
          @endforelse
          <tr><th colspan="6" class="text-right">Profit share for this period</th><td class="text-right"><b>@format_currency($p_payable)</b></td></tr>
          <tr><th colspan="6" class="text-right">Already paid for this period</th><td class="text-right">@format_currency($p_paid)</td></tr>
          <tr class="success"><th colspan="6" class="text-right" style="font-size: 15px;">Due for this period</th><td class="text-right" style="font-size: 15px;"><b>@format_currency($p_due)</b></td></tr>
        </table>

        @if (! empty($period['error']))
          <div class="alert alert-danger" style="margin-bottom: 8px;">{{ $period['error'] }}. Choose another period.</div>
        @elseif ($p_due <= 0)
          <div class="alert alert-warning" style="margin-bottom: 8px;">Nothing to pay for this period{{ $p_paid > 0 ? ': it is already paid' : '' }}.</div>
        @elseif (empty($period['settlement']))
          <div class="alert alert-warning" style="margin-bottom: 8px;">Saving will <b>lock {{ $p_start }} ~ {{ $p_end }}</b>
            (profit settlement), so these figures can never change and this period can never be paid twice.</div>
        @endif

        {{-- 3. the payment --}}
        @if ($can_pay)
          <div class="row">
            <div class="col-sm-4 form-group">
              <label>Amount *</label>
              <input type="text" name="amount" class="form-control input_number" value="{{ @num_format($p_due) }}" required>
            </div>
            <div class="col-sm-4 form-group">
              <label>Paid on *</label>
              <input type="text" name="paid_on" class="form-control investor-date" value="{{ @format_date('now') }}" readonly required>
            </div>
            <div class="col-sm-4 form-group">
              <label>Method</label>
              <select name="method" class="form-control">
                @foreach ($methods as $k => $v)
                  <option value="{{ $k }}">{{ $v }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-sm-6 form-group">
              <label>Reference</label>
              <input type="text" name="reference" class="form-control" placeholder="Cheque / transaction no.">
            </div>
            <div class="col-sm-6 form-group">
              <label>Note</label>
              <input type="text" name="note" class="form-control">
            </div>
          </div>
        @endif
        <small class="text-muted">A payout is not an expense: it does not change the Profit / Loss report.
          Total balance due (all locked periods): @format_currency($balance['balance'])</small>
      </div>
      <div class="modal-footer">
        <a href="{{ action([\App\Http\Controllers\InvestorController::class, 'statement'], [$investor->id]) }}?start={{ urlencode($p_start) }}&end={{ urlencode($p_end) }}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-primary pull-left">
          <i class="fa fa-list"></i> Full sales detail of this period</a>
        @if ($can_pay)
          <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">Pay @format_currency($p_due)</button>
        @endif
        <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">@lang('messages.close')</button>
      </div>
    </form>
  </div>
</div>
<script type="text/javascript">
  //Recalculate the window for the chosen period
  $('#pay_period_show').on('click', function() {
    var $modal = $(this).closest('.modal');
    $.get(@json($pay_url), { start: $('#pay_start').val(), end: $('#pay_end').val() }, function(html) {
      $modal.html(html);
      $modal.find('.investor-date').datepicker({ autoclose: true, format: datepicker_date_format });
      __currency_convert_recursively($modal);
    });
  });
</script>
