{{--
  ds.toasts: the toast region. Render it once in the layout (the app and guest layouts already do).
  Raise a toast from anywhere: window.dsToast({ title: 'Bid placed', body: 'You lead at R1,300.00', tone: 'success' }), or dispatch the browser event
  'ds-toast' with the same detail (Livewire: $this->dispatch('ds-toast', title: 'Saved', tone: 'success')). Tones: info, success, notice, danger.
  Toasts leave after 6 seconds (never when they carry an error), pause while hovered or focused, and can always be dismissed. They are for confirmations, not for errors a person must act on.
--}}
<div class="ds-toasts" role="region" aria-label="Notifications" data-ds-toasts></div>
<template id="ds-toast-template">
    <div class="ds-toast" role="status">
        <span class="ds-toast__icon" aria-hidden="true"><x-ds.icon name="info" :size="20" class="t-info" /><x-ds.icon name="check-circle" :size="20" class="t-success" /><x-ds.icon name="pause" :size="20" class="t-notice" /><x-ds.icon name="alert" :size="20" class="t-danger" /></span>
        <div class="ds-toast__text"><p class="ds-toast__title"></p><p class="ds-toast__body"></p></div>
        <button type="button" class="ds-toast__close" data-toast-close aria-label="Dismiss"><x-ds.icon name="close" :size="16" /></button>
    </div>
</template>
