<div class="min-h-screen flex flex-col sm:justify-center items-center px-5 pt-10 pb-10 sm:pt-0 bg-[var(--ink)]">
    <div class="mb-2">
        {{ $logo }}
    </div>

    <div class="w-full sm:max-w-md mt-6 px-6 py-6 bg-[var(--ink-soft)] border border-[var(--line)] shadow-[0_20px_60px_-15px_rgba(0,0,0,0.6)] overflow-hidden sm:rounded-lg">
        {{ $slot }}
    </div>
</div>
