<div class="modal-dialog" role="document">
    <div class="modal-content">
        {!! Form::open(['url' => action([\App\Http\Controllers\TransactionPaymentController::class, 'postPartyToParty']), 'method' => 'post', 'id' => 'party_payment_add_form', 'files' => true ]) !!}

        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                    aria-hidden="true">&times;</span></button>
            <h4 class="modal-title">Party To Party Transfer </h4>
        </div>

        <div class="modal-body">
            <div class="col-md-12">
                <div class="form-group">
                  {!! Form::label("paid_on" , __('lang_v1.paid_on') . ':*') !!}
                  <div class="input-group">
                    <span class="input-group-addon">
                      <i class="fa fa-calendar"></i>
                    </span>
                    <input type="text" name="paid_on" id="paid_on" value="{{ @format_datetime('now') }}" class="form-control" readonly required>
                </div>
                </div>
              </div>
            <table id="" class="table table-condensed table-bordered table-striped table-responsive">
                <thead>
                    <tr>
                        <th style="width: 20%;">Entry Type</th>
                        <th style="width: 60%;">Customers</th>
                        <th style="width: 20%;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Received</td> 
                        <td>
                            {!! Form::select('customer_id', $customers, null, [
                                'class' => 'form-control select2 party_customer_id',
                                'style' => 'width:100%; max-width: 500px;', 'required',
                                'placeholder' => __('lang_v1.all'),
                            ]) !!}
                            <small class="text-danger hide party_customer_due_text"><strong>@lang('account.customer_due'):</strong>
                                <span></span></small>
                        </td>
                        <td> 
                            {!! Form::text('amount_received', @num_format(0), [
                                'class' => 'form-control input_number party_payment_amount_received',
                                'required',
                                'placeholder' => __('sale.amount'),
                            ]) !!}
                        </td>
                    </tr>
                    <tr>
                        <td>Paid</td>
                        <td>
                            {!! Form::select('supplier_id', $suppliers, null, [
                                'class' => 'form-control select2 party_supplier_id',
                                'style' => 'width:100%; max-width: 500px;', 'required',
                                'placeholder' => __('lang_v1.all'),
                            ]) !!}
                            <small class="text-danger hide party_supplier_due_text"><strong>@lang('account.supplier_due'):</strong>
                                <span></span></small>
                        </td>
                        <td>
                            {!! Form::text('amount_paid', @num_format(0), [
                                'class' => 'form-control input_number party_payment_amount_paid',
                                'required',
                                'placeholder' => __('sale.amount'),
                            ]) !!}
                        </td>
                    </tr>
                </tbody>
            </table>
            

            <div class="col-md-4">
                <div class="form-group">
                  {!! Form::label('document', __('purchase.attach_document') . ':') !!}
                  {!! Form::file('document', ['accept' => implode(',', array_keys(config('constants.document_upload_mimes_types')))]); !!}
                  <p class="help-block">
                  @includeIf('components.document_help_text')</p>
                </div>
              </div>
              <div class="clearfix"></div>
             
              <div class="col-md-12">
                <div class="form-group">
                  {!! Form::label("note", __('lang_v1.payment_note') . ':') !!}
                  {!! Form::textarea("note", null, ['class' => 'form-control', 'rows' => 3]); !!}
                </div>
              </div>

        </div>

        <div class="modal-footer">
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
            <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white"
                data-dismiss="modal">@lang('messages.close')</button>
        </div>

        {!! Form::close() !!}

    </div><!-- /.modal-content -->
</div><!-- /.modal-dialog -->
