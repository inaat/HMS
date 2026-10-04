@extends('layouts.app')
@section('title', __('Builties'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header no-print">
    <h1>@lang('All Builties')
        <small></small>
    </h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
</section>

<!-- Main content -->
<section class="content no-print">

    {{-- @component('components.filters', ['title' => __('report.filters')])
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_location_id',  __('Transport Companies') . ':') !!}
                {!! Form::select('purchase_list_filter_location_id', $TransportCompany, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]); !!}
            </div>
        </div>
    @endcomponent --}}

    @component('components.widget', ['class' => 'box-primary', 'title' => __('All builties')])
        @can('purchase.create')
            @slot('tool')
                <div class="box-tools">
                    <a class="btn btn-block btn-primary" href="{{action([\App\Http\Controllers\BuiltyController::class, 'create'])}}">
                    <i class="fa fa-plus"></i> @lang('messages.add')</a>
                </div>
            @endslot
        @endcan
        @can('purchase.view')
            <div class="table-responsive">
                <table class="table table-bordered table-striped ajax_view" id="purchase_table">
                    <thead>
                        <tr>
                            <th>@lang('Recevied Date')</th>
                            <th>@lang('Builty Number')</th>
                            <th>@lang('Transport Company')</th>
                            <th>@lang('Builty Date')</th>
                            <th>@lang('From Address')</th>
                            <th>@lang('To address')</th>
                            <th>@lang('Sender Name')</th>
                            <th>@lang('Amount')</th>
                            <th>@lang('messages.action')</th>
                        </tr>
                    </thead>

                </table>
            </div>
        @endcan
    @endcomponent

    <div class="modal fade product_modal" tabindex="-1" role="dialog"
    	aria-labelledby="gridSystemModalLabel">
    </div>

    <div class="modal fade payment_modal" tabindex="-1" role="dialog"
        aria-labelledby="gridSystemModalLabel">
    </div>

    <div class="modal fade edit_payment_modal" tabindex="-1" role="dialog"
        aria-labelledby="gridSystemModalLabel">
    </div>

</section>

<section id="receipt_section" class="print_section"></section>

<!-- /.content -->
@stop
@section('javascript')

<script>

    $('#purchase_table').on('click', 'a.delete-purchase', function(e) {
        e.preventDefault();
        
        swal({
            title: LANG.sure,
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(willDelete => {
            if (willDelete) {
                var href = $(this).attr('href');
                $.ajax({
                    method: 'DELETE',
                    url: href,
                    dataType: 'json',
                    success: function(result) {
                        if (result.success == true) {
                            toastr.success(result.msg);
                            purchase_table.ajax.reload();
                        } else {
                            toastr.error(result.msg);
                        }
                    },
                });
            }
        });
    });

 //Purchase table
 $(document).on(
        'change',
        '#purchase_list_filter_location_id',
        function() {
            purchase_table.ajax.reload();
        }
    );
 purchase_table = $('#purchase_table').DataTable({
        processing: true,
        serverSide: true,
        aaSorting: [[0, 'desc']],
        ajax: {
   url: "{{ route('builty.index') }}",
   data: function(d) {
                if ($('#purchase_list_filter_location_id').length) {
                    d.location_id = $('#purchase_list_filter_location_id').val();
                }
            },
  },

        columns: [
            { data: 'recevied_date', name: 'recevied_date' },
            { data: 'biulty_number', name: 'biulty_number' },
            { data: 'goodstransportcompany_id', name: 'goodstransportcompany_id' },
            { data: 'biulty_date', name: 'biulty_date' },
            { data: 'from_address', name: 'from_address' },
            { data: 'to_address', name: 'to_address' },
            { data: 'sender_name', name: 'sender_name' },
            { data: 'amount', name: 'amount' },
            { data: 'action', name: 'action' },
        ]
 });

</script>

@endsection