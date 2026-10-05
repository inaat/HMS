@php
    $applies_to = ! empty($rule) && ! empty($rule->product_id) ? 'product' : 'brand';
@endphp
<tr>
  <td>
    <select name="rules[{{ $index }}][applies_to]" class="form-control input-sm rule-applies-to">
      <option value="brand" @if($applies_to == 'brand') selected @endif>Brand</option>
      <option value="product" @if($applies_to == 'product') selected @endif>Product</option>
    </select>
  </td>
  <td>
    <div class="rule-brand-wrap">
      <select name="rules[{{ $index }}][brand_id]" class="form-control input-sm">
        <option value="">Select brand</option>
        @foreach($brands as $id => $name)
          <option value="{{ $id }}" @if(! empty($rule) && $rule->brand_id == $id) selected @endif>{{ $name }}</option>
        @endforeach
      </select>
    </div>
    <div class="rule-product-wrap">
      <select name="rules[{{ $index }}][product_id]" class="form-control input-sm rule-product">
        <option value="">Select product</option>
        @foreach($products as $id => $name)
          <option value="{{ $id }}" @if(! empty($rule) && $rule->product_id == $id) selected @endif>{{ $name }}</option>
        @endforeach
      </select>
    </div>
  </td>
  <td>
    <select name="rules[{{ $index }}][type]" class="form-control input-sm rule-type">
      <option value="percentage" @if(empty($rule) || $rule->type == 'percentage') selected @endif>Percentage of sale</option>
      <option value="fixed" @if(! empty($rule) && $rule->type == 'fixed') selected @endif>Fixed per unit</option>
    </select>
  </td>
  <td>
    <div class="input-group input-group-sm">
      <input type="text" name="rules[{{ $index }}][value]" class="form-control input_number" value="{{ ! empty($rule) ? @num_format($rule->value) : '' }}" placeholder="0" required>
      <span class="input-group-addon rule-value-hint">%</span>
    </div>
  </td>
  <td class="text-center">
    <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error remove-commission-rule" title="Remove"><i class="fa fa-trash"></i></button>
  </td>
</tr>
