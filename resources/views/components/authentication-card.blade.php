<div class="relative min-h-screen flex flex-col sm:justify-center items-center px-5 pt-10 pb-10 sm:pt-0 overflow-hidden">
    {{-- Same hero photo as welcome.blade.php (wooden gavel, Sasun Bughdaryan), reused as-is so the
    auth pages carry the same photographic identity as the welcome hero. --}}
    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1767972463877-b64ba4283cd0?q=80&w=2400&auto=format&fit=crop');"></div>
    <div class="absolute inset-0" style="background: radial-gradient(ellipse 68% 62% at 50% 40%, rgba(23,17,15,0.9) 0%, rgba(23,17,15,0.68) 45%, rgba(23,17,15,0.35) 74%, rgba(23,17,15,0.12) 100%);"></div>
    <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(23,17,15,0.6) 0%, transparent 18%, transparent 74%, rgba(23,17,15,0.5) 100%);"></div>

    <div class="relative z-10 mb-2">
        {{ $logo }}
    </div>

    <div class="relative z-10 w-full sm:max-w-md mt-6 px-6 py-6 bg-[var(--ink-soft)] border border-[var(--line)] shadow-[0_20px_60px_-15px_rgba(0,0,0,0.6)] overflow-hidden sm:rounded-lg">
        {{ $slot }}
    </div>
</div>
