@props(['style' => session('flash.bannerStyle', 'success'), 'message' => session('flash.banner')])

{{-- Jetstream's flash banner (session flash.banner) and the browser event `banner-message`, drawn as a design-system alert at the top of the page. --}}
<div x-data="{{ json_encode(['show' => true, 'style' => $style, 'message' => $message]) }}"
     x-show="show && message" style="display: none;" class="sh-flash"
     x-on:banner-message.window="style = event.detail.style; message = event.detail.message; show = true;">
    <div class="ds-alert" :class="{ 'ds-alert--success': style == 'success', 'ds-alert--danger': style == 'danger', 'ds-alert--notice': style == 'warning' }" role="status">
        <x-ds.icon name="info" :size="20" class="ds-alert__icon" />
        <div class="ds-alert__body"><div class="ds-alert__text" x-text="message"></div></div>
        <button type="button" class="ds-alert__close" aria-label="Dismiss message" x-on:click="show = false"><x-ds.icon name="close" :size="16" /></button>
    </div>
</div>
