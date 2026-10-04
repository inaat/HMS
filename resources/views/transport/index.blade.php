@extends('layouts.app')
@section('title', 'Transport Companies')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang( 'Transport Companies' )
        <small>@lang( 'Manage your transport companies' )</small>
    </h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
</section>

<!-- Main content -->
<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => __( 'All your transport companies' )])
        @can('brand.create')
            @slot('tool')
                <div class="box-tools">
                    <button type="button" class="btn btn-block btn-primary btn-modal"
                        data-href="{{action([\App\Http\Controllers\TransportCompanyController::class, 'create'])}}"
                        data-container=".transport_modal">
                        <i class="fa fa-plus"></i> @lang( 'messages.add' )</button>
                </div>
            @endslot
        @endcan
        @can('brand.view')
            <div class="table-responsive">
                <table class="table table-bordered table-striped" id="transport_table">
                    <thead>
                        <tr>
                            <th>@lang( 'Transport Companies' )</th>
                            <th>@lang( 'brand.note' )</th>
                            <th>@lang( 'messages.action' )</th>
                        </tr>
                    </thead>
                </table>
            </div>
        @endcan
    @endcomponent

    <div class="modal fade transport_modal" tabindex="-1" role="dialog"
    	aria-labelledby="gridSystemModalLabel">
    </div>

</section>
<!-- /.content -->
<!-- /.content -->
@stop
@section('javascript')

<script>
    //Brands table
    var transport_table = $('#transport_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: "{{ route('transport.index') }}",
        columnDefs: [
            {
                targets: 2,
                orderable: false,
                searchable: false,
            },
        ],
    });
  $(document).on('click', 'button.edit_transport_button', function() {
        $('div.transport_modal').load($(this).data('href'), function() {
            $(this).modal('show');

            $('form#transport_edit_form').submit(function(e) {
                e.preventDefault();
                var form = $(this);
                var data = form.serialize();

                $.ajax({
                    method: 'POST',
                    url: $(this).attr('action'),
                    dataType: 'json',
                    data: data,
                    beforeSend: function(xhr) {
                        __disable_submit_button(form.find('button[type="submit"]'));
                    },
                    success: function(result) {
                        if (result.success == true) {
                            $('div.transport_modal').modal('hide');
                            toastr.success(result.msg);
                            transport_table.ajax.reload();
                        } else {
                            toastr.error(result.msg);
                        }
                    },
                });
            });
        });
    });

    $(document).on('click', 'button.delete_transport_button', function() {
        swal({
            title: LANG.sure,
            text: LANG.confirm_delete_brand,
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(willDelete => {
            if (willDelete) {
                var href = $(this).data('href');
                var data = $(this).serialize();

                $.ajax({
                    method: 'DELETE',
                    url: href,
                    dataType: 'json',
                    data: data,
                    success: function(result) {
                        if (result.success == true) {
                            toastr.success(result.msg);
                            transport_table.ajax.reload();
                        } else {
                            toastr.error(result.msg);
                        }
                    },
                });
            }
        });
    });
</script>
@endsection
