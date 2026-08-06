@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'bg-[var(--ink)] border border-[var(--line)] text-[var(--paper)] placeholder:text-[var(--stone)]/60 focus:border-[var(--gold)] focus:ring-[var(--gold)] rounded-md shadow-sm disabled:opacity-50']) !!}>
