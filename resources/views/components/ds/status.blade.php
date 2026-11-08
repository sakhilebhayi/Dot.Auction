{{--
  ds.status: the status vocabulary. Every state has a WORD, a silhouette and a hue; colour is never the only signal.
  Props: state (live|ending|scheduled|sold|unsold|pending|cancelled|extended|draft|awaiting_payment|paid|payment_overdue|failed|expired|initiated|unmatched),
         or auction=<Auction> to derive the lot state (draft, cancelled, pending_review, scheduled, ended sold / unsold, live, ending soon),
         count=null (for extended: "Extended 2 times"), size='md' (sm|md), long=false (use the long sentence form, for headers).
  Deriving from an Auction reads status, starts_at, ends_at and, for ended lots, winningBid (eager load it to avoid a query per card).
--}}
@props(['state' => null, 'auction' => null, 'count' => null, 'size' => 'md', 'long' => false])
@php
    $state = $state ?? ($auction ? \App\Support\Display::lotState($auction) : 'draft');
    $vocab = \App\Support\Display::statusVocabulary();
    $def = $vocab[$state] ?? ['word' => ucfirst(str_replace('_', ' ', $state)), 'long' => null, 'icon' => 'info', 'tone' => 'neutral'];
    $word = $state === 'extended' && $count ? 'Extended '.$count.($count === 1 ? ' time' : ' times') : ($long && $def['long'] ? $def['long'] : $def['word']);
@endphp
<span {{ $attributes->class(['ds-status', 'ds-status--'.$def['tone'], 'ds-status--'.$size, 'ds-status--'.$state]) }} data-state="{{ $state }}"><x-ds.icon :name="$def['icon']" :size="$size === 'sm' ? 12 : 14" /><span>{{ $word }}</span></span>
