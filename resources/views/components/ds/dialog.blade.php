{{--
  ds.dialog: a modal on the native <dialog> element, so focus is trapped, Escape closes, and the page behind is inert without scripting of our own.
  Open it with any element carrying data-dialog-open="{id}", or window.dsDialog.open('{id}'), or the Livewire/Alpine event $dispatch('ds-dialog-open', { id: '{id}' }).
  Close it with data-dialog-close inside, Escape, or a click on the backdrop. Focus returns to the opener. On phones it is a bottom sheet that scrolls inside.
  Props: id (required), title (required; the heading and the accessible name), description=null, size='md' (sm | md | lg), open=false (server-open, e.g. after a failed submit).
  Slots: default (body), footer (actions; the main action last on wide screens, first on phones).
--}}
@props(['id', 'title', 'description' => null, 'size' => 'md', 'open' => false])
<dialog id="{{ $id }}" {{ $attributes->class(['ds-dialog', 'ds-dialog--'.$size]) }} aria-labelledby="{{ $id }}-title" @if ($description) aria-describedby="{{ $id }}-desc" @endif data-ds-dialog @if ($open) data-open-on-load @endif>
    <div class="ds-dialog__panel">
        <header class="ds-dialog__head">
            <h2 class="ds-dialog__title" id="{{ $id }}-title">{{ $title }}</h2>
            <button type="button" class="ds-iconbtn ds-iconbtn--small" data-dialog-close title="Close"><x-ds.icon name="close" :size="18" /><span class="sr-only">Close</span></button>
        </header>
        @if ($description)<p class="ds-dialog__desc" id="{{ $id }}-desc">{{ $description }}</p>@endif
        <div class="ds-dialog__body">{{ $slot }}</div>
        @isset($footer)<footer class="ds-dialog__foot">{{ $footer }}</footer>@endisset
    </div>
</dialog>
