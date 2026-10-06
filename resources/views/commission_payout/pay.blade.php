@php
    $df = session('business.date_format');
    $pay_url = action([\App\Http\Controllers\CommissionPayoutController::class, 'payForm']);
    if (! empty($period)) {
        $p_start = \Carbon::parse($period['start'])->format($df);
        $p_end = \Carbon::parse($period['end'])->format($df);
        $p_sales = $period['sales_amount'];
        $p_sales_c = $period['sales_commission'];
        $p_ret = $period['returns_amount'];
        $p_ret_c = $period['returns_commission'];
        $p_bf = $period['carry_brought_forward'];
        $p_payable = $period['payable'];
        $p_cf = $period['carry_forward'];
        $p_paid = $period['paid'];
        $p_due = $period['due'];
        $can_pay = empty($period['error']) && $p_due > 0;
    } else {
        $can_pay = false;
    }
@endphp
<div class="modal-dialog modal-lg" role="document">
  <div class="modal-content">
    <form class="cmmsn-pay-form" method="POST" action="{{ action([\App\Http\Controllers\CommissionPayoutController::class, 'store']) }}">
      @csrf
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        <h4 class="modal-title"><i class="fa fa-money-bill-wave"></i> Pay commission</h4>
      </div>
      <div class="modal-body">
        @if ($agents->isEmpty())
          <div class="alert alert-warning">No commission agents yet. Add one in User management &rarr; Sales Commission Agents.</div>
        @else
          {{-- 1. agent + period --}}
          <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin-bottom: 10px;">
            <div style="min-width: 220px;">
              <label style="display: block; margin-bottom: 2px;">Commission agent *</label>
              <select name="agent_id" id="cpay_agent" class="form-control">
                @foreach ($agents as $id => $name)
                  <option value="{{ $id }}" @if ($agent && $agent->id == $id) selected @endif>{{ trim($name) }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label style="display: block; margin-bottom: 2px;">Period from *</label>
              <input type="text" name="start" id="cpay_start" class="form-control cpay-date" value="{{ $p_start ?? '' }}" readonly required style="width: 130px; background: #fff;">
            </div>
            <div>
              <label style="display: block; margin-bottom: 2px;">to *</label>
              <input type="text" name="end" id="cpay_end" class="form-control cpay-date" value="{{ $p_end ?? '' }}" readonly required style="width: 130px; background: #fff;">
            </div>
            <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white" id="cpay_calculate"><i class="fa fa-sync"></i> Calculate</button>
          </div>

          @if (! empty($period))
            {{-- 2. what the period gives the agent --}}
            <table class="table table-condensed table-bordered" style="margin-bottom: 8px;">
              <tr style="background: #f5f5f5;"><th></th><th class="text-right">Amount</th><th class="text-right">Commission</th></tr>
              <tr>
                <td>Sales in this period ({{ $period['sales']->pluck('transaction_id')->unique()->count() }} invoices, as sold)</td>
                <td class="text-right">@format_currency($p_sales)</td>
                <td class="text-right">@format_currency($p_sales_c)</td>
              </tr>
              <tr style="color: #c0392b;">
                <td>Less: returns made in this period ({{ $period['returns']->pluck('return_id')->unique()->count() }} returns, any sale date)</td>
                <td class="text-right">&minus;@format_currency($p_ret)</td>
                <td class="text-right">&minus;@format_currency($p_ret_c)</td>
              </tr>
              @if ($p_bf > 0)
                <tr style="color: #c0392b;">
                  <td colspan="2">Less: returns left over from the previous period</td>
                  <td class="text-right">&minus;@format_currency($p_bf)</td>
                </tr>
              @endif
              <tr><th colspan="2" class="text-right">Commission for this period</th><td class="text-right"><b>@format_currency($p_payable)</b></td></tr>
              @if ($p_cf > 0)
                <tr><td colspan="2" class="text-right text-muted">Returns more than sales: taken off the next period</td><td class="text-right text-muted">@format_currency($p_cf)</td></tr>
              @endif
              <tr><th colspan="2" class="text-right">Already paid for this period</th><td class="text-right">@format_currency($p_paid)</td></tr>
              <tr class="success"><th colspan="2" class="text-right" style="font-size: 15px;">Due for this period</th><td class="text-right" style="font-size: 15px;"><b>@format_currency($p_due)</b></td></tr>
            </table>

            @if (! empty($period['error']))
              <div class="alert alert-danger" style="margin-bottom: 8px;">{{ $period['error'] }}. Choose another period.</div>
            @elseif ($p_due <= 0)
              <div class="alert alert-warning" style="margin-bottom: 8px;">Nothing to pay for this period{{ $p_paid > 0 ? ': it is already paid' : '' }}.</div>
            @elseif (empty($period['settlement']))
              <div class="alert alert-warning" style="margin-bottom: 8px;">Paying will <b>lock {{ $p_start }} ~ {{ $p_end }}</b> for this agent:
                the commission can't change and this period can't be paid twice. A sale returned later is taken off the next payout.</div>
            @endif
            @if (! empty($period['changed']))
              <div class="alert alert-info" style="margin-bottom: 8px;">Sales of this locked period were changed after locking; the locked commission stays.</div>
            @endif
          @endif

          {{-- 3. the payment (saved as an expense) --}}
          @if ($can_pay)
            <div class="row">
              <div class="col-sm-4 form-group">
                <label>Amount *</label>
                <input type="text" name="amount" class="form-control input_number" value="{{ @num_format($p_due) }}" required>
              </div>
              <div class="col-sm-4 form-group">
                <label>Paid on *</label>
                <input type="text" name="paid_on" class="form-control cpay-date" value="{{ @format_date('now') }}" readonly required style="background: #fff;">
              </div>
              <div class="col-sm-4 form-group">
                <label>Location (for the expense) *</label>
                <select name="location_id" class="form-control" required>
                  @foreach ($locations as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-sm-4 form-group">
                <label>Payment method</label>
                <select name="method" class="form-control">
                  @foreach ($payment_types as $k => $v)
                    <option value="{{ $k }}">{{ $v }}</option>
                  @endforeach
                </select>
              </div>
              @if (! empty($accounts) && count($accounts))
                <div class="col-sm-4 form-group">
                  <label>Payment account</label>
                  <select name="account_id" class="form-control">
                    @foreach ($accounts as $k => $v)
                      <option value="{{ $k }}">{{ $v }}</option>
                    @endforeach
                  </select>
                </div>
              @endif
              <div class="col-sm-4 form-group">
                <label>Note</label>
                <input type="text" name="note" class="form-control">
              </div>
            </div>
            <small class="text-muted">Saved as an <b>expense</b> (category "Sales commission", expense for the agent): it shows in Expenses,
              lowers net profit in Profit / Loss, and is taken from the payment account.</small>
          @endif
        @endif
      </div>
      <div class="modal-footer">
        @if ($agent && ! empty($period))
          <a href="{{ action([\App\Http\Controllers\ReportController::class, 'getCommissionAgentReport']) }}?commission_agent={{ $agent->id }}&start_date={{ $period['start'] }}&end_date={{ $period['end'] }}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-primary pull-left" target="_blank">
            <i class="fa fa-list"></i> Sales detail of this period</a>
        @endif
        @if ($can_pay)
          <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">Pay @format_currency($p_due)</button>
        @endif
        <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">@lang('messages.close')</button>
      </div>
    </form>
  </div>
</div>
<script type="text/javascript">
  (function() {
    var $modal = $('#cpay_calculate').closest('.modal');
    $modal.find('.cpay-date').datepicker({ autoclose: true, format: datepicker_date_format });
    __currency_convert_recursively($modal);

    function reload(data) {
      $.get(@json($pay_url), data, function(html) {
        $modal.html(html);
      });
    }
    //recalculate for the chosen agent / period; a new agent starts at its own next unpaid period
    $('#cpay_calculate').on('click', function() {
      reload({ agent_id: $('#cpay_agent').val(), start: $('#cpay_start').val(), end: $('#cpay_end').val() });
    });
    $('#cpay_agent').on('change', function() {
      reload({ agent_id: $(this).val() });
    });

    $modal.find('form.cmmsn-pay-form').on('submit', function(e) {
      e.preventDefault();
      var $form = $(this);
      var $btn = $form.find('button[type="submit"]').prop('disabled', true);
      $.ajax({
        method: 'POST',
        url: $form.attr('action'),
        data: $form.serialize(),
        dataType: 'json',
        success: function(result) {
          if (result.success) {
            toastr.success(result.msg);
            $modal.modal('hide');
            setTimeout(function() { location.reload(); }, 800);
          } else {
            toastr.error(result.msg);
            $btn.prop('disabled', false);
          }
        },
        error: function(xhr) {
          toastr.error(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : @json(__('messages.something_went_wrong')));
          $btn.prop('disabled', false);
        }
      });
    });
  })();
</script>
