@php
    $scope = $deal->scope ?? 'brand';
    $share_type = $deal->share_type ?? 'percentage';
    $locked = ! empty($deal) && in_array($deal->id, $used ?? []);
@endphp
<tr>
  <td>
    <input type="hidden" name="deals[{{ $index }}][id]" value="{{ $deal->id ?? '' }}">
    <select name="deals[{{ $index }}][scope]" class="form-control input-sm deal-scope">
      <option value="brand" @if($scope == 'brand') selected @endif>Brand</option>
      <option value="product" @if($scope == 'product') selected @endif>Product</option>
      <option value="overall" @if($scope == 'overall') selected @endif>Overall business</option>
    </select>
  </td>
  <td>
    <div class="deal-brand-wrap">
      <select name="deals[{{ $index }}][brand_id]" class="form-control input-sm deal-brand">
        <option value="">Select brand</option>
        @foreach($brands as $id => $name)
          <option value="{{ $id }}" @if(! empty($deal) && $deal->brand_id == $id) selected @endif>{{ $name }}</option>
        @endforeach
      </select>
    </div>
    <div class="deal-product-wrap">
      <select name="deals[{{ $index }}][product_id]" class="form-control input-sm deal-product">
        <option value="">Select product</option>
        @foreach($products as $id => $name)
          <option value="{{ $id }}" @if(! empty($deal) && $deal->product_id == $id) selected @endif>{{ $name }}</option>
        @endforeach
      </select>
    </div>
    <div class="deal-overall-text text-muted" style="padding-top: 5px;">Net profit of the whole business</div>
    <select name="deals[{{ $index }}][location_id]" class="form-control input-sm" style="margin-top: 4px;">
      <option value="">All locations</option>
      @foreach($locations as $id => $name)
        <option value="{{ $id }}" @if(! empty($deal) && $deal->location_id == $id) selected @endif>{{ $name }}</option>
      @endforeach
    </select>
  </td>
  <td>
    <select name="deals[{{ $index }}][share_type]" class="form-control input-sm deal-share-type">
      <option value="percentage" @if($share_type == 'percentage') selected @endif>Fixed % of profit</option>
      <option value="capital" @if($share_type == 'capital') selected @endif>By invested capital</option>
    </select>
    <div class="deal-percent-wrap" style="margin-top: 4px;">
      <div class="input-group input-group-sm">
        <input type="text" name="deals[{{ $index }}][share_percent]" class="form-control input_number" value="{{ ! empty($deal) && $deal->share_percent > 0 ? @num_format($deal->share_percent) : '' }}" placeholder="e.g. 30">
        <span class="input-group-addon">%</span>
      </div>
    </div>
    <div class="deal-capital-wrap" style="margin-top: 4px;">
      <input type="text" name="deals[{{ $index }}][pool_capital]" class="form-control input-sm input_number" value="{{ ! empty($deal) && $deal->pool_capital > 0 ? @num_format($deal->pool_capital) : '' }}" placeholder="Total capital of this business / brand">
      <small class="text-muted">Share = investor's average capital &divide; this total</small>
    </div>
  </td>
  <td>
    <input type="text" name="deals[{{ $index }}][start_date]" class="form-control input-sm deal-date" value="{{ ! empty($deal) ? @format_date($deal->start_date) : @format_date('now') }}" placeholder="From" readonly>
    <input type="text" name="deals[{{ $index }}][end_date]" class="form-control input-sm deal-date" value="{{ ! empty($deal) && ! empty($deal->end_date) ? @format_date($deal->end_date) : '' }}" placeholder="To (open)" readonly style="margin-top: 4px;">
  </td>
  <td class="text-center">
    <input type="checkbox" name="deals[{{ $index }}][is_active]" value="1" @if(empty($deal) || $deal->is_active) checked @endif title="Active">
  </td>
  <td class="text-center">
    <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error remove-deal"
      @if (! empty($deal)) data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'deleteDeal'], [$deal->investor_id, $deal->id]) }}" @endif
      title="{{ $locked ? 'Used in a locked settlement: it will only be switched off' : 'Delete' }}"><i class="fa fa-trash"></i></button>
  </td>
</tr>
