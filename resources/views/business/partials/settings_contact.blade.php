<!--Purchase related settings -->
<div class="pos-tab-content">
    <div class="row">
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('default_credit_limit',__('lang_v1.default_credit_limit') . ':') !!}
                {!! Form::text('common_settings[default_credit_limit]', $common_settings['default_credit_limit'] ?? '', ['class' => 'form-control input_number',
                'placeholder' => __('lang_v1.default_credit_limit'), 'id' => 'default_credit_limit']); !!}
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('whatsapp_send_as', 'Send ledger & invoice on WhatsApp as:') !!}
                {!! Form::select('common_settings[whatsapp_send_as]', ['image' => 'Image (opens in chat)', 'pdf' => 'PDF file', 'both' => 'Image + PDF'], $common_settings['whatsapp_send_as'] ?? 'image', ['class' => 'form-control', 'id' => 'whatsapp_send_as']); !!}
                <p class="help-block">Image is easiest for customers on a phone; PDF is better for long ledgers and printing.</p>
            </div>
        </div>
    </div>
</div>