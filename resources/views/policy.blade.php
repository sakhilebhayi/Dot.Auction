<x-guest-layout>
    <div class="pt-4 px-5 bg-[var(--ink)]">
        <div class="min-h-screen flex flex-col items-center pt-6 sm:pt-0">
            <div>
                <x-authentication-card-logo />
            </div>

            <div class="w-full sm:max-w-2xl mt-6 p-6 bg-[var(--ink-soft)] border border-[var(--line)] shadow-md overflow-hidden sm:rounded-lg prose prose-invert prose-headings:font-display prose-headings:text-[var(--paper)] prose-a:text-[var(--gold-soft)] prose-strong:text-[var(--paper)]">
                {!! $policy !!}
            </div>
        </div>
    </div>
</x-guest-layout>
