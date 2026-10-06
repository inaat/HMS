<div class="modal-dialog" role="document">
  <div class="modal-content">
    <form class="investor-ajax-form" method="POST"
      action="{{ empty($investor) ? action([\App\Http\Controllers\InvestorController::class, 'store']) : action([\App\Http\Controllers\InvestorController::class, 'update'], [$investor->id]) }}">
      @csrf
      @if (! empty($investor))
        @method('PUT')
      @endif
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        <h4 class="modal-title">{{ empty($investor) ? 'Add investor' : 'Edit investor' }}</h4>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Name *</label>
          <input type="text" name="name" class="form-control" value="{{ $investor->name ?? '' }}" required>
        </div>
        <div class="form-group">
          <label>Mobile (for the WhatsApp statement)</label>
          <input type="text" name="mobile" class="form-control" value="{{ $investor->mobile ?? '' }}" placeholder="03001234567">
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" class="form-control" rows="2">{{ $investor->notes ?? '' }}</textarea>
        </div>
        <div class="checkbox">
          <label><input type="checkbox" name="is_active" value="1" @if (empty($investor) || $investor->is_active) checked @endif> Active (inactive investors are left out of new settlements)</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
        <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">@lang('messages.close')</button>
      </div>
    </form>
  </div>
</div>
