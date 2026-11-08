{{--
  ds.table: a data table that becomes stacked rows on phones (below 40rem) instead of scrolling sideways.
  Props: caption (required; visible to screen readers, shown when captionVisible), captionVisible=false, label=null (aria-label for the scroll region when scroll=true), scroll=false
         (use only for very wide tables: it adds a focusable horizontal scroll region and keeps the table layout on phones).
  Slot: <thead><tr><th scope="col">..</th></tr></thead><tbody><tr><td data-label="Price" class="num">..</td></tr></tbody>.
  Rules: every <td> carries data-label with its column name (the stacked view prints it); figures get class="num" (right aligned, tabular);
  put the row's main link or the row action in the last cell; no zebra stripes, no header band, numbers right aligned.
--}}
@props(['caption', 'captionVisible' => false, 'scroll' => false, 'label' => null])
<div {{ $attributes->class(['ds-tablewrap', 'is-scroll' => $scroll]) }} @if ($scroll) role="region" tabindex="0" aria-label="{{ $label ?? $caption }}" @endif>
    <table class="ds-table @unless ($scroll) is-stackable @endunless">
        <caption @class(['sr-only' => ! $captionVisible, 'ds-table__caption' => $captionVisible])>{{ $caption }}</caption>
        {{ $slot }}
    </table>
</div>
