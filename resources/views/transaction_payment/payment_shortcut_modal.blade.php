<div class="modal-dialog" role="document">
    <div class="modal-content">

        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                    aria-hidden="true">&times;</span></button>
            <h4 class="modal-title">Payment ({{ $due_payment_type }}) </h4>
        </div>

        <div class="modal-body">

            @if($due_payment_type=='sell')
            <div class="col-md-12">
                <div class="form-group">
                    {!! Form::label('customer', __('Customer') . ':') !!}

                    {!! Form::select('customer_id', $customers, null, [
                        'class' => 'form-control select2 getpay_customer_id',
                        'style' => 'width:100%; max-width: 500px;',
                        'required',
                        'placeholder' => __('lang_v1.all'),
                    ]) !!}
                    <small class="text-danger hide party_customer_due_text"><strong>@lang('account.customer_due'):</strong>
                        <span></span></small>

                </div>
            </div>
     @endif


     @if($due_payment_type=='purchase')

            <div class="col-md-12">
                <div class="form-group">
                    {!! Form::label('supplier', __('purchase.supplier') . ':') !!}
                    {!! Form::select('supplier_id', $suppliers, null, [
                        'class' => 'form-control select2 getpay_supplier_id',
                        'style' => 'width:100%; max-width: 500px;',
                        'required',
                        'placeholder' => __('lang_v1.all'),
                    ]) !!}
                    <small class="text-danger hide party_supplier_due_text"><strong>@lang('account.supplier_due'):</strong>
                        <span></span></small>
                </div>
            </div>
            @endif
            <div class="clearfix"></div>



        </div>

        <div class="modal-footer">
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
            <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white"
                data-dismiss="modal">@lang('messages.close')</button>
        </div>

        {!! Form::close() !!}

    </div><!-- /.modal-content -->
</div><!-- /.modal-dialog -->
