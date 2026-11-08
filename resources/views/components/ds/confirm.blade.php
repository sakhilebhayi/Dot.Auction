{{--
  ds.confirm: a trigger button plus its confirm dialog in one tag. The slot is the trigger's label.
  Props: title, body, action, method, confirm, cancel, tone: as ds.confirm-dialog. variant='quiet' (the trigger's ds.button variant), size='md', icon=null, id=null (generated).
  Example: <x-ds.confirm :action="route('seller.auctions.destroy', $a)" method="DELETE" title="Delete this draft?" body="The photos go with it. This cannot be undone." confirm="Delete draft" variant="danger" icon="trash">Delete</x-ds.confirm>
--}}
@props(['title', 'body' => null, 'action', 'method' => 'POST', 'confirm' => 'Confirm', 'cancel' => 'Keep it', 'tone' => 'danger', 'variant' => 'quiet', 'size' => 'md', 'icon' => null, 'id' => null])
@php $id = $id ?? 'confirm-'.substr(md5($action.$title.$method), 0, 8); @endphp
<button type="button" {{ $attributes->class(['ds-btn', 'ds-btn--'.$variant, 'ds-btn--'.$size]) }} data-dialog-open="{{ $id }}">@if ($icon)<x-ds.icon :name="$icon" :size="$size === 'sm' ? 16 : 18" />@endif<span class="ds-btn__label">{{ $slot }}</span></button>
<x-ds.confirm-dialog :id="$id" :title="$title" :body="$body" :action="$action" :method="$method" :confirm="$confirm" :cancel="$cancel" :tone="$tone" />
