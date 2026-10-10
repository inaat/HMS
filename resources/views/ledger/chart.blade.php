@extends('layouts.app')
@section('title', 'Chart of accounts')

@section('content')
@include('ledger.partials.styles')
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Chart of accounts <small>{{ $subtitle }} · same list as Payment Accounts &gt; Account Types</small></h1>
</section>
<section class="content">
    @include('ledger.partials.nav', ['active' => 'chart'])

    @component('components.widget')
        <div class="no-print" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:10px;">
            <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white" id="add_account"><i class="fa fa-plus"></i> Add account</button>
            <span class="text-muted small">Click an account to see its general ledger. Payment accounts (cash, bank) are added in Payment Accounts and show under their type.</span>
            <div style="margin-left:auto; display:flex; gap:6px;">
                <a href="{{ request()->fullUrlWithQuery(['print' => 1]) }}" target="_blank" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white"><i class="fa fa-print"></i> Print</a>
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-success"><i class="fa fa-file-excel"></i> Excel</a>
            </div>
        </div>
        <div class="table-responsive">
            @include('ledger.partials.table')
        </div>
    @endcomponent
</section>

<div class="modal fade contains_select2" id="account_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST" action="{{ action([\App\Http\Controllers\LedgerController::class, 'storeAccount']) }}" class="modal-content" id="account_form">
            @csrf
            <input type="hidden" name="id" id="acc_id">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title" id="acc_title">Add account</h4>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-sm-8 form-group">
                        <label for="acc_name">Account name *</label>
                        <input type="text" name="name" id="acc_name" class="form-control" required maxlength="191">
                    </div>
                    <div class="col-sm-4 form-group">
                        <label for="acc_code">Code</label>
                        <input type="text" name="code" id="acc_code" class="form-control" maxlength="20" placeholder="e.g. 5420">
                    </div>
                    <div class="col-sm-6 form-group acc-structure">
                        <label for="acc_parent">Group *</label>
                        {!! Form::select('parent_id', $mains, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'acc_parent']) !!}
                    </div>
                    <div class="col-sm-6 form-group acc-structure">
                        <label for="acc_detail">Account type *</label>
                        <select name="detail_type" id="acc_detail" class="form-control select2" style="width:100%;"></select>
                    </div>
                    <div class="col-sm-12 acc-structure">
                        <label style="font-weight:normal;"><input type="checkbox" name="contra" value="1" id="acc_contra">
                            Opposite side (contra account, e.g. Sales returns under Income, Drawings under Equity)</label>
                    </div>
                    <div class="col-sm-12 text-muted small" id="acc_fixed_note" style="display:none;">
                        This account is used by the POS postings: only its name and code can be changed.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">Save</button>
                <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">Close</button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('javascript')
<script>
    $(function () {
        var detailTypes = @json($detail_types), mainClass = @json($main_class);
        function fillDetails(selected) {
            var cls = mainClass[$('#acc_parent').val()] || 'asset', sel = $('#acc_detail').empty();
            $.each(detailTypes[cls] || {}, function (k, v) { sel.append(new Option(v, k, false, k === selected)); });
            sel.trigger('change');
        }
        $('#acc_parent').on('change', function () { fillDetails(); });

        $('#add_account').on('click', function () {
            $('#acc_title').text('Add account');
            $('#acc_id, #acc_name, #acc_code').val('');
            $('#acc_contra').prop('checked', false);
            $('.acc-structure').show(); $('#acc_fixed_note').hide();
            $('#acc_parent').prop('disabled', false).trigger('change');
            $('#account_modal').modal('show');
        });
        $(document).on('click', '.edit_account', function () {
            var a = $(this).data('account');
            $('#acc_title').text('Edit account');
            $('#acc_id').val(a.id); $('#acc_name').val(a.name); $('#acc_code').val(a.code);
            $('#acc_parent').val(String(a.parent)).trigger('change');
            fillDetails(a.detail);
            $('#acc_contra').prop('checked', a.contra == 1);
            // accounts the POS posts to: name / code only
            $('.acc-structure').toggle(!a.fixed);
            $('#acc_fixed_note').toggle(!!a.fixed);
            $('#account_modal').modal('show');
        });
        $(document).on('submit', '.delete_account', function (e) {
            var form = this;
            if ($(form).data('ok')) { return true; }
            e.preventDefault();
            swal({ title: 'Delete this account?', icon: 'warning', buttons: ['Cancel', 'Delete'], dangerMode: true })
                .then(function (ok) { if (ok) { $(form).data('ok', true); form.submit(); } });
        });
    });
</script>
@endsection
