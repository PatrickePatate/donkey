{{--
    Toggle switch. Bind with wire:model / x-model (via x-modelable), or listen for the
    "switch-change" event whose detail is the new boolean value.
--}}
@props(['disabled' => false, 'checked' => false])

<button
    type="button"
    role="switch"
    x-data="{ checked: @js((bool) $checked) }"
    x-modelable="checked"
    x-on:click="checked = ! checked; $dispatch('switch-change', checked)"
    x-bind:aria-checked="checked.toString()"
    x-bind:data-state="checked ? 'checked' : 'unchecked'"
    @disabled($disabled)
    {{ $attributes->class(
        'peer inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border border-transparent shadow-xs transition-all outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-primary data-[state=unchecked]:bg-input dark:data-[state=unchecked]:bg-input/80'
    ) }}
>
    <span
        class="pointer-events-none block size-4 rounded-full bg-background shadow-sm ring-0 transition-transform"
        x-bind:class="checked ? 'translate-x-4' : 'translate-x-0.5'"
    ></span>
</button>
