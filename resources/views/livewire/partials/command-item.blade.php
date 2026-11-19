<x-ui.icon :name="$item['icon']" class="shrink-0 text-muted-foreground" />
<span class="flex-1 truncate">{{ $item['label'] }}</span>
@isset($item['hint'])
    <span class="shrink-0 font-mono text-xs text-muted-foreground">{{ $item['hint'] }}</span>
@endisset
