{{--
  ds.confirm-dialog: replaces the browser's confirm(). A dialog that holds the real form, so the destructive request is only sent from the confirm button.
  Props: id (required), title (required: the question, "Delete this draft?"), body=null (what will happen, and whether it can be undone), action (required URL),
         method='POST' (PUT, PATCH, DELETE are spoofed), confirm='Confirm' (the verb: "Delete draft", never "OK"), cancel='Keep it', tone='danger' (danger | primary).
  Slot: extra form fields (a reason, a checkbox). Open it with data-dialog-open="{id}" on a ds.button, or use ds.confirm which renders the trigger as well.
--}}
@props(['id', 'title', 'body' => null, 'action', 'method' => 'POST', 'confirm' => 'Confirm', 'cancel' => 'Keep it', 'tone' => 'danger'])
<x-ds.dialog :id="$id" :title="$title" size="sm">
    <form method="POST" action="{{ $action }}" class="ds-confirm" data-ds-confirm-form>
        @csrf
        @if (! in_array(strtoupper($method), ['GET', 'POST'], true))@method($method)@endif
        @if ($body)<p class="ds-confirm__body">{{ $body }}</p>@endif
        {{ $slot }}
        <div class="ds-dialog__foot">
            <x-ds.button type="submit" :variant="$tone === 'danger' ? 'danger' : 'primary'">{{ $confirm }}</x-ds.button>
            <x-ds.button variant="quiet" data-dialog-close>{{ $cancel }}</x-ds.button>
        </div>
    </form>
</x-ds.dialog>
