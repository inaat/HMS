<div class="modal-dialog modal-lg" role="document">
  <div class="modal-content">
    <div class="modal-header">
      <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      <h4 class="modal-title">Capital: {{ $investor->name }}</h4>
    </div>
    <div class="modal-body">
      @can('investor.update')
        <form class="investor-ajax-form" method="POST" action="{{ action([\App\Http\Controllers\InvestorController::class, 'storeCapital'], [$investor->id]) }}">
          @csrf
          <div class="row">
            <div class="col-sm-3 form-group">
              <label>Type</label>
              <select name="type" class="form-control">
                <option value="invest">Invest (money in)</option>
                <option value="withdraw">Withdraw (money back)</option>
              </select>
            </div>
            <div class="col-sm-3 form-group">
              <label>Date</label>
              <input type="text" name="date" class="form-control investor-date" value="{{ @format_date('now') }}" readonly required>
            </div>
            <div class="col-sm-3 form-group">
              <label>Amount</label>
              <input type="text" name="amount" class="form-control input_number" required>
            </div>
            <div class="col-sm-3 form-group">
              <label>Method</label>
              <select name="method" class="form-control">
                @foreach ($methods as $k => $v)
                  <option value="{{ $k }}">{{ $v }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-sm-4 form-group">
              <label>Reference</label>
              <input type="text" name="reference" class="form-control">
            </div>
            <div class="col-sm-6 form-group">
              <label>Note</label>
              <input type="text" name="note" class="form-control">
            </div>
            <div class="col-sm-2 form-group">
              <label>&nbsp;</label>
              <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-w-full">Add</button>
            </div>
          </div>
        </form>
      @endcan

      <table class="table table-bordered table-striped table-condensed">
        <thead>
          <tr><th>Date</th><th>Type</th><th class="text-right">Amount</th><th>Method</th><th>Reference / note</th><th></th></tr>
        </thead>
        <tbody>
          @forelse ($entries as $entry)
            <tr>
              <td>{{ @format_date($entry->date) }}</td>
              <td>
                @if ($entry->type == 'invest')
                  <span class="label label-success">Invest</span>
                @else
                  <span class="label label-warning">Withdraw</span>
                @endif
              </td>
              <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $entry->type == 'invest' ? $entry->amount : -$entry->amount }}</span></td>
              <td>{{ $methods[$entry->method] ?? $entry->method }}</td>
              <td>{{ $entry->reference }} {{ $entry->note }}</td>
              <td>
                @can('investor.update')
                  <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error investor-delete" data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'deleteCapital'], [$investor->id, $entry->id]) }}"><i class="fa fa-trash"></i></a>
                @endcan
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-muted">No capital entries yet</td></tr>
          @endforelse
        </tbody>
        <tfoot>
          <tr class="bg-gray">
            <th colspan="2">Current capital</th>
            <th class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $entries->sum(fn ($e) => $e->type == 'invest' ? $e->amount : -$e->amount) }}</span></th>
            <th colspan="3"></th>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="modal-footer">
      <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">@lang('messages.close')</button>
    </div>
  </div>
</div>
