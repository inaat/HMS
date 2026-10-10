{{-- Add / edit account type when the chart of accounts is set up: same fields as Accounts > Chart of accounts.
     Accounts the books post to (and the main groups) keep their place: only name and code can change. --}}
@php
    $at = $account_type ?? null;
    $fixed = $at && (empty($at->parent_account_type_id) || ! empty($at->system_key) || ! empty($at->expense_category_id));
    $mains = $account_types->sortBy(fn ($t) => ($t->code ?? 'zzzz').'|'.$t->name);
    $main_class = $mains->mapWithKeys(fn ($t) => [$t->id => $t->classification ?: 'asset']);
    $main_debit = $mains->mapWithKeys(fn ($t) => [$t->id => $t->debit_increases === null ? null : (int) $t->debit_increases]);
    $contra = $at && $at->parent_account_type_id && $at->debit_increases !== null && isset($main_debit[$at->parent_account_type_id])
        && (int) $at->debit_increases !== (int) $main_debit[$at->parent_account_type_id];
    $form_id = 'chart_fields_'.uniqid();
@endphp
<div id="{{ $form_id }}">
    <div class="row">
        <div class="col-sm-8 form-group">
            {!! Form::label('name', __('lang_v1.name').':*') !!}
            {!! Form::text('name', $at->name ?? null, ['class' => 'form-control', 'required', 'maxlength' => 191, 'placeholder' => 'e.g. Shop rent deposit']) !!}
        </div>
        <div class="col-sm-4 form-group">
            {!! Form::label('code', 'Code:') !!}
            {!! Form::text('code', $at->code ?? null, ['class' => 'form-control', 'maxlength' => 20, 'placeholder' => 'e.g. 1550']) !!}
        </div>
        @if ($fixed)
            <div class="col-sm-12 text-muted small">
                {{ empty($at->parent_account_type_id) ? 'This is a main group of the chart of accounts.' : 'The books post to this account.' }}
                Only its name and code can be changed.
            </div>
        @else
            <div class="col-sm-6 form-group">
                {!! Form::label('parent_account_type_id', 'Group:*') !!}
                {!! Form::select('parent_account_type_id', $mains->mapWithKeys(fn ($t) => [$t->id => trim($t->code.' '.$t->name)]), $at->parent_account_type_id ?? null,
                    ['class' => 'form-control chart-parent', 'style' => 'width:100%', 'required', 'placeholder' => __('messages.please_select')]) !!}
            </div>
            <div class="col-sm-6 form-group">
                {!! Form::label('detail_type', 'Account type:*') !!}
                <select name="detail_type" class="form-control chart-detail" style="width:100%;" data-selected="{{ $at->detail_type ?? '' }}"></select>
            </div>
            <div class="col-sm-12">
                <label style="font-weight:normal;">
                    <input type="checkbox" name="contra" value="1" @if ($contra) checked @endif>
                    Opposite side (contra account, e.g. Sales returns under Income, Drawings under Equity)
                </label>
            </div>
        @endif
    </div>
</div>
@if (! $fixed)
    <script>
        (function () {
            var box = $('#{{ $form_id }}'), modal = box.closest('.modal');
            var detailTypes = @json(\App\Http\Controllers\LedgerController::DETAIL_TYPES), mainClass = @json($main_class);
            var parent = box.find('.chart-parent'), detail = box.find('.chart-detail');
            function fill(selected) {
                var cls = mainClass[parent.val()] || 'asset';
                detail.empty();
                $.each(detailTypes[cls] || {}, function (k, v) { detail.append(new Option(v, k, false, k === selected)); });
                detail.trigger('change');
            }
            parent.select2({ dropdownParent: modal.length ? modal : $(document.body) });
            detail.select2({ dropdownParent: modal.length ? modal : $(document.body), minimumResultsForSearch: Infinity });
            parent.on('change', function () { fill(); });
            fill(detail.data('selected'));
        })();
    </script>
@endif
