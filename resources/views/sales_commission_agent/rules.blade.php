@php
    $agent_name = trim(implode(' ', array_filter([$user->surname, $user->first_name, $user->last_name])));
@endphp
<div class="modal-dialog modal-lg" role="document">
  <div class="modal-content">
    <form id="commission_rules_form" method="POST" action="{{ action([\App\Http\Controllers\SalesCommissionAgentController::class, 'saveRules'], [$user->id]) }}">
      @csrf
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">Commission rules: {{ $agent_name }}</h4>
      </div>

      <div class="modal-body">
        <div class="alert alert-info" style="margin-bottom: 12px;">
          Set commission for a <strong>brand</strong> or a single <strong>product</strong>.
          <strong>Percentage</strong> = % of the net sale. <strong>Fixed</strong> = amount for every unit sold (e.g. HILAL: Fixed 1 = Rs 1 per piece).<br>
          A product rule is used first, then the brand rule. Everything else uses the agent's normal commission:
          <strong>{{ @num_format($user->cmmsn_percent) }}%</strong>.
        </div>

        <div class="table-responsive">
          <table class="table table-bordered" id="commission_rules_table" style="margin-bottom: 8px;">
            <thead>
              <tr style="background: #f9fafb;">
                <th style="width: 120px;">Applies to</th>
                <th>Brand / product</th>
                <th style="width: 170px;">Commission type</th>
                <th style="width: 120px;">Value</th>
                <th style="width: 40px;"></th>
              </tr>
            </thead>
            <tbody>
              @foreach($rules as $i => $rule)
                @include('sales_commission_agent.partials.rule_row', ['index' => $i, 'rule' => $rule])
              @endforeach
            </tbody>
          </table>
        </div>
        <p class="text-muted" id="commission_rules_empty" @if($rules->isNotEmpty()) style="display: none;" @endif>
          No rules yet: this agent gets {{ @num_format($user->cmmsn_percent) }}% on everything.
        </p>
        <button type="button" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-primary tw-dw-btn-sm" id="add_commission_rule">
          <i class="fa fa-plus"></i> Add rule
        </button>

      </div>

      <div class="modal-footer">
        <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white" id="save_commission_rules">@lang('messages.save')</button>
        <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">@lang('messages.close')</button>
      </div>
    </form>
  </div>
</div>

{{-- Template for new rows: kept out of the form so it is never submitted or validated --}}
<script type="text/template" id="commission_rule_template">
  @include('sales_commission_agent.partials.rule_row', ['index' => '__INDEX__', 'rule' => null])
</script>

<script type="text/javascript">
  (function() {
    var $modal = $('div.commission_agent_modal');
    var $table = $('#commission_rules_table');
    var next_index = {{ $rules->count() }};

    function init_row($row) {
      $row.find('select.rule-brand').select2({ dropdownParent: $modal, width: '100%', placeholder: 'Select brand' });
      $row.find('select.rule-product').select2({ dropdownParent: $modal, width: '100%', placeholder: 'Select product' });
      toggle_target($row);
      update_hint($row);
    }

    //Show the brand or the product dropdown
    function toggle_target($row) {
      var is_product = $row.find('.rule-applies-to').val() == 'product';
      $row.find('.rule-brand-wrap').toggle(! is_product);
      $row.find('.rule-product-wrap').toggle(is_product);
    }

    function update_hint($row) {
      $row.find('.rule-value-hint').text($row.find('.rule-type').val() == 'fixed' ? 'per unit' : '%');
    }

    $table.find('tbody tr').each(function() {
      init_row($(this));
    });

    $('#add_commission_rule').on('click', function() {
      var html = $('#commission_rule_template').html().replace(/__INDEX__/g, next_index++);
      var $row = $($.parseHTML($.trim(html)));
      $table.find('tbody').append($row);
      init_row($row);
      $('#commission_rules_empty').hide();
    });

    $table.on('change', '.rule-applies-to', function() {
      toggle_target($(this).closest('tr'));
    });
    $table.on('change', '.rule-type', function() {
      update_hint($(this).closest('tr'));
    });
    $table.on('click', '.remove-commission-rule', function() {
      $(this).closest('tr').remove();
      $('#commission_rules_empty').toggle($table.find('tbody tr').length == 0);
    });

    $('#commission_rules_form').on('submit', function(e) {
      e.preventDefault();
      var $btn = $('#save_commission_rules').prop('disabled', true);
      $.ajax({
        method: 'POST',
        url: $(this).attr('action'),
        data: $(this).serialize(),
        dataType: 'json',
        success: function(result) {
          if (result.success) {
            toastr.success(result.msg);
            $modal.modal('hide');
            if ($.fn.DataTable.isDataTable('#sales_commission_agent_table')) {
              $('#sales_commission_agent_table').DataTable().ajax.reload();
            }
          } else {
            toastr.error(result.msg);
          }
        },
        error: function() {
          toastr.error("{{ __('messages.something_went_wrong') }}");
        },
        complete: function() {
          $btn.prop('disabled', false);
        }
      });
    });
  })();
</script>
