{{-- Shared by the investor pages: modal forms save by ajax, delete buttons, date pickers inside modals --}}
<script type="text/javascript">
    $(document).on('shown.bs.modal', '.investor_modal', function() {
        $(this).find('.investor-date').datepicker({ autoclose: true, format: datepicker_date_format });
        $(this).find('.investor-select2').select2({ dropdownParent: $(this), width: '100%' });
        __currency_convert_recursively($(this));
    });

    $(document).on('submit', 'form.investor-ajax-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]').prop('disabled', true);
        $.ajax({
            method: 'POST',
            url: $form.attr('action'),
            data: $form.serialize(),
            dataType: 'json',
            success: function(result) {
                if (result.success) {
                    toastr.success(result.msg);
                    setTimeout(function() { location.reload(); }, 600);
                } else {
                    toastr.error(result.msg);
                    $btn.prop('disabled', false);
                }
            },
            error: function(xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : @json(__('messages.something_went_wrong'));
                toastr.error(msg);
                $btn.prop('disabled', false);
            }
        });
    });

    $(document).on('click', '.investor-delete', function(e) {
        e.preventDefault();
        var url = $(this).data('href');
        swal({ title: LANG.sure, text: $(this).data('confirm') || '', icon: 'warning', buttons: true, dangerMode: true }).then(function(ok) {
            if (!ok) {
                return;
            }
            $.ajax({
                method: 'DELETE',
                url: url,
                dataType: 'json',
                data: { _token: '{{ csrf_token() }}' },
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        setTimeout(function() { location.reload(); }, 600);
                    } else {
                        toastr.error(result.msg);
                    }
                }
            });
        });
    });
</script>
