{{--
  ds.dl: a definition list. The default way to show facts about a lot, a sale or an account.
  Props: layout='ledger' (term left, value right, ruled rows; figures right-aligned) | 'stacked' (term over value) | 'inline' (wraps in a row).
  Slot: <div class="ds-dl__row"><dt>Term</dt><dd>Value</dd></div> rows, or ds.stat entries.
--}}
@props(['layout' => 'ledger'])
<dl {{ $attributes->class(['ds-dl', 'ds-dl--'.$layout]) }}>{{ $slot }}</dl>
