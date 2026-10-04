  @if($product->sold_quantity-$product->total_quantity_returned>0)
    <tr >
        <td><span class="sr_number"></span></td>

        <td>
            {{ $product->product_name }} ({{$product->sub_sku}})
        
                <br>
            
        </td>
         <td>
        
                     {!! Form::text('purchases[' . $row_count . '][return_unit_price]',
            number_format($product->unit_price_inc_tax,2), ['class' => 'form-control input-sm purchase_unit_cost input_number','data-min'=>'1', 'data-rule-required'=>'true','required']); !!}

        </td>
         <td>
         {{ $product->sold_quantity-$product->total_quantity_returned }}
        </td>
  <td>
            
            <input name="purchases[{{$row_count}}][product_id]" type="hidden" value="{{ $product->product_id }}">
            <input class="row_variation_id" name="purchases[{{$row_count}}][variation_id]" type="hidden" value="{{ $product->variation_id }}">


    <input type="text" 
                name="purchases[{{$row_count}}][return_quantity]" 
                value="{{@format_quantity(1)}}"
                class="form-control input-sm purchase_quantity input_number mousetrap"
                required
                data-min="1"
                data-rule-required="true" 
                data-rule-min-value="1"
                    data-rule-max-value="{{$product->sold_quantity}}"
                    data-msg-max-value="{{__('lang_v1.max_quantity_quantity_allowed', ['quantity' => $product->sold_quantity])}}" 
            >
       
        </td>    
           <td>
            <span class="row_subtotal display_currency">0</span>
            <input type="hidden" class="row_subtotal_input" value=0>
        </td>   
         <?php $row_count++ ;?>

        <td><i class="fa fa-times remove_purchase_entry_row text-danger" title="Remove" style="cursor:pointer;"></i></td>
    </tr>

<input type="hidden" id="row_count" value="{{ $row_count }}">

@endif