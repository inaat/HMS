@extends('layouts.app')
@section('title', __( 'user.users' ))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang( 'user.users' )
        <small class="tw-text-sm md:tw-text-base tw-text-gray-700 tw-font-semibold">@lang( 'user.manage_users' )</small>
    </h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
</section>

<!-- Main content -->
<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => __( 'user.all_users' )])
        @can('user.create')
            @slot('tool')
                <div class="box-tools">
                    <a class="tw-dw-btn tw-bg-gradient-to-r tw-from-indigo-600 tw-to-blue-500 tw-font-bold tw-text-white tw-border-none tw-rounded-full" href="{{action([\App\Http\Controllers\ManageUserController::class, 'create'])}}">
                        <svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-plus"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>                        @lang( 'messages.add' )
                    </a>
                 </div>
            @endslot
        @endcan
        @can('user.view')
            <div class="table-responsive">
                <table class="table table-bordered table-striped" id="users_table">
                    <thead>
                        <tr>
                            <th>@lang( 'business.username' )</th>
                            <th>@lang( 'user.name' )</th>
                            <th>@lang( 'user.role' )</th>
                            <th>@lang( 'business.email' )</th>
                            <th>@lang( 'messages.action' )</th>
                        </tr>
                    </thead>
                </table>
            </div>
        @endcan
    @endcomponent

    <div class="modal fade user_modal" tabindex="-1" role="dialog" 
    	aria-labelledby="gridSystemModalLabel">
    </div>

</section>
<!-- /.content -->
@stop
@section('javascript')
<script type="text/javascript">
    //Roles table
    $(document).ready( function(){
        var users_table = $('#users_table').DataTable({
                    processing: true,
                    serverSide: true,
                    fixedHeader:false,
                    ajax: '/users',
                    columnDefs: [ {
                        "targets": [4],
                        "orderable": false,
                        "searchable": false
                    } ],
                    "columns":[
                        {"data":"username"},
                        {"data":"full_name"},
                        {"data":"role"},
                        {"data":"email"},
                        {"data":"action"}
                    ]
                });
        $(document).on('click', 'button.delete_user_button', function(){
            swal({
              title: LANG.sure,
              text: LANG.confirm_delete_user,
              icon: "warning",
              buttons: true,
              dangerMode: true,
            }).then((willDelete) => {
                if (willDelete) {
                    var href = $(this).data('href');
                    var data = $(this).serialize();
                    $.ajax({
                        method: "DELETE",
                        url: href,
                        dataType: "json",
                        data: data,
                        success: function(result){
                            if(result.success == true){
                                toastr.success(result.msg);
                                users_table.ajax.reload();
                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });
                }
             });
        });

        // Order booker > "Commission agent": pick the agent their mobile orders earn commission for.
        @php
            $booker_agent_list = \App\User::where('business_id', session('user.business_id'))->where('is_cmmsn_agnt', 1)->whereNull('deleted_at')->get()
                ->map(function ($a) {
                    return ['id' => $a->id, 'name' => trim($a->first_name.' '.$a->last_name)];
                })->values();
        @endphp
        var agents = {!! json_encode($booker_agent_list) !!};
        $(document).on('click', 'button.set_booker_agent', function () {
            var btn = $(this);
            var options = '<option value="">None</option>' + agents.map(function (a) {
                return '<option value="' + a.id + '"' + (String(a.id) === String(btn.data('agent')) ? ' selected' : '') + '>' + $('<div>').text(a.name).html() + '</option>';
            }).join('');
            // Searchable select2 in the popup; its list opens on the page above the popup (the popup's animation would
            // misplace a list attached inside it).
            if (!$('#booker_agent_select2_css').length) {
                $('head').append('<style id="booker_agent_select2_css">.select2-container--open{z-index:100001}</style>');
            }
            setTimeout(function () {
                $('#booker_agent_select').select2({width: '100%', placeholder: 'None', allowClear: true})
                    .on('change', function () { btn.data('picked', $(this).val()); });
                btn.data('picked', $('#booker_agent_select').val());
            }, 50);
            swal({
                title: 'Commission agent',
                text: btn.data('name') + "'s mobile orders will earn commission for:",
                content: $('<select id="booker_agent_select" class="form-control" style="margin-top:6px">' + options + '</select>')[0],
                buttons: ['Cancel', 'Save'],
            }).then(function (ok) {
                $('#booker_agent_select').select2('destroy');
                if (!ok) return;
                $.post(btn.data('href'), {_token: $('meta[name="csrf-token"]').attr('content'), agent_id: btn.data('picked')}, function (result) {
                    result.success ? toastr.success(result.msg) : toastr.error(result.msg);
                    users_table.ajax.reload(null, false);
                }, 'json');
            });
        });
    });
</script>
@endsection
