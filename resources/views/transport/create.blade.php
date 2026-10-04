<div class="modal-dialog" role="document">
  <div class="modal-content">

    {!! Form::open(['url' => action([\App\Http\Controllers\TransportCompanyController::class, 'store']) ,'method' => 'post', 'id' => $quick_add ? 'quick_add_transport_form' : 'transport_add_form' ]) !!}

    <div class="modal-header">
      <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      <h4 class="modal-title">@lang( 'Add Transport Company' )</h4>
    </div>

    <div class="modal-body">
      <div class="form-group">
        {!! Form::label('name', __( 'Transport Company Name' ) . ':*') !!}
          {!! Form::text('name', null, ['class' => 'form-control', 'required', 'placeholder' => __( 'Transport Company Name' ) ]); !!}
      </div>

      <div class="form-group">
        {!! Form::label('description', __( 'Transport Company Short Description' ) . ':') !!}
          {!! Form::text('description', null, ['class' => 'form-control','placeholder' => __( 'brand.short_description' )]); !!}
      </div>
    </div>

    <div class="modal-footer">
      <button type="submit" class="btn btn-primary">@lang( 'messages.save' )</button>
      <button type="button" class="btn btn-default" data-dismiss="modal">@lang( 'messages.close' )</button>
    </div>

    {!! Form::close() !!}

  </div><!-- /.modal-content -->
</div><!-- /.modal-dialog -->