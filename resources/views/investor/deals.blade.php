<div class="modal-dialog modal-lg" role="document" style="width: 95%; max-width: 1100px;">
  <div class="modal-content">
    <form class="investor-ajax-form" method="POST" action="{{ action([\App\Http\Controllers\InvestorController::class, 'saveDeals'], [$investor->id]) }}">
      @csrf
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        <h4 class="modal-title">Deals: {{ $investor->name }}</h4>
      </div>

      <div class="modal-body">
        <div class="alert alert-info" style="margin-bottom: 12px;">
          A deal says what this investor gets a share of:
          <strong>Brand</strong> / <strong>Product</strong> = gross profit of those items (sale &minus; purchase cost, returns removed);
          <strong>Overall business</strong> = net profit (same as the Profit / Loss report).<br>
          <strong>Fixed %</strong> = that % of the profit. <strong>By invested capital</strong> = investor's average capital in the period
          &divide; the total capital you enter. A loss is carried forward and recovered from later profit before anything is paid again.
        </div>

        <div class="table-responsive">
          <table class="table table-bordered" id="investor_deals_table" style="margin-bottom: 8px;">
            <thead>
              <tr style="background: #f9fafb;">
                <th style="width: 130px;">Share of</th>
                <th>Brand / product, location</th>
                <th style="width: 210px;">Share</th>
                <th style="width: 140px;">Period</th>
                <th style="width: 55px;">Active</th>
                <th style="width: 40px;"></th>
              </tr>
            </thead>
            <tbody>
              @foreach($deals as $i => $deal)
                @include('investor.partials.deal_row', ['index' => $i, 'deal' => $deal])
              @endforeach
            </tbody>
          </table>
        </div>
        <p class="text-muted" id="investor_deals_empty" @if($deals->count()) style="display: none;" @endif>No deals yet: add one below.</p>
        <button type="button" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-primary tw-dw-btn-sm" id="add_investor_deal">
          <i class="fa fa-plus"></i> Add deal
        </button>
      </div>

      <div class="modal-footer">
        <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
        <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">@lang('messages.close')</button>
      </div>
    </form>
  </div>
</div>

{{-- Template for new rows: kept out of the form so it is never submitted --}}
<script type="text/template" id="investor_deal_template">
  @include('investor.partials.deal_row', ['index' => '__INDEX__', 'deal' => null])
</script>

<script type="text/javascript">
  (function() {
    var $modal = $('div.investor_modal');
    var $table = $('#investor_deals_table');
    var next_index = {{ $deals->count() }};

    function init_row($row) {
      $row.find('select.deal-brand').select2({ dropdownParent: $modal, width: '100%', placeholder: 'Select brand' });
      $row.find('select.deal-product').select2({ dropdownParent: $modal, width: '100%', placeholder: 'Select product' });
      $row.find('.deal-date').datepicker({ autoclose: true, format: datepicker_date_format, clearBtn: true });
      toggle($row);
    }

    //Show the brand / product dropdown and the % or capital field for the chosen options
    function toggle($row) {
      var scope = $row.find('.deal-scope').val();
      $row.find('.deal-brand-wrap').toggle(scope == 'brand');
      $row.find('.deal-product-wrap').toggle(scope == 'product');
      $row.find('.deal-overall-text').toggle(scope == 'overall');
      var by_capital = $row.find('.deal-share-type').val() == 'capital';
      $row.find('.deal-percent-wrap').toggle(! by_capital);
      $row.find('.deal-capital-wrap').toggle(by_capital);
    }

    $table.find('tbody tr').each(function() {
      init_row($(this));
    });

    $('#add_investor_deal').on('click', function() {
      var html = $('#investor_deal_template').html().replace(/__INDEX__/g, next_index++);
      var $row = $($.parseHTML($.trim(html)));
      $table.find('tbody').append($row);
      init_row($row);
      $('#investor_deals_empty').hide();
    });

    $table.on('change', '.deal-scope, .deal-share-type', function() {
      toggle($(this).closest('tr'));
    });
    function remove_row($row) {
      $row.remove();
      $('#investor_deals_empty').toggle($table.find('tbody tr').length == 0);
    }

    //Saved deal: delete it right away (after a confirm). New, unsaved row: just remove it.
    $table.on('click', '.remove-deal', function() {
      var $row = $(this).closest('tr');
      var url = $(this).data('href');
      if (!url) {
        remove_row($row);
        return;
      }
      swal({ title: LANG.sure, text: 'Delete this deal now?', icon: 'warning', buttons: true, dangerMode: true }).then(function(ok) {
        if (!ok) {
          return;
        }
        $.ajax({
          method: 'DELETE',
          url: url,
          dataType: 'json',
          data: { _token: '{{ csrf_token() }}' },
          success: function(result) {
            if (result.success) {
              toastr.success(result.msg);
              remove_row($row);
            } else {
              toastr.error(result.msg);
            }
          },
          error: function() {
            toastr.error(@json(__('messages.something_went_wrong')));
          }
        });
      });
    });

    //Closing the window refreshes the page, so the statement / list never shows deleted deals
    $modal.one('hidden.bs.modal', function() {
      location.reload();
    });
  })();
</script>
