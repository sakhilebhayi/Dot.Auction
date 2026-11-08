<ul class="ds-catalogue">
    @foreach (['live', 'ending', 'extended', 'scheduled', 'sold', 'unsold', 'pending', 'cancelled'] as $k)
        <li><x-ds.lot-card :auction="$lots[$k]" href="#" :watching="$k === 'live'" /></li>
    @endforeach
    <li><x-ds.lot-card :auction="$lots['live']" href="#" compact /></li>
</ul>
