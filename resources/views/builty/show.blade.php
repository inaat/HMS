<div class="modal-dialog modal-xl" role="document">
  <div class="modal-content">

  <div class="modal-body">



    <br>
    <div class="row">
      <div class="col-sm-12 col-xs-12">
        <div class="table-responsive">
          <table class="table bg-gray">
            <thead>
              <tr class="bg-green">
                <th>@lang('Recevied Date')</th>
                <th>@lang('Builty Number')</th>
                <th>@lang('Transport Company')</th>
                <th>@lang('Builty Date')</th>
                <th>@lang('From Address')</th>
                <th>@lang('To address')</th>
                <th>@lang('Sender Name')</th>
                <th>@lang('Amount')</th>

              </tr>
            </thead>


             <tr>
               <td>{{@format_date($builty->recevied_date)}}</td>
               <td>{{$builty->biulty_number}}</td>
               <td>{{$builty->transport->name}}</td>
               <td>{{$builty->biulty_date}}</td>
               <td>{{$builty->from_address}}</td>
               <td>{{$builty->to_address}}</td>
               <td>{{$builty->sender_name}}</td>
               <td>{{$builty->amount}}</td>


             </tr>

          </table>
        </div>
      </div>
    </div>
    <br>
    <div class="row">
      <div class="col-sm-12 col-xs-12">
        <h4>{{ __('Items Info') }}:</h4>
      </div>
      <div class="col-md-6 col-sm-12 col-xs-12">
        <div class="table-responsive">
          <table class="table bg-gray">
            <tr class="bg-green">
              <th class="text-center">#</th>
              <th class="text-center">{{ __('Item') }}</th>
              <th class="text-center">{{ __('Item Quantity') }}</th>
              <th class="text-center">{{ __('weight') }}</th>
              <th class="text-center">{{ __('charges') }}</th>
            </tr>
            @foreach($builty->builtyitems as $item_line)

            <tr>
              <td class="text-center">{{ $loop->iteration }}</td>

              <td class="text-center">{{ $item_line->item }}</td>
              <td class="text-center">{{ $item_line->item_quantity}}</td>
              <td class="text-center">{{ $item_line->weight }}</td>
              <td class="text-center">{{ $item_line->charges }}</td>



              </tr>

          @endforeach
          </table>
        </div>
      </div>

  </div>    <div class="modal-footer">
      <button type="button" class="btn btn-primary no-print" aria-label="Print"
      onclick="$(this).closest('div.modal-content').printThis();"><i class="fa fa-print"></i> @lang( 'messages.print' )
      </button>
      <button type="button" class="btn btn-default no-print" data-dismiss="modal">@lang( 'messages.close' )</button>
    </div>
  </div>
</div>

<script type="text/javascript">
	$(document).ready(function(){
		var element = $('div.modal-xl');
		__currency_convert_recursively(element);
	});
</script>