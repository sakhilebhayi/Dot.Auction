{{--
  ds.stat: one figure with its label, for a headline number. Never lay four of these out as equal tiles.
  Layouts that work: one large stat beside a ds.dl ledger of the rest; a single row of at most three with different weights (size lg + two md); or a ledger only.
  Props: label (required), value=null (or the slot, e.g. a ds.money), note=null (the baseline or window the figure is measured against), size='md' (sm|md|lg), tone='neutral' (neutral|urgent|ok).
--}}
@props(['label', 'value' => null, 'note' => null, 'size' => 'md', 'tone' => 'neutral'])
<div {{ $attributes->class(['ds-stat', 'ds-stat--'.$size, 'ds-stat--'.$tone]) }}>
    <dt class="ds-stat__label">{{ $label }}</dt>
    <dd class="ds-stat__value num">{{ $value ?? $slot }}</dd>
    @if ($note)<dd class="ds-stat__note">{{ $note }}</dd>@endif
</div>
