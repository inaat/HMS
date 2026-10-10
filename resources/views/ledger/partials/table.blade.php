{{-- One report table for the screen and the print page.
     $table: head [[label, align]], rows [cells, class, indent, link], foot, name_col (column indented / linked). --}}
@php
    $align = array_column($table['head'], 1);
    $nc = $table['name_col'] ?? 0;
@endphp
<table class="ledger-table">
    <thead>
        <tr>
            @foreach ($table['head'] as [$label, $a])
                <th class="{{ $a }}">{{ $label }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($table['rows'] as $r)
            <tr class="{{ $r['class'] }}">
                @foreach ($r['cells'] as $i => $cell)
                    @if (is_array($cell))
                        <td class="no-print" style="white-space:nowrap;">
                            <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary edit_account" data-account='@json($cell)'><i class="fa fa-edit"></i> Edit</button>
                            @if ($cell['can_delete'])
                                <form method="POST" action="{{ action([\App\Http\Controllers\LedgerController::class, 'destroyAccount'], [$cell['id']]) }}" style="display:inline;" class="delete_account">
                                    @csrf <button class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error"><i class="fa fa-trash"></i></button>
                                </form>
                            @endif
                        </td>
                    @elseif ($cell === null)
                        <td class="no-print"></td>
                    @elseif ($i == $nc)
                        <td style="padding-left: {{ 6 + 18 * $r['indent'] }}px;">
                            @if (! empty($r['link']) && empty($print))
                                <a href="{{ $r['link'] }}">{{ $cell }}</a>
                            @else
                                {{ $cell }}
                            @endif
                        </td>
                    @else
                        <td class="{{ $align[$i] ?? '' }}">{{ $cell }}</td>
                    @endif
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($table['head']) }}" class="center muted">Nothing for these dates.</td></tr>
        @endforelse
    </tbody>
    @if (! empty($table['foot']))
        <tfoot>
            @foreach ($table['foot'] as $r)
                <tr class="{{ $r['class'] }}">
                    @foreach ($r['cells'] as $i => $cell)
                        <td class="{{ $align[$i] ?? '' }}">{{ is_array($cell) ? '' : $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tfoot>
    @endif
</table>
